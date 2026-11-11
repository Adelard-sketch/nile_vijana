<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Payments\PaymentService;
use App\Support\Present;

/**
 * Studio booking with deposits held in escrow.
 *
 *  held      -> slot reserved for a few minutes while the artist pays the deposit
 *  confirmed -> deposit received (money sits in escrow, not with the studio yet)
 *  completed -> session happened; escrow released to the studio minus commission
 *  cancelled -> by the artist (refund if early enough), the studio (full refund) or the system
 *  no_show   -> artist did not come; studio keeps the deposit
 *  expired   -> hold ran out without payment
 */
final class BookingService
{
    /** Hourly slots for one room on one day. */
    public static function availability(array $room, string $date): array
    {
        $weekday = (int) date('N', strtotime($date));
        $hours = DB::one('SELECT open_time, close_time FROM room_hours WHERE room_id = ? AND weekday = ?', [$room['id'], $weekday]);
        $blocked = DB::one('SELECT reason FROM room_blocked_dates WHERE room_id = ? AND blocked_date = ?', [$room['id'], $date]);
        if (!$hours || $blocked) {
            return ['date' => $date, 'open' => false, 'reason' => $blocked['reason'] ?? 'Closed on this day', 'slots' => []];
        }

        $busy = DB::all(
            "SELECT start_at, end_at FROM bookings
              WHERE room_id = ? AND start_at < ? AND end_at > ?
                AND (status IN ('confirmed','completed') OR (status = 'held' AND hold_expires_at > NOW()))",
            [$room['id'], "$date 23:59:59", "$date 00:00:00"]
        );

        $slots = [];
        $t = strtotime("$date {$hours['open_time']}");
        $close = strtotime("$date {$hours['close_time']}");
        while ($t + 3600 <= $close) {
            $free = $t > time();
            foreach ($busy as $b) {
                if ($t < strtotime($b['end_at']) && $t + 3600 > strtotime($b['start_at'])) {
                    $free = false;
                    break;
                }
            }
            $slots[] = ['start' => date('Y-m-d H:i', $t), 'end' => date('Y-m-d H:i', $t + 3600), 'available' => $free];
            $t += 3600;
        }
        return [
            'date'        => $date,
            'open'        => true,
            'opens'       => substr($hours['open_time'], 0, 5),
            'closes'      => substr($hours['close_time'], 0, 5),
            'hourly_rate' => (int) $room['hourly_rate'],
            'min_hours'   => (int) $room['min_hours'],
            'slots'       => $slots,
        ];
    }

    public static function overlaps(int $roomId, string $start, string $end, int $exceptBookingId = 0): bool
    {
        return (int) DB::value(
            "SELECT COUNT(*) FROM bookings
              WHERE room_id = ? AND id <> ? AND start_at < ? AND end_at > ?
                AND (status IN ('confirmed','completed') OR (status = 'held' AND hold_expires_at > NOW()))",
            [$roomId, $exceptBookingId, $end, $start]
        ) > 0;
    }

