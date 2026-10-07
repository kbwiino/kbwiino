<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Services\DocumentService;

final class ExtractDocumentJob
{
    public static function handle(array $payload): void
    {
        DocumentService::extractVersion((int) $payload['version_id']);
    }
}
