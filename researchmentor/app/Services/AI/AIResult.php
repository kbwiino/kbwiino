<?php
declare(strict_types=1);

namespace App\Services\AI;

final class AIResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?array $json = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly string $model = '',
        public readonly ?string $requestId = null,
        public readonly string $stopReason = 'end_turn',
        public readonly int $latencyMs = 0,
        public readonly int $attempts = 1,
        public readonly bool $mock = false,
    ) {
    }
}
