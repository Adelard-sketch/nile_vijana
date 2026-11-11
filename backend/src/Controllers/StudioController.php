<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\BookingService;
use App\Services\Storage;
use App\Support\Present;

/** Studio listings, rooms, opening hours, blocked days and availability. */
final class StudioController extends Controller
{
    private const DEFAULT_HOURS = [
        1 => ['09:00', '21:00'], 2 => ['09:00', '21:00'], 3 => ['09:00', '21:00'],
        4 => ['09:00', '21:00'], 5 => ['09:00', '21:00'], 6 => ['09:00', '21:00'],
    ];

    /** GET /api/studios?city=&q=&page= — verified studios only. */
    public function index(Request $req): array
    {
        $q = $req->query;
        [$page, $per, $offset] = pagination($q);
        $where = ["s.status = 'verified'"];
        $params = [];
        if (!empty($q['city'])) {
            $where[] = 's.city = ?';
            $params[] = $q['city'];
        }
        if (!empty($q['q'])) {
            $where[] = '(s.name LIKE ? OR s.address LIKE ? OR s.equipment LIKE ?)';
            $like = '%' . $q['q'] . '%';
            array_push($params, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM studios s WHERE $whereSql", $params);
        $rows = DB::all(
            "SELECT s.*,
                    (SELECT MIN(r.hourly_rate) FROM studio_rooms r WHERE r.studio_id = s.id AND r.status = 'active') AS price_from,
                    (SELECT AVG(rv.rating) FROM reviews rv WHERE rv.target_type = 'studio' AND rv.target_id = s.id) AS avg_rating,
                    (SELECT COUNT(*) FROM reviews rv WHERE rv.target_type = 'studio' AND rv.target_id = s.id) AS review_count
               FROM studios s WHERE $whereSql ORDER BY s.name LIMIT $per OFFSET $offset",
            $params
        );
        return $this->paginated(array_map(static fn ($s) => Present::studio($s), $rows), $page, $per, $total);
    }

    /** GET /api/studios/mine — studios I own, in any status. */
    public function mine(Request $req): array
    {
        $rows = DB::all('SELECT * FROM studios WHERE owner_id = ? ORDER BY id', [$req->userId()]);
        return ['data' => array_map(fn ($s) => Present::studio($s, $this->rooms((int) $s['id'], true)), $rows)];
    }

    /** POST /api/studios — list a studio (an admin verifies it before it is public). */
    public function store(Request $req): Response
    {
        $d = Validator::validate($req->all(), $this->studioRules(true));
        $uid = $req->userId();
        $id = DB::tx(function () use ($d, $uid) {
            $id = DB::insert('studios', array_filter($this->studioRow($d), static fn ($v) => $v !== null) + [
                'owner_id' => $uid,
                'slug'     => $this->uniqueSlug($d['name']),
                'status'   => config('moderation.auto_approve') ? 'verified' : 'pending',
            ]);
            Auth::addRole($uid, 'studio_owner');
            return $id;
        });
        return Response::created($this->present($id, true) + [
            'next_step' => 'Add rooms with POST /api/studios/' . $id . '/rooms. An admin will verify your studio.',
        ]);
    }

    /** GET /api/studios/{id} — details, rooms with opening hours, rating, latest reviews. */
    public function show(Request $req): array
    {
        $s = $this->studio($req->id());
        $isOwner = $req->userId() === (int) $s['owner_id'] || Auth::isAdmin($req->user);
        if ($s['status'] !== 'verified' && !$isOwner) {
            throw HttpException::notFound('Studio');
        }
        $out = $this->present((int) $s['id'], $isOwner);
        $out['reviews'] = DB::all(
            "SELECT rv.rating, rv.comment, rv.created_at, u.name FROM reviews rv JOIN users u ON u.id = rv.user_id
              WHERE rv.target_type = 'studio' AND rv.target_id = ? ORDER BY rv.id DESC LIMIT 5",
            [$s['id']]
        );
        return $out;
    }

    /** PATCH /api/studios/{id} */
    public function update(Request $req): array
    {
        $s = $this->studio($req->id());
        $this->ownerOrAdmin($req, (int) $s['owner_id'], 'Studio');
        $d = Validator::validate($req->all(), $this->studioRules(false));
        $row = array_filter($this->studioRow($d), static fn ($v) => $v !== null);
        if ($row) {
            DB::update('studios', $row, 'id = ?', [$s['id']]);
        }
        return $this->present((int) $s['id'], true);
    }

    /** POST /api/studios/{id}/cover (multipart, field "cover") */
    public function cover(Request $req): array
    {
        $s = $this->studio($req->id());
        $this->ownerOrAdmin($req, (int) $s['owner_id'], 'Studio');
        $file = $req->file('cover') ?? throw HttpException::invalid('Choose an image', ['cover' => 'Required']);
        $path = Storage::storeImage($file, 'studios', 'cover');
        DB::update('studios', ['cover_path' => $path], 'id = ?', [$s['id']]);
        return ['cover_url' => upload_url($path)];
    }

    /**
     * POST /api/studios/{id}/rooms
     * {"name":"Vocal booth","hourly_rate":15000,"min_hours":2,"engineer_included":true,
     *  "hours":[{"weekday":1,"open":"09:00","close":"21:00"}, ...]}   (hours optional: Mon-Sat 09-21)
     */
    public function addRoom(Request $req): Response
    {
        $s = $this->studio($req->id());
        $this->ownerOrAdmin($req, (int) $s['owner_id'], 'Studio');
        $d = Validator::validate($req->all(), $this->roomRules(true) + ['hours' => 'nullable|array|max:7']);
        $hours = isset($d['hours']) ? $this->validHours($d['hours']) : self::DEFAULT_HOURS;
        $photo = $req->file('photo') ? Storage::storeImage($req->file('photo'), 'studios', 'photo') : null;

        $roomId = DB::tx(function () use ($s, $d, $hours, $photo) {
            $row = array_filter($this->roomRow($d), static fn ($v) => $v !== null);
            $roomId = DB::insert('studio_rooms', $row + ['studio_id' => $s['id'], 'photo_path' => $photo]);
            $this->saveHours($roomId, $hours);
            return $roomId;
        });
        return Response::created($this->roomWithHours($roomId));
    }

    /** PATCH /api/rooms/{id} — name, rate, minimum hours, engineer, status (active/inactive) */
    public function updateRoom(Request $req): array
    {
        $room = $this->roomForOwner($req);
        $d = Validator::validate($req->all(), $this->roomRules(false) + ['status' => 'nullable|in:active,inactive']);
        $row = array_filter($this->roomRow($d) + ['status' => $d['status'] ?? null], static fn ($v) => $v !== null);
        if ($row) {
            DB::update('studio_rooms', $row, 'id = ?', [$room['id']]);
        }
        return $this->roomWithHours((int) $room['id']);
    }

    /** PUT /api/rooms/{id}/hours {"hours":[{"weekday":1,"open":"09:00","close":"21:00"}]} replaces the week. */
    public function setHours(Request $req): array
    {
        $room = $this->roomForOwner($req);
        $d = Validator::validate($req->all(), ['hours' => 'required|array|max:7']);
        $hours = $this->validHours($d['hours']);
        DB::tx(fn () => $this->saveHours((int) $room['id'], $hours));
        return $this->roomWithHours((int) $room['id']);
    }

    /** POST /api/rooms/{id}/blocked-dates {"date":"2026-12-25","reason":"Christmas"} */
    public function blockDate(Request $req): Response
    {
        $room = $this->roomForOwner($req);
        $d = Validator::validate($req->all(), ['date' => 'required|date', 'reason' => 'nullable|string|max:255']);
        if ($d['date'] < date('Y-m-d')) {
            throw HttpException::invalid('Choose today or a future date', ['date' => 'In the past']);
        }
        $booked = (int) DB::value(
            "SELECT COUNT(*) FROM bookings WHERE room_id = ? AND DATE(start_at) = ? AND status IN ('held','confirmed')",
            [$room['id'], $d['date']]
        );
        if ($booked) {
            throw HttpException::conflict("There are $booked bookings on that day. Cancel them first.");
        }
        DB::run('INSERT IGNORE INTO room_blocked_dates (room_id, blocked_date, reason) VALUES (?, ?, ?)', [$room['id'], $d['date'], $d['reason'] ?? null]);
        return Response::created($this->roomWithHours((int) $room['id']));
    }

    /** DELETE /api/rooms/{id}/blocked-dates/{blockedId} */
    public function unblockDate(Request $req): Response
    {
        $room = $this->roomForOwner($req);
        DB::run('DELETE FROM room_blocked_dates WHERE id = ? AND room_id = ?', [$req->id('blockedId'), $room['id']]);
        return Response::noContent();
    }

    /** GET /api/rooms/{id}/availability?date=2026-11-05 — hourly slots with free/busy. */
    public function availability(Request $req): array
    {
        $d = Validator::validate($req->query, ['date' => 'required|date']);
        $room = DB::one(
            "SELECT r.* FROM studio_rooms r JOIN studios s ON s.id = r.studio_id
              WHERE r.id = ? AND r.status = 'active' AND s.status = 'verified'",
            [$req->id()]
        ) ?? throw HttpException::notFound('Room');
        if ($d['date'] < date('Y-m-d') || $d['date'] > date('Y-m-d', strtotime('+90 days'))) {
            throw HttpException::invalid('Choose a date within the next 90 days', ['date' => 'Out of range']);
        }
        return BookingService::availability($room, $d['date']) + ['room_id' => (int) $room['id']];
    }

    // ------------------------------------------------------------------

    private function studioRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'nullable';
        return [
            'name'         => "$req|string|min:2|max:120",
            'city'         => "$req|string|max:80",
            'description'  => 'nullable|string|max:3000',
            'address'      => 'nullable|string|max:255',
            'latitude'     => 'nullable|string|max:20',
            'longitude'    => 'nullable|string|max:20',
            'phone'        => 'nullable|phone',
            'equipment'    => 'nullable|string|max:3000',
            'amenities'    => 'nullable|string|max:1000',
            'backup_power' => 'nullable|bool',
        ];
    }

    private function studioRow(array $d): array
    {
        foreach (['latitude', 'longitude'] as $c) {
            if (isset($d[$c]) && !is_numeric($d[$c])) {
                throw HttpException::invalid('Invalid map location', [$c => 'Must be a number']);
            }
        }
        return [
            'name'         => $d['name'] ?? null,
            'city'         => $d['city'] ?? null,
            'description'  => $d['description'] ?? null,
            'address'      => $d['address'] ?? null,
            'latitude'     => $d['latitude'] ?? null,
            'longitude'    => $d['longitude'] ?? null,
            'phone'        => $d['phone'] ?? null,
            'equipment'    => $d['equipment'] ?? null,
            'amenities'    => $d['amenities'] ?? null,
            'backup_power' => isset($d['backup_power']) ? (int) $d['backup_power'] : null,
        ];
    }

    private function roomRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'nullable';
        return [
            'name'              => "$req|string|min:2|max:120",
            'description'       => 'nullable|string|max:2000',
            'hourly_rate'       => "$req|int|min:500|max:2000000",
            'min_hours'         => 'nullable|int|min:1|max:12',
            'engineer_included' => 'nullable|bool',
        ];
    }

