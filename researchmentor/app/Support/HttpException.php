<?php
declare(strict_types=1);

namespace App\Support;

class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(string $what = 'Resource'): self { return new self(404, 'not_found', "{$what} not found."); }
    public static function forbidden(string $msg = 'You are not permitted to perform this action.'): self { return new self(403, 'forbidden', $msg); }
    public static function unauthenticated(): self { return new self(401, 'unauthenticated', 'Authentication required.'); }
    public static function badRequest(string $msg, array $details = []): self { return new self(400, 'bad_request', $msg, $details); }
    public static function conflict(string $msg): self { return new self(409, 'conflict', $msg); }
    public static function tooMany(string $msg = 'Too many requests. Please try again later.'): self { return new self(429, 'rate_limited', $msg); }
    public static function unprocessable(string $msg, array $details = []): self { return new self(422, 'validation_failed', $msg, $details); }
}
