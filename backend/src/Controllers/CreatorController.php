<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Services\Storage;
use App\Support\Present;

final class CreatorController extends Controller
{
    /**
     * PATCH /api/creators/me — create or edit my artist/producer profile.
     * Creating one gives the account the artist and/or producer role.
     */
    public function upsert(Request $req): array
    {
        $uid = $req->userId();
        $existing = DB::one('SELECT * FROM creator_profiles WHERE user_id = ?', [$uid]);
        $d = Validator::validate($req->all(), [
            'stage_name'      => ($existing ? 'nullable' : 'required') . '|string|min:2|max:80',
            'type'            => ($existing ? 'nullable' : 'required') . '|in:artist,producer,both',
            'bio'             => 'nullable|string|max:2000',
            'city'            => 'nullable|string|max:80',
            'payout_phone'    => 'nullable|phone',
            'payout_provider' => 'nullable|in:MTN_MOMO_RWA,AIRTEL_RWA',
        ]);

        $row = array_filter($d, static fn ($v) => $v !== null);
        if (!empty($d['payout_phone']) && empty($d['payout_provider'])) {
            $row['payout_provider'] = detect_provider($d['payout_phone']);
        }
        if (!empty($d['stage_name']) && (!$existing || $d['stage_name'] !== $existing['stage_name'])) {
            $row['slug'] = $this->uniqueSlug($d['stage_name'], $uid);
        }

        DB::tx(static function () use ($uid, $row, $existing) {
            if ($existing) {
                if ($row) {
                    DB::update('creator_profiles', $row, 'user_id = ?', [$uid]);
                }
            } else {
                DB::insert('creator_profiles', $row + ['user_id' => $uid]);
            }
            $type = $row['type'] ?? $existing['type'];
            if ($type === 'artist' || $type === 'both') {
                Auth::addRole($uid, 'artist');
            }
            if ($type === 'producer' || $type === 'both') {
                Auth::addRole($uid, 'producer');
            }
        });

        $profile = DB::one('SELECT * FROM creator_profiles WHERE user_id = ?', [$uid]);
        return Present::creator($profile) + [
            'payout_phone'    => $profile['payout_phone'],
            'payout_provider' => $profile['payout_provider'],
            'roles'           => Auth::roles($uid),
        ];
    }

    /** POST /api/creators/me/avatar (multipart, field "avatar") */
    public function avatar(Request $req): array
    {
        $file = $req->file('avatar') ?? throw HttpException::invalid('Choose an image', ['avatar' => 'Required']);
        $profile = DB::one('SELECT * FROM creator_profiles WHERE user_id = ?', [$req->userId()])
            ?? throw HttpException::invalid('Create your creator profile first');
        $path = Storage::storeImage($file, 'avatars', 'avatar');
        DB::update('creator_profiles', ['avatar_path' => $path], 'user_id = ?', [$req->userId()]);
        if ($profile['avatar_path']) {
            @unlink(BASE_PATH . '/public/uploads/' . $profile['avatar_path']);
        }
        return ['avatar_url' => upload_url($path)];
    }

    /** GET /api/creators/{slug} — public profile page with songs and beats. */
    public function show(Request $req): array
    {
        $slug = $req->param('slug');
        $p = ctype_digit($slug)
            ? DB::one('SELECT * FROM creator_profiles WHERE user_id = ?', [(int) $slug])
            : DB::one('SELECT * FROM creator_profiles WHERE slug = ?', [$slug]);
        if (!$p) {
            throw HttpException::notFound('Creator');
        }
        $id = (int) $p['user_id'];

        $tracks = DB::all(
            "SELECT t.*, ? AS creator_name, ? AS creator_slug FROM tracks t
              WHERE t.creator_id = ? AND t.status = 'live' ORDER BY t.released_at DESC LIMIT 100",
            [$p['stage_name'], $p['slug'], $id]
        );
        $beats = DB::all(
            "SELECT b.*, ? AS producer_name, ? AS producer_slug FROM beats b
              WHERE b.producer_id = ? AND b.status = 'live' ORDER BY b.created_at DESC LIMIT 100",
            [$p['stage_name'], $p['slug'], $id]
        );
        $tiers = BeatController::tiersFor(array_column($beats, 'id'));

        $viewer = $req->userId();
        return [
            'creator'   => Present::creator($p),
            'followers' => (int) DB::value('SELECT COUNT(*) FROM follows WHERE creator_id = ?', [$id]),
            'following' => $viewer ? (bool) DB::value('SELECT 1 FROM follows WHERE follower_id = ? AND creator_id = ?', [$viewer, $id]) : false,
            'total_plays' => (int) DB::value("SELECT COALESCE(SUM(play_count), 0) FROM tracks WHERE creator_id = ? AND status = 'live'", [$id]),
            'tracks'    => array_map([Present::class, 'track'], $tracks),
            'beats'     => array_map(static fn ($b) => Present::beat($b, $tiers[$b['id']] ?? []), $beats),
        ];
    }

    public function follow(Request $req): array
    {
        $creatorId = $req->id();
        if ($creatorId === $req->userId()) {
            throw HttpException::invalid('You cannot follow yourself');
        }
        if (!DB::value('SELECT 1 FROM creator_profiles WHERE user_id = ?', [$creatorId])) {
            throw HttpException::notFound('Creator');
        }
        DB::run('INSERT IGNORE INTO follows (follower_id, creator_id) VALUES (?, ?)', [$req->userId(), $creatorId]);
        return $this->followState($req->userId(), $creatorId);
    }

    public function unfollow(Request $req): array
    {
        DB::run('DELETE FROM follows WHERE follower_id = ? AND creator_id = ?', [$req->userId(), $req->id()]);
        return $this->followState($req->userId(), $req->id());
    }

    private function followState(int $uid, int $creatorId): array
    {
        return [
            'following' => (bool) DB::value('SELECT 1 FROM follows WHERE follower_id = ? AND creator_id = ?', [$uid, $creatorId]),
            'followers' => (int) DB::value('SELECT COUNT(*) FROM follows WHERE creator_id = ?', [$creatorId]),
        ];
    }

    private function uniqueSlug(string $name, int $uid): string
    {
        $base = slugify($name);
        $slug = $base;
        $n = 2;
        while (DB::value('SELECT 1 FROM creator_profiles WHERE slug = ? AND user_id <> ?', [$slug, $uid])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }
}
