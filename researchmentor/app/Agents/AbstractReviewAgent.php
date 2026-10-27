<?php
declare(strict_types=1);

namespace App\Agents;

abstract class AbstractReviewAgent implements ReviewAgent
{
    public function needsDocument(): bool { return true; }

    public function variables(AiRunContext $ctx, string $documentText): array { return []; }

    public function afterSave(AiRunContext $ctx, array $reviewRow, array $review): void {}
}
