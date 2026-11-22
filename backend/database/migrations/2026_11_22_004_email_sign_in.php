<?php
declare(strict_types=1);

/*
 * Sign-in by email or username with a one-time code sent by email.
 *  - users.username: the second sign-in identifier (phone numbers are no longer used to sign in).
 *    Existing accounts get one built from their email or name, e.g. "kwame.mensah".
 *  - users.email_verified_at: set the first time a sign-in code sent to the email is used.
 *  - auth_challenges: one row per password check that passed and is waiting for its email code
 *    (and per "forgot password" request). Codes and tickets are stored only as hashes.
 *  - login_attempts: failed and successful password checks, for brute-force limits.
 */

use App\Core\DB;
use App\Services\Accounts;
use App\Support\Migrator;

return static function (Migrator $m): void {
    $m->addColumn('users', 'username', 'VARCHAR(40) NULL AFTER name');
    $m->addColumn('users', 'email_verified_at', 'DATETIME NULL AFTER email');

    $missing = DB::all('SELECT id, name, email FROM users WHERE username IS NULL OR username = "" ORDER BY id');
    foreach ($missing as $u) {
        $username = Accounts::uniqueUsername((string) ($u['email'] ?: $u['name']), (int) $u['id']);
        DB::update('users', ['username' => $username], 'id = ?', [$u['id']]);
    }
    if ($missing) {
        $m->log('~ usernames created for ' . count($missing) . ' existing account(s)');
    }
    $hasIndex = DB::value(
        "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_username'"
    );
    if (!$hasIndex) {
        DB::pdo()->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_username (username)');
        $m->log('+ users.username unique');
    }
    DB::pdo()->exec("ALTER TABLE users MODIFY country CHAR(2) NOT NULL DEFAULT 'GH'");

    $m->createTable('auth_challenges', "CREATE TABLE auth_challenges (
        id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id           BIGINT UNSIGNED NOT NULL,
        purpose           ENUM('login','reset') NOT NULL DEFAULT 'login',
        ticket_hash       CHAR(64) NOT NULL UNIQUE,
        code_hash         CHAR(64) NOT NULL,
        attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
        sends             TINYINT UNSIGNED NOT NULL DEFAULT 1,
        last_sent_at      DATETIME NOT NULL,
        code_expires_at   DATETIME NOT NULL,
        ticket_expires_at DATETIME NOT NULL,
        used_at           DATETIME NULL,
        ip                VARCHAR(45) NULL,
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_challenges_user (user_id, created_at),
        CONSTRAINT fk_challenges_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $m->createTable('login_attempts', "CREATE TABLE login_attempts (
        id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(190) NOT NULL,
        ip         VARCHAR(45) NULL,
        success    TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_attempts_identifier (identifier, created_at),
        KEY idx_attempts_ip (ip, created_at)
    ) ENGINE=InnoDB");
};
