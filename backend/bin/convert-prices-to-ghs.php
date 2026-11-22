<?php
declare(strict_types=1);

/*
 * One-off: convert amounts that were entered in Rwandan francs (RWF) into Ghana cedis (GH₵).
 *
 * The platform first ran with RWF prices (e.g. a beat lease of 10,000 and studio rooms at
 * 15,000 per hour). Those numbers are now shown as GH₵, which makes them far too high.
 * This script divides every stored amount by the exchange rate, rounds to whole cedis, and
 * keeps the wallet ledger consistent by converting every money table with the same rate.
 *
 *   php bin/convert-prices-to-ghs.php                 show what would change (changes nothing)
 *   php bin/convert-prices-to-ghs.php --rate=125      use 125 RWF = 1 GH₵ (check today's rate)
 *   php bin/convert-prices-to-ghs.php --apply         really convert (run ONCE, after a backup)
 *
 * Windows / XAMPP:  C:\xampp\php\php.exe bin\convert-prices-to-ghs.php
 * It refuses to run twice: the conversion is recorded in the schema_migrations table.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\DB;

$apply = in_array('--apply', $argv, true);
$rate = 125.0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--rate=')) {
        $rate = (float) substr($arg, 7);
    }
}
if ($rate <= 1) {
    exit("--rate must be the number of RWF in one cedi, e.g. --rate=125\n");
}

$marker = 'currency_rwf_to_ghs';
DB::pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
if (DB::value('SELECT applied_at FROM schema_migrations WHERE name = ?', [$marker])) {
    exit("Already converted on " . DB::value('SELECT applied_at FROM schema_migrations WHERE name = ?', [$marker]) . ". Nothing to do.\n");
}

// table => columns holding money
$columns = [
    'license_tiers'  => ['price'],
    'tracks'         => ['price'],
    'studio_rooms'   => ['hourly_rate'],
    'bookings'       => ['hourly_rate', 'total', 'deposit', 'amount_paid'],
    'orders'         => ['total'],
    'order_items'    => ['price', 'commission'],
    'payments'       => ['amount'],
    'payouts'        => ['amount'],
    'ledger_entries' => ['amount'],
];

printf("Rate: %s RWF = 1 GH₵ (%s)\n\n", rtrim(rtrim(number_format($rate, 2), '0'), '.'), $apply ? 'APPLYING' : 'dry run, nothing is changed');
$plan = [];
foreach ($columns as $table => $cols) {
    $exists = DB::value('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    if (!$exists) {
        continue;
    }
    foreach ($cols as $col) {
        $row = DB::one("SELECT COUNT(*) AS n, COALESCE(MIN(`$col`),0) AS lo, COALESCE(MAX(`$col`),0) AS hi FROM `$table` WHERE `$col` <> 0");
        printf("  %-16s %-12s %5d rows   %s .. %s  ->  %s .. %s\n", $table, $col, $row['n'],
            number_format((float) $row['lo']), number_format((float) $row['hi']),
            number_format(round($row['lo'] / $rate)), number_format(round($row['hi'] / $rate)));
        $plan[] = [$table, $col];
    }
}

if (!$apply) {
    echo "\nNothing was changed. Back up the database, then run again with --apply.\n";
    exit(0);
}

DB::tx(static function () use ($plan, $rate, $marker) {
    foreach ($plan as [$table, $col]) {
        // Prices never drop below 1 cedi; ledger rows keep their sign.
        $expr = $table === 'ledger_entries'
            ? "ROUND(`$col` / $rate)"
            : "CASE WHEN `$col` = 0 THEN 0 ELSE GREATEST(1, ROUND(`$col` / $rate)) END";
        DB::run("UPDATE `$table` SET `$col` = $expr");
    }
    // Keep each order's total equal to the sum of its items after rounding.
    DB::run('UPDATE orders o JOIN (SELECT order_id, SUM(price) s FROM order_items GROUP BY order_id) x ON x.order_id = o.id SET o.total = x.s');
    DB::run('INSERT INTO schema_migrations (name) VALUES (?)', [$marker]);
});
echo "\nDone. Amounts are now in Ghana cedis. Review a few prices in the dashboard.\n";
