<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\BackgroundJob;
use App\Support\{Config, Database, Logger};

/**
 * Dispatches background work. QUEUE_DRIVER=sync runs the job inline (dev/small installs);
 * QUEUE_DRIVER=database stores it for bin/worker.php (production).
 */
final class QueueDispatcher
{
    public const HANDLERS = [
        'extract_document' => \App\Jobs\ExtractDocumentJob::class,
        'ai_review' => \App\Jobs\RunAiReviewJob::class,
        'index_knowledge_document' => \App\Jobs\IndexKnowledgeDocumentJob::class,
        'purge_expired' => \App\Jobs\PurgeExpiredJob::class,
    ];

    /** @return int|null background job id when queued, null when run inline */
    public static function dispatch(string $type, array $payload): ?int
    {
        if (!isset(self::HANDLERS[$type])) {
            throw new \InvalidArgumentException("Unknown job type {$type}");
        }
        if (Config::get('app.queue_driver') === 'sync') {
            try {
                self::run($type, $payload);
            } catch (\Throwable $e) {
                Logger::error("Inline job {$type} failed: " . $e->getMessage());
            }
            return null;
        }
        return BackgroundJob::create(['job_type' => $type, 'payload' => $payload]);
    }

    public static function run(string $type, array $payload): void
    {
        $class = self::HANDLERS[$type] ?? throw new \InvalidArgumentException("Unknown job type {$type}");
        $class::handle($payload);
    }

    /** Claim and run one pending job. Returns false when the queue is empty. */
    public static function work(): bool
    {
        $job = Database::transaction(function () {
            $row = Database::fetchOne("SELECT * FROM background_jobs WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP() ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");
            if (!$row) {
                return null;
            }
            Database::execute("UPDATE background_jobs SET status = 'running', reserved_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE id = ?", [$row['id']]);
            return $row;
        });
        if (!$job) {
            return false;
        }
        try {
            self::run($job['job_type'], json_decode($job['payload'], true) ?: []);
            Database::execute("UPDATE background_jobs SET status = 'completed', finished_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?", [$job['id']]);
        } catch (\Throwable $e) {
            $attempts = (int) $job['attempts'] + 1;
            $final = $attempts >= (int) $job['max_attempts'];
            Logger::error("Job {$job['job_type']}#{$job['id']} failed (attempt {$attempts}): " . $e->getMessage());
            Database::execute(
                'UPDATE background_jobs SET status = ?, last_error = ?, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), finished_at = ? WHERE id = ?',
                [$final ? 'failed' : 'pending', mb_substr($e->getMessage(), 0, 900), 30 * $attempts * $attempts, $final ? gmdate('Y-m-d H:i:s') : null, $job['id']]
            );
        }
        return true;
    }
}
