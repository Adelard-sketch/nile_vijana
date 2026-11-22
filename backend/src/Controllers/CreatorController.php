<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Models\BeatModel;
use App\Models\CreatorModel;
use App\Models\TrackModel;
use App\Models\UserModel;
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
        $creators = new CreatorModel();
        $existing = $creators->findByUserId($uid);
        $d = Validator::validate($req->all(), [
            'stage_name'      => ($existing ? 'nullable' : 'required') . '|string|min:2|max:80',
            'type'            => ($existing ? 'nullable' : 'required') . '|in:artist,producer,both,choir,manager,vocal_coach',
            'bio'             => 'nullable|string|max:2000',
            'city'            => 'nullable|string|max:80',
            'payout_phone'    => 'nullable|phone',
            'payout_provider' => 'nullable|in:MTN,VOD,ATL',
            'genres'          => 'nullable|string|max:255',
            'contact_email'   => 'nullable|email|max:190',
            'contact_phone'   => 'nullable|phone',
            'website'         => 'nullable|string|max:255',
            'members_count'   => 'nullable|int|min:2|max:5000',
            'experience_years'=> 'nullable|int|min:0|max:80',
            'specialization'  => 'nullable|string|max:255',
            'company'         => 'nullable|string|max:120',
        ]);

        $row = array_filter($d, static fn ($v) => $v !== null);
        if (isset($row['genres'])) {
            $row['genres'] = \App\Services\Onboarding::cleanList($row['genres']);
        }
        if (!empty($d['payout_phone']) && empty($d['payout_provider'])) {
            $row['payout_provider'] = detect_provider($d['payout_phone']);
        }
        if (!empty($d['stage_name']) && (!$existing || $d['stage_name'] !== $existing['stage_name'])) {
            $row['slug'] = $creators->uniqueSlug($d['stage_name'], $uid);
        }

        $profile = $creators->saveProfile($uid, $row, $existing);
        return Present::creatorPrivate($profile) + [
            'roles'           => (new UserModel())->roles($uid),
        ];
    }

    /** GET /api/creators/me/manager — private manager contact for the signed-in artist. */
    public function manager(Request $req): array
    {
        return [
            'manager' => DB::one(
                'SELECT manager_name AS name, manager_email AS email, manager_phone AS phone, company
                   FROM creator_manager_contacts WHERE creator_id = ?',
                [$req->userId()]
            ),
        ];
    }

    /** PATCH /api/creators/me/manager — save or replace the artist's private manager contact. */
    public function saveManager(Request $req): array
    {
        $data = Validator::validate($req->all(), [
            'name'    => 'required|string|min:2|max:120',
            'email'   => 'nullable|email|max:190',
            'phone'   => 'nullable|phone',
            'company' => 'nullable|string|max:120',
        ]);
        if (empty($data['email']) && empty($data['phone'])) {
            throw HttpException::invalid('Add an email address or phone number for the manager.', [
                'email' => 'Add the manager\'s email address or phone number.',
                'phone' => 'Add the manager\'s phone number or email address.',
            ]);
        }

        DB::run(
            'INSERT INTO creator_manager_contacts (creator_id, manager_name, manager_email, manager_phone, company)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE manager_name = VALUES(manager_name),
                                     manager_email = VALUES(manager_email),
                                     manager_phone = VALUES(manager_phone),
                                     company = VALUES(company)',
            [$req->userId(), $data['name'], $data['email'] ?? null, $data['phone'] ?? null, $data['company'] ?? null]
        );
        return $this->manager($req);
    }

    /** DELETE /api/creators/me/manager — remove the artist's private manager contact. */
    public function removeManager(Request $req): array
    {
        DB::run('DELETE FROM creator_manager_contacts WHERE creator_id = ?', [$req->userId()]);
        return ['removed' => true];
    }

    /** POST /api/creators/me/avatar (multipart, field "avatar") */
    public function avatar(Request $req): array
    {
        $file = $req->file('avatar') ?? throw HttpException::invalid('Choose a profile picture.', ['avatar' => 'Profile picture is required.']);
        $creators = new CreatorModel();
        $profile = $creators->findByUserId($req->userId())
            ?? throw HttpException::invalid('Create your creator profile first');
        $path = Storage::storeImage($file, 'avatars', 'avatar');
        $creators->updateAvatar($req->userId(), $path);
        if ($profile['avatar_path']) {
            @unlink(BASE_PATH . '/public/uploads/' . $profile['avatar_path']);
        }
        return ['avatar_url' => upload_url($path)];
    }

    /** GET /api/creators/{slug} — public profile page with songs and beats. */
    /**
     * GET /api/creators?type=artist|choir|producer|manager|vocal_coach&q=&city=&page=
     * Public directory of creators (verified profiles, or anyone with live music or beats).
     */
    public function index(Request $req): array
    {
        $types = array_values(array_intersect(
            explode(',', (string) ($req->query['type'] ?? 'artist,choir,producer,both')),
            ['artist', 'choir', 'producer', 'both', 'manager', 'vocal_coach']
        )) ?: ['artist', 'choir', 'producer', 'both'];
        if (in_array('artist', $types, true) || in_array('producer', $types, true)) {
            $types[] = 'both';
        }
        $types = array_values(array_unique($types));
        $in = implode(',', array_fill(0, count($types), '?'));
        $where = ["cp.type IN ($in)", "u.status = 'active'",
            "(cp.verified = 1 OR EXISTS (SELECT 1 FROM tracks t WHERE t.creator_id = cp.user_id AND t.status = 'live') OR EXISTS (SELECT 1 FROM beats b WHERE b.producer_id = cp.user_id AND b.status = 'live'))"];
        $params = $types;
        $q = trim((string) ($req->query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(cp.stage_name LIKE ? OR cp.genres LIKE ? OR cp.city LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        $city = trim((string) ($req->query['city'] ?? ''));
        if ($city !== '') {
            $where[] = 'cp.city = ?';
            $params[] = $city;
        }
        [$page, $per, $offset] = pagination($req->query, 24, 60);
        $sqlWhere = implode(' AND ', $where);
        $total = (int) \App\Core\DB::value("SELECT COUNT(*) FROM creator_profiles cp JOIN users u ON u.id = cp.user_id WHERE $sqlWhere", $params);
        $rows = \App\Core\DB::all(
            "SELECT cp.*,
                    (SELECT COUNT(*) FROM tracks t WHERE t.creator_id = cp.user_id AND t.status = 'live') AS live_tracks,
                    (SELECT COUNT(*) FROM beats b WHERE b.producer_id = cp.user_id AND b.status = 'live') AS live_beats,
                    (SELECT COALESCE(SUM(t.play_count), 0) FROM tracks t WHERE t.creator_id = cp.user_id AND t.status = 'live') AS plays,
                    (SELECT COUNT(*) FROM follows f WHERE f.creator_id = cp.user_id) AS followers
               FROM creator_profiles cp JOIN users u ON u.id = cp.user_id
              WHERE $sqlWhere
              ORDER BY cp.verified DESC, plays DESC, cp.stage_name
              LIMIT $per OFFSET $offset",
            $params
        );
        return $this->paginated(array_map(static fn ($r) => Present::creator($r) + [
            'live_tracks' => (int) $r['live_tracks'],
            'live_beats'  => (int) $r['live_beats'],
            'plays'       => (int) $r['plays'],
            'followers'   => (int) $r['followers'],
        ], $rows), $page, $per, $total);
    }

    public function show(Request $req): array
    {
        $slug = $req->param('slug');
        $creators = new CreatorModel();
        $p = $creators->findPublicProfile($slug);
        if (!$p) {
            throw HttpException::notFound('Creator');
        }
        $id = (int) $p['user_id'];

        $tracks = (new TrackModel())->liveByCreator($id, $p['stage_name'], $p['slug']);
        $beats = (new BeatModel())->liveByProducer($id, $p['stage_name'], $p['slug']);
        $tiers = (new BeatModel())->tiersFor(array_column($beats, 'id'));

        $viewer = $req->userId();
        return [
            'creator'   => Present::creator($p),
            'followers' => $creators->followerCount($id),
            'following' => $viewer ? $creators->follows($viewer, $id) : false,
            'total_plays' => $creators->totalPlays($id),
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
        $creators = new CreatorModel();
        if (!$creators->exists($creatorId)) {
            throw HttpException::notFound('Creator');
        }
        $creators->follow($req->userId(), $creatorId);
        return $this->followState($req->userId(), $creatorId);
    }

    public function unfollow(Request $req): array
    {
        (new CreatorModel())->unfollow($req->userId(), $req->id());
        return $this->followState($req->userId(), $req->id());
    }

    private function followState(int $uid, int $creatorId): array
    {
        $creators = new CreatorModel();
        return [
            'following' => $creators->follows($uid, $creatorId),
            'followers' => $creators->followerCount($creatorId),
        ];
    }
}
