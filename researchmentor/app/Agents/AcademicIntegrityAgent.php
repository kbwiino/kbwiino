<?php
declare(strict_types=1);

namespace App\Agents;

/** Academic Integrity Agent: citation/attribution indicators. It does not perform a plagiarism check and never fabricates references. */
final class AcademicIntegrityAgent extends AbstractReviewAgent
{
    public function type(): string { return 'integrity'; }
    public function label(): string { return 'Academic integrity indicators'; }
}
