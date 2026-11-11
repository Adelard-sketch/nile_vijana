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
use App\Services\Storage;
use App\Support\Present;

/** Songs: upload, free streaming, plays, likes. */
final class TrackController extends Controller
{
    private const SELECT = "SELECT t.*, cp.stage_name AS creator_name, cp.slug AS creator_slug,
                                   (SELECT COUNT(*) FROM likes l WHERE l.track_id = t.id) AS like_count
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
     * Fields: title, genre, mood, language, price (RWF, 0 = stream only), album_id
     * Files:  audio (required), cover (optional image)
     */
    public function store(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'title'    => 'required|string|max:160',
            'genre'    => 'nullable|string|max:60',
            'mood'     => 'nullable|string|max:60',
            'language' => 'nullable|string|max:30',
            'price'    => 'nullable|int|min:0|max:100000',
            'album_id' => 'nullable|int',
        ]);
        $uid = $req->userId();
        if (!empty($d['album_id']) && !DB::value('SELECT 1 FROM albums WHERE id = ? AND creator_id = ?', [$d['album_id'], $uid])) {
            throw HttpException::invalid('Album not found', ['album_id' => 'Not your album']);
        }
        if (!DB::value('SELECT 1 FROM creator_profiles WHERE user_id = ?', [$uid])) {
            throw HttpException::invalid('Create your artist profile first (PATCH /api/creators/me)');
        }
        $audio = $req->file('audio') ?? throw HttpException::invalid('Choose an audio file', ['audio' => 'Required']);

        $original = Storage::storePrivate($audio, 'tracks', Storage::AUDIO, (int) config('uploads.max_audio_mb', 50), 'audio');
        $cover = $req->file('cover') ? Storage::storeImage($req->file('cover'), 'covers', 'cover') : null;

        $ffmpeg = Media::enabled();
        $status = $ffmpeg ? 'processing' : Media::afterProcessing();
        $id = DB::insert('tracks', [
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
        ]);
        if ($ffmpeg) {
            Jobs::push('process_track', ['track_id' => $id]);
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
        return Present::track($this->markLiked([$t], $req->userId())[0]);
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
            'price'    => 'nullable|int|min:0|max:100000',
            'album_id' => 'nullable|int',
        ]);
        if (!empty($d['album_id']) && !DB::value('SELECT 1 FROM albums WHERE id = ? AND creator_id = ?', [$d['album_id'], $t['creator_id']])) {
            throw HttpException::invalid('Album not found', ['album_id' => 'Not your album']);
        }
        $changes = array_filter($d, static fn ($v, $k) => $v !== null || $k === 'album_id', ARRAY_FILTER_USE_BOTH);
        if ($changes) {
            DB::update('tracks', $changes, 'id = ?', [$t['id']]);
        }
        return Present::track($this->find((int) $t['id']));
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
     * POST /api/tracks/{id}/plays {"seconds": 42, "session_id": "...", "city": "Kigali", "device": "android"}
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
        foreach ($rows as &$r) {
            $r['liked'] = isset($liked[$r['id']]);
        }
        return $rows;
    }
}
