<?php
declare(strict_types=1);

namespace App\Services\AI;

/** Provider/transport failure. The message is safe to show to users (never contains secrets). */
class AIException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        public readonly int $attempts = 1,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
