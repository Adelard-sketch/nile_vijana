<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * pawaPay API v2 (mobile money in 20+ African markets, including
 * MTN MoMo and Airtel Money in Rwanda).
 * Docs: https://docs.pawapay.io/v2/docs/deposits and /v2/docs/payouts
 *
 * Provider codes for Rwanda: MTN_MOMO_RWA, AIRTEL_RWA.
 */
final class PawaPayGateway implements PaymentGateway
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('payments.pawapay.base_url'), '/');
        $this->token = (string) config('payments.pawapay.token');
        if ($this->token === '') {
            throw new \RuntimeException('Set payments.pawapay.token in config/config.php');
        }
    }

    public function name(): string
    {
        return 'pawapay';
    }

    public function initiateDeposit(string $id, int $amount, string $currency, string $phone, string $provider): array
    {
        [$code, $res] = $this->request('POST', '/v2/deposits', [
            'depositId' => $id,
            'amount'    => (string) $amount,
            'currency'  => $currency,
            'payer'     => [
                'type'           => 'MMO',
                'accountDetails' => ['phoneNumber' => $phone, 'provider' => $provider],
            ],
        ]);
        return $this->initiationResult($code, $res);
    }

    public function depositStatus(string $id): array
    {
        return $this->statusResult(...$this->request('GET', '/v2/deposits/' . rawurlencode($id)));
    }

    public function initiatePayout(string $id, int $amount, string $currency, string $phone, string $provider): array
    {
        [$code, $res] = $this->request('POST', '/v2/payouts', [
            'payoutId'  => $id,
            'amount'    => (string) $amount,
            'currency'  => $currency,
            'recipient' => [
                'type'           => 'MMO',
                'accountDetails' => ['phoneNumber' => $phone, 'provider' => $provider],
            ],
        ]);
        return $this->initiationResult($code, $res);
    }

    public function payoutStatus(string $id): array
    {
        return $this->statusResult(...$this->request('GET', '/v2/payouts/' . rawurlencode($id)));
    }

    private function initiationResult(int $code, ?array $res): array
    {
        $status = $res['status'] ?? null;
        if ($status === 'ACCEPTED' || $status === 'DUPLICATE_IGNORED') {
            return ['state' => 'accepted', 'message' => null];
        }
        if ($status === 'REJECTED') {
            return ['state' => 'rejected', 'message' => $res['failureReason']['failureMessage'] ?? 'Rejected by provider'];
        }
        // HTTP 500 / timeouts: the payment may still exist. Check its status later; never assume failure.
        return ['state' => 'unknown', 'message' => "HTTP $code"];
    }

    private function statusResult(int $code, ?array $res): array
    {
        if (($res['status'] ?? null) === 'NOT_FOUND') {
            return ['state' => 'not_found', 'provider_txn_id' => null, 'message' => 'Not found at pawaPay'];
        }
        $data = $res['data'] ?? null;
        if (($res['status'] ?? null) !== 'FOUND' || !is_array($data)) {
            return ['state' => 'pending', 'provider_txn_id' => null, 'message' => "Status check failed (HTTP $code)"];
        }
        $state = match ($data['status'] ?? '') {
            'COMPLETED' => 'completed',
            'FAILED'    => 'failed',
            default     => 'pending',      // ACCEPTED, PROCESSING, ENQUEUED, IN_RECONCILIATION...
        };
        return [
            'state'           => $state,
            'provider_txn_id' => $data['providerTransactionId'] ?? null,
            'message'         => $data['failureReason']['failureMessage'] ?? null,
        ];
    }

    /** @return array{0:int,1:?array} [http status, decoded JSON] */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        app_log('payments', "pawaPay $method $path -> HTTP $code", ['error' => $err ?: null]);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return [$code, is_array($decoded) ? $decoded : null];
    }
}
