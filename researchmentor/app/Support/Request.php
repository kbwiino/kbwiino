<?php
declare(strict_types=1);

namespace App\Support;

final class Request
{
    public array $params = [];      // route parameters
    public array $attributes = [];  // middleware-populated

    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        public array $headers = [],
        public array $files = [],
        public array $server = [],
        public ?Session $session = null,
    ) {
        $this->method = strtoupper($method);
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public static function capture(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($k, 5)))] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        $body = $_POST;
        $ct = strtolower($headers['content-type'] ?? '');
        if (str_contains($ct, 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);
            $decoded = json_decode($raw ?: '', true);
            $body = is_array($decoded) ? $decoded : [];
        }
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', rtrim($path, '/') ?: '/', $_GET, $body, $headers, $_FILES, $_SERVER);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/') || $this->path === '/api';
    }

    public function wantsJson(): bool
    {
        return $this->isApi() || str_contains(strtolower($this->header('accept', '')), 'application/json');
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->headers['user-agent'] ?? ''), 0, 255);
    }

    public function user(): ?array
    {
        return $this->attributes['user'] ?? null;
    }

    public function file(string $key): ?UploadedFile
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['tmp_name']) || is_array($f['tmp_name'])) {
            return null;
        }
        return new UploadedFile(
            (string) $f['tmp_name'],
            (string) ($f['name'] ?? ''),
            (int) ($f['size'] ?? 0),
            (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE),
            (bool) ($f['_test'] ?? false)
        );
    }
}