    private function roomRow(array $d): array
    {
        return [
            'name'              => $d['name'] ?? null,
            'description'       => $d['description'] ?? null,
            'hourly_rate'       => $d['hourly_rate'] ?? null,
            'min_hours'         => $d['min_hours'] ?? null,
            'engineer_included' => isset($d['engineer_included']) ? (int) $d['engineer_included'] : null,
        ];
    }

    /** @return array<int, array{0:string,1:string}> weekday => [open, close] */
    private function validHours(array $hours): array
    {
        $out = [];
        foreach (array_values($hours) as $i => $h) {
            $day = (int) ($h['weekday'] ?? 0);
            $open = substr((string) ($h['open'] ?? ''), 0, 5);
            $close = substr((string) ($h['close'] ?? ''), 0, 5);
            $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
            if ($day < 1 || $day > 7 || !preg_match($time, $open) || !preg_match($time, $close) || $open >= $close) {
                throw HttpException::invalid('Invalid opening hours', ["hours.$i" => 'Use weekday 1-7 (Mon-Sun) with open < close, e.g. 09:00-21:00']);
            }
            $out[$day] = [$open, $close];
        }
        return $out;
    }

    private function saveHours(int $roomId, array $hours): void
    {
        DB::run('DELETE FROM room_hours WHERE room_id = ?', [$roomId]);
        foreach ($hours as $day => [$open, $close]) {
            DB::insert('room_hours', ['room_id' => $roomId, 'weekday' => $day, 'open_time' => $open, 'close_time' => $close]);
        }
    }

