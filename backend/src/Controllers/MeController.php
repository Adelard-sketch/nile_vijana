<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Services\Accounts;
use App\Services\Ledger;
use App\Support\Present;

final class MeController extends Controller
{
    /** GET /api/me — who am I, my roles, profile, studios and balance. */
    public function show(Request $req): array
    {
        $uid = $req->userId();
        $profile = DB::one('SELECT * FROM creator_profiles WHERE user_id = ?', [$uid]);
        $studios = DB::all('SELECT id, name, slug, status, review_note FROM studios WHERE owner_id = ? ORDER BY id', [$uid]);
        return [
            'user'            => Present::user($req->user),
            'creator_profile' => $profile ? Present::creatorPrivate($profile) : null,
            'studios'         => array_map(static fn ($s) => $s + ['id' => (int) $s['id']], $studios),
            'wallet_balance'  => Ledger::balance($uid),
            'currency'        => config('money.currency'),
        ];
    }

    /** PATCH /api/me — name, username, email, phone, language, password. */
    public function update(Request $req): array
    {
        $input = $req->all();
        if (isset($input['username'])) {
            $input['username'] = Accounts::normalizeUsername((string) $input['username']);
        }
        $d = Validator::validate($input, [
            'name'             => 'nullable|string|min:2|max:120',
            'username'         => 'nullable|string|max:30',
            'email'            => 'nullable|email|max:190',
            'phone'            => 'nullable|phone',
            'language'         => 'nullable|in:en,fr,tw,ha',
            'current_password' => 'nullable|string|max:100',
            'new_password'     => 'nullable|string|max:100',
        ]);
        $uid = $req->userId();
        $row = DB::one('SELECT * FROM users WHERE id = ?', [$uid]);
        $changes = [];
        $errors = [];

        if (!empty($d['name'])) {
            $changes['name'] = $d['name'];
        }
        if (!empty($d['language'])) {
            $changes['language'] = $d['language'];
        }
        if (!empty($d['username']) && $d['username'] !== $row['username']) {
            if ($problem = Accounts::usernameProblem($d['username'])) {
                $errors['username'] = $problem;
            } elseif (DB::value('SELECT id FROM users WHERE username = ? AND id <> ?', [$d['username'], $uid])) {
                $errors['username'] = 'That username is taken. Try another one.';
            } else {
                $changes['username'] = $d['username'];
            }
        }
        if (array_key_exists('email', $d) && $d['email'] !== $row['email']) {
            if (!$d['email']) {
                $errors['email'] = 'Email address is required: sign-in codes are sent to it.';
            } elseif (DB::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$d['email'], $uid])) {
                $errors['email'] = 'This email is used by another account.';
            } else {
                $changes['email'] = $d['email'];
                $changes['email_verified_at'] = null;     // confirmed again at the next sign-in
            }
        }
        if (array_key_exists('phone', $d) && $d['phone'] !== $row['phone']) {
            if ($d['phone'] && DB::value('SELECT id FROM users WHERE phone = ? AND id <> ?', [$d['phone'], $uid])) {
                $errors['phone'] = 'This phone number is on another account.';
            } else {
                $changes['phone'] = $d['phone'];
            }
        }
        if (!empty($d['new_password'])) {
            if ($row['password_hash'] && !password_verify((string) ($d['current_password'] ?? ''), $row['password_hash'])) {
                $errors['current_password'] = 'Your current password is not correct.';
            } else {
                try {
                    Accounts::checkPassword($d['new_password'], 'new_password');
                    $changes['password_hash'] = Auth::hashPassword($d['new_password']);
                } catch (HttpException $e) {
                    $errors += $e->errors;
                }
            }
        }
        if ($errors) {
            throw HttpException::invalid('Your changes were not saved. Please fix the highlighted fields.', $errors);
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
        $saved = DB::all(
            "SELECT t.*, cp.stage_name AS creator_name, cp.slug AS creator_slug
               FROM saved_tracks s JOIN tracks t ON t.id = s.track_id
               LEFT JOIN creator_profiles cp ON cp.user_id = t.creator_id
              WHERE s.user_id = ? AND t.status = 'live' ORDER BY s.created_at DESC LIMIT 200",
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
            'saved_tracks' => array_map([Present::class, 'track'], $saved),
            'following'    => array_map([Present::class, 'creator'], $following),
        ];
    }
}
