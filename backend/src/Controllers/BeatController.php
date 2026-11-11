<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Jobs;
use App\Services\Media;
use App\Services\Storage;
use App\Support\Present;

/** Beat market: producers upload instrumentals with licence tiers. */
final class BeatController extends Controller
{
    private const TIERS = ['mp3_lease', 'wav_lease', 'unlimited', 'exclusive'];

    private const SELECT = "SELECT b.*, cp.stage_name AS producer_name, cp.slug AS producer_slug,
                                   (SELECT MIN(lt.price) FROM license_tiers lt WHERE lt.beat_id = b.id AND lt.active = 1) AS min_price
                              FROM beats b
                              LEFT JOIN creator_profiles cp ON cp.user_id = b.producer_id";

    /** GET /api/beats?q=&genre=&mood=&key=&bpm_min=&bpm_max=&price_max=&producer=&sort=newest|popular|price_low|price_high */
    public function index(Request $req): array
    {
        $q = $req->query;
        [$page, $per, $offset] = pagination($q);
        $where = ["b.status = 'live'"];
        $params = [];

        if (!empty($q['q'])) {
            $where[] = '(b.title LIKE ? OR b.tags LIKE ? OR b.genre LIKE ? OR cp.stage_name LIKE ?)';
            $like = '%' . $q['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        foreach (['genre' => 'b.genre', 'mood' => 'b.mood', 'key' => 'b.musical_key'] as $f => $col) {
            if (!empty($q[$f])) {
                $where[] = "$col = ?";
                $params[] = $q[$f];
            }
        }
        if (!empty($q['bpm_min'])) {
            $where[] = 'b.bpm >= ?';
            $params[] = (int) $q['bpm_min'];
        }
        if (!empty($q['bpm_max'])) {
            $where[] = 'b.bpm <= ?';
            $params[] = (int) $q['bpm_max'];
        }
        if (!empty($q['price_max'])) {
            $where[] = '(SELECT MIN(lt.price) FROM license_tiers lt WHERE lt.beat_id = b.id AND lt.active = 1) <= ?';
            $params[] = (int) $q['price_max'];
        }
        if (!empty($q['producer'])) {
            $where[] = ctype_digit((string) $q['producer']) ? 'b.producer_id = ?' : 'cp.slug = ?';
            $params[] = $q['producer'];
        }
        $order = match ($q['sort'] ?? 'newest') {
            'popular'    => 'b.play_count DESC, b.id DESC',
            'price_low'  => 'min_price ASC, b.id DESC',
            'price_high' => 'min_price DESC, b.id DESC',
            default      => 'b.created_at DESC, b.id DESC',
        };

        $whereSql = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM beats b LEFT JOIN creator_profiles cp ON cp.user_id = b.producer_id WHERE $whereSql", $params);
        $rows = DB::all(self::SELECT . " WHERE $whereSql ORDER BY $order LIMIT $per OFFSET $offset", $params);
        $tiers = self::tiersFor(array_column($rows, 'id'));
        return $this->paginated(array_map(static fn ($b) => Present::beat($b, $tiers[$b['id']] ?? []), $rows), $page, $per, $total);
    }

    /** GET /api/beats/mine — my beats with sales counts. */
    public function mine(Request $req): array
    {
        $rows = DB::all(self::SELECT . " WHERE b.producer_id = ? AND b.status <> 'removed' ORDER BY b.id DESC", [$req->userId()]);
        $tiers = self::tiersFor(array_column($rows, 'id'));
        $sales = [];
        foreach (DB::all(
            "SELECT oi.beat_id, COUNT(*) AS n, SUM(oi.price - oi.commission) AS earned
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.seller_id = ? AND oi.item_type = 'license' AND o.status = 'paid' GROUP BY oi.beat_id",
            [$req->userId()]
        ) as $s) {
            $sales[$s['beat_id']] = ['licences_sold' => (int) $s['n'], 'earned' => (int) $s['earned']];
        }
        return ['data' => array_map(static fn ($b) => Present::beat($b, $tiers[$b['id']] ?? []) + ($sales[$b['id']] ?? ['licences_sold' => 0, 'earned' => 0]), $rows)];
    }

    /**
     * POST /api/beats (multipart/form-data)
     * Fields: title, bpm, key, genre, mood, tags ("afro, love"),
     *         tiers = JSON list, e.g. [{"tier":"mp3_lease","price":15000},{"tier":"exclusive","price":200000}]
     * Files:  mp3 (required), wav, stems (.zip), preview (required unless FFmpeg is on), cover
     */
    public function store(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'title' => 'required|string|max:160',
            'bpm'   => 'nullable|int|min:40|max:250',
            'key'   => 'nullable|string|max:12',
            'genre' => 'nullable|string|max:60',
            'mood'  => 'nullable|string|max:60',
            'tags'  => 'nullable|string|max:255',
            'tiers' => 'required|array|min:1|max:4',
        ]);
        $uid = $req->userId();
        if (!DB::value('SELECT 1 FROM creator_profiles WHERE user_id = ?', [$uid])) {
            throw HttpException::invalid('Create your producer profile first (PATCH /api/creators/me)');
        }
        $tiers = $this->validTiers($d['tiers']);

