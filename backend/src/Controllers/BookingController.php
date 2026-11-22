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
use App\Services\Payments\PaymentService;

final class BookingController extends Controller
{
    /**
     * POST /api/bookings
     * {"room_id":1, "start_at":"2026-12-05 14:00", "hours":2, "notes":"..."}  (deposit paid with Paystack)
     * Holds the slot and asks for the deposit by mobile money.
     * Then poll GET /api/bookings/{id} until status is "pending" (deposit paid, waiting for the
     * studio) and later "confirmed" once the studio accepts.
     */
    public function store(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'room_id'  => 'required|int',
            'start_at' => 'required|datetime',
            'hours'    => 'required|int|min:1|max:12',
            'phone'    => 'nullable|phone',
            'provider' => 'nullable|in:MTN,VOD,ATL',
            'notes'    => 'nullable|string|max:500',
        ], ['room_id' => 'Room', 'start_at' => 'Date and start time', 'hours' => 'Number of hours', 'notes' => 'Notes for the studio']);
        [$phone, $provider] = $this->paymentPhone($d);
        $booking = BookingService::create($req->user, $d['room_id'], $d['start_at'], $d['hours'], $phone, $provider, $d['notes'] ?? null);
        return Response::created($booking);
    }

    /** GET /api/bookings?as=artist|studio&status= — my sessions, or bookings at my studios. */
    public function index(Request $req): array
    {
        $as = ($req->query['as'] ?? 'artist') === 'studio' ? 'studio' : 'artist';
        $params = [$req->userId()];
        $sql = $as === 'studio'
            ? 'SELECT b.id FROM {bookings} b JOIN {studio_rooms} r ON r.id = b.room_id JOIN {studios} s ON s.id = r.studio_id WHERE s.owner_id = ?'
            : 'SELECT b.id FROM {bookings} b WHERE b.artist_id = ?';
        if (!empty($req->query['status'])) {
            $sql .= ' AND b.status = ?';
            $params[] = $req->query['status'];
        }
        if (!empty($req->query['upcoming'])) {
            $sql .= ' AND b.start_at >= NOW()';
        }
        $sql .= ' ORDER BY b.start_at DESC LIMIT 100';
        $ids = array_column(DB::all($sql, $params), 'id');
        return ['as' => $as, 'data' => array_map(static fn ($id) => BookingService::present((int) $id), $ids)];
    }

    /** GET /api/bookings/{id} — also checks the deposit payment status. */
    public function show(Request $req): array
    {
        $this->authorizeView($req);
        $payment = DB::one("SELECT * FROM payments WHERE booking_id = ? AND status IN ('initiated','accepted') ORDER BY id DESC LIMIT 1", [$req->id()]);
        if ($payment) {
            PaymentService::refresh($payment);
        }
        return BookingService::present($req->id());
    }

    /**
     * PATCH /api/bookings/{id}
     * {"action": "accept" | "reject" | "cancel" | "complete" | "no_show" | "studio_no_show", "note": "reason (required to reject)"}
     */
    public function action(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'action' => 'required|in:accept,reject,cancel,complete,no_show,studio_no_show',
            'note'   => 'nullable|string|max:500',
        ]);
        return BookingService::action($req->user, $req->id(), $d['action'], $d['note'] ?? null);
    }

    /** POST /api/bookings/{id}/pay-balance - opens a Paystack payment for what is still owed. */
    public function payBalance(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'phone'    => 'nullable|phone',
            'provider' => 'nullable|in:MTN,VOD,ATL',
        ]);
        [$phone, $provider] = $this->paymentPhone($d);
        return BookingService::payBalance($req->user, $req->id(), $phone, $provider);
    }

    private function authorizeView(Request $req): void
    {
        $row = DB::one(
            'SELECT b.artist_id, s.owner_id FROM bookings b JOIN studio_rooms r ON r.id = b.room_id
               JOIN studios s ON s.id = r.studio_id WHERE b.id = ?',
            [$req->id()]
        );
        $uid = $req->userId();
        if (!$row || ((int) $row['artist_id'] !== $uid && (int) $row['owner_id'] !== $uid && !Auth::isAdmin($req->user))) {
            throw HttpException::notFound('Booking');
        }
    }
}
