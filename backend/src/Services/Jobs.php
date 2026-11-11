<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * A simple database-backed job queue (no Redis needed on XAMPP).
 * Web requests push jobs; bin/worker.php runs them.
 */
final class Jobs
{
    public static function push(string $type, array $payload, int $delaySeconds = 0): int
    {
        return DB::insert('jobs', [
            'type'      => $type,
            'payload'   => json_encode($payload),
            'run_after' => now($delaySeconds),
        ]);
    }

    /** Run up to $limit queued jobs. Returns how many ran. */
    public static function runQueued(int $limit = 10): int
    {
        $ran = 0;
        while ($ran < $limit) {
            $job = DB::tx(static function () {
                $job = DB::one("SELECT * FROM jobs WHERE status = 'queued' AND run_after <= NOW() ORDER BY id LIMIT 1 FOR UPDATE");
                if ($job) {
                    DB::update('jobs', ['status' => 'running', 'attempts' => $job['attempts'] + 1], 'id = ?', [$job['id']]);
                }
                return $job;
            });
            if (!$job) {
                break;
            }
            $ran++;
            try {
                self::handle($job['type'], json_decode($job['payload'], true) ?: []);
                DB::update('jobs', ['status' => 'done', 'last_error' => null], 'id = ?', [$job['id']]);
            } catch (\Throwable $e) {
                $retry = $job['attempts'] + 1 < 3;
                DB::update('jobs', [
                    'status'     => $retry ? 'queued' : 'failed',
                    'last_error' => mb_substr($e->getMessage(), 0, 2000),
                    'run_after'  => now(60),
                ], 'id = ?', [$job['id']]);
                app_log('jobs', "Job {$job['id']} ({$job['type']}) failed: " . $e->getMessage());
            }
        }
        return $ran;
    }

    private static function handle(string $type, array $p): void
    {
        match ($type) {
            'process_track' => Media::processTrack((int) $p['track_id']),
            'process_beat'  => Media::processBeat((int) $p['beat_id']),
            default         => throw new \RuntimeException("Unknown job type $type"),
        };
    }
}