        $mp3 = $req->file('mp3') ?? throw HttpException::invalid('Upload the beat as MP3', ['mp3' => 'Required']);
        $wav = $req->file('wav');
        $needsWav = array_diff(array_column($tiers, 'tier'), ['mp3_lease']);
        if ($needsWav && !$wav) {
            throw HttpException::invalid('WAV, unlimited and exclusive licences deliver a WAV file. Upload one.', ['wav' => 'Required for these tiers']);
        }
        $preview = $req->file('preview');
        if (!$preview && !Media::enabled()) {
            throw HttpException::invalid('Upload a short tagged preview (30-60 s)', ['preview' => 'Required (FFmpeg is off)']);
        }

        $maxAudio = (int) config('uploads.max_audio_mb', 50);
        $paths = [
            'mp3_path'     => Storage::storePrivate($mp3, 'beats', Storage::MP3, $maxAudio, 'mp3'),
            'wav_path'     => $wav ? Storage::storePrivate($wav, 'beats', Storage::WAV, $maxAudio * 3, 'wav') : null,
            'stems_path'   => $req->file('stems') ? Storage::storePrivate($req->file('stems'), 'stems', Storage::ZIP, (int) config('uploads.max_stems_mb', 200), 'stems') : null,
            'preview_path' => $preview ? Storage::storePrivate($preview, 'previews', Storage::AUDIO, 20, 'preview') : null,
            'cover_path'   => $req->file('cover') ? Storage::storeImage($req->file('cover'), 'covers', 'cover') : null,
        ];

