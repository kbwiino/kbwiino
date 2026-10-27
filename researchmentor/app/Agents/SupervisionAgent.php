<?php
declare(strict_types=1);

namespace App\Agents;

use App\Services\AI\{AIServiceInterface, PromptManager, Schemas};
use App\Support\Database;

/**
 * Supervision Agent: drafts a meeting brief (agenda, follow-up questions, unresolved issues, suggested actions)
 * from recorded project activity. Output is a SUGGESTION for the supervisor - it never becomes an official
 * decision or an active task without human acceptance.
 */
final class SupervisionAgent
{
    public static function activity(array $project): string
    {
        $progress = ProgressAgent::report($project);
        $unres = ResearchProjectAgent::unresolvedIssues($project);
        $docs = Database::fetchAll(
            'SELECT d.title, d.category, v.version_number, v.created_at FROM document_versions v JOIN documents d ON d.id = v.document_id
             WHERE d.project_id = ? AND d.deleted_at IS NULL AND d.visibility = \'shared\' ORDER BY v.id DESC LIMIT 8',
            [$project['id']]
        );
        $last = Database::fetchOne("SELECT title, scheduled_at, decisions, outcome FROM supervision_sessions WHERE project_id = ? AND status = 'completed' ORDER BY scheduled_at DESC LIMIT 1", [$project['id']]);
        $lines = ['Stage: ' . $project['stage']];
        $lines[] = 'Attention flags: ' . ($progress['attention']['reasons'] ? implode('; ', $progress['attention']['reasons']) : 'none');
        $lines[] = 'Milestones: ' . $progress['milestones']['completed'] . '/' . $progress['milestones']['total'] . ' complete; overdue: ' . (implode(', ', array_column($progress['milestones']['overdue'], 'title')) ?: 'none');
        $lines[] = 'Open tasks: ' . ($unres['open_tasks'] ? implode('; ', array_map(fn($t) => $t['title'] . ($t['due'] ? ' (due ' . $t['due'] . ')' : ''), $unres['open_tasks'])) : 'none');
        $lines[] = 'Open AI findings (unreviewed suggestions): ' . ($unres['ai_findings'] ? implode('; ', array_map(fn($f) => "{$f['code']} [{$f['severity']}] {$f['issue']}", $unres['ai_findings'])) : 'none');
        $lines[] = 'Recent submissions: ' . ($docs ? implode('; ', array_map(fn($d) => "{$d['title']} v{$d['version_number']} ({$d['created_at']})", $docs)) : 'none');
        $lines[] = 'Open supervisor comments: ' . $progress['open_supervisor_comments'];
        if ($last) {
            $lines[] = 'Last completed meeting: ' . $last['title'] . ' on ' . $last['scheduled_at'] . '. Official outcome: ' . ($last['outcome'] ?: 'not recorded') . '. Decisions: ' . ($last['decisions'] ?: 'not recorded');
        }
        return implode("\n", $lines);
    }

    /** @return array{brief:array,result:\App\Services\AI\AIResult} */
    public static function brief(AIServiceInterface $ai, array $user, array $project): array
    {
        $prompt = PromptManager::render('supervision.brief', [
            'project_context' => ResearchProjectAgent::promptContext($project, 4000),
            'activity' => self::activity($project),
        ]);
        $res = $ai->generateStructuredResponse($prompt['system'], $prompt['user'], Schemas::supervisionBrief(), [
            'purpose' => 'supervision_brief', 'user_id' => $user['id'], 'project_id' => $project['id'],
        ]);
        return ['brief' => Schemas::brief($res->json ?? []), 'result' => $res];
    }
}
