<?php
declare(strict_types=1);

namespace App\Agents;

use App\Services\AI\AIServiceInterface;

final class AiRunContext
{
    public function __construct(
        public array $user,
        public array $project,
        public ?array $document,
        public ?array $version,
        public AIServiceInterface $ai,
        public int $jobId = 0,
        public array $options = [],
    ) {
    }

    /** Provider options attached to every call (usage logging). */
    public function callOptions(string $purpose): array
    {
        return ['purpose' => $purpose, 'user_id' => $this->user['id'], 'project_id' => $this->project['id'], 'job_id' => $this->jobId ?: null];
    }
}
