<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Anything that can collect mobile money (deposits) and send it (payouts).
 * Results are normalised so the rest of the app never sees provider details:
 *
 *   initiate*()  -> ['state' => 'accepted' | 'rejected' | 'unknown', 'message' => ?string]
 *   *Status()    -> ['state' => 'pending' | 'completed' | 'failed' | 'not_found',
 *                    'provider_txn_id' => ?string, 'message' => ?string]
 */
interface PaymentGateway
{
    public function name(): string;

    public function initiateDeposit(string $id, int $amount, string $currency, string $phone, string $provider): array;

    public function depositStatus(string $id): array;

    public function initiatePayout(string $id, int $amount, string $currency, string $phone, string $provider): array;

    public function payoutStatus(string $id): array;
}
