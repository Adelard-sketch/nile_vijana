<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Text messages (sign-in codes, booking confirmations, payout alerts).
 * Driver 'log' writes to storage/logs/sms.log; 'africastalking' sends real SMS.
 */
final class Sms
{
    public static function send(string $phone, string $message): bool
    {
        $driver = (string) config('sms.driver', 'log');
        if ($driver !== 'africastalking') {
            app_log('sms', "to +$phone: $message");
            return true;
        }

        $c = config('sms.africastalking');
        $endpoint = !empty($c['sandbox'])
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';
        $fields = ['username' => $c['username'], 'to' => '+' . $phone, 'message' => $message];
        if (!empty($c['from'])) {
            $fields['from'] = $c['from'];
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['apiKey: ' . $c['api_key'], 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = $code >= 200 && $code < 300;
        app_log('sms', ($ok ? 'sent' : 'FAILED') . " to +$phone", ['http' => $code, 'response' => is_string($body) ? mb_substr($body, 0, 300) : null]);
        return $ok;
    }
}
