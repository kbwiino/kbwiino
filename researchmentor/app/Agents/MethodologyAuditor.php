<?php
declare(strict_types=1);

namespace App\Agents;

/** Methodology Auditor: checks internal consistency against the stated design and institutional guidelines. */
final class MethodologyAuditor extends AbstractReviewAgent
{
    public function type(): string { return 'methodology'; }
    public function label(): string { return 'Methodology audit'; }
}
