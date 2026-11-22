<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\BookingService;
use App\Services\OrderService;
use App\Services\Payments\PaymentService;
use App\Support\Present;

/** Payment history and the page Paystack sends the buyer back to. */
final class PaymentController extends Controller
{
    /** GET /api/payments — my payments, newest first (orders, tips, studio deposits). */
    public function index(Request $req): array
    {
        $rows = DB::all(
            "SELECT p.*,
                    (SELECT GROUP_CONCAT(oi.title ORDER BY oi.id SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = p.order_id) AS order_titles,
                    s.name AS studio_name
               FROM payments p
               LEFT JOIN bookings b ON b.id = p.booking_id
               LEFT JOIN studio_rooms r ON r.id = b.room_id
               LEFT JOIN studios s ON s.id = r.studio_id
              WHERE p.user_id = ?
              ORDER BY p.id DESC LIMIT 100",
            [$req->userId()]
        );
        return ['data' => array_map(static fn ($p) => Present::payment($p) + [
            'order_id'    => $p['order_id'] !== null ? (int) $p['order_id'] : null,
            'booking_id'  => $p['booking_id'] !== null ? (int) $p['booking_id'] : null,
            'description' => self::describe($p),
        ], $rows)];
    }

    /**
     * GET /api/payments/{reference} — checks the payment with Paystack (verify) and says
     * what it was for. The return page calls this after the checkout closes.
     */
    public function show(Request $req): array
    {
        $p = PaymentService::findByReference($req->param('reference'));
        if (!$p || ((int) $p['user_id'] !== $req->userId() && !Auth::isAdmin($req->user))) {
            throw HttpException::notFound('Payment');
        }
        $p = PaymentService::refresh($p, true);
        $out = Present::payment($p) + [
            'order_id'    => $p['order_id'] !== null ? (int) $p['order_id'] : null,
            'booking_id'  => $p['booking_id'] !== null ? (int) $p['booking_id'] : null,
            'description' => self::describe($p),
        ];
        if ($p['order_id']) {
            $out['order'] = OrderService::present((int) $p['order_id']);
        }
        if ($p['booking_id']) {
            $out['booking'] = BookingService::present((int) $p['booking_id']);
        }
        return $out;
    }

    private static function describe(array $p): string
    {
        return match ($p['purpose']) {
            'order'           => 'Order #' . $p['order_id'] . (($p['order_titles'] ?? null) ? ': ' . mb_strimwidth((string) $p['order_titles'], 0, 90, '…') : ''),
            'booking_deposit' => 'Studio deposit, booking #' . $p['booking_id'] . (($p['studio_name'] ?? null) ? ' at ' . $p['studio_name'] : ''),
            'booking_balance' => 'Studio balance, booking #' . $p['booking_id'] . (($p['studio_name'] ?? null) ? ' at ' . $p['studio_name'] : ''),
            default           => 'Payment',
        };
    }
}
