<?php
declare(strict_types=1);

/*
 * Upgrade an EXISTING database to the latest structure without losing data.
 *
 *   php bin/migrate.php            apply every migration not applied yet
 *   php bin/migrate.php --status   list migrations and whether they ran
 *
 * Windows / XAMPP:  C:\xampp\php\php.exe bin\migrate.php
 *
 * Migrations live in database/migrations/*.php and run in file-name order.
 * Each one is recorded in the schema_migrations table, so it runs only once.
 * A fresh install can instead import database/schema.sql (already up to date)
 * and then run this script, which will simply mark everything as applied.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\DB;
use App\Support\Migrator;

DB::pdo()->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        name       VARCHAR(190) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB'
);

$applied = array_column(DB::all('SELECT name, applied_at FROM schema_migrations'), 'applied_at', 'name');
$files = glob(BASE_PATH . '/database/migrations/*.php') ?: [];
sort($files);

if (in_array('--status', $argv, true)) {
    foreach ($files as $file) {
        $name = basename($file, '.php');
        printf("%-50s %s\n", $name, isset($applied[$name]) ? 'applied ' . $applied[$name] : 'PENDING');
    }
    exit(0);
}

$migrator = new Migrator();
$ran = 0;
foreach ($files as $file) {
    $name = basename($file, '.php');
    if (isset($applied[$name])) {
        continue;
    }
    echo "Running $name" . PHP_EOL;
    $migration = require $file;
    try {
        $migration($migrator);
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED $name: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    DB::insert('schema_migrations', ['name' => $name]);
    $ran++;
}
echo $ran ? "Done: $ran migration(s) applied." . PHP_EOL : 'Database is already up to date.' . PHP_EOL;