    public static function create(array $user, int $roomId, string $startAt, int $hours, string $phone, string $provider, ?string $notes): array
    {
        $room = DB::one(
            'SELECT r.*, s.status AS studio_status, s.owner_id FROM studio_rooms r
               JOIN studios s ON s.id = r.studio_id WHERE r.id = ?',
            [$roomId]
        );
        if (!$room || $room['status'] !== 'active' || $room['studio_status'] !== 'verified') {
            throw HttpException::notFound('Room');
        }
        if ((int) $room['owner_id'] === (int) $user['id']) {
            throw HttpException::invalid('You cannot book your own studio');
        }
        if ($hours < (int) $room['min_hours'] || $hours > 12) {
            throw HttpException::invalid("Book between {$room['min_hours']} and 12 hours", ['hours' => 'Out of range']);
        }

        $start = strtotime($startAt);
        $end = $start + $hours * 3600;
        $date = date('Y-m-d', $start);
        if ($start <= time()) {
            throw HttpException::invalid('Choose a time in the future', ['start_at' => 'In the past']);
        }
        if (date('i', $start) !== '00') {
            throw HttpException::invalid('Sessions start on the hour, e.g. 14:00', ['start_at' => 'Not on the hour']);
        }
        $open = DB::one('SELECT open_time, close_time FROM room_hours WHERE room_id = ? AND weekday = ?', [$roomId, (int) date('N', $start)]);
        $blocked = DB::value('SELECT 1 FROM room_blocked_dates WHERE room_id = ? AND blocked_date = ?', [$roomId, $date]);
        if (!$open || $blocked || $start < strtotime("$date {$open['open_time']}") || $end > strtotime("$date {$open['close_time']}")) {
            throw HttpException::invalid('The studio is closed at that time', ['start_at' => 'Outside opening hours']);
        }

        $total = (int) $room['hourly_rate'] * $hours;
        $deposit = max(1, (int) round($total * (float) config('money.deposit_rate', 0.3)));
        $startStr = date('Y-m-d H:i:s', $start);
        $endStr = date('Y-m-d H:i:s', $end);

        $bookingId = DB::tx(static function () use ($room, $user, $startStr, $endStr, $hours, $total, $deposit, $notes) {
            // Lock the room row so two people can't grab the same slot at the same moment.
            DB::one('SELECT id FROM studio_rooms WHERE id = ? FOR UPDATE', [$room['id']]);
            if (self::overlaps((int) $room['id'], $startStr, $endStr)) {
                throw HttpException::conflict('That time is already booked. Pick another slot.');
            }
            return DB::insert('bookings', [
                'room_id'         => $room['id'],
                'artist_id'       => $user['id'],
                'start_at'        => $startStr,
                'end_at'          => $endStr,
                'hours'           => $hours,
                'hourly_rate'     => $room['hourly_rate'],
                'total'           => $total,
                'deposit'         => $deposit,
                'status'          => 'held',
                'hold_expires_at' => now(60 * (int) config('money.booking_hold_minutes', 10)),
                'notes'           => $notes,
            ]);
        });

        PaymentService::start('booking_deposit', (int) $user['id'], $deposit, $phone, $provider, null, $bookingId);
        return self::present($bookingId);
    }

    /** Artist pays the rest of the session price in the app (optional). */
    public static function payBalance(array $user, int $bookingId, string $phone, string $provider): array
    {
        $b = DB::one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
        if (!$b || (int) $b['artist_id'] !== (int) $user['id']) {
            throw HttpException::notFound('Booking');
        }
        if ($b['status'] !== 'confirmed') {
            throw HttpException::conflict('Only confirmed bookings can be paid');
        }
        $balance = (int) $b['total'] - (int) $b['amount_paid'];
        if ($balance <= 0) {
            throw HttpException::conflict('This booking is already fully paid');
        }
        $pending = DB::value("SELECT id FROM payments WHERE booking_id = ? AND purpose = 'booking_balance' AND status IN ('initiated','accepted')", [$bookingId]);
        if ($pending) {
            throw HttpException::conflict('A payment for this booking is already in progress');
        }
        PaymentService::start('booking_balance', (int) $user['id'], $balance, $phone, $provider, null, $bookingId);
        return self::present($bookingId);
    }

