<?php
declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\DB;
use App\Services\BookingService;
use App\Services\OrderService;
use App\Services\PayoutService;

/**
 * Mobile money collections (pawaPay "deposits").
 *
 * Mobile money is asynchronous: we ask the provider to collect, the buyer
 * approves with their PIN on their phone, and the result arrives later.
 * Rule: we only trust a status that WE fetched from the provider's API,
 * never what a browser or an incoming callback body claims.
 *
 * Status gets checked from three places, all ending in refresh():
 *   1. the frontend polling GET /api/orders/{id} or /api/bookings/{id}
 *   2. pawaPay calling POST /api/webhooks/pawapay
 *   3. the background worker (bin/worker.php) as a safety net
 */
final class PaymentService
{
    public static function gateway(?string $name = null): PaymentGateway
    {
        $name ??= (string) config('payments.gateway', 'sandbox');
        return $name === 'pawapay' ? new PawaPayGateway() : new SandboxGateway();
    }

    /** Create a payment record and ask the gateway to collect the money. */
    public static function start(string $purpose, int $userId, int $amount, string $phone, string $provider, ?int $orderId = null, ?int $bookingId = null): array
    {
        $gateway = self::gateway();
        $publicId = uuid4();
        $currency = (string) config('money.currency', 'RWF');

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
            'amount'     => $amount,
            'currency'   => $currency,
        ]);

        try {
            $result = $gateway->initiateDeposit($publicId, $amount, $currency, $phone, $provider);
        } catch (\Throwable $e) {
            app_log('payments', "initiate failed for $publicId: " . $e->getMessage());
            $result = ['state' => 'unknown', 'message' => $e->getMessage()];
        }

        if ($result['state'] === 'rejected') {
            self::fail($id, (string) ($result['message'] ?? 'Rejected by the mobile money provider'));
        } elseif ($result['state'] === 'accepted') {
            DB::update('payments', ['status' => 'accepted'], "id = ? AND status = 'initiated'", [$id]);
        }
        // 'unknown' stays 'initiated'; refresh() will find out what happened.
        return self::find($id);
    }

    public static function find(int $id): array
    {
        return DB::one('SELECT * FROM payments WHERE id = ?', [$id]) ?? [];
    }

    /** Ask the provider for the latest status and apply it. Safe to call many times. */
    public static function refresh(array $payment, bool $force = false): array
    {
        if (!in_array($payment['status'] ?? '', ['initiated', 'accepted'], true)) {
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
                    self::fail($id, 'The payment never reached the mobile money provider');
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
