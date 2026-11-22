<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Jobs;
use App\Services\Media;
use App\Services\Moderation;
use App\Services\Storage;
use App\Support\Present;

/** Songs: upload, free streaming, plays, likes. */
final class TrackController extends Controller
{
    private const SELECT = "SELECT t.*, cp.stage_name AS creator_name, cp.slug AS creator_slug,
                                   (SELECT COUNT(*) FROM likes l WHERE l.track_id = t.id) AS like_count,
                                   (SELECT COUNT(*) FROM saved_tracks s WHERE s.track_id = t.id) AS save_count
                              FROM tracks t
                              LEFT JOIN creator_profiles cp ON cp.user_id = t.creator_id";

    /** GET /api/tracks?q=&genre=&mood=&language=&creator=&sort=newest|popular|title&page= */
    public function index(Request $req): array
    {
        $q = $req->query;
        [$page, $per, $offset] = pagination($q);
        $where = ["t.status = 'live'"];
        $params = [];

        if (!empty($q['q'])) {
            $where[] = '(t.title LIKE ? OR cp.stage_name LIKE ? OR t.genre LIKE ?)';
            $like = '%' . $q['q'] . '%';
            array_push($params, $like, $like, $like);
        }
        foreach (['genre', 'mood', 'language'] as $f) {
            if (!empty($q[$f])) {
                $where[] = "t.$f = ?";
                $params[] = $q[$f];
            }
        }
        if (!empty($q['creator'])) {
            $where[] = ctype_digit((string) $q['creator']) ? 't.creator_id = ?' : 'cp.slug = ?';
            $params[] = $q['creator'];
        }
        $order = match ($q['sort'] ?? 'newest') {
            'popular' => 't.play_count DESC, t.id DESC',
            'title'   => 't.title ASC',
            default   => 't.released_at DESC, t.id DESC',
        };

        $whereSql = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM tracks t LEFT JOIN creator_profiles cp ON cp.user_id = t.creator_id WHERE $whereSql", $params);
        $rows = DB::all(self::SELECT . " WHERE $whereSql ORDER BY $order LIMIT $per OFFSET $offset", $params);
        $rows = $this->markLiked($rows, $req->userId());
        return $this->paginated(array_map([Present::class, 'track'], $rows), $page, $per, $total);
    }

    /** GET /api/tracks/mine — my uploads in every status. */
    public function mine(Request $req): array
    {
        $rows = DB::all(self::SELECT . " WHERE t.creator_id = ? AND t.status <> 'removed' ORDER BY t.id DESC", [$req->userId()]);
        return ['data' => array_map([Present::class, 'track'], $rows)];
    }

