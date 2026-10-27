<?php
declare(strict_types=1);

namespace App\Agents;

/** Research Gap Agent: possible gaps only - never presented as established; confidence is capped and verification questions are required. */
final class ResearchGapAgent extends AbstractReviewAgent
{
    public function type(): string { return 'gap'; }
    public function label(): string { return 'Research gap analysis'; }
}
