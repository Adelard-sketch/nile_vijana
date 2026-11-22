<?php
declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\DB;
use App\Core\HttpException;
use App\Services\BookingService;
use App\Services\OrderService;
use App\Services\PayoutService;

/**
 * Collecting money for orders (songs, beat licences, tips) and studio bookings.
 *
 * With Paystack the buyer pays on Paystack's checkout (card or mobile money) and the
 * result arrives later. Rule: we only trust a status that WE fetched from the provider's
 * API (Paystack: GET /transaction/verify), never what a browser or a callback body claims.
 * Amounts always come from the database, never from the browser.
 *
 * Status gets checked from four places, all ending in refresh():
 *   1. the payment return page (GET /api/payments/{reference}) after the Paystack checkout
 *   2. the frontend polling GET /api/orders/{id} or /api/bookings/{id}
 *   3. Paystack calling POST /api/webhooks/paystack (signed with the secret key)
 *   4. the background worker (bin/worker.php) as a safety net
 * complete() runs inside a row lock and does nothing the second time, so a payment can
 * never be delivered twice however many of these arrive together.
 */
final class PaymentService
{
    public static function gateway(?string $name = null): PaymentGateway
    {
        $name ??= (string) config('payments.gateway', 'paystack');
        return match ($name) {
            'pawapay' => new PawaPayGateway(),
            'sandbox' => config('app.env') === 'production'
                ? throw new HttpException(503, 'The test payment simulator cannot be used in production. Set PAYMENT_GATEWAY=paystack.')
                : new SandboxGateway(),
            default   => new PaystackGateway(),
        };
    }

    /** Stop before anything is created when payments cannot be taken (e.g. no Paystack key yet). */
    public static function ensureReady(): void
    {
        $name = (string) config('payments.gateway', 'paystack');
        if ($name === 'paystack' && !PaystackGateway::configured()) {
            throw new HttpException(503, 'Online payments are not set up yet. The site owner must add the Paystack keys (PAYSTACK_SECRET_KEY) in backend/.env.');
        }
        self::gateway();
    }

    /** Create a payment record and ask the gateway to collect the money. */
    public static function start(string $purpose, int $userId, int $amount, ?string $phone, ?string $provider, ?int $orderId = null, ?int $bookingId = null, string $description = ''): array
    {
        $gateway = self::gateway();
        $publicId = uuid4();
        $currency = (string) config('money.currency', 'GHS');
        $user = DB::one('SELECT id, name, email FROM users WHERE id = ?', [$userId]) ?? [];

        // Save BEFORE calling the provider, so a crash never loses track of money.
        $id = DB::insert('payments', [
            'public_id'  => $publicId,
            'purpose'    => $purpose,
            'order_id'   => $orderId,
            'booking_id' => $bookingId,
            'user_id'    => $userId,
            'gateway'    => $gateway->name(),
            'provider'   => $provider,
            'phone'      => $phone,
            'email'      => $user['email'] ?? null,
            'amount'     => $amount,
            'currency'   => $currency,
        ]);

        try {
            $result = $gateway->initiateDeposit($publicId, $amount, $currency, $phone, $provider, [
                'email'        => $user['email'] ?? null,
                'name'         => $user['name'] ?? null,
                'purpose'      => $purpose,
                'order_id'     => $orderId,
                'booking_id'   => $bookingId,
                'description'  => $description,
                'callback_url' => frontend_url('payment.html?reference=' . $publicId),
            ]);
        } catch (\Throwable $e) {
            app_log('payments', "initiate failed for $publicId: " . $e->getMessage());
            $result = ['state' => 'unknown', 'message' => $e->getMessage()];
        }

        if ($result['state'] === 'rejected') {
            self::fail($id, (string) ($result['message'] ?? 'Rejected by the mobile money provider'));
        } elseif ($result['state'] === 'accepted') {
            DB::update('payments', [
                'status'       => 'accepted',
                'checkout_url' => isset($result['checkout_url']) ? mb_substr((string) $result['checkout_url'], 0, 500) : null,
                'access_code'  => isset($result['access_code']) ? mb_substr((string) $result['access_code'], 0, 100) : null,
            ], "id = ? AND status = 'initiated'", [$id]);
        }
        // 'unknown' stays 'initiated'; refresh() will find out what happened.
        return self::find($id);
    }

    public static function find(int $id): array
    {
        return DB::one('SELECT * FROM payments WHERE id = ?', [$id]) ?? [];
    }

    public static function findByReference(string $reference): ?array
    {
        return DB::one('SELECT * FROM payments WHERE public_id = ?', [$reference]);
    }

