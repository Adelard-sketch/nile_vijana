<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\LicensePdf;
use App\Services\OrderService;
use App\Services\Payments\PaymentService;
use App\Services\Signer;

final class OrderController extends Controller
{
    /**
     * POST /api/orders
     * {"items":[{"type":"license","license_tier_id":3},{"type":"tip","creator_id":2,"amount":1000}],
     *  "phone":"0781234567","provider":"MTN_MOMO_RWA"}
     * The buyer gets a prompt on their phone. Poll GET /api/orders/{id} until status is "paid".
     */
    public function store(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'items'    => 'required|array|min:1|max:20',
            'phone'    => 'required|phone',
            'provider' => 'nullable|in:MTN_MOMO_RWA,AIRTEL_RWA',
        ]);
        [$phone, $provider] = $this->paymentPhone($d);
        return Response::created(OrderService::create($req->user, $d['items'], $phone, $provider));
    }

    /** GET /api/orders — my orders, newest first. */
    public function index(Request $req): array
    {
        $ids = array_column(DB::all('SELECT id FROM orders WHERE buyer_id = ? ORDER BY id DESC LIMIT 50', [$req->userId()]), 'id');
        return ['data' => array_map(static fn ($id) => OrderService::present((int) $id), $ids)];
    }

    /** GET /api/orders/{id} — poll this after checkout; it also checks the payment status. */
    public function show(Request $req): array
    {
        $order = $this->myOrder($req);
        if ($order['status'] === 'pending') {
            $payment = DB::one("SELECT * FROM payments WHERE order_id = ? AND status IN ('initiated','accepted') ORDER BY id DESC LIMIT 1", [$order['id']]);
            if ($payment) {
                PaymentService::refresh($payment);
            }
        }
        return OrderService::present((int) $order['id']);
    }

    /** GET /api/orders/{id}/downloads — signed links (valid 24 hours) for everything bought. */
    public function downloads(Request $req): array
    {
        $order = $this->myOrder($req);
        if ($order['status'] !== 'paid') {
            throw HttpException::conflict('This order is not paid yet');
        }
        $items = DB::all(
            "SELECT oi.*, b.wav_path, b.stems_path FROM order_items oi
               LEFT JOIN beats b ON b.id = oi.beat_id
              WHERE oi.order_id = ? AND oi.item_type <> 'tip' ORDER BY oi.id",
            [$order['id']]
        );
        $uid = (int) $order['buyer_id'];
        $ttl = 86400;
        $link = static fn (int $itemId, string $kind) => url('/api/files/' . Signer::sign(['i' => $itemId, 'k' => $kind, 'u' => $uid], $ttl));

        $out = [];
        foreach ($items as $it) {
            $files = [];
            if ($it['item_type'] === 'track') {
                $files[] = ['kind' => 'track', 'label' => 'Song (full quality)', 'url' => $link((int) $it['id'], 'track')];
            } else {
                $files[] = ['kind' => 'mp3', 'label' => 'MP3', 'url' => $link((int) $it['id'], 'mp3')];
                if ($it['tier'] !== 'mp3_lease' && $it['wav_path']) {
                    $files[] = ['kind' => 'wav', 'label' => 'WAV', 'url' => $link((int) $it['id'], 'wav')];
                }
                if ($it['tier'] === 'exclusive' && $it['stems_path']) {
                    $files[] = ['kind' => 'stems', 'label' => 'Stems (zip)', 'url' => $link((int) $it['id'], 'stems')];
                }
                $files[] = ['kind' => 'license', 'label' => 'Licence (PDF)', 'url' => $link((int) $it['id'], 'license')];
            }
            $out[] = ['order_item_id' => (int) $it['id'], 'title' => $it['title'], 'tier' => $it['tier'], 'files' => $files];
        }
        return ['order_id' => (int) $order['id'], 'expires_in' => $ttl, 'items' => $out];
    }

    /** GET /api/order-items/{id}/license — the licence PDF (logged-in buyer). */
    public function license(Request $req): Response
    {
        $item = DB::one(
            "SELECT oi.id, o.buyer_id FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.id = ? AND oi.item_type = 'license' AND o.status = 'paid'",
            [$req->id()]
        );
        if (!$item || ((int) $item['buyer_id'] !== $req->userId() && !Auth::isAdmin($req->user))) {
            throw HttpException::notFound('Licence');
        }
        [$pdf, $name] = self::licensePdf((int) $item['id']);
        return Response::bytes($pdf, 'application/pdf', $name);
    }

    /** Build the licence PDF for a paid licence order item. */
    public static function licensePdf(int $orderItemId): array
    {
        $r = DB::one(
            "SELECT oi.*, o.paid_at, o.currency, u.name AS buyer_name, u.phone AS buyer_phone,
                    b.title AS beat_title, b.bpm, b.musical_key, b.stems_path, lt.stream_cap, cp.stage_name AS producer
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
               JOIN users u ON u.id = o.buyer_id
               JOIN beats b ON b.id = oi.beat_id
               LEFT JOIN license_tiers lt ON lt.id = oi.license_tier_id
               LEFT JOIN creator_profiles cp ON cp.user_id = oi.seller_id
              WHERE oi.id = ? AND oi.item_type = 'license'",
            [$orderItemId]
        ) ?? throw HttpException::notFound('Licence');

        $pdf = LicensePdf::render([
            'number'      => sprintf('NVC-%06d-%06d', $r['order_id'], $r['id']),
            'date'        => date('j F Y', strtotime($r['paid_at'] ?? 'now')),
            'producer'    => $r['producer'] ?? 'Producer',
            'buyer_name'  => $r['buyer_name'],
            'buyer_phone' => $r['buyer_phone'],
            'beat_title'  => $r['beat_title'],
            'bpm'         => $r['bpm'],
            'key'         => $r['musical_key'],
            'tier'        => $r['tier'],
            'stream_cap'  => $r['stream_cap'],
            'price'       => $r['price'],
            'currency'    => $r['currency'],
            'order_id'    => $r['order_id'],
            'has_stems'   => (bool) $r['stems_path'],
        ]);
        return [$pdf, 'licence-' . slugify($r['beat_title']) . '-' . $r['id'] . '.pdf'];
    }

    private function myOrder(Request $req): array
    {
        $order = DB::one('SELECT * FROM orders WHERE id = ?', [$req->id()]);
        if (!$order || ((int) $order['buyer_id'] !== $req->userId() && !Auth::isAdmin($req->user))) {
            throw HttpException::notFound('Order');
        }
        return $order;
    }
}
