<?php
declare(strict_types=1);

namespace App\Jobs;

final class RunAiReviewJob
{
    public static function handle(array $payload): void
    {
        \App\Services\AiReviewService::runJob((int) $payload['job_id']);
    }
}
