<?php
declare(strict_types=1);

/*
 * Q&A: questions sent from the website (signed in or not) and the team's answers.
 * status: open (new) -> answered (reply emailed to the person) -> closed
 */

use App\Support\Migrator;

return static function (Migrator $m): void {
    $m->createTable('questions', "CREATE TABLE questions (
        id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reference    VARCHAR(20) NOT NULL UNIQUE,
        user_id      BIGINT UNSIGNED NULL,
        name         VARCHAR(120) NOT NULL,
        email        VARCHAR(190) NOT NULL,
        phone        VARCHAR(20) NULL,
        category     ENUM('general','account','payments','music','beats','studios','creators','other') NOT NULL DEFAULT 'general',
        subject      VARCHAR(160) NOT NULL,
        body         TEXT NOT NULL,
        status       ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
        answer       TEXT NULL,
        answered_by  BIGINT UNSIGNED NULL,
        answered_at  DATETIME NULL,
        is_public    TINYINT(1) NOT NULL DEFAULT 0,
        ip           VARCHAR(45) NULL,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_questions_status (status, created_at),
        KEY idx_questions_user (user_id),
        KEY idx_questions_public (is_public, answered_at),
        CONSTRAINT fk_questions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_questions_answered_by FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
};