    /**
     * POST /api/tracks (multipart/form-data)
     * Fields: title, genre, mood, language, price (GHS, 0 = stream only), album_id
     * Files:  audio (required), cover (optional image)
     */
    public function store(Request $req): Response
    {
        // Every problem is collected first, so the form can list them all at once:
        // "Track title is missing. Audio file is required. Genre has not been selected..."
        [$d, $errors] = Validator::check($req->all(), [
            'title'    => 'required|string|max:160',
            'genre'    => 'required|string|max:60',
            'mood'     => 'nullable|string|max:60',
            'language' => 'nullable|string|max:30',
            'price'    => 'nullable|int|min:0|max:' . (int) config('money.max_track_price', 500),
            'album_id' => 'nullable|int',
            'creator_id' => 'nullable|int|min:1',
            'description'  => 'nullable|string|max:3000',
            'release_date' => 'nullable|date',
            'publish'      => 'nullable|in:live,in_review',   // admins only; creators always go to review
            'allow_download' => 'nullable|bool',              // free download for signed-in listeners
        ], ['title' => 'Track title', 'price' => 'Download price (GH₵)', 'creator_id' => 'Artist or choir', 'album_id' => 'Album']);
        $errors = self::missing($errors, ['title' => 'Track title is missing.', 'genre' => 'Genre has not been selected.']);
        $uid = $req->userId();
        if (Auth::isAdmin($req->user)) {
            $uid = (int) ($d['creator_id'] ?? 0);
            if ($uid < 1 || !DB::value(
                "SELECT 1 FROM creator_profiles WHERE user_id = ? AND type IN ('artist', 'both', 'choir')",
                [$uid]
            )) {
                $errors['creator_id'] = 'Choose the artist or choir this song belongs to.';
            }
        } elseif (!DB::value("SELECT 1 FROM creator_profiles WHERE user_id = ? AND type IN ('artist', 'both', 'choir')", [$uid])) {
            throw HttpException::invalid('Create your artist or choir profile first (Profile and settings).');
        }
        if (!empty($d['album_id']) && !DB::value('SELECT 1 FROM albums WHERE id = ? AND creator_id = ?', [$d['album_id'], $uid])) {
            $errors['album_id'] = 'That album was not found among your albums.';
        }
        $audio = $req->file('audio');
        if ($problem = Storage::problem($audio, Storage::AUDIO, (int) config('uploads.max_audio_mb', 50), 'audio')) {
            $errors['audio'] = $problem;
        }
        if ($problem = Storage::problem($req->file('cover'), Storage::IMAGE, (int) config('uploads.max_image_mb', 5), 'cover')) {
            $errors['cover'] = $req->file('cover') ? $problem : 'Cover artwork is missing.';
        }
        if ($errors) {
            throw HttpException::invalid('Your track could not be uploaded. Please complete the following:', $errors);
        }

        $original = Storage::storePrivate($audio, 'tracks', Storage::AUDIO, (int) config('uploads.max_audio_mb', 50), 'audio');
        $cover = Storage::storeImage($req->file('cover'), 'covers', 'cover');

        $ffmpeg = Media::enabled();
        [$status, $publish, $review] = Moderation::submission($req->user, $d['publish'] ?? null, $ffmpeg);
        $id = DB::insert('tracks', $review + [
            'creator_id'    => $uid,
            'album_id'      => $d['album_id'] ?? null,
            'title'         => $d['title'],
            'genre'         => $d['genre'] ?? null,
            'mood'          => $d['mood'] ?? null,
            'language'      => $d['language'] ?? null,
            'price'         => $d['price'] ?? 0,
            'original_path' => $original,
            'stream_path'   => $ffmpeg ? null : $original,   // without FFmpeg we stream the original
            'cover_path'    => $cover,
            'status'        => $status,
            'released_at'   => $status === 'live' ? now() : null,
            'description'   => $d['description'] ?? null,
            'release_date'  => $d['release_date'] ?? null,
            'allow_download'=> !empty($d['allow_download']) ? 1 : 0,
        ]);
        if ($ffmpeg) {
            Jobs::push('process_track', ['track_id' => $id, 'publish' => $publish]);
        }
        return Response::created(Present::track($this->find($id)) + [
            'next_step' => $status === 'live' ? 'Your song is live.' : ($ffmpeg ? 'Processing audio, then it goes to review.' : 'Waiting for admin review.'),
        ]);
    }

    /** GET /api/tracks/{id} */
    public function show(Request $req): array
    {
        $t = $this->find($req->id());
        if ($t['status'] !== 'live') {
            $this->ownerOrAdmin($req, (int) $t['creator_id'], 'Track');
        }
        $out = Present::track($this->markLiked([$t], $req->userId())[0]);
        $uid = $req->userId();
        $out['owned'] = $uid ? $this->owns($uid, (int) $t['id']) : false;
        $out['can_download'] = $uid && ($out['owned'] || (bool) $t['allow_download'] || $uid === (int) $t['creator_id'] || Auth::isAdmin($req->user));
        if ($req->userId() === (int) $t['creator_id'] || Auth::isAdmin($req->user)) {
            $out['listen_url'] = FileController::listenUrl('track', (int) $t['id']);
        }
        return $out;
    }