    /** Called inside the payment transaction once deposit or balance money has arrived. */
    public static function paymentCompleted(int $bookingId, array $payment): void
    {
        $b = DB::one(
            'SELECT b.*, s.owner_id FROM bookings b
               JOIN studio_rooms r ON r.id = b.room_id JOIN studios s ON s.id = r.studio_id
              WHERE b.id = ? FOR UPDATE',
            [$bookingId]
        );
        if (!$b) {
            return;
        }
        $amount = (int) $payment['amount'];
        $label = $payment['purpose'] === 'booking_deposit' ? 'Deposit' : 'Balance';
        Ledger::post('escrow', null, $amount, 'booking_escrow', 'booking', $bookingId, "$label paid for booking #$bookingId");
        DB::update('bookings', ['amount_paid' => (int) $b['amount_paid'] + $amount], 'id = ?', [$bookingId]);

        $refund = static function (string $why) use ($b, $amount, $bookingId) {
            Ledger::post('escrow', null, -$amount, 'booking_refund', 'booking', $bookingId, $why);
            Ledger::post('user', (int) $b['artist_id'], $amount, 'booking_refund', 'booking', $bookingId, $why);
        };

        if ($payment['purpose'] === 'booking_balance') {
            if (!in_array($b['status'], ['confirmed', 'completed'], true)) {
                $refund('Balance refunded: booking no longer active');
            } elseif ($b['status'] === 'completed') {
                self::release($b, $amount);             // session already done: pay the studio now
            }
            return;
        }

        // Deposit. Normally the booking is still 'held'. If the hold expired or the first
        // attempt failed, confirm anyway as long as the slot is still free.
        $revivable = $b['status'] === 'held'
            || ($b['status'] === 'expired')
            || ($b['status'] === 'cancelled' && $b['cancelled_by'] === 'system');
        if (!$revivable) {
            $refund('Deposit refunded: booking was cancelled');
            return;
        }
        if (strtotime($b['start_at']) <= time() || self::overlaps((int) $b['room_id'], $b['start_at'], $b['end_at'], $bookingId)) {
            DB::update('bookings', ['status' => 'cancelled', 'cancelled_by' => 'system', 'hold_expires_at' => null], 'id = ?', [$bookingId]);
            $refund('Deposit refunded: the slot was no longer free');
            return;
        }

        DB::update('bookings', ['status' => 'confirmed', 'cancelled_by' => null, 'hold_expires_at' => null], 'id = ?', [$bookingId]);
        self::notifyConfirmed($bookingId);
    }

    public static function depositFailed(int $bookingId): void
    {
        DB::update('bookings', ['status' => 'cancelled', 'cancelled_by' => 'system', 'hold_expires_at' => null], "id = ? AND status = 'held'", [$bookingId]);
    }

    /**
     * Actions:
     *  cancel          artist or studio owner (before the session starts)
     *  complete        studio owner/admin, after the start time: releases escrow to the studio
     *  no_show         studio owner/admin, after the start time: artist didn't come, studio keeps deposit
     *  studio_no_show  admin only: studio didn't open, full refund to the artist
     */
    public static function action(array $user, int $bookingId, string $action): array
    {
        DB::tx(static function () use ($user, $bookingId, $action) {
            $b = DB::one(
                'SELECT b.*, s.owner_id FROM bookings b
                   JOIN studio_rooms r ON r.id = b.room_id JOIN studios s ON s.id = r.studio_id
                  WHERE b.id = ? FOR UPDATE',
                [$bookingId]
            );
            if (!$b) {
                throw HttpException::notFound('Booking');
            }
            $uid = (int) $user['id'];
            $isArtist = (int) $b['artist_id'] === $uid;
            $isOwner = (int) $b['owner_id'] === $uid;
            $isAdmin = Auth::isAdmin($user);
            if (!$isArtist && !$isOwner && !$isAdmin) {
                throw HttpException::notFound('Booking');
            }
            $started = strtotime($b['start_at']) <= time();
            $escrow = Ledger::escrowFor($bookingId);

            switch ($action) {
                case 'cancel':
                    if (!in_array($b['status'], ['held', 'confirmed'], true)) {
                        throw HttpException::conflict('This booking can no longer be cancelled');
                    }
                    if ($started) {
                        throw HttpException::conflict('The session has already started');
                    }
                    if ($isArtist) {
                        $hoursLeft = (strtotime($b['start_at']) - time()) / 3600;
                        if ($b['status'] === 'held' || $hoursLeft >= (int) config('money.free_cancel_hours', 24)) {
                            self::refund($b, $escrow, 'Cancelled in time: full refund');
                        } else {
                            // Late cancellation: the studio keeps the deposit, anything extra goes back.
                            $keep = min((int) $b['deposit'], $escrow);
                            self::release($b, $keep);
                            self::refund($b, $escrow - $keep, 'Late cancellation: balance refunded');
                        }
                        $by = 'artist';
                    } else {
                        self::refund($b, $escrow, 'Cancelled by the studio: full refund');
                        $by = $isOwner ? 'studio' : 'admin';
                    }
                    DB::update('bookings', ['status' => 'cancelled', 'cancelled_by' => $by, 'hold_expires_at' => null], 'id = ?', [$bookingId]);
                    break;

                case 'complete':
                case 'no_show':
                    if (!$isOwner && !$isAdmin) {
                        throw HttpException::forbidden('Only the studio can do this');
                    }
                    if ($b['status'] !== 'confirmed') {
                        throw HttpException::conflict('Only confirmed bookings can be closed');
                    }
                    if (!$started) {
                        throw HttpException::conflict('Wait until the session start time');
                    }
                    if ($action === 'complete') {
                        self::release($b, $escrow);
                    } else {
                        $keep = min((int) $b['deposit'], $escrow);
                        self::release($b, $keep);
                        self::refund($b, $escrow - $keep, 'Artist no-show: balance refunded');
                    }
                    DB::update('bookings', ['status' => $action === 'complete' ? 'completed' : 'no_show'], 'id = ?', [$bookingId]);
                    break;

                case 'studio_no_show':
                    if (!$isAdmin) {
                        throw HttpException::forbidden('Only an admin can record a studio no-show');
                    }
                    if ($b['status'] !== 'confirmed') {
                        throw HttpException::conflict('Only confirmed bookings can be closed');
                    }
                    self::refund($b, $escrow, 'Studio no-show: full refund');
                    DB::update('bookings', ['status' => 'cancelled', 'cancelled_by' => 'studio'], 'id = ?', [$bookingId]);
                    break;

                default:
                    throw HttpException::invalid('action must be cancel, complete, no_show or studio_no_show');
            }
        });
        return self::present($bookingId);
    }

