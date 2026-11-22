<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;

/**
 * Account helpers shared by sign-up, admin onboarding and migrations:
 * usernames (one of the two sign-in identifiers, with email) and password rules.
 */
final class Accounts
{
    /** 3-30 characters: letters, numbers, dots and underscores, starting with a letter. */
    public const USERNAME_PATTERN = '/^[a-z][a-z0-9._]{2,29}$/';

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim(ltrim(trim($username), '@')));
    }

    /** Problem with a username in plain words, or null when it is fine. */
    public static function usernameProblem(string $username): ?string
    {
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            return 'Username must be 3 to 30 characters, start with a letter, and use only letters, numbers, dots or underscores.';
        }
        if (str_contains($username, '..') || str_ends_with($username, '.')) {
            return 'Username cannot end with a dot or have two dots in a row.';
        }
        if (in_array($username, ['admin', 'administrator', 'support', 'help', 'nilevijana', 'root', 'system'], true)
            && !DB::value('SELECT id FROM users WHERE username = ?', [$username])) {
            return 'That username is reserved. Please choose another.';
        }
        return null;
    }

    /** A free username built from an email or a name: "kwame.mensah", then "kwame.mensah2"... */
    public static function uniqueUsername(string $seed, ?int $exceptUserId = null): string
    {
        $base = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $seed) ?: $seed);
        $base = preg_replace('/@.*$/', '', $base) ?? $base;
        $base = preg_replace('/[^a-z0-9._]+/', '.', $base) ?? '';
        $base = trim(preg_replace('/\.{2,}/', '.', $base) ?? '', '._');
        if ($base === '' || !ctype_alpha($base[0])) {
            $base = 'user' . $base;
        }
        $base = substr($base, 0, 24);
        if (strlen($base) < 3) {
            $base .= 'nv';
        }
        $candidate = $base;
        for ($i = 2; $i < 10000; $i++) {
            $taken = DB::value('SELECT id FROM users WHERE username = ?' . ($exceptUserId ? ' AND id <> ' . (int) $exceptUserId : ''), [$candidate]);
            if (!$taken) {
                return $candidate;
            }
            $candidate = $base . $i;
        }
        return $base . bin2hex(random_bytes(3));
    }

    /**
     * Refuse weak passwords; returns 'medium' or 'strong'.
     * @param string $field the form field the error belongs to
     */
    public static function checkPassword(string $password, string $field = 'password'): string
    {
        if (mb_strlen($password) > 100) {
            throw HttpException::invalid('Your password is too long.', [$field => 'Password must be at most 100 characters.']);
        }
        $strength = password_strength($password);
        if ($strength === 'weak') {
            $missing = password_problems($password);
            throw HttpException::invalid('Choose a stronger password.', [
                $field => 'Password is too weak. Add ' . implode(', ', $missing) . '.',
            ]);
        }
        return $strength;
    }
}
