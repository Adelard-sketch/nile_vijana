<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Anything that can collect money (deposits) and send it (payouts).
 * Results are normalised so the rest of the app never sees provider details:
 *
 *   initiate*()  -> ['state' => 'accepted' | 'rejected' | 'unknown', 'message' => ?string,
 *                    'checkout_url' => ?string, 'access_code' => ?string]   (hosted checkouts)
 *   *Status()    -> ['state' => 'pending' | 'completed' | 'failed' | 'not_found',
 *                    'provider_txn_id' => ?string, 'message' => ?string]
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * @param array $context email, name, callback_url, metadata (used by hosted checkouts such as Paystack)
     */
    public function initiateDeposit(string $id, int $amount, string $currency, ?string $phone, ?string $provider, array $context = []): array;

    public function depositStatus(string $id): array;

    public function initiatePayout(string $id, int $amount, string $currency, string $phone, string $provider, array $context = []): array;

    public function payoutStatus(string $id): array;
}