    /**
     * Ask the provider for the latest status and apply it. Safe to call many times.
     * $lateSuccess: also re-check a payment we already marked failed (a Paystack webhook
     * saying the buyer paid after all, e.g. finished the checkout after it timed out here).
     */
    public static function refresh(array $payment, bool $force = false, bool $lateSuccess = false): array
    {
        $open = in_array($payment['status'] ?? '', ['initiated', 'accepted'], true);
        if (!$open && !($lateSuccess && ($payment['status'] ?? '') === 'failed')) {
            return $payment;
        }
        // Don't hammer the provider when a page polls every second.
        if (!$force && $payment['last_checked_at'] && strtotime($payment['last_checked_at']) > time() - 3) {
            return $payment;
        }
        $id = (int) $payment['id'];
        DB::update('payments', ['last_checked_at' => now()], 'id = ?', [$id]);

        try {
            $status = self::gateway($payment['gateway'])->depositStatus($payment['public_id']);
        } catch (\Throwable $e) {
            app_log('payments', "status check failed for {$payment['public_id']}: " . $e->getMessage());
            return self::find($id);
        }

        switch ($status['state']) {
            case 'completed':
                self::complete($id, $status['provider_txn_id'] ?? null);
                break;
            case 'failed':
                self::fail($id, (string) ($status['message'] ?? 'The payment was not approved'));
                break;
            case 'not_found':
                // Only conclude failure once the provider has had time to register it.
                if (strtotime($payment['created_at']) < time() - 120) {
                    self::fail($id, 'The payment never reached the payment provider');
                }
                break;
            case 'pending':
                // Checkout opened but never finished: stop checking after the order would have expired.
                $limit = 60 * ((int) config('money.order_expiry_minutes', 30) + 30);
                if ($open && strtotime($payment['created_at']) < time() - $limit) {
                    self::fail($id, 'The payment was not completed in time');
                }
                break;
        }
        return self::find($id);
    }

    /** Mark a payment as paid and deliver what was bought. Runs exactly once per payment. */
    public static function complete(int $paymentId, ?string $providerTxnId): void
    {
        DB::tx(static function () use ($paymentId, $providerTxnId) {
            $p = DB::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$p || $p['status'] === 'completed') {
                return;                                   // already handled: do nothing
            }
            if ($p['status'] === 'failed') {
                app_log('payments', "late success for {$p['public_id']}: delivering now");
            }
            DB::update('payments', [
                'status'          => 'completed',
                'provider_txn_id' => $providerTxnId,
                'completed_at'    => now(),
                'failure_reason'  => null,
            ], 'id = ?', [$paymentId]);
            app_log('payments', "payment {$p['public_id']} completed", ['amount' => $p['amount'], 'purpose' => $p['purpose']]);

            $p['status'] = 'completed';
            match ($p['purpose']) {
                'order'                              => OrderService::paymentCompleted((int) $p['order_id'], $p),
                'booking_deposit', 'booking_balance' => BookingService::paymentCompleted((int) $p['booking_id'], $p),
            };
        });
    }

    public static function fail(int $paymentId, string $reason): void
    {
        DB::tx(static function () use ($paymentId, $reason) {
            $p = DB::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$p || in_array($p['status'], ['completed', 'failed'], true)) {
                return;
            }
            DB::update('payments', ['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$paymentId]);
            app_log('payments', "payment {$p['public_id']} failed: $reason");

            if ($p['purpose'] === 'order') {
                OrderService::paymentFailed((int) $p['order_id']);
            } elseif ($p['purpose'] === 'booking_deposit') {
                BookingService::depositFailed((int) $p['booking_id']);
            }
        });
    }

    /**
     * Paystack webhook (already signature-checked by the controller). We read only the
     * reference, then verify with Paystack ourselves before changing anything.
     */
    public static function handlePaystackEvent(array $event): string
    {
        $type = (string) ($event['event'] ?? '');
        $reference = (string) ($event['data']['reference'] ?? '');
        if ($reference === '') {
            return 'ignored';
        }
        if (str_starts_with($type, 'charge.')) {
            $p = self::findByReference($reference);
            if ($p) {
                self::refresh($p, true, $type === 'charge.success');
            }
            return $p ? 'payment' : 'unknown-reference';
        }
        if (str_starts_with($type, 'transfer.')) {
            $po = DB::one('SELECT * FROM payouts WHERE public_id = ?', [$reference]);
            if ($po) {
                PayoutService::refresh($po, true);
            }
            return $po ? 'payout' : 'unknown-reference';
        }
        return 'ignored';
    }

    /**
     * pawaPay callback. We read only the id from the body, then fetch the real
     * status from pawaPay ourselves, so a forged callback cannot mark anything paid.
     */
    public static function handleCallback(array $body): string
    {
        if (!empty($body['depositId'])) {
            $p = DB::one('SELECT * FROM payments WHERE public_id = ?', [(string) $body['depositId']]);
            if ($p) {
                self::refresh($p, true);
            }
            return 'deposit';
        }
        if (!empty($body['payoutId'])) {
            $po = DB::one('SELECT * FROM payouts WHERE public_id = ?', [(string) $body['payoutId']]);
            if ($po) {
                PayoutService::refresh($po, true);
            }
            return 'payout';
        }
        return 'ignored';
    }
}
