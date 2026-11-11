<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;
use App\Services\Payments\PaymentService;
use App\Support\Present;

/**
 * Fan store and beat market checkout.
 * Cart items:
 *   {"type": "license", "license_tier_id": 12}
 *   {"type": "track",   "track_id": 7}
 *   {"type": "tip",     "creator_id": 3, "amount": 1000}
 * Prices always come from the database, never from the client.
 */
final class OrderService
{
    public static function create(array $user, array $items, string $phone, string $provider): array
    {
        $uid = (int) $user['id'];
        $rate = (float) config('money.commission_sales', 0.15);
        $lines = [];
        $seen = [];

        foreach (array_values($items) as $i => $item) {
            $n = $i + 1;
            $type = is_array($item) ? ($item['type'] ?? '') : '';
            if ($type === 'license') {
                $tierId = (int) ($item['license_tier_id'] ?? 0);
                $row = DB::one(
                    'SELECT lt.*, b.title, b.producer_id, b.status AS beat_status
                       FROM license_tiers lt JOIN beats b ON b.id = lt.beat_id WHERE lt.id = ?',
                    [$tierId]
                );
                if (!$row || !$row['active'] || $row['beat_status'] !== 'live') {
                    throw HttpException::invalid("Item $n: this licence is not for sale");
                }
                if ((int) $row['producer_id'] === $uid) {
                    throw HttpException::invalid("Item $n: you cannot buy your own beat");
                }
                $key = 'beat-' . $row['beat_id'];
                if (isset($seen[$key])) {
                    throw HttpException::invalid("Item $n: choose only one licence per beat");
                }
                $seen[$key] = true;
                $lines[] = [
                    'item_type' => 'license', 'beat_id' => (int) $row['beat_id'], 'license_tier_id' => (int) $row['id'],
                    'tier' => $row['tier'], 'seller_id' => (int) $row['producer_id'],
                    'title' => $row['title'] . ' - ' . LicensePdf::TIER_NAMES[$row['tier']], 'price' => (int) $row['price'],
                ];
            } elseif ($type === 'track') {
                $row = DB::one('SELECT id, title, creator_id, price, status FROM tracks WHERE id = ?', [(int) ($item['track_id'] ?? 0)]);
                if (!$row || $row['status'] !== 'live' || (int) $row['price'] <= 0) {
                    throw HttpException::invalid("Item $n: this song is not for sale");
                }
                if ((int) $row['creator_id'] === $uid) {
                    throw HttpException::invalid("Item $n: you cannot buy your own song");
                }
                $key = 'track-' . $row['id'];
                if (isset($seen[$key])) {
                    continue;                              // same song twice: keep one
                }
                $seen[$key] = true;
                $lines[] = [
                    'item_type' => 'track', 'track_id' => (int) $row['id'], 'seller_id' => (int) $row['creator_id'],
                    'title' => $row['title'], 'price' => (int) $row['price'],
                ];
            } elseif ($type === 'tip') {
                $creatorId = (int) ($item['creator_id'] ?? 0);
                $amount = (int) ($item['amount'] ?? 0);
                $creator = DB::one('SELECT user_id, stage_name FROM creator_profiles WHERE user_id = ?', [$creatorId]);
                if (!$creator) {
                    throw HttpException::invalid("Item $n: creator not found");
                }
                if ($creatorId === $uid) {
                    throw HttpException::invalid("Item $n: you cannot tip yourself");
                }
                $min = (int) config('money.min_tip', 100);
                if ($amount < $min || $amount > 1000000) {
                    throw HttpException::invalid("Item $n: a tip must be between $min and 1,000,000 RWF");
                }
                $lines[] = [
                    'item_type' => 'tip', 'seller_id' => $creatorId,
                    'title' => 'Tip for ' . $creator['stage_name'], 'price' => $amount,
                ];
            } else {
                throw HttpException::invalid("Item $n: type must be license, track or tip");
            }
        }

        if (!$lines) {
            throw HttpException::invalid('Your cart is empty');
        }
        $total = array_sum(array_column($lines, 'price'));

        $orderId = DB::tx(static function () use ($uid, $lines, $total, $phone, $provider, $rate) {
            // Only one buyer at a time can be paying for an exclusive licence.
            foreach ($lines as $line) {
                if (($line['tier'] ?? null) !== 'exclusive') {
                    continue;
                }
                $beat = DB::one('SELECT status FROM beats WHERE id = ? FOR UPDATE', [$line['beat_id']]);
                if (!$beat || $beat['status'] !== 'live') {
                    throw HttpException::conflict('This beat has just been sold exclusively');
                }
                $busy = (int) DB::value(
                    "SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE oi.beat_id = ? AND oi.tier = 'exclusive' AND o.status = 'pending' AND o.created_at > ?",
                    [$line['beat_id'], now(-60 * (int) config('money.order_expiry_minutes', 30))]
                );
                if ($busy > 0) {
                    throw HttpException::conflict('Someone is paying for the exclusive licence right now. Try again in a few minutes.');
                }
            }

            $orderId = DB::insert('orders', [
                'buyer_id' => $uid, 'total' => $total, 'currency' => (string) config('money.currency', 'RWF'),
                'phone' => $phone, 'provider' => $provider,
            ]);
            foreach ($lines as $line) {
                [, $commission] = split_commission($line['price'], $rate);
                DB::insert('order_items', $line + ['order_id' => $orderId, 'commission' => $commission]);
            }
            return $orderId;
        });

        PaymentService::start('order', $uid, $total, $phone, $provider, $orderId);
        return self::present($orderId);
    }