        $status = $paths['preview_path'] ? Media::afterProcessing() : 'processing';
        $id = DB::tx(static function () use ($uid, $d, $paths, $status, $tiers) {
            $id = DB::insert('beats', [
                'producer_id' => $uid,
                'title'       => $d['title'],
                'bpm'         => $d['bpm'] ?? null,
                'musical_key' => $d['key'] ?? null,
                'genre'       => $d['genre'] ?? null,
                'mood'        => $d['mood'] ?? null,
                'tags'        => $d['tags'] ?? null,
                'status'      => $status,
            ] + $paths);
            foreach ($tiers as $t) {
                DB::insert('license_tiers', ['beat_id' => $id, 'tier' => $t['tier'], 'price' => $t['price'], 'stream_cap' => $t['stream_cap'], 'active' => 1]);
            }
            return $id;
        });
        if ($status === 'processing') {
            Jobs::push('process_beat', ['beat_id' => $id]);
        }
        return Response::created($this->present($id) + [
            'next_step' => match ($status) {
                'live'       => 'Your beat is live.',
                'processing' => 'Making the preview, then it goes to review.',
                default      => 'Waiting for admin review.',
            },
        ]);
    }

    /** GET /api/beats/{id} */
    public function show(Request $req): array
    {
        $b = $this->find($req->id());
        if (!in_array($b['status'], ['live', 'sold_exclusive'], true)) {
            $this->ownerOrAdmin($req, (int) $b['producer_id'], 'Beat');
        }
        return $this->present((int) $b['id']);
    }

    /**
     * PATCH /api/beats/{id} (JSON): title, bpm, key, genre, mood, tags,
     * tiers: [{"tier":"wav_lease","price":30000}, {"tier":"mp3_lease","active":false}]
     */
    public function update(Request $req): array
    {
        $b = $this->find($req->id());
        $this->ownerOrAdmin($req, (int) $b['producer_id'], 'Beat');
        if (in_array($b['status'], ['sold_exclusive', 'removed'], true)) {
            throw HttpException::conflict('This beat can no longer be edited');
        }
        $d = Validator::validate($req->all(), [
            'title' => 'nullable|string|max:160',
            'bpm'   => 'nullable|int|min:40|max:250',
            'key'   => 'nullable|string|max:12',
            'genre' => 'nullable|string|max:60',
            'mood'  => 'nullable|string|max:60',
            'tags'  => 'nullable|string|max:255',
            'tiers' => 'nullable|array|max:4',
        ]);

        DB::tx(function () use ($b, $d) {
            $fields = array_filter([
                'title' => $d['title'] ?? null, 'bpm' => $d['bpm'] ?? null, 'musical_key' => $d['key'] ?? null,
                'genre' => $d['genre'] ?? null, 'mood' => $d['mood'] ?? null, 'tags' => $d['tags'] ?? null,
            ], static fn ($v) => $v !== null);
            if ($fields) {
                DB::update('beats', $fields, 'id = ?', [$b['id']]);
            }
            foreach ($d['tiers'] ?? [] as $i => $t) {
                $tier = $t['tier'] ?? '';
                if (!in_array($tier, self::TIERS, true)) {
                    throw HttpException::invalid('Unknown licence tier', ["tiers.$i.tier" => 'Use ' . implode(', ', self::TIERS)]);
                }
                if ($tier !== 'mp3_lease' && !$b['wav_path']) {
                    throw HttpException::invalid('This beat has no WAV file, so only an MP3 lease is possible', ["tiers.$i.tier" => 'Needs WAV']);
                }
                $existing = DB::one('SELECT * FROM license_tiers WHERE beat_id = ? AND tier = ?', [$b['id'], $tier]);
                $price = isset($t['price']) ? (int) $t['price'] : ($existing['price'] ?? null);
                if ($price === null || $price < 100 || $price > 50000000) {
                    throw HttpException::invalid('Each licence needs a price of at least 100 RWF', ["tiers.$i.price" => 'Invalid']);
                }
                $row = [
                    'price'      => $price,
                    'stream_cap' => array_key_exists('stream_cap', $t) ? ($t['stream_cap'] === null ? null : max(0, (int) $t['stream_cap'])) : ($existing['stream_cap'] ?? null),
                    'active'     => array_key_exists('active', $t) ? (int) (bool) $t['active'] : 1,
                ];
                if ($existing) {
                    DB::update('license_tiers', $row, 'id = ?', [$existing['id']]);
                } else {
                    DB::insert('license_tiers', $row + ['beat_id' => $b['id'], 'tier' => $tier]);
                }
            }
        });
        return $this->present((int) $b['id']);
    }

    /** DELETE /api/beats/{id} — takes it off sale (licences already sold stay valid). */
    public function destroy(Request $req): Response
    {
        $b = $this->find($req->id());
        $this->ownerOrAdmin($req, (int) $b['producer_id'], 'Beat');
        DB::update('beats', ['status' => 'removed'], 'id = ?', [$b['id']]);
        DB::update('license_tiers', ['active' => 0], 'beat_id = ?', [$b['id']]);
        return Response::noContent();
    }

    /** GET /api/beats/{id}/preview — the tagged preview. Full files are only sold. */
    public function preview(Request $req): Response
    {
        $b = $this->find($req->id());
        if ($b['status'] !== 'live' || !$b['preview_path']) {
            throw HttpException::notFound('Preview');
        }
        return Response::file(Storage::privatePath($b['preview_path']), Storage::mimeFor($b['preview_path']));
    }

    /** POST /api/beats/{id}/plays — count a preview listen (for "popular" sorting). */
    public function play(Request $req): array
    {
        $n = DB::run("UPDATE beats SET play_count = play_count + 1, updated_at = updated_at WHERE id = ? AND status = 'live'", [$req->id()])->rowCount();
        if (!$n) {
            throw HttpException::notFound('Beat');
        }
        return ['ok' => true];
    }

    /** Licence tiers for many beats at once: [beat_id => [tier rows]] */
    public static function tiersFor(array $beatIds): array
    {
        if (!$beatIds) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($beatIds), '?'));
        $out = [];
        foreach (DB::all("SELECT * FROM license_tiers WHERE beat_id IN ($marks) ORDER BY FIELD(tier,'mp3_lease','wav_lease','unlimited','exclusive')", array_values($beatIds)) as $t) {
            $out[$t['beat_id']][] = $t;
        }
        return $out;
    }

    private function validTiers(array $tiers): array
    {
        $clean = [];
        foreach (array_values($tiers) as $i => $t) {
            $tier = is_array($t) ? ($t['tier'] ?? '') : '';
            $price = is_array($t) ? (int) ($t['price'] ?? 0) : 0;
            if (!in_array($tier, self::TIERS, true)) {
                throw HttpException::invalid('Unknown licence tier', ["tiers.$i.tier" => 'Use ' . implode(', ', self::TIERS)]);
            }
            if (isset($clean[$tier])) {
                throw HttpException::invalid('Each tier can appear once', ["tiers.$i.tier" => 'Duplicate']);
            }
            if ($price < 100 || $price > 50000000) {
                throw HttpException::invalid('Each licence needs a price of at least 100 RWF', ["tiers.$i.price" => 'Invalid']);
            }
            $cap = $t['stream_cap'] ?? null;
            $clean[$tier] = ['tier' => $tier, 'price' => $price, 'stream_cap' => $cap === null ? null : max(0, (int) $cap)];
        }
        return array_values($clean);
    }

    private function find(int $id): array
    {
        return DB::one(self::SELECT . ' WHERE b.id = ?', [$id]) ?? throw HttpException::notFound('Beat');
    }

    private function present(int $id): array
    {
        $b = $this->find($id);
        return Present::beat($b, self::tiersFor([$id])[$id] ?? []);
    }
}
