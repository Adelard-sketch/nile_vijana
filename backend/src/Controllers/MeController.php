<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Services\Ledger;
use App\Support\Present;

final class MeController extends Controller
{
    /** GET /api/me — who am I, my roles, profile, studios and balance. */
    public function show(Request $req): array
    {
        $uid = $req->userId();
        $profile = DB::one('SELECT * FROM creator_profiles WHERE user_id = ?', [$uid]);
        $studios = DB::all('SELECT id, name, slug, status FROM studios WHERE owner_id = ? ORDER BY id', [$uid]);
        return [
            'user'            => Present::user($req->user),
            'creator_profile' => $profile ? Present::creator($profile) + [
                'payout_phone'    => $profile['payout_phone'],
                'payout_provider' => $profile['payout_provider'],
            ] : null,
            'studios'         => $studios,
            'wallet_balance'  => Ledger::balance($uid),
            'currency'        => config('money.currency'),
        ];
    }

    /** PATCH /api/me — name, email, language, password. */
    public function update(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'name'             => 'nullable|string|min:2|max:120',
            'email'            => 'nullable|email|max:190',
            'language'         => 'nullable|in:en,rw,sw,fr',
            'current_password' => 'nullable|string|max:100',
            'new_password'     => 'nullable|string|min:8|max:100',
        ]);
        $uid = $req->userId();
        $row = DB::one('SELECT * FROM users WHERE id = ?', [$uid]);
        $changes = [];

        if (!empty($d['name'])) {
            $changes['name'] = $d['name'];
        }
        if (!empty($d['language'])) {
            $changes['language'] = $d['language'];
        }
        if (array_key_exists('email', $d) && $d['email'] !== $row['email']) {
            if ($d['email'] && DB::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$d['email'], $uid])) {
                throw HttpException::conflict('This email is used by another account');
            }
            $changes['email'] = $d['email'];
        }
        if (!empty($d['new_password'])) {
            if ($row['password_hash'] && !password_verify((string) ($d['current_password'] ?? ''), $row['password_hash'])) {
                throw HttpException::invalid('Current password is wrong', ['current_password' => 'Wrong password']);
            }
            $changes['password_hash'] = Auth::hashPassword($d['new_password']);
        }
        if ($changes) {
            DB::update('users', $changes, 'id = ?', [$uid]);
        }
        return Present::user(DB::one('SELECT * FROM users WHERE id = ?', [$uid]), Auth::roles($uid));
    }

    /** GET /api/me/wallet — balance and recent money movements. */
    public function wallet(Request $req): array
    {
        $uid = $req->userId();
        return [
            'balance'  => Ledger::balance($uid),
            'currency' => config('money.currency'),
            'min_payout' => (int) config('money.min_payout'),
            'history'  => array_map(static fn ($r) => [
                'id'         => (int) $r['id'],
                'amount'     => (int) $r['amount'],
                'type'       => $r['type'],
                'memo'       => $r['memo'],
                'ref'        => ['type' => $r['ref_type'], 'id' => (int) $r['ref_id']],
                'created_at' => $r['created_at'],
            ], Ledger::history($uid, (int) ($req->query['limit'] ?? 50))),
        ];
    }

    /** GET /api/me/library — what I bought, liked and follow. */
    public function library(Request $req): array
    {
        $uid = $req->userId();
        $purchases = DB::all(
            "SELECT oi.id, oi.order_id, oi.item_type, oi.title, oi.tier, oi.beat_id, oi.track_id, oi.price, o.paid_at
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE o.buyer_id = ? AND o.status = 'paid' AND oi.item_type <> 'tip'
              ORDER BY o.paid_at DESC",
            [$uid]
        );
        $liked = DB::all(
            "SELECT t.*, cp.stage_name AS creator_name, cp.slug AS creator_slug
               FROM likes l JOIN tracks t ON t.id = l.track_id
               LEFT JOIN creator_profiles cp ON cp.user_id = t.creator_id
              WHERE l.user_id = ? AND t.status = 'live' ORDER BY l.created_at DESC LIMIT 200",
            [$uid]
        );
        $following = DB::all(
            'SELECT cp.* FROM follows f JOIN creator_profiles cp ON cp.user_id = f.creator_id
              WHERE f.follower_id = ? ORDER BY f.created_at DESC',
            [$uid]
        );
        return [
            'purchases' => array_map(static fn ($p) => [
                'order_item_id' => (int) $p['id'],
                'order_id'      => (int) $p['order_id'],
                'type'          => $p['item_type'],
                'title'         => $p['title'],
                'tier'          => $p['tier'],
                'price'         => (int) $p['price'],
                'paid_at'       => $p['paid_at'],
                'downloads_url' => url('/api/orders/' . $p['order_id'] . '/downloads'),
            ], $purchases),
            'liked_tracks' => array_map([Present::class, 'track'], $liked),
            'following'    => array_map([Present::class, 'creator'], $following),
        ];
    }
}
