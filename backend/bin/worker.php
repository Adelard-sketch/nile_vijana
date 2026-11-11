<?php
declare(strict_types=1);

/*
 * Background worker: audio processing, payment checks, expiring holds and orders.
 *
 *   php bin/worker.php          run once (use with cron / Windows Task Scheduler every minute)
 *   php bin/worker.php --loop   keep running, checking every 10 seconds (handy during development)
 *
 * Linux cron example:
 *   * * * * * cd /var/www/nile-vijana-api && php bin/worker.php >> storage/logs/worker.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Services\Maintenance;

$loop = in_array('--loop', $argv, true);

do {
    try {
        $stats = Maintenance::run();
        echo date('Y-m-d H:i:s') . ' ' . json_encode($stats) . PHP_EOL;
    } catch (Throwable $e) {
        app_log('errors', 'worker: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
        fwrite(STDERR, date('Y-m-d H:i:s') . ' ERROR ' . $e->getMessage() . PHP_EOL);
    }
    if ($loop) {
        sleep(10);
    }
} while ($loop);
