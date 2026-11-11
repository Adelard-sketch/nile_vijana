<?php
declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\DB;

/**
 * Fake mobile money for development and class demos. No account needed.
 *  - Every payment and payout is accepted.
 *  - It completes once it is older than payments.sandbox.complete_after_seconds.
 *  - Phone numbers ending in 99 FAIL, so you can demo the failure path.
 */
final class SandboxGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function initiateDeposit(string $id, int $amount, string $currency, string $phone, string $provider): array
    {
        app_log('payments', "sandbox deposit $id: $amount $currency from $phone ($provider)");
        return ['state' => 'accepted', 'message' => null];
    }

    public function depositStatus(string $id): array
    {
        $row = DB::one('SELECT phone, created_at FROM payments WHERE public_id = ?', [$id]);
        return $this->simulate($row, 'SBX-D-');
    }

    public function initiatePayout(string $id, int $amount, string $currency, string $phone, string $provider): array
    {
        app_log('payments', "sandbox payout $id: $amount $currency to $phone ($provider)");
        return ['state' => 'accepted', 'message' => null];
    }

    public function payoutStatus(string $id): array
    {
        $row = DB::one('SELECT phone, updated_at AS created_at FROM payouts WHERE public_id = ?', [$id]);
        return $this->simulate($row, 'SBX-P-');
    }

    private function simulate(?array $row, string $prefix): array
    {
        if (!$row) {
            return ['state' => 'not_found', 'provider_txn_id' => null, 'message' => 'Unknown id'];
        }
        $wait = (int) config('payments.sandbox.complete_after_seconds', 5);
        if (strtotime($row['created_at']) > time() - $wait) {
            return ['state' => 'pending', 'provider_txn_id' => null, 'message' => null];
        }
        if (str_ends_with($row['phone'], '99')) {
            return ['state' => 'failed', 'provider_txn_id' => null, 'message' => 'Sandbox: payer declined (number ends in 99)'];
        }
        return ['state' => 'completed', 'provider_txn_id' => $prefix . strtoupper(bin2hex(random_bytes(5))), 'message' => null];
    }
}
