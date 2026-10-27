<?php
declare(strict_types=1);

namespace App\Agents;

use App\Models\{ResearchHypothesis, ResearchObjective, ResearchQuestion, ResearchTheory, ResearchVariable};
use App\Services\ConstitutionService;
use App\Support\Database;

/**
 * Research Project Agent: assembles project context for other agents and for people.
 * It reads recorded project data only - it does not generate or change anything.
 */
final class ResearchProjectAgent
{
    public static function summary(array $p): array
    {
        $id = (int) $p['id'];
        $constitution = ConstitutionService::current($p);
        return [
            'project' => [
                'title' => $p['title'], 'research_area' => $p['research_area'], 'degree_level' => $p['degree_level'], 'stage' => $p['stage'],
                'problem_statement' => $p['problem_statement'], 'purpose' => $p['purpose'], 'approach' => $p['approach'], 'design' => $p['design'],
                'paradigm' => $p['paradigm'], 'population' => $p['population'], 'sampling_approach' => $p['sampling_approach'],
                'data_collection_methods' => $p['data_collection_methods'], 'data_analysis_methods' => $p['data_analysis_methods'], 'ethics_information' => $p['ethics_information'],
            ],
            'objectives' => array_column(ResearchObjective::where(['project_id' => $id], 'position, id'), 'body'),
            'questions' => array_column(ResearchQuestion::where(['project_id' => $id], 'position, id'), 'body'),
            'hypotheses' => array_column(ResearchHypothesis::where(['project_id' => $id], 'position, id'), 'body'),
            'variables' => array_map(fn($v) => trim($v['name'] . ' (' . $v['variable_role'] . ')' . ($v['indicators'] ? ' indicators: ' . $v['indicators'] : '') . ($v['instrument'] ? ' instrument: ' . $v['instrument'] : '')), ResearchVariable::where(['project_id' => $id])),
            'theories' => array_column(ResearchTheory::where(['project_id' => $id]), 'name'),
            'constitution' => $constitution ? ['version' => (int) $constitution['version_number'], 'approved_at' => $constitution['approved_at'], 'values' => ConstitutionService::values($constitution)] : null,
            'approved_decisions' => self::approvedDecisions($p),
            'unresolved_issues' => self::unresolvedIssues($p),
        ];
    }

    /** Official decisions recorded by humans (supervision approvals). */
    public static function approvedDecisions(array $p): array
    {
        $rows = Database::fetchAll(
            'SELECT a.subject_type, a.decision, a.notes, a.decided_at, u.first_name, u.last_name FROM supervision_approvals a JOIN users u ON u.id = a.decided_by WHERE a.project_id = ? ORDER BY a.id DESC LIMIT 20',
            [$p['id']]
        );
        return array_map(fn($r) => ['type' => $r['subject_type'], 'decision' => $r['decision'], 'notes' => $r['notes'], 'at' => $r['decided_at'], 'by' => trim($r['first_name'] . ' ' . $r['last_name'])], $rows);
    }

    public static function unresolvedIssues(array $p): array
    {
        $rows = Database::fetchAll(
            "SELECT i.issue_code, i.severity, i.issue, r.review_type FROM ai_review_issues i JOIN ai_reviews r ON r.id = i.review_id
             WHERE r.project_id = ? AND i.status = 'open' ORDER BY FIELD(i.severity,'critical','high','medium','low'), i.id DESC LIMIT 15",
            [$p['id']]
        );
        $tasks = Database::fetchAll("SELECT title, due_date FROM supervision_action_items WHERE project_id = ? AND state IN ('open','in_progress') ORDER BY due_date IS NULL, due_date LIMIT 10", [$p['id']]);
        return [
            'ai_findings' => array_map(fn($r) => ['code' => $r['issue_code'], 'severity' => $r['severity'], 'type' => $r['review_type'], 'issue' => mb_substr($r['issue'], 0, 200)], $rows),
            'open_tasks' => array_map(fn($t) => ['title' => $t['title'], 'due' => $t['due_date']], $tasks),
        ];
    }

    /** Text block for prompts. Size-capped. */
    public static function promptContext(array $p, int $maxChars = 6000): string
    {
        $s = self::summary($p);
        $lines = [];
        foreach ($s['project'] as $k => $v) {
            if ($v !== null && $v !== '') {
                $lines[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $v;
            }
        }
        foreach (['objectives' => 'Objectives', 'questions' => 'Research questions', 'hypotheses' => 'Hypotheses', 'variables' => 'Variables', 'theories' => 'Theories'] as $k => $label) {
            if ($s[$k]) {
                $lines[] = $label . ":\n" . implode("\n", array_map(fn($i, $n) => '  ' . ($n + 1) . '. ' . $i, $s[$k], array_keys($s[$k])));
            }
        }
        $text = implode("\n", $lines);
        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars) . "\n[project context truncated]" : $text;
    }

    public static function constitutionBlock(array $p): string
    {
        $c = ConstitutionService::current($p);
        if (!$c) {
            return 'No approved Research Constitution exists yet.';
        }
        $out = ["Approved Research Constitution v{$c['version_number']} (approved " . $c['approved_at'] . '):'];
        foreach (ConstitutionService::values($c) as $k => $v) {
            $t = is_array($v) ? ConstitutionService::listToText($v) : (string) $v;
            if (trim($t) !== '') {
                $out[] = '- ' . str_replace('_', ' ', $k) . ': ' . mb_substr($t, 0, 1500);
            }
        }
        return implode("\n", $out);
    }
}