    /** PATCH /api/tracks/{id} (JSON) */
    public function update(Request $req): array
    {
        $t = $this->find($req->id());
        $this->ownerOrAdmin($req, (int) $t['creator_id'], 'Track');
        if ($t['status'] === 'removed') {
            throw HttpException::conflict('This song was removed');
        }
        $d = Validator::validate($req->all(), [
            'title'    => 'nullable|string|max:160',
            'genre'    => 'nullable|string|max:60',
            'mood'     => 'nullable|string|max:60',
            'language' => 'nullable|string|max:30',
            'price'    => 'nullable|int|min:0|max:' . (int) config('money.max_track_price', 500),
            'album_id' => 'nullable|int',
            'description'  => 'nullable|string|max:3000',
            'release_date' => 'nullable|date',
            'allow_download' => 'nullable|bool',
        ]);
        if (isset($d['allow_download'])) {
            $d['allow_download'] = $d['allow_download'] ? 1 : 0;
        }
        if (!empty($d['album_id']) && !DB::value('SELECT 1 FROM albums WHERE id = ? AND creator_id = ?', [$d['album_id'], $t['creator_id']])) {
            throw HttpException::invalid('Album not found', ['album_id' => 'Not your album']);
        }
        $nullable = ['album_id', 'description', 'release_date'];
        $changes = array_filter($d, static fn ($v, $k) => $v !== null || in_array($k, $nullable, true), ARRAY_FILTER_USE_BOTH);
        if ($changes) {
            DB::update('tracks', $changes, 'id = ?', [$t['id']]);
        }
        return Present::track($this->find((int) $t['id']));
    }

    /**
     * POST /api/tracks/{id}/resubmit (multipart) — fix a rejected (or pending) song and send it
     * for review again. Any field may change; files are optional: audio (replaces the song), cover.
     */
    public function resubmit(Request $req): array
    {
        $t = $this->find($req->id());
        $this->ownerOrAdmin($req, (int) $t['creator_id'], 'Track');
        if (!in_array($t['status'], ['rejected', 'in_review'], true)) {
            throw HttpException::conflict('Only songs that are waiting for review or were rejected can be resubmitted');
        }
        $d = Validator::validate($req->all(), [
            'title'        => 'nullable|string|max:160',
            'genre'        => 'nullable|string|max:60',
            'mood'         => 'nullable|string|max:60',
            'language'     => 'nullable|string|max:30',
            'price'        => 'nullable|int|min:0|max:' . (int) config('money.max_track_price', 500),
            'description'  => 'nullable|string|max:3000',
            'release_date' => 'nullable|date',
            'allow_download' => 'nullable|bool',
        ]);
        if (isset($d['allow_download'])) {
            $d['allow_download'] = $d['allow_download'] ? 1 : 0;
        }
        $changes = array_filter($d, static fn ($v) => $v !== null);
        if ($audio = $req->file('audio')) {
            $changes['original_path'] = Storage::storePrivate($audio, 'tracks', Storage::AUDIO, (int) config('uploads.max_audio_mb', 50), 'audio');
            $changes['stream_path'] = Media::enabled() ? null : $changes['original_path'];
            $changes['preview_path'] = null;
            $changes['duration_sec'] = null;
        }
        if ($cover = $req->file('cover')) {
            $changes['cover_path'] = Storage::storeImage($cover, 'covers', 'cover');
        }
        $needsProcessing = isset($changes['original_path']) && Media::enabled();
        [$status, $publish, $review] = Moderation::submission($req->user, null, $needsProcessing);
        DB::update('tracks', $changes + $review + ['status' => $status], 'id = ?', [$t['id']]);
        if ($needsProcessing) {
            Jobs::push('process_track', ['track_id' => (int) $t['id'], 'publish' => $publish]);
        }
        return Present::track($this->find((int) $t['id'])) + ['next_step' => 'Sent for review again.'];
    }

    /** DELETE /api/tracks/{id} — hides the song (buyers keep their downloads). */
    public function destroy(Request $req): Response
    {
        $t = $this->find($req->id());
        $this->ownerOrAdmin($req, (int) $t['creator_id'], 'Track');
        DB::update('tracks', ['status' => 'removed'], 'id = ?', [$t['id']]);
        return Response::noContent();
    }

    /** POST /api/tracks/{id}/cover (multipart, field "cover") */
    public function cover(Request $req): array
    {
        $t = $this->find($req->id());
        $this->ownerOrAdmin($req, (int) $t['creator_id'], 'Track');
        $file = $req->file('cover') ?? throw HttpException::invalid('Choose an image', ['cover' => 'Required']);
        $path = Storage::storeImage($file, 'covers', 'cover');
        DB::update('tracks', ['cover_path' => $path], 'id = ?', [$t['id']]);
        return ['cover_url' => upload_url($path)];
    }