    /** Called inside the payment transaction once the money has arrived. */
    public static function paymentCompleted(int $orderId, array $payment): void
    {
        $order = DB::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$order || in_array($order['status'], ['paid', 'refunded'], true)) {
            return;
        }
        $items = DB::all('SELECT * FROM order_items WHERE order_id = ?', [$orderId]);

        // A late payment for an exclusive that someone else already bought: refund to the wallet.
        foreach ($items as $it) {
            if ($it['tier'] === 'exclusive') {
                $beat = DB::one('SELECT status FROM beats WHERE id = ? FOR UPDATE', [$it['beat_id']]);
                if (!$beat || $beat['status'] !== 'live') {
                    Ledger::post('user', (int) $order['buyer_id'], (int) $order['total'], 'refund', 'order', $orderId, 'Exclusive no longer available - refunded to wallet');
                    DB::update('orders', ['status' => 'refunded'], 'id = ?', [$orderId]);
                    return;
                }
            }
        }

        DB::update('orders', ['status' => 'paid', 'paid_at' => now()], 'id = ?', [$orderId]);

        foreach ($items as $it) {
            $price = (int) $it['price'];
            $commission = (int) $it['commission'];
            $type = $it['item_type'] === 'tip' ? 'tip' : 'sale';
            Ledger::post('user', (int) $it['seller_id'], $price - $commission, $type, 'order_item', (int) $it['id'], $it['title']);
            Ledger::post('platform', null, $commission, 'commission', 'order_item', (int) $it['id'], $it['title']);

            if ($it['tier'] === 'exclusive') {
                DB::update('beats', ['status' => 'sold_exclusive'], 'id = ?', [$it['beat_id']]);
                DB::update('license_tiers', ['active' => 0], 'beat_id = ?', [$it['beat_id']]);
            }

            $seller = DB::one('SELECT phone FROM users WHERE id = ?', [$it['seller_id']]);
            if ($seller && $seller['phone']) {
                $what = $type === 'tip' ? 'a tip' : '"' . $it['title'] . '"';
                Sms::send($seller['phone'], 'Nile Vijana: you earned ' . number_format($price - $commission) . " RWF from $what.");
            }
        }
    }

    public static function paymentFailed(int $orderId): void
    {
        DB::update('orders', ['status' => 'failed'], "id = ? AND status = 'pending'", [$orderId]);
    }

    public static function present(int $orderId): array
    {
        $order = DB::one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$order) {
            throw HttpException::notFound('Order');
        }
        $items = DB::all(
            'SELECT oi.*, cp.stage_name AS seller_name FROM order_items oi
               LEFT JOIN creator_profiles cp ON cp.user_id = oi.seller_id
              WHERE oi.order_id = ? ORDER BY oi.id',
            [$orderId]
        );
        $payment = DB::one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
        return Present::order($order, $items, $payment);
    }
}
