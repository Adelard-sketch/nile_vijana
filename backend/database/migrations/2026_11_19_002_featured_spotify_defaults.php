<?php
declare(strict_types=1);

/*
 * Older databases never had the featured_spotify_embeds table, so the home page
 * showed no featured music (and logged an error on every visit). The first
 * migration creates the table; this one adds the same default embeds that
 * seed.sql uses, but only when the table is still empty. Edit them later in
 * the featured_spotify_embeds table.
 */

use App\Core\DB;
use App\Support\Migrator;

return static function (Migrator $m): void {
    if (!$m->hasTable('featured_spotify_embeds') || (int) DB::value('SELECT COUNT(*) FROM featured_spotify_embeds') > 0) {
        return;
    }
    foreach ([
        ['Featured song 1', 'track', '3xo7tCrLjBGrg1QbVUasFX', 1],
        ['Featured song 2', 'track', '4iJJOsuXC9Y3Xp3wrmfl24', 2],
        ['Featured playlist 1', 'playlist', '37i9dQZF1DXcBWIGoYBM5M', 1],
        ['Featured playlist 2', 'playlist', '37i9dQZF1DWYkaDif7Ztbp', 2],
    ] as [$title, $type, $id, $order]) {
        DB::insert('featured_spotify_embeds', ['title' => $title, 'embed_type' => $type, 'spotify_id' => $id, 'display_order' => $order]);
    }
    $m->log('+ 4 default featured Spotify embeds');
};
