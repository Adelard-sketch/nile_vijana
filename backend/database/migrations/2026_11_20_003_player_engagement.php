<?php
declare(strict_types=1);

/*
 * Listener actions from the new player:
 *  - saved_tracks:    songs a listener saved to their library (separate from favourites, which are `likes`)
 *  - track_shares:    each time a song is shared (link copied, WhatsApp, X, Facebook...)
 *  - track_downloads: each download of a song file (after a purchase, or a free download)
 *  - tracks.allow_download: the artist lets signed-in listeners download a free song
 * Counts shown on the dashboards are computed from these rows.
 */

use App\Support\Migrator;

return static function (Migrator $m): void {
    $m->createTable('saved_tracks', "CREATE TABLE saved_tracks (
        user_id    BIGINT UNSIGNED NOT NULL,
        track_id   BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, track_id),
        KEY idx_saved_tracks_track (track_id),
        CONSTRAINT fk_saved_tracks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_saved_tracks_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $m->createTable('track_shares', "CREATE TABLE track_shares (
        id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        track_id   BIGINT UNSIGNED NOT NULL,
        user_id    BIGINT UNSIGNED NULL,
        session_id VARCHAR(64) NOT NULL,
        channel    VARCHAR(20) NOT NULL DEFAULT 'link',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_track_shares_track (track_id, created_at),
        CONSTRAINT fk_track_shares_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $m->createTable('track_downloads', "CREATE TABLE track_downloads (
        id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        track_id   BIGINT UNSIGNED NOT NULL,
        user_id    BIGINT UNSIGNED NOT NULL,
        source     ENUM('purchase','free') NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_track_downloads_track (track_id, created_at),
        CONSTRAINT fk_track_downloads_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE,
        CONSTRAINT fk_track_downloads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $m->addColumn('tracks', 'allow_download', 'TINYINT(1) NOT NULL DEFAULT 0');
};
