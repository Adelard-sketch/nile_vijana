<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Analytics;
use App\Services\BookingService;
use App\Services\Accounts;
use App\Services\Ledger;
use App\Services\Moderation;
use App\Services\Onboarding;
use App\Services\PayoutService;
use App\Services\Sms;
use App\Support\Present;

/** The Nile Vijana team's back office. Every route here requires the admin role. */
final class AdminController extends Controller
{
    /**
     * GET /api/admin/overview — everything the admin dashboard shows, from real records:
     * account totals by type, content and approvals, revenue and commission per month,
     * creator and studio earnings, pending bookings, recent sign-ups and activity.
     */
    public function overview(Request $req): array
    {
        $roles = array_map('intval', array_column(DB::all('SELECT role, COUNT(*) AS n FROM {user_roles} GROUP BY role'), 'n', 'role'));
        $types = array_map('intval', array_column(DB::all('SELECT type, COUNT(*) AS n FROM {creator_profiles} GROUP BY type'), 'n', 'type'));
        $count = static fn (string $table) => array_map('intval', array_column(DB::all("SELECT status, COUNT(*) AS n FROM $table GROUP BY status"), 'n', 'status'));
        $tracks = $count('tracks');
        $beats = $count('beats');
        $studios = $count('studios');
        $bookings = $count('bookings');
        $paidItems = static fn (string $types) => (int) DB::value(
            "SELECT COALESCE(SUM(oi.price - oi.commission), 0) FROM {order_items} oi JOIN {orders} o ON o.id = oi.order_id
              WHERE o.status = 'paid' AND oi.item_type IN ($types)"
        );
        $monthly = Analytics::platformMonthly(12);
        $thisMonth = end($monthly);
        $pendingApprovals = [
            'songs'    => $tracks['in_review'] ?? 0,
            'beats'    => $beats['in_review'] ?? 0,
            'studios'  => $studios['pending'] ?? 0,
            'payouts'  => (int) DB::value("SELECT COUNT(*) FROM {payouts} WHERE status = 'requested'"),
            'reports'  => (int) DB::value("SELECT COUNT(*) FROM {reports} WHERE status = 'open'"),
            'questions' => (int) DB::value("SELECT COUNT(*) FROM {questions} WHERE status = 'open'"),
        ];
        $recentUsers = DB::all('SELECT u.*, cp.type AS profile_type, cp.stage_name FROM {users} u LEFT JOIN {creator_profiles} cp ON cp.user_id = u.id ORDER BY u.id DESC LIMIT 8');

        return [
            'currency' => config('money.currency'),
            'accounts' => [
                'users'          => (int) DB::value('SELECT COUNT(*) FROM {users}'),
                'new_this_month' => (int) DB::value('SELECT COUNT(*) FROM {users} WHERE created_at >= ?', [date('Y-m-01 00:00:00')]),
                'artists'        => ($types['artist'] ?? 0) + ($types['both'] ?? 0),
                'choirs'         => $types['choir'] ?? 0,
                'producers'      => ($types['producer'] ?? 0) + ($types['both'] ?? 0),
                'managers'       => $types['manager'] ?? 0,
                'vocal_coaches'  => $types['vocal_coach'] ?? 0,
                'studios'        => array_sum($studios),
                'studios_verified' => $studios['verified'] ?? 0,
                'fans'           => $roles['fan'] ?? 0,
                'roles'          => $roles,
            ],
            'content' => [
                'songs'       => array_sum($tracks) - ($tracks['removed'] ?? 0),
                'songs_live'  => $tracks['live'] ?? 0,
                'beats'       => array_sum($beats) - ($beats['removed'] ?? 0),
                'beats_live'  => $beats['live'] ?? 0,
                'rejected'    => ($tracks['rejected'] ?? 0) + ($beats['rejected'] ?? 0),
                'processing'  => ($tracks['processing'] ?? 0) + ($beats['processing'] ?? 0),
                'songs_by_status' => $tracks,
                'beats_by_status' => $beats,
            ],
            'pending_approvals' => $pendingApprovals + ['total' => $pendingApprovals['songs'] + $pendingApprovals['beats'] + $pendingApprovals['studios']],
            'revenue' => [
                'sales_volume'       => (int) DB::value("SELECT COALESCE(SUM(total), 0) FROM {orders} WHERE status = 'paid'"),
                'booking_volume'     => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM {payments} WHERE purpose <> 'order' AND status = 'completed'"),
                'platform_revenue'   => Ledger::platformBalance(),        // commission kept by Nile Vijana
                'commission_this_month' => (int) ($thisMonth['commission'] ?? 0),
                'artist_earnings'    => $paidItems("'track','tip'"),       // songs + tips, after commission
                'producer_earnings'  => $paidItems("'license'"),          // beat licences, after commission
                'studio_earnings'    => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM {ledger_entries} WHERE account = 'user' AND type = 'booking_release'"),
                'held_in_escrow'     => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM {ledger_entries} WHERE account = 'escrow'"),
                'creator_balances'   => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM {ledger_entries} WHERE account = 'user'"),
                'payouts_waiting'    => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM {payouts} WHERE status = 'requested'"),
                'paid_orders'        => (int) DB::value("SELECT COUNT(*) FROM {orders} WHERE status = 'paid'"),
                'monthly'            => $monthly,
            ],
            'bookings' => [
                'by_status' => $bookings,
                'pending'   => array_map(static fn ($id) => BookingService::present((int) $id), array_column(DB::all(
                    "SELECT id FROM {bookings} WHERE status = 'pending' ORDER BY start_at LIMIT 6"
                ), 'id')),
            ],
            'recent_registrations' => array_map(static fn ($u) => [
                'id'           => (int) $u['id'],
                'name'         => $u['name'],
                'profile_name' => $u['stage_name'],
                'profile_type' => $u['profile_type'],
                'roles'        => Auth::roles((int) $u['id']),
                'created_at'   => $u['created_at'],
            ], $recentUsers),
            'activity' => Analytics::activity(12),

            // Older keys, kept so existing pages keep working.
            'users'               => (int) DB::value('SELECT COUNT(*) FROM {users}'),
            'roles'               => $roles,
            'live_tracks'         => $tracks['live'] ?? 0,
            'live_beats'          => $beats['live'] ?? 0,
            'verified_studios'    => $studios['verified'] ?? 0,
            'paid_orders'         => (int) DB::value("SELECT COUNT(*) FROM {orders} WHERE status = 'paid'"),
            'sales_volume'        => (int) DB::value("SELECT COALESCE(SUM(total), 0) FROM {orders} WHERE status = 'paid'"),
            'booking_volume'      => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM {payments} WHERE purpose <> 'order' AND status = 'completed'"),
            'platform_commission' => Ledger::platformBalance(),
            'held_in_escrow'      => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM {ledger_entries} WHERE account = 'escrow'"),
            'creator_balances'    => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM {ledger_entries} WHERE account = 'user'"),
            'payouts_waiting'     => (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM {payouts} WHERE status = 'requested'"),
            'open_reports'        => $pendingApprovals['reports'],
        ];
    }

    /** GET /api/admin/activity?limit=30 — the platform activity feed. */
    public function activity(Request $req): array
    {
        return ['data' => Analytics::activity((int) ($req->query['limit'] ?? 30))];
    }

    /** GET /api/admin/bookings?status=pending|confirmed|...&page= — every studio booking. */
    public function bookings(Request $req): array
    {
        [$page, $per, $offset] = pagination($req->query, 30, 100);
        $where = ["b.status NOT IN ('held','expired')"];
        $params = [];
        if (!empty($req->query['status']) && $req->query['status'] !== 'all') {
            $where = ['b.status = ?'];
            $params[] = $req->query['status'];
        }
        $w = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM {bookings} b WHERE $w", $params);
        $ids = array_column(DB::all("SELECT b.id FROM {bookings} b WHERE $w ORDER BY b.start_at DESC LIMIT $per OFFSET $offset", $params), 'id');
        return $this->paginated(array_map(static fn ($id) => BookingService::present((int) $id), $ids), $page, $per, $total);
    }

    /**
     * GET /api/admin/creator-profiles?type=artist|musician|producer|choir|manager|vocal_coach|all&q=
     * Profiles for selectors (upload "on behalf of", managed artists) and the people list.
     *   artist   = artists (incl. "both")      musician = artists + choirs (who can own songs)
     *   producer = producers (incl. "both")    all      = every profile
     */
    public function creatorProfiles(Request $req): array
    {
        $d = Validator::validate($req->query, [
            'type' => 'required|in:artist,musician,producer,choir,manager,vocal_coach,all',
            'q'    => 'nullable|string|max:80',
        ]);
        $types = match ($d['type']) {
            'artist'   => ['artist', 'both'],
            'musician' => ['artist', 'both', 'choir'],
            'producer' => ['producer', 'both'],
            'all'      => ['artist', 'producer', 'both', 'choir', 'manager', 'vocal_coach'],
            default    => [$d['type']],
        };
        $in = implode(',', array_fill(0, count($types), '?'));
        $params = $types;
        $search = '';
        if (!empty($d['q'])) {
            $search = ' AND (cp.stage_name LIKE ? OR u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)';
            $like = '%' . $d['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $rows = DB::all(
            "SELECT cp.*, u.name AS account_name, u.phone AS account_phone, u.email AS account_email, u.status AS account_status
               FROM {creator_profiles} cp JOIN {users} u ON u.id = cp.user_id
              WHERE cp.type IN ($in)$search
              ORDER BY cp.stage_name, cp.user_id LIMIT 500",
            $params
        );

        $links = [];
        $managerIds = array_column(array_filter($rows, static fn ($p) => $p['type'] === 'manager'), 'user_id');
        if ($managerIds) {
            $in = implode(',', array_fill(0, count($managerIds), '?'));
            foreach (DB::all("SELECT manager_id, artist_id FROM {manager_artists} WHERE manager_id IN ($in)", $managerIds) as $l) {
                $links[(int) $l['manager_id']][] = (int) $l['artist_id'];
            }
        }
        return ['data' => array_map(static fn (array $p): array => Present::creatorPrivate($p) + [
            'stage_name'     => $p['stage_name'],
            'account'        => ['name' => $p['account_name'], 'phone' => $p['account_phone'], 'email' => $p['account_email'], 'status' => $p['account_status']],
        ] + ($p['type'] === 'manager' ? ['managed_artists' => $links[(int) $p['user_id']] ?? []] : []), $rows)];
    }

    /** PUT /api/admin/managers/{id}/artists {"artist_ids":[3, 6]} — which artists and choirs a manager looks after. */
    public function setManagerArtists(Request $req): array
    {
        $managerId = $req->id();
        if (!DB::value("SELECT 1 FROM {creator_profiles} WHERE user_id = ? AND type = 'manager'", [$managerId])) {
            throw HttpException::notFound('Manager');
        }
        $d = Validator::validate($req->all(), ['artist_ids' => 'nullable|array|max:50']);
        $ids = array_values(array_unique(array_filter(array_map('intval', $d['artist_ids'] ?? []), static fn ($id) => $id > 0)));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $found = array_map('intval', array_column(DB::all("SELECT user_id FROM {creator_profiles} WHERE user_id IN ($in) AND type IN ('artist','both','choir')", $ids), 'user_id'));
            if (count($found) !== count($ids)) {
                throw HttpException::invalid('Only artists and choirs can be linked to a manager', ['artist_ids' => 'Unknown artist']);
            }
        }
        DB::tx(static function () use ($managerId, $ids) {
            DB::run('DELETE FROM {manager_artists} WHERE manager_id = ?', [$managerId]);
            foreach ($ids as $artistId) {
                DB::insert('manager_artists', ['manager_id' => $managerId, 'artist_id' => $artistId]);
            }
        });
        return ['manager_id' => $managerId, 'managed_artists' => $ids];
    }

    /**
     * POST /api/admin/onboard (multipart or JSON) — the one onboarding form for every account type:
     * artist, choir, producer, manager, vocal_coach, studio. See Services\Onboarding for the fields.
     * Optional file: picture (profile picture, or the studio's cover photo).
     */
    public function onboard(Request $req): Response
    {
        $result = Onboarding::create($req->user, $req->all(), $req->file('picture'));
        $out = ['user_id' => $result['user_id'], 'type' => $result['type']];
        if ($result['studio_id']) {
            $studio = DB::one('SELECT * FROM {studios} WHERE id = ?', [$result['studio_id']]);
            $rooms = DB::all('SELECT * FROM {studio_rooms} WHERE studio_id = ? ORDER BY id', [$result['studio_id']]);
            $out['studio'] = Present::studio($studio, $rooms);
        } else {
            $out['profile'] = Present::creatorPrivate((new \App\Models\CreatorModel())->findByUserId($result['user_id']));
            if ($result['type'] === 'manager') {
                $out['managed_artists'] = array_map('intval', array_column(
                    DB::all('SELECT artist_id FROM {manager_artists} WHERE manager_id = ?', [$result['user_id']]), 'artist_id'
                ));
            }
        }
        return Response::created($out);
    }

    /**
     * POST /api/admin/creators — onboard an artist or producer so the admin can
     * upload on their behalf. Creates the account when the phone is new (phone-code
     * sign-in only) or attaches a profile to an existing user, then grants the
     * matching role(s). The new creator appears in the upload profile selector.
     */
    public function onboardCreator(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'name'       => 'required|string|min:2|max:120',
            'phone'      => 'required|phone',
            'email'      => 'required|email|max:190',     // sign-in codes are sent here
            'stage_name' => 'required|string|min:2|max:80',
            'type'       => 'required|in:artist,producer,both',
            'city'       => 'nullable|string|max:80',
            'bio'        => 'nullable|string|max:2000',
        ]);

