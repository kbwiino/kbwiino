<?php
declare(strict_types=1);

namespace App\Agents;

/** Literature Review Agent: theoretical/empirical/methodological literature, synthesis, relevance, recency, context and citation placement. */
final class LiteratureReviewAgent extends AbstractReviewAgent
{
    public function type(): string { return 'literature'; }
    public function label(): string { return 'Literature review'; }
}
