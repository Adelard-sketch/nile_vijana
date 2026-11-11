<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Music coach listings and request-and-confirm mentorship sessions. */
final class MentorController extends Controller
{
    /** GET /api/mentors?q=&city= — active music coaches. */
    public function index(Request $req): array
    {
        $filters = Validator::validate($req->query, [
            'q' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:80',
        ]);
        [$page, $per, $offset] = pagination($req->query);
        $where = ['mp.active = 1'];
        $params = [];
        if (!empty($filters['q'])) {
            $where[] = '(mp.headline LIKE ? OR mp.specialties LIKE ? OR cp.stage_name LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['city'])) {
            $where[] = 'cp.city LIKE ?';
            $params[] = '%' . $filters['city'] . '%';
        }
        $condition = implode(' AND ', $where);
        $total = (int) DB::value(
            "SELECT COUNT(*) FROM mentor_profiles mp JOIN creator_profiles cp ON cp.user_id = mp.user_id WHERE $condition",
            $params
        );
        $rows = DB::all(
            "SELECT mp.user_id, cp.stage_name, cp.slug, cp.city, cp.avatar_path, cp.verified,
                    mp.headline, mp.specialties
               FROM mentor_profiles mp JOIN creator_profiles cp ON cp.user_id = mp.user_id
              WHERE $condition ORDER BY cp.verified DESC, cp.stage_name LIMIT $per OFFSET $offset",
            $params
        );
        return $this->paginated(array_map([self::class, 'presentMentor'], $rows), $page, $per, $total);
    }

    /** GET /api/mentors/{slug} — public mentor profile. */
    public function show(Request $req): array
    {
        $row = DB::one(
            "SELECT mp.user_id, cp.stage_name, cp.slug, cp.bio, cp.city, cp.avatar_path, cp.verified,
                    mp.headline, mp.specialties
               FROM mentor_profiles mp JOIN creator_profiles cp ON cp.user_id = mp.user_id
              WHERE cp.slug = ? AND mp.active = 1",
            [$req->param('slug')]
        ) ?? throw HttpException::notFound('Mentor');
        return self::presentMentor($row);
    }

    /** PATCH /api/mentors/me — opt in or update the signed-in creator's mentor listing. */
    public function upsert(Request $req): array
    {
        $uid = $req->userId();
        $existing = DB::one('SELECT * FROM mentor_profiles WHERE user_id = ?', [$uid]);
        $d = Validator::validate($req->all(), [
            'headline' => ($existing ? 'nullable' : 'required') . '|string|min:3|max:160',
            'specialties' => ($existing ? 'nullable' : 'required') . '|string|min:3|max:500',
            'active' => 'nullable|bool',
        ]);
        if (array_key_exists('active', $d) && $d['active'] === null) {
            throw HttpException::invalid('The active field cannot be cleared', ['active' => 'Choose true or false']);
        }
        if (!DB::value('SELECT 1 FROM creator_profiles WHERE user_id = ?', [$uid])) {
            throw HttpException::invalid('Create your creator profile before opting in as a mentor');
        }
        if (!$existing || $d) {
            DB::tx(static function () use ($uid, $d, $existing): void {
                if ($existing) {
                    if ($d) {
                        DB::update('mentor_profiles', $d, 'user_id = ?', [$uid]);
                    }
                } else {
                    DB::insert('mentor_profiles', $d + ['user_id' => $uid]);
                }
            });
        }
        return self::mentorForUser($uid);
    }

