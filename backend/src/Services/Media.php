<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Audio processing with FFmpeg (optional). Runs inside the background worker,
 * never during a web request, because converting audio takes a few seconds.
 *
 *  - Tracks: a low-bitrate stream file (saves fans' data), a 30-second preview, the duration.
 *  - Beats:  a short preview with a fade-out, plus an optional voice tag mixed in.
 */
final class Media
{
    public static function enabled(): bool
    {
        return (bool) config('media.ffmpeg_enabled', false);
    }

    public static function processTrack(int $trackId): void
    {
        $t = DB::one('SELECT * FROM tracks WHERE id = ?', [$trackId]);
        if (!$t) {
            return;
        }
        $src = Storage::privatePath($t['original_path']);
        $duration = self::duration($src);

        $stream = Storage::newPrivatePath('streams', 'mp3');
        self::ffmpeg(['-i', $src, '-vn', '-ac', '2', '-b:a', (string) config('media.stream_bitrate', '96k'), Storage::privatePath($stream)]);

        $preview = Storage::newPrivatePath('previews', 'mp3');
        $start = $duration && $duration > 60 ? (int) ($duration / 3) : 0;   // a slice from the middle
        self::ffmpeg(['-ss', (string) $start, '-t', '30', '-i', $src, '-vn', '-ac', '2', '-b:a', '64k',
            '-af', 'afade=t=in:d=1,afade=t=out:st=27:d=3', Storage::privatePath($preview)]);

        $status = $t['status'] === 'processing' ? self::afterProcessing() : $t['status'];
        DB::update('tracks', [
            'duration_sec' => $duration,
            'stream_path'  => $stream,
            'preview_path' => $preview,
            'status'       => $status,
            'released_at'  => ($status === 'live' && !$t['released_at']) ? now() : $t['released_at'],
        ], 'id = ?', [$trackId]);
    }

    public static function processBeat(int $beatId): void
    {
        $b = DB::one('SELECT * FROM beats WHERE id = ?', [$beatId]);
        if (!$b) {
            return;
        }
        $updates = [];
        if (!$b['preview_path']) {
            $src = Storage::privatePath($b['mp3_path']);
            $seconds = (int) config('media.preview_seconds', 60);
            $preview = Storage::newPrivatePath('previews', 'mp3');
            $out = Storage::privatePath($preview);
            $fadeStart = max(0, $seconds - 5);
            $tag = config('media.tag_file');

            if ($tag && is_file((string) $tag)) {
                // Mix the voice tag in 2 seconds after the start.
                self::ffmpeg(['-i', $src, '-i', (string) $tag, '-filter_complex',
                    "[0:a]atrim=0:$seconds,afade=t=out:st=$fadeStart:d=5[a];[1:a]adelay=2000|2000[t];[a][t]amix=inputs=2:duration=first:dropout_transition=0[out]",
                    '-map', '[out]', '-ac', '2', '-b:a', '96k', $out]);
            } else {
                self::ffmpeg(['-i', $src, '-t', (string) $seconds, '-vn', '-ac', '2', '-b:a', '96k',
                    '-af', "afade=t=out:st=$fadeStart:d=5", $out]);
            }
            $updates['preview_path'] = $preview;
        }
        if ($b['status'] === 'processing') {
            $updates['status'] = self::afterProcessing();
        }
        if ($updates) {
            DB::update('beats', $updates, 'id = ?', [$beatId]);
        }
    }

    /** Status an upload moves to once its files are ready. */
    public static function afterProcessing(): string
    {
        return config('moderation.auto_approve') ? 'live' : 'in_review';
    }

    public static function duration(string $file): ?int
    {
        $cmd = escapeshellarg((string) config('media.ffprobe', 'ffprobe'))
            . ' -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($file);
        $out = trim((string) shell_exec($cmd . ' 2>&1'));
        return is_numeric($out) ? (int) round((float) $out) : null;
    }

    private static function ffmpeg(array $args): void
    {
        $outPath = end($args);
        $dir = dirname($outPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $cmd = escapeshellarg((string) config('media.ffmpeg', 'ffmpeg')) . ' -y -loglevel error '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0 || !is_file($outPath)) {
            throw new \RuntimeException('FFmpeg failed: ' . implode(' ', array_slice($output, -3)));
        }
    }
}
