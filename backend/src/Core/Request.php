<?php
declare(strict_types=1);

namespace App\Core;

/** The incoming HTTP request: method, path, query, JSON/form body, files, user. */
final class Request
{
    public array $params = [];      // route parameters, e.g. {id}
    public ?array $user = null;     // logged-in user (set by the router)

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files,
        private readonly array $server
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        }

        // Works whether the app lives at the web root or in a sub-folder
        // (e.g. http://localhost/nile-vijana-api/api/beats): keep everything from "/api".
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $pos = strpos($path, '/api/');
        if ($pos !== false) {
            $path = substr($path, $pos);
        } elseif (str_ends_with($path, '/api')) {
            $path = '/api';
        }
        $path = '/' . trim($path, '/');

        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        // PHP silently drops the whole form when it is bigger than post_max_size; say so
        // instead of reporting every field as missing.
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > 0 && !$_POST && !$_FILES && str_contains($contentType, 'multipart/form-data')) {
            $limit = self::iniBytes((string) ini_get('post_max_size'));
            throw new HttpException(413, sprintf(
                'The files are too large to upload together (%s MB). The server accepts up to %s MB per upload: use smaller files, or raise post_max_size and upload_max_filesize in php.ini.',
                number_format($length / 1048576, 1),
                number_format($limit / 1048576)
            ), ['files' => 'The files are larger than the server allows.']);
        }
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new HttpException(400, 'Request body is not valid JSON');
            }
            $body = $decoded;
        }

        return new self($method, $path, $_GET, $body, $_FILES, $_SERVER);
    }

    /** "64M" -> 67108864 */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function param(string $name): string
    {
        return (string) ($this->params[$name] ?? '');
    }

    public function id(string $name = 'id'): int
    {
        return (int) ($this->params[$name] ?? 0);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    public function bearerToken(): ?string
    {
        // Apache sometimes moves the header; .htaccess restores it.
        $h = $this->server['HTTP_AUTHORIZATION'] ?? $this->server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($h), $m)) {
            return $m[1];
        }
        return null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** An uploaded file, or null when the field was left empty. */
    public function file(string $name): ?array
    {
        $f = $this->files[$name] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    /** The logged-in user's id (only call on routes that require login). */
    public function userId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }
}
