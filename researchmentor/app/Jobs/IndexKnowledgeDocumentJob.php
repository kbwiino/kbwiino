<?php
declare(strict_types=1);

namespace App\Jobs;

final class IndexKnowledgeDocumentJob
{
    public static function handle(array $payload): void
    {
        \App\Services\KnowledgeService::indexDocument((int) $payload['document_id']);
    }
}