    private function studio(int $id): array
    {
        return DB::one('SELECT * FROM studios WHERE id = ?', [$id]) ?? throw HttpException::notFound('Studio');
    }

    private function roomForOwner(Request $req): array
    {
        $room = DB::one('SELECT r.*, s.owner_id FROM studio_rooms r JOIN studios s ON s.id = r.studio_id WHERE r.id = ?', [$req->id()])
            ?? throw HttpException::notFound('Room');
        $this->ownerOrAdmin($req, (int) $room['owner_id'], 'Room');
        return $room;
    }

    private function rooms(int $studioId, bool $includeInactive): array
    {
        $rows = DB::all(
            'SELECT * FROM studio_rooms WHERE studio_id = ?' . ($includeInactive ? '' : " AND status = 'active'") . ' ORDER BY hourly_rate',
            [$studioId]
        );
        foreach ($rows as &$r) {
            $r['hours'] = $this->hoursFor((int) $r['id']);
        }
        return $rows;
    }

    private function hoursFor(int $roomId): array
    {
        $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        return array_map(static fn ($h) => [
            'weekday' => (int) $h['weekday'],
            'day'     => $days[(int) $h['weekday']],
            'open'    => substr($h['open_time'], 0, 5),
            'close'   => substr($h['close_time'], 0, 5),
        ], DB::all('SELECT * FROM room_hours WHERE room_id = ? ORDER BY weekday', [$roomId]));
    }

    private function roomWithHours(int $roomId): array
    {
        $r = DB::one('SELECT * FROM studio_rooms WHERE id = ?', [$roomId]);
        $r['hours'] = $this->hoursFor($roomId);
        $out = Present::room($r);
        $out['blocked_dates'] = DB::all(
            'SELECT id, blocked_date AS date, reason FROM room_blocked_dates WHERE room_id = ? AND blocked_date >= CURDATE() ORDER BY blocked_date',
            [$roomId]
        );
        return $out;
    }

    private function present(int $id, bool $includeInactiveRooms): array
    {
        $s = $this->studio($id);
        $rating = DB::one("SELECT AVG(rating) AS avg, COUNT(*) AS n FROM reviews WHERE target_type = 'studio' AND target_id = ?", [$id]);
        return Present::studio($s, $this->rooms($id, $includeInactiveRooms), [
            'average' => $rating['avg'] !== null ? round((float) $rating['avg'], 1) : null,
            'count'   => (int) $rating['n'],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = slugify($name);
        $slug = $base;
        $n = 2;
        while (DB::value('SELECT 1 FROM studios WHERE slug = ?', [$slug])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }
}
