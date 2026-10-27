<?php
declare(strict_types=1);

namespace App\Agents;

/** Academic Writing Agent: feedback only; it does not rewrite the student's work. */
final class AcademicWritingAgent extends AbstractReviewAgent
{
    public function type(): string { return 'writing'; }
    public function label(): string { return 'Academic writing feedback'; }
}