    /** POST /api/mentors/{id}/bookings — request a session; mentor must confirm. */
    public function book(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'start_at' => 'required|datetime',
            'duration_minutes' => 'required|int|min:30|max:240',
            'subject' => 'required|string|min:3|max:160',
            'message' => 'nullable|string|max:1000',
        ]);
        if ($d['duration_minutes'] % 30 !== 0) {
            throw HttpException::invalid('Session duration must be in 30-minute increments', [
                'duration_minutes' => 'Choose 30, 60, 90 minutes, or another 30-minute increment up to 240',
            ]);
        }
        $start = new \DateTimeImmutable($d['start_at']);
        $end = $start->modify('+' . $d['duration_minutes'] . ' minutes');
        if ($start <= new \DateTimeImmutable()) {
            throw HttpException::invalid('Choose a future session time', ['start_at' => 'Must be in the future']);
        }
        $mentorId = $req->id();
        $menteeId = $req->userId();
        if ($mentorId === $menteeId) {
            throw HttpException::invalid('You cannot book yourself as a mentor');
        }

        $bookingId = DB::tx(static function () use ($mentorId, $menteeId, $d, $start, $end): int {
            $mentor = DB::one(
                'SELECT user_id FROM mentor_profiles WHERE user_id = ? AND active = 1 FOR UPDATE',
                [$mentorId]
            ) ?? throw HttpException::notFound('Mentor');
            self::ensureNoConfirmedConflict(
                (int) $mentor['user_id'],
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s')
            );
            return DB::insert('mentor_bookings', [
                'mentor_id' => $mentorId,
                'mentee_id' => $menteeId,
                'start_at' => $start->format('Y-m-d H:i:s'),
                'end_at' => $end->format('Y-m-d H:i:s'),
                'duration_minutes' => $d['duration_minutes'],
                'subject' => $d['subject'],
                'message' => $d['message'] ?? null,
            ]);
        });

        return Response::created(self::booking($bookingId, $menteeId));
    }

    /** GET /api/mentor-bookings?as=mentee|mentor&status= — my mentorship sessions. */
    public function bookings(Request $req): array
    {
        $filters = Validator::validate($req->query, [
            'as' => 'nullable|in:mentee,mentor',
            'status' => 'nullable|in:requested,confirmed,declined,cancelled,completed',
        ]);
        $as = $filters['as'] ?? 'mentee';
        $column = $as === 'mentor' ? 'b.mentor_id' : 'b.mentee_id';
        $where = ["$column = ?"];
        $params = [$req->userId()];
        if (!empty($filters['status'])) {
            $where[] = 'b.status = ?';
            $params[] = $filters['status'];
        }
        $condition = implode(' AND ', $where);
        [$page, $per, $offset] = pagination($req->query);
        $total = (int) DB::value("SELECT COUNT(*) FROM mentor_bookings b WHERE $condition", $params);
        $rows = DB::all(
            "SELECT b.*, mentor.stage_name AS mentor_name, mentor.slug AS mentor_slug,
                    COALESCE(mentee.stage_name, mentee_user.name) AS mentee_name, mentee.slug AS mentee_slug
               FROM mentor_bookings b
               JOIN creator_profiles mentor ON mentor.user_id = b.mentor_id
               LEFT JOIN creator_profiles mentee ON mentee.user_id = b.mentee_id
               JOIN users mentee_user ON mentee_user.id = b.mentee_id
              WHERE $condition ORDER BY b.start_at DESC LIMIT $per OFFSET $offset",
            $params
        );
        return [
            'as' => $as,
            ...$this->paginated(array_map([self::class, 'presentBooking'], $rows), $page, $per, $total),
        ];
    }

    /** PATCH /api/mentor-bookings/{id} — confirm, decline, cancel, or complete a session. */
    public function bookingAction(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'action' => 'required|in:confirm,decline,cancel,complete',
            'note' => 'nullable|string|max:1000',
        ]);
        $uid = $req->userId();

        return DB::tx(static function () use ($req, $d, $uid): array {
            $booking = DB::one('SELECT * FROM mentor_bookings WHERE id = ? FOR UPDATE', [$req->id()])
                ?? throw HttpException::notFound('Mentor booking');
            $isMentor = (int) $booking['mentor_id'] === $uid;
            $isMentee = (int) $booking['mentee_id'] === $uid;
            if (!$isMentor && !$isMentee) {
                throw HttpException::notFound('Mentor booking');
            }

            $action = $d['action'];
            $next = match ($action) {
                'confirm' => $isMentor && $booking['status'] === 'requested' ? 'confirmed' : null,
                'decline' => $isMentor && $booking['status'] === 'requested' ? 'declined' : null,
                'cancel' => ($isMentor || $isMentee) && in_array($booking['status'], ['requested', 'confirmed'], true) ? 'cancelled' : null,
                'complete' => $isMentor && $booking['status'] === 'confirmed' && $booking['end_at'] <= now() ? 'completed' : null,
            };
            if ($next === null) {
                throw HttpException::conflict('This action is not available for your role or the current booking status');
            }
            if ($next === 'confirmed') {
                if ($booking['start_at'] <= now()) {
                    throw HttpException::conflict('A session that has already started cannot be confirmed');
                }
                DB::one('SELECT user_id FROM mentor_profiles WHERE user_id = ? FOR UPDATE', [$booking['mentor_id']])
                    ?? throw HttpException::conflict('The mentor profile is no longer available');
                self::ensureNoConfirmedConflict(
                    (int) $booking['mentor_id'],
                    $booking['start_at'],
                    $booking['end_at'],
                    (int) $booking['id']
                );
            }
            DB::update('mentor_bookings', [
                'status' => $next,
                'mentor_note' => $isMentor ? ($d['note'] ?? null) : $booking['mentor_note'],
                'responded_at' => $isMentor && in_array($action, ['confirm', 'decline'], true) ? now() : $booking['responded_at'],
            ], 'id = ?', [$booking['id']]);
            return self::booking((int) $booking['id'], $uid);
        });
    }

    private static function ensureNoConfirmedConflict(int $mentorId, string $start, string $end, ?int $exceptId = null): void
    {
        $sql = "SELECT id FROM mentor_bookings
                 WHERE mentor_id = ? AND status = 'confirmed'
                   AND start_at < ? AND end_at > ?";
        $params = [$mentorId, $end, $start];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        if (DB::value($sql . ' LIMIT 1', $params)) {
            throw HttpException::conflict('The mentor already has a confirmed session during that time');
        }
    }

    private static function mentorForUser(int $uid): array
    {
        $row = DB::one(
            'SELECT mp.user_id, cp.stage_name, cp.slug, cp.bio, cp.city, cp.avatar_path, cp.verified,
                    mp.headline, mp.specialties, mp.active
               FROM mentor_profiles mp JOIN creator_profiles cp ON cp.user_id = mp.user_id
              WHERE mp.user_id = ?',
            [$uid]
        ) ?? throw HttpException::notFound('Mentor profile');
        return self::presentMentor($row);
    }

    private static function booking(int $id, int $viewerId): array
    {
        $row = DB::one(
            'SELECT b.*, mentor.stage_name AS mentor_name, mentor.slug AS mentor_slug,
                    COALESCE(mentee.stage_name, mentee_user.name) AS mentee_name, mentee.slug AS mentee_slug
               FROM mentor_bookings b
               JOIN creator_profiles mentor ON mentor.user_id = b.mentor_id
               LEFT JOIN creator_profiles mentee ON mentee.user_id = b.mentee_id
               JOIN users mentee_user ON mentee_user.id = b.mentee_id
              WHERE b.id = ? AND (b.mentor_id = ? OR b.mentee_id = ?)',
            [$id, $viewerId, $viewerId]
        ) ?? throw HttpException::notFound('Mentor booking');
        return self::presentBooking($row);
    }

    private static function presentMentor(array $row): array
    {
        $row['id'] = (int) $row['user_id'];
        unset($row['user_id']);
        $row['verified'] = (bool) ($row['verified'] ?? false);
        $row['avatar_url'] = upload_url($row['avatar_path'] ?? null);
        unset($row['avatar_path']);
        if (isset($row['active'])) {
            $row['active'] = (bool) $row['active'];
        }
        return $row;
    }

    private static function presentBooking(array $row): array
    {
        foreach (['id', 'mentor_id', 'mentee_id', 'duration_minutes'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        return $row;
    }
}
