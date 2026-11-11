<?php
declare(strict_types=1);

/*
 * Small global helper functions used across the app.
 */

use App\Core\Config;

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Current local time as a MySQL DATETIME string. */
function now(int $offsetSeconds = 0): string
{
    return date('Y-m-d H:i:s', time() + $offsetSeconds);
}

/** Random UUID v4 (used as pawaPay depositId / payoutId). */
function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * Normalise a phone number to international digits only (MSISDN).
 * Rwandan local numbers (07XXXXXXXX) become 2507XXXXXXXX.
 * Returns null when the number is not plausible.
 */
function normalize_phone(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 10 && $digits[0] === '0') {
        $digits = '250' . substr($digits, 1);          // Rwanda by default
    } elseif (strlen($digits) === 9 && $digits[0] === '7') {
        $digits = '250' . $digits;
    }
    return (strlen($digits) >= 11 && strlen($digits) <= 15) ? $digits : null;
}

/**
 * Guess the mobile money provider from a Rwandan number.
 * MTN: 078, 079   Airtel: 072, 073
 */
function detect_provider(string $msisdn): ?string
{
    $prefix = substr($msisdn, 0, 5);
    return match ($prefix) {
        '25078', '25079' => 'MTN_MOMO_RWA',
        '25072', '25073' => 'AIRTEL_RWA',
        default          => null,
    };
}

function slugify(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text) ?? '', '-'));
    return $text !== '' ? $text : 'item';
}

/** Absolute URL for an API path, e.g. url('/api/beats/1/preview'). */
function url(string $path): string
{
    return rtrim((string) config('app.base_url'), '/') . $path;
}

/** Public URL for an uploaded image stored under public/uploads. */
function upload_url(?string $relative): ?string
{
    return $relative ? url('/uploads/' . $relative) : null;
}

/** Split an amount into [seller_net, platform_commission]. */
function split_commission(int $amount, float $rate): array
{
    $commission = (int) round($amount * $rate);
    return [$amount - $commission, $commission];
}

/** Append a line to storage/logs/<channel>.log */
function app_log(string $channel, string $message, array $context = []): void
{
    $line = '[' . date('c') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    @file_put_contents(BASE_PATH . '/storage/logs/' . $channel . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** Read ?page=&per_page= safely. Returns [page, perPage, offset]. */
function pagination(array $query, int $defaultPer = 20, int $maxPer = 50): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $per  = min($maxPer, max(1, (int) ($query['per_page'] ?? $defaultPer)));
    return [$page, $per, ($page - 1) * $per];
}
