<?php
/**
 * PaystackClient.php — Simple Paystack API client.
 * Requires PAYSTACK_SECRET_KEY and PAYSTACK_BASE_URL to be defined (via core.php).
 *
 * Usage:
 *   $client = new PaystackClient();
 *
 *   // Start a payment (returns authorization_url to redirect the buyer)
 *   $result = $client->initializeTransaction([
 *       'email'        => 'buyer@example.com',
 *       'amount'       => 5000,          // in pesewas (GH₵ 50.00)
 *       'currency'     => 'GHS',
 *       'reference'    => 'unique-ref',
 *       'callback_url' => 'https://yoursite.com/payment-return',
 *   ]);
 *
 *   // Verify after the buyer returns
 *   $result = $client->verifyTransaction('unique-ref');
 */

declare(strict_types=1);

class PaystackClient
{
    private string $secretKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '';
        $this->baseUrl   = defined('PAYSTACK_BASE_URL')   ? rtrim(PAYSTACK_BASE_URL, '/') : 'https://api.paystack.co';
    }

    /**
     * POST /transaction/initialize
     * Returns ['status' => true, 'data' => ['authorization_url' => ..., 'reference' => ...]]
     */
    public function initializeTransaction(array $payload): array
    {
        return $this->request('POST', '/transaction/initialize', $payload);
    }

    /**
     * GET /transaction/verify/{reference}
     * Returns ['status' => true, 'data' => ['status' => 'success', 'amount' => ..., ...]]
     */
    public function verifyTransaction(string $reference): array
    {
        return $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
    }

    /**
     * POST /transferrecipient — register a mobile money number for a payout.
     */
    public function createTransferRecipient(array $payload): array
    {
        return $this->request('POST', '/transferrecipient', $payload);
    }

    /**
     * POST /transfer — send money to a transfer recipient.
     */
    public function initiateTransfer(array $payload): array
    {
        return $this->request('POST', '/transfer', $payload);
    }

    /**
     * GET /transfer/verify/{reference}
     */
    public function verifyTransfer(string $reference): array
    {
        return $this->request('GET', '/transfer/verify/' . rawurlencode($reference));
    }

    /**
     * Verify a Paystack webhook signature.
     * Pass the raw request body and the X-Paystack-Signature header value.
     */
    public function validWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if ($this->secretKey === '' || $signature === null) {
            return false;
        }
        return hash_equals(
            hash_hmac('sha512', $rawBody, $this->secretKey),
            strtolower(trim($signature))
        );
    }

    /**
     * Make an authenticated request to the Paystack API.
     * Returns the decoded JSON as an array, or ['status' => false, 'message' => '...'] on error.
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->secretKey === '') {
            return ['status' => false, 'message' => 'PAYSTACK_SECRET_KEY is not defined'];
        }

        $ch = curl_init($this->baseUrl . $path);

        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);

        $raw   = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => false, 'message' => 'Network error: ' . $error];
        }

        $decoded = json_decode((string) $raw, true);

        if (!is_array($decoded)) {
            return ['status' => false, 'message' => 'Invalid response from Paystack (HTTP ' . $code . ')'];
        }

        return $decoded;
    }
}
