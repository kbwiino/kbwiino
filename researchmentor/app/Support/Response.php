<?php
declare(strict_types=1);

namespace App\Support;

final class Response
{
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
        public ?string $filePath = null,
    ) {
    }

    public static function json(mixed $payload, int $status = 200, array $headers = []): self
    {
        return new self($status, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function ok(mixed $data = null, array $meta = [], int $status = 200): self
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta) {
            $payload['meta'] = $meta;
        }
        return self::json($payload, $status);
    }

    public static function error(int $status, string $code, string $message, array $details = []): self
    {
        $err = ['code' => $code, 'message' => $message];
        if ($details) {
            $err['details'] = $details;
        }
        return self::json(['success' => false, 'error' => $err], $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, '', ['Location' => $url]);
    }

    /** Stream a private file as a download; the path is never revealed to the client. */
    public static function download(string $path, string $downloadName, string $mime, bool $inline = false): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $downloadName) ?: 'download';
        return new self(200, '', [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $path);
    }

    public function withHeader(string $k, string $v): self
    {
        $this->headers[$k] = $v;
        return $this;
    }

    public function decoded(): mixed
    {
        return json_decode($this->body, true);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
