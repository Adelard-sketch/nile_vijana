<?php
declare(strict_types=1);

/*
 * Dashboards, unified onboarding and approval workflow.
 *  - New account types: choir, manager, vocal_coach (roles + creator profile types)
 *  - Extra profile fields used by the onboarding form
 *  - manager_artists: which artists/choirs a manager looks after
 *  - Songs and beats: description, release date, review note (why it was rejected), who reviewed it and when
 *  - Studios: review note
 *  - Bookings: 'pending' (deposit paid, studio must accept) and 'rejected' (studio declined, deposit refunded)
 */

use App\Core\DB;
use App\Support\Migrator;

return static function (Migrator $m): void {
    $m->extendEnum('user_roles', 'role', ['fan', 'artist', 'producer', 'studio_owner', 'admin', 'choir', 'manager', 'vocal_coach']);
    $m->extendEnum('creator_profiles', 'type', ['artist', 'producer', 'both', 'choir', 'manager', 'vocal_coach']);

    $m->addColumn('creator_profiles', 'genres', 'VARCHAR(255) NULL');
    $m->addColumn('creator_profiles', 'contact_email', 'VARCHAR(190) NULL');
    $m->addColumn('creator_profiles', 'contact_phone', 'VARCHAR(20) NULL');
    $m->addColumn('creator_profiles', 'website', 'VARCHAR(255) NULL');
    $m->addColumn('creator_profiles', 'members_count', 'SMALLINT UNSIGNED NULL');
    $m->addColumn('creator_profiles', 'experience_years', 'TINYINT UNSIGNED NULL');
    $m->addColumn('creator_profiles', 'specialization', 'VARCHAR(255) NULL');
    $m->addColumn('creator_profiles', 'company', 'VARCHAR(120) NULL');
    $m->addColumn('creator_profiles', 'onboarded_by', 'BIGINT UNSIGNED NULL');

    $m->createTable('manager_artists', "CREATE TABLE manager_artists (
        manager_id BIGINT UNSIGNED NOT NULL,
        artist_id  BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (manager_id, artist_id),
        KEY idx_manager_artists_artist (artist_id),
        CONSTRAINT fk_manager_artists_manager FOREIGN KEY (manager_id) REFERENCES creator_profiles(user_id) ON DELETE CASCADE,
        CONSTRAINT fk_manager_artists_artist FOREIGN KEY (artist_id) REFERENCES creator_profiles(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    foreach (['tracks', 'beats'] as $table) {
        $m->addColumn($table, 'description', 'TEXT NULL');
        $m->addColumn($table, 'release_date', 'DATE NULL');
        $m->addColumn($table, 'review_note', 'VARCHAR(500) NULL');
        $m->addColumn($table, 'reviewed_by', 'BIGINT UNSIGNED NULL');
        $m->addColumn($table, 'reviewed_at', 'DATETIME NULL');
        $m->addColumn($table, 'submitted_at', 'DATETIME NULL');
    }
    // Existing uploads count as submitted when they were created.
    DB::run('UPDATE tracks SET submitted_at = created_at WHERE submitted_at IS NULL');
    DB::run('UPDATE beats SET submitted_at = created_at WHERE submitted_at IS NULL');

    $m->addColumn('studios', 'review_note', 'VARCHAR(500) NULL');
    $m->addColumn('studios', 'reviewed_by', 'BIGINT UNSIGNED NULL');
    $m->addColumn('studios', 'reviewed_at', 'DATETIME NULL');

    $m->extendEnum('bookings', 'status', ['held', 'pending', 'confirmed', 'completed', 'cancelled', 'no_show', 'expired', 'rejected']);
    $m->addColumn('bookings', 'studio_note', 'VARCHAR(500) NULL');
    $m->addColumn('bookings', 'responded_at', 'DATETIME NULL');
};
