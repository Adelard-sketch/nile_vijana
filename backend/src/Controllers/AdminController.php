<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Services\Ledger;
use App\Services\PayoutService;
use App\Services\Sms;
use App\Support\Present;

/** The Nile Vijana team's back office. Every route here requires the admin role. */
final class AdminController extends Controller
{
    /** GET /api/admin/overview — platform totals. */
    public function overview(Request $req): array
    {
        return [
            'users'            => (int) DB::value('SELECT COUNT(*) FROM users'),
            'roles'            => array_column(DB::all('SELECT role, COUNT(*) AS n FROM user_roles GROUP BY role'), 'n', 'role'),
            'live_tracks'      => (int) DB::value("SELECT COUNT(*) FROM tracks WHERE status = 'live'"),
            'live_beats'       => (int) DB::value("SELECT COUNT(*) FROM beats WHERE status = 'live'"),
            'verified_studios' => (int) DB::value("SELECT COUNT(*) FROM studios WHERE status = 'verified'"),
            'paid_orders'      => (int) DB::value("SELECT COUNT(*) FROM orders WHERE status = 'paid'"),
            'sales_volume'     => (int) DB::value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'paid'"),
            'booking_volume'   => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE purpose <> 'order' AND status = 'completed'"),
            'platform_commission' => Ledger::platformBalance(),
            'held_in_escrow'   => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE account = 'escrow'"),
            'creator_balances' => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE account = 'user'"),
            'payouts_waiting'  => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM payouts WHERE status = 'requested'"),
            'open_reports'     => (int) DB::value("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
            'currency'         => config('money.currency'),
        ];
    }

    /** GET /api/admin/queue — everything waiting for a decision. */
    public function queue(Request $req): array
    {
        return [
            'tracks'   => DB::all(
                "SELECT t.id, t.title, t.genre, t.created_at, cp.stage_name AS creator FROM tracks t
                   LEFT JOIN creator_profiles cp ON cp.user_id = t.creator_id WHERE t.status = 'in_review' ORDER BY t.id LIMIT 50"
            ),
            'beats'    => DB::all(
                "SELECT b.id, b.title, b.genre, b.created_at, cp.stage_name AS producer FROM beats b
                   LEFT JOIN creator_profiles cp ON cp.user_id = b.producer_id WHERE b.status = 'in_review' ORDER BY b.id LIMIT 50"
            ),
            'studios'  => DB::all(
                "SELECT s.id, s.name, s.city, s.address, s.phone, s.created_at, u.name AS owner FROM studios s
                   JOIN users u ON u.id = s.owner_id WHERE s.status = 'pending' ORDER BY s.id LIMIT 50"
            ),
            'creators' => DB::all(
                'SELECT cp.user_id AS id, cp.stage_name, cp.type, cp.city, cp.created_at, u.phone FROM creator_profiles cp
                   JOIN users u ON u.id = cp.user_id WHERE cp.verified = 0 ORDER BY cp.created_at LIMIT 50'
            ),
            'payouts'  => DB::all(
                "SELECT p.id, p.amount, p.phone, p.provider, p.created_at, u.name FROM payouts p
                   JOIN users u ON u.id = p.user_id WHERE p.status = 'requested' ORDER BY p.id LIMIT 50"
            ),
            'reports'  => DB::all("SELECT * FROM reports WHERE status = 'open' ORDER BY id LIMIT 50"),
        ];
    }

    /** POST /api/admin/tracks/{id}/review {"decision":"approve"|"reject","note":"..."} */
    public function reviewTrack(Request $req): array
    {
        $d = $this->decision($req);
        $t = DB::one('SELECT t.*, u.phone FROM tracks t JOIN users u ON u.id = t.creator_id WHERE t.id = ?', [$req->id()])
            ?? throw HttpException::notFound('Track');
        $live = $d['decision'] === 'approve';
        DB::update('tracks', [
            'status'      => $live ? 'live' : 'rejected',
            'released_at' => $live ? ($t['released_at'] ?? now()) : $t['released_at'],
        ], 'id = ?', [$t['id']]);
        $this->notify($t['phone'], $live ? "your song \"{$t['title']}\" is now live." : "your song \"{$t['title']}\" was not approved. " . ($d['note'] ?? ''));
        return ['id' => (int) $t['id'], 'status' => $live ? 'live' : 'rejected'];
    }

    /** POST /api/admin/beats/{id}/review */
    public function reviewBeat(Request $req): array
    {
        $d = $this->decision($req);
        $b = DB::one('SELECT b.*, u.phone FROM beats b JOIN users u ON u.id = b.producer_id WHERE b.id = ?', [$req->id()])
            ?? throw HttpException::notFound('Beat');
        if ($b['status'] === 'sold_exclusive') {
            throw HttpException::conflict('This beat was sold exclusively');
        }
        $live = $d['decision'] === 'approve';
        DB::update('beats', ['status' => $live ? 'live' : 'rejected'], 'id = ?', [$b['id']]);
        $this->notify($b['phone'], $live ? "your beat \"{$b['title']}\" is now on sale." : "your beat \"{$b['title']}\" was not approved. " . ($d['note'] ?? ''));
        return ['id' => (int) $b['id'], 'status' => $live ? 'live' : 'rejected'];
    }

    /** POST /api/admin/studios/{id}/review */
    public function reviewStudio(Request $req): array
    {
        $d = $this->decision($req);
        $s = DB::one('SELECT s.*, u.phone AS owner_phone FROM studios s JOIN users u ON u.id = s.owner_id WHERE s.id = ?', [$req->id()])
            ?? throw HttpException::notFound('Studio');
        $ok = $d['decision'] === 'approve';
        DB::update('studios', ['status' => $ok ? 'verified' : 'rejected'], 'id = ?', [$s['id']]);
        $this->notify($s['owner_phone'], $ok ? "{$s['name']} is verified and open for bookings." : "{$s['name']} was not verified. " . ($d['note'] ?? ''));
        return ['id' => (int) $s['id'], 'status' => $ok ? 'verified' : 'rejected'];
    }

    /** POST /api/admin/creators/{id}/verify {"verified": true} */
    public function verifyCreator(Request $req): array
    {
        $d = Validator::validate($req->all(), ['verified' => 'required|bool']);
        $n = DB::update('creator_profiles', ['verified' => (int) $d['verified']], 'user_id = ?', [$req->id()]);
        if (!$n && !DB::value('SELECT 1 FROM creator_profiles WHERE user_id = ?', [$req->id()])) {
            throw HttpException::notFound('Creator');
        }
        return ['id' => $req->id(), 'verified' => $d['verified']];
    }

    /** GET /api/admin/payouts?status=requested */
    public function payouts(Request $req): array
    {
        $status = $req->query['status'] ?? 'requested';
        $rows = DB::all(
            'SELECT p.*, u.name, u.phone AS account_phone, (SELECT COALESCE(SUM(amount),0) FROM ledger_entries le WHERE le.account = \'user\' AND le.user_id = p.user_id) AS balance_after
               FROM payouts p JOIN users u ON u.id = p.user_id WHERE p.status = ? ORDER BY p.id DESC LIMIT 100',
            [$status]
        );
        return ['data' => array_map(static fn ($p) => Present::payout($p) + [
            'user' => ['id' => (int) $p['user_id'], 'name' => $p['name'], 'phone' => $p['account_phone']],
            'balance_after' => (int) $p['balance_after'],
        ], $rows)];
    }

    /** POST /api/admin/payouts/{id}/approve — sends the money by mobile money. */
    public function approvePayout(Request $req): array
    {
        return Present::payout(PayoutService::approve($req->user, $req->id()));
    }

    /** POST /api/admin/payouts/{id}/reject {"reason":"..."} — money goes back to the wallet. */
    public function rejectPayout(Request $req): array
    {
        $d = Validator::validate($req->all(), ['reason' => 'required|string|max:255']);
        return Present::payout(PayoutService::reject($req->id(), $d['reason']));
    }

    /** GET /api/admin/reports?status=open */
    public function reports(Request $req): array
    {
        $status = $req->query['status'] ?? 'open';
        return ['data' => DB::all(
            'SELECT r.*, u.name AS reporter FROM reports r JOIN users u ON u.id = r.reporter_id WHERE r.status = ? ORDER BY r.id DESC LIMIT 100',
            [$status]
        )];
    }

    /**
     * POST /api/admin/reports/{id}/resolve
     * {"status":"resolved"|"dismissed","note":"...","remove_content":true}
     * remove_content takes the track/beat down, suspends the studio or the user.
     */
    public function resolveReport(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'status'         => 'required|in:resolved,dismissed',
            'note'           => 'nullable|string|max:500',
            'remove_content' => 'nullable|bool',
        ]);
        $r = DB::one('SELECT * FROM reports WHERE id = ?', [$req->id()]) ?? throw HttpException::notFound('Report');

