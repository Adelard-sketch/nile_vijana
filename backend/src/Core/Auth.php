<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Token authentication.
 * After sign-in the client receives a random token and sends it on every request:
 *   Authorization: Bearer <token>
 * Only a SHA-256 hash of the token is stored, so a leaked database
 * does not leak working tokens.
 */
final class Auth
{
    public static function issueToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        DB::insert('auth_tokens', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now(86400 * (int) config('app.token_days', 30)),
        ]);
        return $token;
    }

    public static function revoke(string $token): void
    {
        DB::run('DELETE FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
    }

    public static function resolve(Request $req): ?array
    {
        $token = $req->bearerToken();
        if ($token === null) {
            return null;
        }
        $user = DB::one(
            "SELECT u.id, u.name, u.phone, u.email, u.country, u.language, u.status, u.created_at,
                    t.id AS token_id, t.last_used_at
               FROM auth_tokens t
               JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.status = 'active'",
            [hash('sha256', $token)]
        );
        if ($user === null) {
            return null;
        }
        // Record activity at most once every 10 minutes to avoid a write on every request.
        if ($user['last_used_at'] === null || strtotime($user['last_used_at']) < time() - 600) {
            DB::run('UPDATE auth_tokens SET last_used_at = NOW() WHERE id = ?', [$user['token_id']]);
        }
        unset($user['token_id'], $user['last_used_at']);
        $user['roles'] = self::roles((int) $user['id']);
        return $user;
    }

    public static function roles(int $userId): array
    {
        return array_column(DB::all('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role', [$userId]), 'role');
    }

    public static function addRole(int $userId, string $role): void
    {
        DB::run('INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, ?)', [$userId, $role]);
    }

    /** True if the user has any of the roles. Admins pass every role check. */
    public static function hasRole(?array $user, string ...$roles): bool
    {
        if (!$user) {
            return false;
        }
        $mine = $user['roles'] ?? [];
        return in_array('admin', $mine, true) || (bool) array_intersect($roles, $mine);
    }

    public static function isAdmin(?array $user): bool
    {
        return $user !== null && in_array('admin', $user['roles'] ?? [], true);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }
}
