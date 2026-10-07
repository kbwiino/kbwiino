<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;

/** Fixed-window limiter backed by MySQL (works across PHP-FPM workers). */
final class RateLimiter
{
    /** Registers a hit; returns false when the limit has been exceeded. */
    public static function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        $bucket = substr($bucket, 0, 190);
        Database::execute(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$bucket, $window]
        );
        $hits = (int) Database::fetchValue('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$bucket, $window]);
        return $hits <= $limit;
    }

    public static function remaining(string $bucket, int $limit, int $windowSeconds): int
    {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        $hits = (int) Database::fetchValue('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [substr($bucket, 0, 190), $window]);
        return max(0, $limit - $hits);
    }

    public static function enforce(string $bucket, int $limit, int $windowSeconds, string $message = 'Rate limit exceeded. Please wait before trying again.'): void
    {
        if (!self::hit($bucket, $limit, $windowSeconds)) {
            throw HttpException::tooMany($message);
        }
    }

    public static function purgeOld(): int
    {
        return Database::execute('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);
    }
}