        $phone = $d['phone'];
        $email = $d['email'] ?? null;

        $userId = DB::tx(static function () use ($d, $phone, $email): int {
            $user = DB::one('SELECT id, name FROM {users} WHERE phone = ?', [$phone]);
            if (!$user && $email) {
                $user = DB::one('SELECT id, name FROM {users} WHERE email = ?', [strtolower($email)]);
            }
            if ($user) {
                $uid = (int) $user['id'];
                if (DB::value('SELECT 1 FROM {creator_profiles} WHERE user_id = ?', [$uid])) {
                    throw HttpException::conflict("{$user['name']} already has a creator profile.");
                }
            } else {
                // Guard the unique columns so a clash surfaces as a 409, not a 500.
                if (DB::value('SELECT id FROM {users} WHERE phone = ?', [$phone])) {
                    throw HttpException::conflict('Another account already uses that phone number.');
                }
                if ($email && DB::value('SELECT id FROM {users} WHERE email = ?', [strtolower($email)])) {
                    throw HttpException::conflict('Another account already uses that email.');
                }
                $uid = DB::insert('users', [
                    'name'          => $d['name'],
                    'username'      => Accounts::uniqueUsername((string) $email),
                    'phone'         => $phone,
                    'email'         => $email,
                    'country'       => (string) config('app.country', 'GH'),
                    'password_hash' => null,   // the person sets one with "Forgot password"
                ]);
                Auth::addRole($uid, 'fan');
            }

            $creators = new \App\Models\CreatorModel();
            DB::insert('creator_profiles', [
                'user_id'   => $uid,
                'stage_name' => $d['stage_name'],
                'slug'       => $creators->uniqueSlug($d['stage_name'], $uid),
                'type'      => $d['type'],
                'bio'       => $d['bio'] ?? null,
                'city'      => $d['city'] ?? null,
                'verified'  => 1,
            ]);
            if ($d['type'] === 'artist' || $d['type'] === 'both') {
                Auth::addRole($uid, 'artist');
            }
            if ($d['type'] === 'producer' || $d['type'] === 'both') {
                Auth::addRole($uid, 'producer');
            }
            return $uid;
        });

