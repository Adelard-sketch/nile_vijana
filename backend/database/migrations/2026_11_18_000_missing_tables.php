<?php
declare(strict_types=1);

/*
 * Creates tables that were added to schema.sql after an older database was
 * imported (featured_spotify_embeds, studio_archive_states, opportunities,
 * mentor tables, manager contacts...). Existing tables are left untouched.
 */

use App\Support\Migrator;

return static function (Migrator $m): void {
    $m->createMissingTablesFromSchema(BASE_PATH . '/database/schema.sql');
};
