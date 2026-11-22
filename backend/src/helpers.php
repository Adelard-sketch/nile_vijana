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

/** Random UUID v4 (used as the payment reference sent to Paystack). */
function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * Read an environment variable (set by the server, or by backend/.env).
 * "true"/"false" become booleans; an unset or empty variable gives $default.
 */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return match (strtolower((string) $value)) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        default => $value,
    };
}

/**
 * Load KEY=value lines from a .env file into the environment (only keys not already set).
 * Lines starting with # are comments; values may be wrapped in single or double quotes.
 */
function load_env_file(string $file, bool $overrideExisting = false): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if ($overrideExisting || (getenv($key) === false && !isset($_ENV[$key]))) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * Normalise a phone number to international digits only (MSISDN).
 * Ghana is the home market: local numbers (024 123 4567) become 233241234567.
 * Numbers typed with a country code (+233..., 00233..., +250...) are kept as they are.
 * Returns null when the number is not plausible.
 */
function normalize_phone(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    $cc = (string) config('app.calling_code', '233');
    if (strlen($digits) === 10 && $digits[0] === '0') {
        $digits = $cc . substr($digits, 1);              // 0241234567 -> 233241234567
    } elseif (strlen($digits) === 9 && $digits[0] !== '0') {
        $digits = $cc . $digits;                          // 241234567 -> 233241234567
    }
    return (strlen($digits) >= 11 && strlen($digits) <= 15) ? $digits : null;
}

/** Ghana mobile money networks (codes used by Paystack). */
const MOMO_NETWORKS = [
    'MTN' => 'MTN Mobile Money',
    'VOD' => 'Telecel Cash',
    'ATL' => 'AirtelTigo Money',
];

/**
 * Guess the mobile money network from a Ghana number.
 * MTN: 024, 025, 053, 054, 055, 059 · Telecel: 020, 050 · AirtelTigo: 026, 027, 056, 057
 */
function detect_provider(string $msisdn): ?string
{
    if (!str_starts_with($msisdn, '233') || strlen($msisdn) !== 12) {
        return null;
    }
    return match (substr($msisdn, 3, 2)) {
        '24', '25', '53', '54', '55', '59' => 'MTN',
        '20', '50'                         => 'VOD',
        '26', '27', '56', '57'             => 'ATL',
        default                            => null,
    };
}

/** "233241234567" -> "024 123 4567" for messages; other countries keep +digits. */
function format_phone(?string $msisdn): string
{
    if (!$msisdn) {
        return '';
    }
    if (str_starts_with($msisdn, '233') && strlen($msisdn) === 12) {
        $local = '0' . substr($msisdn, 3);
        return substr($local, 0, 3) . ' ' . substr($local, 3, 3) . ' ' . substr($local, 6);
    }
    return '+' . $msisdn;
}

/** 1500 -> "GH₵ 1,500" (amounts are whole Ghana cedis). */
function money_format_ghs(int|float $amount): string
{
    $symbol = (string) config('money.symbol', 'GH₵');
    $decimals = floor((float) $amount) == (float) $amount ? 0 : 2;
    return $symbol . ' ' . number_format((float) $amount, $decimals);
}

/**
 * Password strength used at sign-up and password changes.
 *  strong: at least 8 characters with an uppercase letter, a digit and a special character
 *  medium: at least 8 characters with an uppercase letter and a digit OR a special character
 *  weak:   anything else (refused)
 */
function password_strength(string $password): string
{
    $long    = mb_strlen($password) >= 8;
    $upper   = (bool) preg_match('/\p{Lu}/u', $password);
    $digit   = (bool) preg_match('/\d/', $password);
    $special = (bool) preg_match('/[^\p{L}\p{N}\s]/u', $password);
    if ($long && $upper && $digit && $special) {
        return 'strong';
    }
    if ($long && $upper && ($digit || $special)) {
        return 'medium';
    }
    return 'weak';
}

/** What a weak password is missing, in plain words. */
function password_problems(string $password): array
{
    $missing = [];
    if (mb_strlen($password) < 8) {
        $missing[] = 'at least 8 characters';
    }
    if (!preg_match('/\p{Lu}/u', $password)) {
        $missing[] = 'an uppercase letter';
    }
    if (!preg_match('/\d/', $password) && !preg_match('/[^\p{L}\p{N}\s]/u', $password)) {
        $missing[] = 'a number or a special character';
    }
    return $missing;
}

/** "kwame.mensah@gmail.com" -> "kw***h@gmail.com" (shown on the sign-in code screen). */
function mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $shown = mb_strlen($local) <= 2 ? mb_substr($local, 0, 1) : mb_substr($local, 0, 2);
    return $shown . '***' . (mb_strlen($local) > 3 ? mb_substr($local, -1) : '') . '@' . $domain;
}

/** Public address of the static website (used in emails and payment return links). */
function frontend_url(string $path = ''): string
{
    $base = (string) config('app.frontend_url', '');
    if ($base === '') {
        $base = preg_replace('#/backend/public/?$#', '/frontend', rtrim((string) config('app.base_url'), '/')) ?? '';
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
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
