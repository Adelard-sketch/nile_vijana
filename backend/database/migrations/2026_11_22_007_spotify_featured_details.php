<?php
declare(strict_types=1);

/*
 * Featured Spotify music shown like the platform's own music: cover art and artist
 * name (filled from Spotify's public oEmbed when an admin adds an item), and albums
 * as well as songs and playlists.
 */

use App\Support\Migrator;

return static function (Migrator $m): void {
    $m->extendEnum('featured_spotify_embeds', 'embed_type', ['track', 'playlist', 'album']);
    $m->addColumn('featured_spotify_embeds', 'subtitle', 'VARCHAR(160) NULL AFTER title');
    $m->addColumn('featured_spotify_embeds', 'cover_url', 'VARCHAR(500) NULL AFTER spotify_id');
};
