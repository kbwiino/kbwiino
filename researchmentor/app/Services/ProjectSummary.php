<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{ResearchMilestone};

/** Builds the export of a project's research design (no document text, no AI content). */
final class ProjectSummary
{
    public static function build(array $project, array $user): array
    {
        $constitution = ConstitutionService::current($project);
        return [
            'generated_at' => gmdate('c'),
            'notice' => 'Project summary generated from stored project data. It is not an official academic record.',
            'project' => ProjectService::present($project, $user),
            'working_design' => ConstitutionService::workingSnapshot($project),
            'research_constitution' => $constitution ? ConstitutionService::present($constitution) : null,
            'milestones' => array_map(fn($m) => ['title' => $m['title'], 'due_date' => $m['due_date'], 'completed_at' => $m['completed_at']], ResearchMilestone::where(['project_id' => $project['id']], 'due_date ASC')),
            'progress' => ProjectService::progressSnapshot($project),
        ];
    }
}
