<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Signed, expiring links (for paid downloads and licence PDFs).
 * The token carries its own data + expiry and an HMAC signature made
 * with app.key, so nobody can forge or extend a link.
 */
final class Signer
{
    public static function sign(array $payload, int $ttlSeconds): string
    {
        $payload['exp'] = time() + $ttlSeconds;
        $data = self::b64(json_encode($payload));
        return $data . '.' . self::b64(hash_hmac('sha256', $data, (string) config('app.key'), true));
    }

    /** Returns the payload, or null if the token is forged or expired. */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$data, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', $data, (string) config('app.key'), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payload = json_decode(self::unb64($data), true);
        if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            return null;
        }
        return $payload;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