    /** GET /api/tracks/{id}/stream — free listening (low bitrate when FFmpeg is on). */
    public function stream(Request $req): Response
    {
        $t = $this->find($req->id());
        if ($t['status'] !== 'live') {
            throw HttpException::notFound('Track');
        }
        $path = $t['stream_path'] ?: $t['original_path'];
        return Response::file(Storage::privatePath($path), Storage::mimeFor($path));
    }

    /** GET /api/tracks/{id}/preview — short clip. */
    public function preview(Request $req): Response
    {
        $t = $this->find($req->id());
        if ($t['status'] !== 'live' || !$t['preview_path']) {
            throw HttpException::notFound('Preview');
        }
        return Response::file(Storage::privatePath($t['preview_path']), Storage::mimeFor($t['preview_path']));
    }

    /**
     * POST /api/tracks/{id}/plays {"seconds": 42, "session_id": "...", "city": "Accra", "device": "android"}
     * The player calls this when the listener stops or passes 30 seconds.
     * A play counts once per listener session per 30 minutes.
     */
    public function play(Request $req): array
    {
        $t = $this->find($req->id());
        if ($t['status'] !== 'live') {
            throw HttpException::notFound('Track');
        }
        $d = Validator::validate($req->all(), [
            'seconds'    => 'required|int|min:0|max:36000',
            'session_id' => 'nullable|string|max:64',
            'city'       => 'nullable|string|max:80',
            'device'     => 'nullable|string|max:40',
        ]);
        $session = $d['session_id'] ?? substr(hash('sha256', $req->ip() . ($req->header('User-Agent') ?? '')), 0, 32);
        $threshold = (int) config('money.play_threshold_seconds', 30);
        if ($t['duration_sec'] && $t['duration_sec'] < $threshold) {
            $threshold = (int) $t['duration_sec'];               // very short songs
        }

        $counted = $d['seconds'] >= $threshold && !DB::value(
            'SELECT 1 FROM play_events WHERE track_id = ? AND session_id = ? AND counted = 1 AND created_at > ? LIMIT 1',
            [$t['id'], $session, now(-1800)]
        );
        DB::insert('play_events', [
            'track_id'       => $t['id'],
            'listener_id'    => $req->userId() ?: null,
            'session_id'     => $session,
            'seconds_played' => $d['seconds'],
            'counted'        => $counted ? 1 : 0,
            'city'           => $d['city'] ?? null,
            'device'         => $d['device'] ?? null,
        ]);
        if ($counted) {
            DB::run('UPDATE tracks SET play_count = play_count + 1, updated_at = updated_at WHERE id = ?', [$t['id']]);
        }
        return ['counted' => $counted, 'plays' => (int) $t['play_count'] + ($counted ? 1 : 0)];
    }

    /** POST /api/tracks/{id}/save — keep the song in my library. */
    public function save(Request $req): array
    {
        $t = $this->find($req->id());
        if ($t['status'] !== 'live') {
            throw HttpException::notFound('Track');
        }
        DB::run('INSERT IGNORE INTO saved_tracks (user_id, track_id) VALUES (?, ?)', [$req->userId(), $t['id']]);
        return ['saved' => true, 'saves' => (int) DB::value('SELECT COUNT(*) FROM saved_tracks WHERE track_id = ?', [$t['id']])];
    }

    /** DELETE /api/tracks/{id}/save */
    public function unsave(Request $req): array
    {
        DB::run('DELETE FROM saved_tracks WHERE user_id = ? AND track_id = ?', [$req->userId(), $req->id()]);
        return ['saved' => false, 'saves' => (int) DB::value('SELECT COUNT(*) FROM saved_tracks WHERE track_id = ?', [$req->id()])];
    }

