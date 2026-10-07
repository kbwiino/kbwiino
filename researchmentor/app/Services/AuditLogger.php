<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Request;

final class AuditLogger
{
    /** Record a sensitive operation. Metadata must never contain secrets or document text. */
    public static function log(?Request $request, string $action, ?string $entityType = null, ?string $entityUuid = null, array $metadata = [], ?array $actor = null): void
    {
        $actor ??= $request?->user();
        AuditLog::create([
            'user_id' => $actor['id'] ?? null,
            'institution_id' => $actor['institution_id'] ?? null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata ?: null,
        ]);
    }
}
