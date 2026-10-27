<?php
declare(strict_types=1);

namespace App\Agents;

/** Compares a document against the approved Research Constitution; every difference is a suggestion for human review. */
final class ConstitutionCheckAgent extends AbstractReviewAgent
{
    public function type(): string { return 'constitution_check'; }
    public function label(): string { return 'Constitution consistency check'; }
}
