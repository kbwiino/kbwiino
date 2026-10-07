<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Services\{DocumentService, RateLimiter};

/** Retention enforcement: purge files of deleted documents past their retention date. */
final class PurgeExpiredJob
{
    public static function handle(array $payload = []): void
    {
        DocumentService::purgeExpired();
        RateLimiter::purgeOld();
    }
}
