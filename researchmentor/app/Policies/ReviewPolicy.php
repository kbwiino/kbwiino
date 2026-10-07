<?php
declare(strict_types=1);

namespace App\Policies;

/** AI reviews are private research content: members only, plus document-level checks. */
final class ReviewPolicy
{
    public function view(array $u, array $review): bool
    {
        $p = \App\Models\ResearchProject::findOrFail((int) $review['project_id']);
        if (!ProjectAccess::hasContentAccess($u, $p)) {
            return false;
        }
        if ($review['document_id']) {
            $doc = \App\Models\Document::find((int) $review['document_id']);
            if ($doc && !(new DocumentPolicy($p))->view($u, $doc)) {
                return false;
            }
        }
        return true;
    }

    /** Accept / modify / reject AI output: human supervisors only. */
    public function decide(array $u, array $review): bool
    {
        $p = \App\Models\ResearchProject::findOrFail((int) $review['project_id']);
        return ProjectAccess::has($u, $p, 'supervisor');
    }

    /** Students may mark findings as resolved/addressed, but not accept or reject them. */
    public function markAddressed(array $u, array $review): bool
    {
        $p = \App\Models\ResearchProject::findOrFail((int) $review['project_id']);
        return ProjectAccess::has($u, $p, 'student', 'supervisor');
    }
}
