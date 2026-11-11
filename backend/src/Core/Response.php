<?php
declare(strict_types=1);

namespace App\Core;

/**
 * What a controller sends back. Controllers usually just return an array
 * (sent as JSON with status 200); use these helpers for anything else.
 */
final class Response
{
    private function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly mixed $body,         // string, or callable that echoes output
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'], $json);
    }

    public static function created(mixed $data): self
    {
        return self::json($data, 201);
    }

    public static function noContent(): self
    {
        return new self(204, [], '');
    }

    /** Raw bytes, e.g. a generated PDF. */
    public static function bytes(string $content, string $mime, ?string $filename = null, bool $inline = true): self
    {
        $headers = ['Content-Type' => $mime, 'Content-Length' => (string) strlen($content)];
        if ($filename) {
            $headers['Content-Disposition'] = ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"';
        }
        return new self(200, $headers, $content);
    }

    /**
     * Stream a file from disk. Supports HTTP Range requests so audio players
     * can seek without downloading the whole file (saves fans' data).
     */
    public static function file(string $path, string $mime, ?string $downloadName = null): self
    {
        if (!is_file($path)) {
            throw HttpException::notFound('File');
        }
        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if (preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            if ($m[1] === '' && $m[2] !== '') {            // last N bytes
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min((int) $m[2], $size - 1);
                }
            }
            if ($start > $end || $start >= $size) {
                return new self(416, ['Content-Range' => "bytes */$size"], '');
            }
            $status = 206;
        }

        $headers = [
            'Content-Type'   => $mime,
            'Accept-Ranges'  => 'bytes',
            'Content-Length' => (string) ($end - $start + 1),
            'Cache-Control'  => 'private, max-age=3600',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes $start-$end/$size";
        }
        if ($downloadName) {
            $headers['Content-Disposition'] = 'attachment; filename="' . str_replace('"', '', $downloadName) . '"';
        }

        $body = static function () use ($path, $start, $end): void {
            $fh = fopen($path, 'rb');
            fseek($fh, $start);
            $left = $end - $start + 1;
            while ($left > 0 && !feof($fh)) {
                $chunk = fread($fh, (int) min(8192, $left));
                echo $chunk;
                $left -= strlen($chunk);
                flush();
            }
            fclose($fh);
        };
        return new self($status, $headers, $body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if (is_callable($this->body)) {
            ($this->body)();
        } else {
            echo $this->body;
        }
    }

    public function status(): int
    {
        return $this->status;
    }
}
