<?php
declare(strict_types=1);

namespace App\Agents;

/** Proposal Review Agent: background, problem, purpose, objectives, questions, hypotheses, scope, significance and alignment. */
final class ProposalReviewAgent extends AbstractReviewAgent
{
    public function type(): string { return 'proposal'; }
    public function label(): string { return 'Proposal review'; }
}
