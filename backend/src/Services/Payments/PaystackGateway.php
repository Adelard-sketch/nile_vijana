<?php
declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\DB;

/**
 * Paystack (https://paystack.com/docs) for Ghana: cards, MTN Mobile Money, Telecel Cash,
 * AirtelTigo Money and bank transfer, charged in GHS.
 *
 * Collections use Paystack's checkout:
 *   1. initiateDeposit()  POST /transaction/initialize -> authorization_url + access_code
 *   2. the browser opens the Paystack popup (or the checkout page) and the buyer pays there
 *   3. depositStatus()    GET /transaction/verify/{reference} - the ONLY thing that marks
 *      a payment as paid, and only when the amount and currency match what we asked for
 *   Webhooks (charge.success) just trigger step 3 again.
 *
 * Withdrawals use Paystack Transfers to mobile money (POST /transferrecipient, POST /transfer,
 * GET /transfer/verify/{reference}) when payments.paystack.transfers is on.
 *
 * Amounts in this app are whole cedis; Paystack takes pesewas (x 100).
 */
final class PaystackGateway implements PaymentGateway
{
    private string $baseUrl;
    private string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('payments.paystack.base_url', 'https://api.paystack.co'), '/');
        $this->secret = trim((string) config('payments.paystack.secret_key', ''));
    }

    public function name(): string
    {
        return 'paystack';
    }

    public static function configured(): bool
    {
        return trim((string) config('payments.paystack.secret_key', '')) !== '';
    }

    public static function testMode(): bool
    {
        return str_starts_with(trim((string) config('payments.paystack.secret_key', '')), 'sk_test_');
    }

    public function initiateDeposit(string $id, int $amount, string $currency, ?string $phone, ?string $provider, array $context = []): array
    {
        $email = (string) ($context['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['state' => 'rejected', 'message' => 'Add an email address to your account to pay with Paystack'];
        }
        $body = [
            'email'        => $email,
            'amount'       => $amount * 100,
            'currency'     => $currency,
            'reference'    => $id,
            'callback_url' => (string) ($context['callback_url'] ?? ''),
            'channels'     => array_values((array) config('payments.paystack.channels', ['card', 'mobile_money'])),
            'metadata'     => [
                'purpose'       => $context['purpose'] ?? null,
                'order_id'      => $context['order_id'] ?? null,
                'booking_id'    => $context['booking_id'] ?? null,
                'custom_fields' => [
                    ['display_name' => 'Paid for', 'variable_name' => 'description', 'value' => (string) ($context['description'] ?? 'Nile Vijana Creatives')],
                ],
            ],
        ];
        if ($phone) {
            $body['metadata']['phone'] = $phone;
        }
        if ($body['callback_url'] === '') {
            unset($body['callback_url']);
        }
        [$code, $res] = $this->request('POST', '/transaction/initialize', $body);
        if ($code >= 200 && $code < 300 && !empty($res['status']) && !empty($res['data']['authorization_url'])) {
            return [
                'state'        => 'accepted',
                'message'      => null,
                'checkout_url' => (string) $res['data']['authorization_url'],
                'access_code'  => (string) ($res['data']['access_code'] ?? ''),
            ];
        }
        if ($code === 0 || $code >= 500) {
            return ['state' => 'unknown', 'message' => 'Paystack could not be reached'];
        }
        return ['state' => 'rejected', 'message' => (string) ($res['message'] ?? 'Paystack refused the payment request')];
    }

    public function depositStatus(string $id): array
    {
        [$code, $res] = $this->request('GET', '/transaction/verify/' . rawurlencode($id));
        if ($code === 400 || $code === 404) {
            return ['state' => 'not_found', 'provider_txn_id' => null, 'message' => (string) ($res['message'] ?? 'Unknown reference')];
        }
        if ($code < 200 || $code >= 300 || empty($res['status']) || !is_array($res['data'] ?? null)) {
            throw new \RuntimeException('Paystack verify failed: HTTP ' . $code . ' ' . ($res['message'] ?? ''));
        }
        $data = $res['data'];
        $status = (string) ($data['status'] ?? '');
        $txn = isset($data['id']) ? (string) $data['id'] : null;

        if ($status === 'success') {
            // Never trust a success for a different amount or currency than we asked for.
            $expected = DB::one('SELECT amount, currency FROM payments WHERE public_id = ?', [$id]);
            $paid = (int) ($data['amount'] ?? 0);
            $currency = strtoupper((string) ($data['currency'] ?? ''));
            if (!$expected || $paid !== (int) $expected['amount'] * 100 || $currency !== strtoupper((string) $expected['currency'])) {
                app_log('payments', "paystack amount mismatch for $id", ['paid' => $paid, 'currency' => $currency, 'expected' => $expected]);
                return ['state' => 'failed', 'provider_txn_id' => $txn, 'message' => 'The amount paid does not match the order. Contact support with reference ' . $id];
            }
            if (!empty($data['channel'])) {
                DB::update('payments', ['channel' => mb_substr((string) $data['channel'], 0, 30)], 'public_id = ?', [$id]);
            }
            return ['state' => 'completed', 'provider_txn_id' => $txn, 'message' => null];
        }
        if (in_array($status, ['failed', 'reversed'], true)) {
            $reason = (string) ($data['gateway_response'] ?? '') ?: 'The payment was declined';
            return ['state' => 'failed', 'provider_txn_id' => $txn, 'message' => $reason];
        }
        // abandoned (checkout opened but not paid yet), ongoing, pending, processing, queued
        return ['state' => 'pending', 'provider_txn_id' => $txn, 'message' => $status];
    }

    public function initiatePayout(string $id, int $amount, string $currency, string $phone, string $provider, array $context = []): array
    {
        if (!filter_var(config('payments.paystack.transfers', false), FILTER_VALIDATE_BOOLEAN)) {
            return ['state' => 'manual', 'message' => 'Paystack Transfers are off: send the money yourself, then mark it as sent'];
        }
        $local = str_starts_with($phone, '233') ? '0' . substr($phone, 3) : $phone;
        [$code, $res] = $this->request('POST', '/transferrecipient', [
            'type'           => 'mobile_money',
            'name'           => (string) ($context['name'] ?? 'Nile Vijana creator'),
            'account_number' => $local,
            'bank_code'      => $provider,
            'currency'       => $currency,
        ]);
        $recipient = (string) ($res['data']['recipient_code'] ?? '');
        if ($code < 200 || $code >= 300 || $recipient === '') {
            return ['state' => $code === 0 || $code >= 500 ? 'unknown' : 'rejected', 'message' => (string) ($res['message'] ?? 'Paystack could not add this mobile money number')];
        }
        DB::update('payouts', ['recipient_code' => $recipient], 'public_id = ?', [$id]);

        [$code, $res] = $this->request('POST', '/transfer', [
            'source'    => 'balance',
            'amount'    => $amount * 100,
            'currency'  => $currency,
            'recipient' => $recipient,
            'reference' => $id,
            'reason'    => 'Nile Vijana Creatives earnings',
        ]);
        if ($code >= 200 && $code < 300 && !empty($res['status'])) {
            return ['state' => 'accepted', 'message' => (string) ($res['data']['status'] ?? '')];
        }
        return ['state' => $code === 0 || $code >= 500 ? 'unknown' : 'rejected', 'message' => (string) ($res['message'] ?? 'Paystack refused the transfer')];
    }

    public function payoutStatus(string $id): array
    {
        [$code, $res] = $this->request('GET', '/transfer/verify/' . rawurlencode($id));
        if ($code === 400 || $code === 404) {
            return ['state' => 'not_found', 'provider_txn_id' => null, 'message' => (string) ($res['message'] ?? 'Unknown reference')];
        }
        if ($code < 200 || $code >= 300 || empty($res['status'])) {
            throw new \RuntimeException('Paystack transfer verify failed: HTTP ' . $code);
        }
        $status = (string) ($res['data']['status'] ?? '');
        $txn = (string) ($res['data']['transfer_code'] ?? '') ?: null;
        return match ($status) {
            'success'                       => ['state' => 'completed', 'provider_txn_id' => $txn, 'message' => null],
            'failed', 'reversed', 'abandoned', 'blocked', 'rejected'
                                            => ['state' => 'failed', 'provider_txn_id' => $txn, 'message' => 'Paystack transfer ' . $status],
            'otp'                           => ['state' => 'pending', 'provider_txn_id' => $txn, 'message' => 'Approve this transfer with the OTP in your Paystack dashboard'],
            default                         => ['state' => 'pending', 'provider_txn_id' => $txn, 'message' => $status],
        };
    }

    /** Webhook check: HMAC-SHA512 of the raw body with the secret key. */
    public static function validSignature(string $rawBody, ?string $signature): bool
    {
        $secret = trim((string) config('payments.paystack.secret_key', ''));
        if ($secret === '' || !$signature) {
            return false;
        }
        return hash_equals(hash_hmac('sha512', $rawBody, $secret), strtolower(trim($signature)));
    }

    /** @return array{0:int, 1:array} HTTP status (0 = network error) and decoded JSON */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->secret === '') {
            throw new \RuntimeException('PAYSTACK_SECRET_KEY is not set in backend/.env');
        }
        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->secret,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            app_log('payments', "paystack $method $path network error: $error");
            return [0, []];
        }
        $decoded = json_decode((string) $raw, true);
        app_log('payments', "paystack $method $path -> $code", ['status' => $decoded['status'] ?? null, 'message' => $decoded['message'] ?? null]);
        return [$code, is_array($decoded) ? $decoded : []];
    }
}