    /** Move escrow money to the studio owner, minus the platform commission. */
    private static function release(array $b, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        [$net, $commission] = split_commission($amount, (float) config('money.commission_bookings', 0.10));
        $id = (int) $b['id'];
        Ledger::post('escrow', null, -$amount, 'booking_release', 'booking', $id, "Released for booking #$id");
        Ledger::post('user', (int) $b['owner_id'], $net, 'booking_release', 'booking', $id, "Studio session #$id");
        Ledger::post('platform', null, $commission, 'commission', 'booking', $id, "Booking commission #$id");
    }

    /** Return escrow money to the artist's wallet. */
    private static function refund(array $b, int $amount, string $why): void
    {
        if ($amount <= 0) {
            return;
        }
        $id = (int) $b['id'];
        Ledger::post('escrow', null, -$amount, 'booking_refund', 'booking', $id, $why);
        Ledger::post('user', (int) $b['artist_id'], $amount, 'booking_refund', 'booking', $id, $why);
    }

    private static function notifyConfirmed(int $bookingId): void
    {
        $row = DB::one(
            'SELECT b.start_at, b.hours, s.name AS studio, owner.phone AS owner_phone, artist.phone AS artist_phone, artist.name AS artist_name
               FROM bookings b JOIN studio_rooms r ON r.id = b.room_id JOIN studios s ON s.id = r.studio_id
               JOIN users owner ON owner.id = s.owner_id JOIN users artist ON artist.id = b.artist_id
              WHERE b.id = ?',
            [$bookingId]
        );
        if (!$row) {
            return;
        }
        $when = date('D j M, H:i', strtotime($row['start_at']));
        if ($row['owner_phone']) {
            Sms::send($row['owner_phone'], "Nile Vijana: new booking #$bookingId at {$row['studio']} - {$row['artist_name']}, $when, {$row['hours']}h. Deposit paid.");
        }
        if ($row['artist_phone']) {
            Sms::send($row['artist_phone'], "Nile Vijana: your session at {$row['studio']} on $when is confirmed (booking #$bookingId).");
        }
    }

    public static function present(int $bookingId): array
    {
        $b = DB::one(
            'SELECT b.*, r.name AS room_name, r.studio_id, s.name AS studio_name, s.slug AS studio_slug, s.owner_id,
                    s.address AS studio_address, u.name AS artist_name, u.phone AS artist_phone
               FROM bookings b JOIN studio_rooms r ON r.id = b.room_id JOIN studios s ON s.id = r.studio_id
               JOIN users u ON u.id = b.artist_id WHERE b.id = ?',
            [$bookingId]
        );
        if (!$b) {
            throw HttpException::notFound('Booking');
        }
        $payment = DB::one('SELECT * FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1', [$bookingId]);
        return Present::booking($b, $payment, Ledger::escrowFor($bookingId));
    }
}