        DB::tx(static function () use ($r, $d, $req) {
            if (!empty($d['remove_content']) && $d['status'] === 'resolved') {
                match ($r['target_type']) {
                    'track'  => DB::update('tracks', ['status' => 'removed'], 'id = ?', [$r['target_id']]),
                    'beat'   => DB::update('beats', ['status' => 'removed'], 'id = ?', [$r['target_id']]),
                    'studio' => DB::update('studios', ['status' => 'suspended'], 'id = ?', [$r['target_id']]),
                    'user'   => DB::update('users', ['status' => 'suspended'], 'id = ?', [$r['target_id']]),
                };
                if ($r['target_type'] === 'beat') {
                    DB::update('license_tiers', ['active' => 0], 'beat_id = ?', [$r['target_id']]);
                }
                if ($r['target_type'] === 'user') {
                    DB::run('DELETE FROM auth_tokens WHERE user_id = ?', [$r['target_id']]);
                }
            }
            DB::update('reports', [
                'status'          => $d['status'],
                'resolution_note' => $d['note'] ?? null,
                'resolved_by'     => $req->userId(),
                'resolved_at'     => now(),
            ], 'id = ?', [$r['id']]);
        });
        return DB::one('SELECT * FROM reports WHERE id = ?', [$r['id']]);
    }

    /** GET /api/admin/users?q=&role=&page= */
    public function users(Request $req): array
    {
        [$page, $per, $offset] = pagination($req->query);
        $where = ['1=1'];
        $params = [];
        if (!empty($req->query['q'])) {
            $where[] = '(u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)';
            $like = '%' . $req->query['q'] . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($req->query['role'])) {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = ?)';
            $params[] = $req->query['role'];
        }
        $w = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM users u WHERE $w", $params);
        $rows = DB::all("SELECT u.* FROM users u WHERE $w ORDER BY u.id DESC LIMIT $per OFFSET $offset", $params);
        return $this->paginated(array_map(static fn ($u) => Present::user($u, Auth::roles((int) $u['id'])) + [
            'status'  => $u['status'],
            'balance' => Ledger::balance((int) $u['id']),
        ], $rows), $page, $per, $total);
    }

    /** PATCH /api/admin/users/{id} {"status":"suspended","add_role":"admin","remove_role":"producer"} */
    public function updateUser(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'status'      => 'nullable|in:active,suspended',
            'add_role'    => 'nullable|in:fan,artist,producer,studio_owner,admin',
            'remove_role' => 'nullable|in:fan,artist,producer,studio_owner,admin',
        ]);
        $id = $req->id();
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]) ?? throw HttpException::notFound('User');
        if ($id === $req->userId() && (($d['status'] ?? null) === 'suspended' || ($d['remove_role'] ?? null) === 'admin')) {
            throw HttpException::invalid('You cannot suspend yourself or remove your own admin role');
        }
        if (!empty($d['status'])) {
            DB::update('users', ['status' => $d['status']], 'id = ?', [$id]);
            if ($d['status'] === 'suspended') {
                DB::run('DELETE FROM auth_tokens WHERE user_id = ?', [$id]);
            }
        }
        if (!empty($d['add_role'])) {
            Auth::addRole($id, $d['add_role']);
        }
        if (!empty($d['remove_role'])) {
            DB::run('DELETE FROM user_roles WHERE user_id = ? AND role = ?', [$id, $d['remove_role']]);
        }
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        return Present::user($u, Auth::roles($id)) + ['status' => $u['status']];
    }

    private function decision(Request $req): array
    {
        return Validator::validate($req->all(), [
            'decision' => 'required|in:approve,reject',
            'note'     => 'nullable|string|max:300',
        ]);
    }

    private function notify(?string $phone, string $message): void
    {
        if ($phone) {
            Sms::send($phone, 'Nile Vijana: ' . trim($message));
        }
    }
}
