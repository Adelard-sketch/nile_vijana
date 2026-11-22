<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;

/**
 * One-time email codes for signing in (after the password is correct) and for
 * resetting a forgotten password.
 *
 * A "challenge" pairs a random ticket (kept by the browser, proves the password step
 * passed) with a 6-digit code (sent by email). Both are stored only as hashes.
 *  - a code expires after auth.otp_minutes and works once (used_at is set atomically)
 *  - auth.otp_max_attempts wrong codes lock that code; a new one can be requested
 *  - a new code can be sent every auth.otp_resend_seconds, at most auth.otp_max_sends times
 *  - the whole sign-in attempt (ticket) expires after 30 minutes
 */
final class SignInCodes
{
    public const TICKET_MINUTES = 30;

    /** Create a challenge and email the code. Returns the public fields for the response. */
    public static function start(array $user, string $purpose, string $ip): array
    {
        if (empty($user['email'])) {
            throw HttpException::invalid('This account has no email address, so we cannot send a sign-in code.', [
                'login' => 'Ask the Nile Vijana team to add an email address to your account.',
            ]);
        }
        $ticket = bin2hex(random_bytes(24));
        $code = self::newCode();
        $minutes = (int) config('auth.otp_minutes', 10);
        $id = DB::insert('auth_challenges', [
            'user_id'           => (int) $user['id'],
            'purpose'           => $purpose,
            'ticket_hash'       => hash('sha256', $ticket),
            'code_hash'         => self::codeHash($ticket, $code),
            'last_sent_at'      => now(),
            'code_expires_at'   => now($minutes * 60),
            'ticket_expires_at' => now(self::TICKET_MINUTES * 60),
            'ip'                => $ip,
        ]);
        if (!self::email($user, $code, $purpose)) {
            DB::run('DELETE FROM auth_challenges WHERE id = ?', [$id]);
            throw new HttpException(503, 'We could not send the code to your email right now. Please try again in a few minutes.');
        }
        return self::publicFields($ticket, (string) $user['email'], $code);
    }

    /** Send a fresh code for the same ticket (the old code stops working). */
    public static function resend(string $ticket, string $purpose): array
    {
        $c = self::find($ticket, $purpose);
        $wait = (int) config('auth.otp_resend_seconds', 60);
        $since = time() - strtotime($c['last_sent_at']);
        if ($since < $wait) {
            $retry = $wait - $since;
            throw new HttpException(429, "Please wait $retry seconds before asking for a new code.", ['retry_after' => (string) $retry]);
        }
        if ((int) $c['sends'] >= (int) config('auth.otp_max_sends', 5)) {
            throw new HttpException(429, 'Too many codes were sent for this sign-in. Please start again.', ['restart' => 'true']);
        }
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$c['user_id']]);
        $code = self::newCode();
        DB::update('auth_challenges', [
            'code_hash'       => self::codeHash($ticket, $code),
            'attempts'        => 0,
            'sends'           => (int) $c['sends'] + 1,
            'last_sent_at'    => now(),
            'code_expires_at' => now((int) config('auth.otp_minutes', 10) * 60),
        ], 'id = ?', [$c['id']]);
        if (!$user || !self::email($user, $code, $purpose)) {
            throw new HttpException(503, 'We could not send the code to your email right now. Please try again in a few minutes.');
        }
        return self::publicFields($ticket, (string) $user['email'], $code);
    }

    /**
     * Check a code. Returns the user row when it is right; the challenge can never be used again.
     * Wrong codes count towards the limit.
     */
    public static function verify(string $ticket, string $code, string $purpose): array
    {
        $c = self::find($ticket, $purpose);
        $max = (int) config('auth.otp_max_attempts', 5);
        if ((int) $c['attempts'] >= $max) {
            throw new HttpException(429, 'Too many wrong codes. Send a new code and try again.', ['code' => 'Too many wrong codes. Send a new code.']);
        }
        if (strtotime($c['code_expires_at']) < time()) {
            throw HttpException::invalid('This code has expired. Send a new code.', ['code' => 'This code has expired. Send a new code.']);
        }
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (!hash_equals($c['code_hash'], self::codeHash($ticket, $code))) {
            DB::run('UPDATE auth_challenges SET attempts = attempts + 1 WHERE id = ?', [$c['id']]);
            $left = $max - (int) $c['attempts'] - 1;
            $message = $left > 0
                ? "That code is not correct. You have $left " . ($left === 1 ? 'try' : 'tries') . ' left.'
                : 'That code is not correct. Send a new code and try again.';
            throw HttpException::invalid($message, ['code' => $message]);
        }
        // Mark used atomically: two requests with the same code cannot both succeed.
        $used = DB::run('UPDATE auth_challenges SET used_at = NOW() WHERE id = ? AND used_at IS NULL', [$c['id']])->rowCount();
        if ($used !== 1) {
            throw HttpException::invalid('This code was already used. Please sign in again.', ['code' => 'Already used']);
        }
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$c['user_id']]);
        if (!$user || $user['status'] !== 'active') {
            throw HttpException::forbidden('This account is suspended. Contact the Nile Vijana team.');
        }
        if (!$user['email_verified_at']) {
            DB::update('users', ['email_verified_at' => now()], 'id = ?', [$user['id']]);
        }
        return $user;
    }

    private static function find(string $ticket, string $purpose): array
    {
        $c = preg_match('/^[a-f0-9]{48}$/', $ticket)
            ? DB::one('SELECT * FROM auth_challenges WHERE ticket_hash = ? AND purpose = ?', [hash('sha256', $ticket), $purpose])
            : null;
        if (!$c || strtotime($c['ticket_expires_at']) < time()) {
            throw HttpException::invalid('This sign-in has expired. Please start again.', ['restart' => 'true']);
        }
        if ($c['used_at']) {
            throw HttpException::invalid('This code was already used. Please sign in again.', ['restart' => 'true']);
        }
        return $c;
    }

    private static function email(array $user, string $code, string $purpose): bool
    {
        $mail = EmailTemplates::signInCode((string) $user['name'], $code, (int) config('auth.otp_minutes', 10), $purpose);
        return Mailer::send((string) $user['email'], $mail['subject'], $mail['html']);
    }

    private static function publicFields(string $ticket, string $email, string $code): array
    {
        $out = [
            'otp_required' => true,
            'ticket'       => $ticket,
            'email'        => mask_email($email),
            'expires_in'   => (int) config('auth.otp_minutes', 10) * 60,
            'resend_in'    => (int) config('auth.otp_resend_seconds', 60),
        ];
        // Testing without a mailbox: only in development with the 'log' mail driver.
        if (config('app.env') === 'development' && !Mailer::delivers()) {
            $out['dev_code'] = $code;
        }
        return $out;
    }

    private static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function codeHash(string $ticket, string $code): string
    {
        return hash_hmac('sha256', hash('sha256', $ticket) . '|' . $code, (string) config('app.key'));
    }
}
