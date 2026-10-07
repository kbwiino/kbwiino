<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiUsageLog;

/** Every provider call is logged (tokens, latency, outcome) - never prompts, documents or responses. */
final class AiUsageLogger
{
    public static function record(array $options, AIServiceInterface $svc, int $in, int $out, int $latencyMs, int $attempts, string $status, ?string $errorCode, ?string $requestId): void
    {
        try {
            AiUsageLog::create([
                'user_id' => $options['user_id'] ?? null,
                'project_id' => $options['project_id'] ?? null,
                'job_id' => $options['job_id'] ?? null,
                'purpose' => mb_substr((string) ($options['purpose'] ?? 'unspecified'), 0, 60),
                'provider' => $svc->provider(),
                'model' => mb_substr($svc->model(), 0, 80),
                'input_tokens' => $in, 'output_tokens' => $out, 'latency_ms' => $latencyMs,
                'attempts' => min(255, $attempts), 'status' => $status, 'error_code' => $errorCode,
                'request_id' => $requestId ? mb_substr($requestId, 0, 80) : null,
            ]);
        } catch (\Throwable $e) {
            \App\Support\Logger::error('Could not write AI usage log: ' . $e->getMessage());
        }
    }
}