    /**
     * POST /api/tracks/{id}/share {"channel": "whatsapp|x|facebook|link|native", "session_id": "..."}
     * Counts a share (the same listener sharing the same song again within 10 minutes counts once).
     */
    public function share(Request $req): array
    {
        $t = $this->find($req->id());
        if ($t['status'] !== 'live') {
            throw HttpException::notFound('Track');
        }
        $d = Validator::validate($req->all(), [
            'channel'    => 'nullable|in:whatsapp,x,facebook,telegram,link,native',
            'session_id' => 'nullable|string|max:64',
        ]);
        $session = $d['session_id'] ?? substr(hash('sha256', $req->ip() . ($req->header('User-Agent') ?? '')), 0, 32);
        $recent = DB::value(
            'SELECT 1 FROM track_shares WHERE track_id = ? AND session_id = ? AND created_at > ? LIMIT 1',
            [$t['id'], $session, now(-600)]
        );
        if (!$recent) {
            DB::insert('track_shares', [
                'track_id'   => $t['id'],
                'user_id'    => $req->userId() ?: null,
                'session_id' => $session,
                'channel'    => $d['channel'] ?? 'link',
            ]);
        }
        return ['counted' => !$recent, 'shares' => (int) DB::value('SELECT COUNT(*) FROM track_shares WHERE track_id = ?', [$t['id']])];
    }

    /**
     * GET /api/tracks/{id}/download — a short-lived link to the full-quality file.
     * Allowed for buyers of the song, for anyone signed in when the artist allows free downloads,
     * and for the artist and admins. Every listener download is recorded.
     */
    public function download(Request $req): array
    {
        $t = $this->find($req->id());
        $uid = $req->userId();
        $staff = $uid === (int) $t['creator_id'] || Auth::isAdmin($req->user);
        if ($t['status'] !== 'live' && !$staff) {
            throw HttpException::notFound('Track');
        }
        $owned = $this->owns($uid, (int) $t['id']);
        if (!$staff && !$owned && !$t['allow_download']) {
            throw new HttpException(402, (int) $t['price'] > 0
                ? 'Buy this song to download it.'
                : 'The artist has not made this song available for download.');
        }
        if (!$staff) {
            DB::insert('track_downloads', ['track_id' => $t['id'], 'user_id' => $uid, 'source' => $owned ? 'purchase' : 'free']);
        }
        return [
            'url'      => FileController::downloadUrl((int) $t['id']),
            'filename' => slugify($t['title']) . '.' . pathinfo((string) $t['original_path'], PATHINFO_EXTENSION),
            'expires_in' => 600,
        ];
    }

    /** Has this user bought the song? */
    private function owns(int $uid, int $trackId): bool
    {
        return $uid > 0 && (bool) DB::value(
            "SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE o.buyer_id = ? AND o.status = 'paid' AND oi.item_type = 'track' AND oi.track_id = ? LIMIT 1",
            [$uid, $trackId]
        );
    }

    public function like(Request $req): array
    {
        $t = $this->find($req->id());
        if ($t['status'] !== 'live') {
            throw HttpException::notFound('Track');
        }
        DB::run('INSERT IGNORE INTO likes (user_id, track_id) VALUES (?, ?)', [$req->userId(), $t['id']]);
        return ['liked' => true, 'likes' => (int) DB::value('SELECT COUNT(*) FROM likes WHERE track_id = ?', [$t['id']])];
    }

    public function unlike(Request $req): array
    {
        DB::run('DELETE FROM likes WHERE user_id = ? AND track_id = ?', [$req->userId(), $req->id()]);
        return ['liked' => false, 'likes' => (int) DB::value('SELECT COUNT(*) FROM likes WHERE track_id = ?', [$req->id()])];
    }

    private function find(int $id): array
    {
        return DB::one(self::SELECT . ' WHERE t.id = ?', [$id]) ?? throw HttpException::notFound('Track');
    }

    private function markLiked(array $rows, int $userId): array
    {
        if (!$userId || !$rows) {
            return $rows;
        }
        $ids = array_column($rows, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $liked = array_flip(array_column(DB::all("SELECT track_id FROM likes WHERE user_id = ? AND track_id IN ($marks)", [$userId, ...$ids]), 'track_id'));
        $saved = array_flip(array_column(DB::all("SELECT track_id FROM saved_tracks WHERE user_id = ? AND track_id IN ($marks)", [$userId, ...$ids]), 'track_id'));
        foreach ($rows as &$r) {
            $r['liked'] = isset($liked[$r['id']]);
            $r['saved'] = isset($saved[$r['id']]);
        }
        return $rows;
    }
}