        $profile = (new \App\Models\CreatorModel())->findByUserId($userId)
            ?? throw new \RuntimeException('Creator profile could not be loaded after onboarding.');
        return Response::created(Present::creator($profile) + ['user_id' => $userId, 'type' => $d['type']]);
    }

    /** Status filter for the content lists: ?status=in_review|live|rejected|processing|removed|all (default: everything but removed). */
    private function statusWhere(Request $req, string $col, array &$params): string
    {
        $status = (string) ($req->query['status'] ?? '');
        if ($status === 'all') {
            return '1=1';
        }
        if (in_array($status, ['processing', 'in_review', 'live', 'rejected', 'removed', 'sold_exclusive'], true)) {
            $params[] = $status;
            return "$col = ?";
        }
        return "$col <> 'removed'";
    }

    /** GET /api/admin/tracks?status=&q=&page=&per_page= */
    public function tracks(Request $req): array
    {
        [$page, $per, $offset] = pagination($req->query, 50, 50);
        $params = [];
        $where = [$this->statusWhere($req, 't.status', $params)];
        $query = trim((string) ($req->query['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(t.title LIKE ? OR t.genre LIKE ? OR cp.stage_name LIKE ?)';
            $like = '%' . $query . '%';
            array_push($params, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) DB::value(
            "SELECT COUNT(*) FROM {tracks} t LEFT JOIN {creator_profiles} cp ON cp.user_id = t.creator_id WHERE $whereSql",
            $params
        );
        $rows = DB::all(
            "SELECT t.*, cp.stage_name AS creator_name, cp.slug AS creator_slug,
                    (SELECT COUNT(*) FROM {likes} l WHERE l.track_id = t.id) AS like_count,
                    (SELECT COUNT(*) FROM {saved_tracks} s WHERE s.track_id = t.id) AS save_count,
                    (SELECT COUNT(*) FROM {track_shares} sh WHERE sh.track_id = t.id) AS share_count,
                    (SELECT COUNT(*) FROM {track_downloads} dl WHERE dl.track_id = t.id) AS download_count
               FROM {tracks} t
               LEFT JOIN {creator_profiles} cp ON cp.user_id = t.creator_id
              WHERE $whereSql
              ORDER BY t.updated_at DESC, t.id DESC
              LIMIT $per OFFSET $offset",
            $params
        );

        return $this->paginated(array_map(static fn ($t) => Present::track($t) + [
            'shares'     => (int) $t['share_count'],
            'downloads'  => (int) $t['download_count'],
            'listen_url' => FileController::listenUrl('track', (int) $t['id']),
            'creator_id' => (int) $t['creator_id'],
        ], $rows), $page, $per, $total);
    }

    /** GET /api/admin/beats?status=&q=&page=&per_page= */
    public function beats(Request $req): array
    {
        [$page, $per, $offset] = pagination($req->query, 50, 50);
        $params = [];
        $where = [$this->statusWhere($req, 'b.status', $params)];
        $query = trim((string) ($req->query['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(b.title LIKE ? OR b.genre LIKE ? OR b.tags LIKE ? OR cp.stage_name LIKE ?)';
            $like = '%' . $query . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) DB::value(
            "SELECT COUNT(*) FROM {beats} b LEFT JOIN {creator_profiles} cp ON cp.user_id = b.producer_id WHERE $whereSql",
            $params
        );
        $rows = DB::all(
            "SELECT b.*, cp.stage_name AS producer_name, cp.slug AS producer_slug,
                    (SELECT MIN(lt.price) FROM {license_tiers} lt WHERE lt.beat_id = b.id AND lt.active = 1) AS min_price
               FROM {beats} b
               LEFT JOIN {creator_profiles} cp ON cp.user_id = b.producer_id
              WHERE $whereSql
              ORDER BY b.updated_at DESC, b.id DESC
              LIMIT $per OFFSET $offset",
            $params
        );
        $tiers = BeatController::tiersFor(array_column($rows, 'id'));
        $items = array_map(
            static fn (array $beat): array => Present::beat($beat, $tiers[$beat['id']] ?? []) + [
                'listen_url'  => FileController::listenUrl('beat', (int) $beat['id']),
                'producer_id' => (int) $beat['producer_id'],
            ],
            $rows
        );

        return $this->paginated($items, $page, $per, $total);
    }

    /** GET /api/admin/queue — everything waiting for a decision. */
    public function queue(Request $req): array
    {
        return [
            'tracks'   => DB::all(
                "SELECT t.id, t.title, t.genre, t.created_at, cp.stage_name AS creator FROM {tracks} t
                   LEFT JOIN {creator_profiles} cp ON cp.user_id = t.creator_id WHERE t.status = 'in_review' ORDER BY t.id LIMIT 50"
            ),
            'beats'    => DB::all(
                "SELECT b.id, b.title, b.genre, b.created_at, cp.stage_name AS producer FROM {beats} b
                   LEFT JOIN {creator_profiles} cp ON cp.user_id = b.producer_id WHERE b.status = 'in_review' ORDER BY b.id LIMIT 50"
            ),
            'studios'  => DB::all(
                "SELECT s.id, s.name, s.city, s.address, s.phone, s.created_at, u.name AS owner FROM {studios} s
                   JOIN {users} u ON u.id = s.owner_id WHERE s.status = 'pending' ORDER BY s.id LIMIT 50"
            ),
            'creators' => DB::all(
                'SELECT cp.user_id AS id, cp.stage_name, cp.type, cp.city, cp.created_at, u.phone FROM {creator_profiles} cp
                   JOIN {users} u ON u.id = cp.user_id WHERE cp.verified = 0 ORDER BY cp.created_at LIMIT 50'
            ),
            'payouts'  => DB::all(
                "SELECT p.id, p.amount, p.phone, p.provider, p.created_at, u.name FROM {payouts} p
                   JOIN {users} u ON u.id = p.user_id WHERE p.status = 'requested' ORDER BY p.id LIMIT 50"
            ),
            'reports'  => DB::all("SELECT * FROM {reports} WHERE status = 'open' ORDER BY id LIMIT 50"),
        ];
    }

    /** GET /api/admin/studios?q=&page=&per_page= */
    public function studios(Request $req): array
    {
        [$page, $per, $offset] = pagination($req->query, 50, 50);
        $where = ['1=1'];
        $params = [];
        if (in_array($req->query['status'] ?? '', ['pending', 'verified', 'rejected', 'suspended'], true)) {
            $where[] = 's.status = ?';
            $params[] = $req->query['status'];
        }
        $query = trim((string) ($req->query['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(s.name LIKE ? OR s.city LIKE ? OR u.name LIKE ?)';
            $like = '%' . $query . '%';
            array_push($params, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) DB::value(
            "SELECT COUNT(*) FROM {studios} s JOIN {users} u ON u.id = s.owner_id WHERE $whereSql",
            $params
        );
        $rows = DB::all(
            "SELECT s.*, u.name AS owner_name,
                    (SELECT MIN(r.hourly_rate) FROM {studio_rooms} r WHERE r.studio_id = s.id AND r.status = 'active') AS price_from,
                    (SELECT AVG(rv.rating) FROM {reviews} rv WHERE rv.target_type = 'studio' AND rv.target_id = s.id) AS avg_rating,
                    (SELECT COUNT(*) FROM {reviews} rv WHERE rv.target_type = 'studio' AND rv.target_id = s.id) AS review_count,
                    (SELECT COUNT(*) FROM {studio_rooms} r WHERE r.studio_id = s.id) AS room_count,
                    (SELECT COUNT(*) FROM {bookings} b JOIN {studio_rooms} r ON r.id = b.room_id WHERE r.studio_id = s.id) AS booking_count,
                    (archive.studio_id IS NOT NULL) AS is_archived
               FROM {studios} s
               JOIN {users} u ON u.id = s.owner_id
               LEFT JOIN {studio_archive_states} archive ON archive.studio_id = s.id
              WHERE $whereSql
              ORDER BY s.updated_at DESC, s.id DESC
              LIMIT $per OFFSET $offset",
            $params
        );
        $items = array_map(static function (array $studio): array {
            $rooms = DB::all('SELECT * FROM {studio_rooms} WHERE studio_id = ? ORDER BY id', [$studio['id']]);
            $presented = Present::studio($studio, $rooms, [
                'average' => $studio['avg_rating'] !== null ? round((float) $studio['avg_rating'], 1) : null,
                'count'   => (int) $studio['review_count'],
            ]);
            return $presented + [
                'owner_name'   => $studio['owner_name'],
                'room_count'   => (int) $studio['room_count'],
                'booking_count'=> (int) $studio['booking_count'],
                'is_archived'  => (bool) $studio['is_archived'],
            ];
        }, $rows);

        return $this->paginated($items, $page, $per, $total);
    }

    /** DELETE /api/admin/studios/{id} — archive a listing and retain its booking history. */
    public function archiveStudio(Request $req): array
    {
        $id = $req->id();
        return DB::tx(static function () use ($id): array {
            $studio = DB::one('SELECT id, status FROM {studios} WHERE id = ? FOR UPDATE', [$id])
                ?? throw HttpException::notFound('Studio');
            $archived = DB::one('SELECT previous_status FROM {studio_archive_states} WHERE studio_id = ?', [$id]);
            if ($archived) {
                return ['id' => $id, 'status' => 'suspended', 'archived' => true];
            }
            if ($studio['status'] === 'suspended') {
                throw HttpException::conflict('This studio is already suspended outside the archive action.');
            }

            DB::run(
                'INSERT INTO {studio_archive_states} (studio_id, previous_status) VALUES (?, ?)',
                [$id, $studio['status']]
            );
            DB::update('studios', ['status' => 'suspended'], 'id = ?', [$id]);
            return ['id' => $id, 'status' => 'suspended', 'archived' => true];
        });
    }

    /** POST /api/admin/studios/{id}/restore */
    public function restoreStudio(Request $req): array
    {
        $id = $req->id();
        return DB::tx(static function () use ($id): array {
            $studio = DB::one('SELECT id FROM {studios} WHERE id = ? FOR UPDATE', [$id])
                ?? throw HttpException::notFound('Studio');
            $archive = DB::one(
                'SELECT previous_status FROM {studio_archive_states} WHERE studio_id = ?',
                [$id]
            ) ?? throw HttpException::conflict('This studio is not archived.');

            DB::update('studios', ['status' => $archive['previous_status']], 'id = ?', [$studio['id']]);
            DB::run('DELETE FROM {studio_archive_states} WHERE studio_id = ?', [$studio['id']]);
            return ['id' => $id, 'status' => $archive['previous_status'], 'archived' => false];
        });
    }

    /**
     * POST /api/admin/tracks/{id}/review {"decision":"approve"|"reject","note":"why (required to reject)"}
     * Only approved songs become public. The reason is stored and shown to the creator.
     */
    public function reviewTrack(Request $req): array
    {
        $d = $this->decision($req);
        return Moderation::review('tracks', $req->id(), $d['decision'], $d['note'] ?? null, $req->userId());
    }

    /** POST /api/admin/beats/{id}/review — same as songs. */
    public function reviewBeat(Request $req): array
    {
        $d = $this->decision($req);
        return Moderation::review('beats', $req->id(), $d['decision'], $d['note'] ?? null, $req->userId());
    }

    /** POST /api/admin/studios/{id}/review — verify a studio (public and bookable) or reject it with a reason. */
    public function reviewStudio(Request $req): array
    {
        $d = $this->decision($req);
        $s = DB::one('SELECT s.*, u.phone AS owner_phone FROM {studios} s JOIN {users} u ON u.id = s.owner_id WHERE s.id = ?', [$req->id()])
            ?? throw HttpException::notFound('Studio');
        $ok = $d['decision'] === 'approve';
        $note = trim((string) ($d['note'] ?? ''));
        if (!$ok && mb_strlen($note) < 5) {
            throw HttpException::invalid('Tell the studio owner what to fix', ['note' => 'Write a short reason (at least 5 characters)']);
        }
        if (DB::value('SELECT 1 FROM {studio_archive_states} WHERE studio_id = ?', [$s['id']])) {
            throw HttpException::conflict('This studio is archived. Restore it first.');
        }
        DB::update('studios', [
            'status'      => $ok ? 'verified' : 'rejected',
            'review_note' => $ok ? null : mb_substr($note, 0, 500),
            'reviewed_by' => $req->userId(),
            'reviewed_at' => now(),
        ], 'id = ?', [$s['id']]);
        $this->notify($s['owner_phone'], $ok ? "{$s['name']} is verified and open for bookings." : "{$s['name']} needs changes before it can be listed: $note");
        return ['id' => (int) $s['id'], 'status' => $ok ? 'verified' : 'rejected', 'review_note' => $ok ? null : $note];
    }

    /** POST /api/admin/creators/{id}/verify {"verified": true} */
    public function verifyCreator(Request $req): array
    {
        $d = Validator::validate($req->all(), ['verified' => 'required|bool']);
        $n = DB::update('creator_profiles', ['verified' => (int) $d['verified']], 'user_id = ?', [$req->id()]);
        if (!$n && !DB::value('SELECT 1 FROM {creator_profiles} WHERE user_id = ?', [$req->id()])) {
            throw HttpException::notFound('Creator');
        }
        return ['id' => $req->id(), 'verified' => $d['verified']];
    }

    /** GET /api/admin/payouts?status=requested */
    public function payouts(Request $req): array
    {
        $status = $req->query['status'] ?? 'requested';
        $rows = DB::all(
            'SELECT p.*, u.name, u.phone AS account_phone, (SELECT COALESCE(SUM(amount),0) FROM {ledger_entries} le WHERE le.account = \'user\' AND le.user_id = p.user_id) AS balance_after
               FROM {payouts} p JOIN {users} u ON u.id = p.user_id WHERE p.status = ? ORDER BY p.id DESC LIMIT 100',
            [$status]
        );
        return ['mode' => PayoutService::mode(), 'data' => array_map(static fn ($p) => Present::payout($p) + [
            'user' => ['id' => (int) $p['user_id'], 'name' => $p['name'], 'phone' => $p['account_phone']],
            'balance_after' => (int) $p['balance_after'],
        ], $rows)];
    }

    /** POST /api/admin/payouts/{id}/mark-sent {"note":"MoMo ref 123..."} — money sent outside the app. */
    public function markPayoutSent(Request $req): array
    {
        $d = Validator::validate($req->all(), ['note' => 'required|string|min:3|max:255'], ['note' => 'Transaction reference or note']);
        return Present::payout(PayoutService::markSent($req->user, $req->id(), $d['note']));
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
            'SELECT r.*, u.name AS reporter FROM {reports} r JOIN {users} u ON u.id = r.reporter_id WHERE r.status = ? ORDER BY r.id DESC LIMIT 100',
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
        $r = DB::one('SELECT * FROM {reports} WHERE id = ?', [$req->id()]) ?? throw HttpException::notFound('Report');

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
                    DB::run('DELETE FROM {auth_tokens} WHERE user_id = ?', [$r['target_id']]);
                }
            }
            DB::update('reports', [
                'status'          => $d['status'],
                'resolution_note' => $d['note'] ?? null,
                'resolved_by'     => $req->userId(),
                'resolved_at'     => now(),
            ], 'id = ?', [$r['id']]);
        });
        return DB::one('SELECT * FROM {reports} WHERE id = ?', [$r['id']]);
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
            $where[] = 'EXISTS (SELECT 1 FROM {user_roles} ur WHERE ur.user_id = u.id AND ur.role = ?)';
            $params[] = $req->query['role'];
        }
        $w = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM {users} u WHERE $w", $params);
        $rows = DB::all("SELECT u.* FROM {users} u WHERE $w ORDER BY u.id DESC LIMIT $per OFFSET $offset", $params);
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
            'email'       => 'nullable|email|max:190',
            'add_role'    => 'nullable|in:fan,artist,producer,studio_owner,admin,choir,manager,vocal_coach',
            'remove_role' => 'nullable|in:fan,artist,producer,studio_owner,admin,choir,manager,vocal_coach',
        ]);
        $id = $req->id();
        $u = DB::one('SELECT * FROM {users} WHERE id = ?', [$id]) ?? throw HttpException::notFound('User');
        if ($id === $req->userId() && (($d['status'] ?? null) === 'suspended' || ($d['remove_role'] ?? null) === 'admin')) {
            throw HttpException::invalid('You cannot suspend yourself or remove your own admin role');
        }
        if (!empty($d['email']) && $d['email'] !== $u['email']) {
            if (DB::value('SELECT id FROM {users} WHERE email = ? AND id <> ?', [$d['email'], $id])) {
                throw HttpException::invalid('That email is on another account.', ['email' => 'That email is on another account.']);
            }
            DB::update('users', ['email' => $d['email'], 'email_verified_at' => null], 'id = ?', [$id]);
        }
        if (!empty($d['status'])) {
            DB::update('users', ['status' => $d['status']], 'id = ?', [$id]);
            if ($d['status'] === 'suspended') {
                DB::run('DELETE FROM {auth_tokens} WHERE user_id = ?', [$id]);
            }
        }
        if (!empty($d['add_role'])) {
            Auth::addRole($id, $d['add_role']);
        }
        if (!empty($d['remove_role'])) {
            DB::run('DELETE FROM {user_roles} WHERE user_id = ? AND role = ?', [$id, $d['remove_role']]);
        }
        $u = DB::one('SELECT * FROM {users} WHERE id = ?', [$id]);
        return Present::user($u, Auth::roles($id)) + ['status' => $u['status']];
    }

    private function decision(Request $req): array
    {
        return Validator::validate($req->all(), [
            'decision' => 'required|in:approve,reject',
            'note'     => 'nullable|string|max:500',
        ]);
    }

    private function notify(?string $phone, string $message): void
    {
        if ($phone) {
            Sms::send($phone, 'Nile Vijana: ' . trim($message));
        }
    }
}
