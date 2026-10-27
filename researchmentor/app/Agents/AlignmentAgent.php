<?php
declare(strict_types=1);

namespace App\Agents;

use App\Models\{AlignmentMatrix, ResearchHypothesis, ResearchObjective, ResearchQuestion, ResearchVariable};
use App\Support\Database;

/**
 * Alignment Agent: Objective -> Question -> Hypothesis -> Variable -> Indicator -> Instrument -> Data -> Analysis.
 * The skeleton is built from recorded project data (no AI); the AI assesses and annotates it.
 * The saved matrix is an editable AI draft that a supervisor reviews and modifies.
 */
final class AlignmentAgent extends AbstractReviewAgent
{
    public function type(): string { return 'alignment'; }
    public function label(): string { return 'Research alignment'; }
    public function needsDocument(): bool { return false; }

    public static function skeleton(array $project): array
    {
        $id = (int) $project['id'];
        $objectives = ResearchObjective::where(['project_id' => $id], 'position, id');
        $questions = ResearchQuestion::where(['project_id' => $id], 'position, id');
        $hyps = ResearchHypothesis::where(['project_id' => $id], 'position, id');
        $vars = ResearchVariable::where(['project_id' => $id], 'id');
        $rows = [];
        $usedQ = $usedH = $usedV = [];
        $analysis = (string) ($project['data_analysis_methods'] ?? '');
        $dataSource = (string) ($project['data_collection_methods'] ?? '');
        $make = function (?array $o, ?array $q, ?array $h, ?array $v) use ($analysis, $dataSource) {
            return [
                'objective' => $o['body'] ?? '', 'research_question' => $q['body'] ?? '', 'hypothesis' => $h['body'] ?? '',
                'variable' => $v ? $v['name'] : '', 'indicator' => $v['indicators'] ?? '', 'instrument' => $v['instrument'] ?? '',
                'data_source' => $v ? $dataSource : '', 'analysis' => $analysis, 'mismatch_note' => '',
            ];
        };
        foreach ($objectives as $o) {
            $qs = array_values(array_filter($questions, fn($q) => (int) $q['objective_id'] === (int) $o['id']));
            if (!$qs) {
                $rows[] = $make($o, null, null, null);
                continue;
            }
            foreach ($qs as $q) {
                $usedQ[$q['id']] = true;
                $hs = array_values(array_filter($hyps, fn($h) => (int) $h['question_id'] === (int) $q['id']));
                if (!$hs) {
                    $rows[] = $make($o, $q, null, null);
                    continue;
                }
                foreach ($hs as $h) {
                    $usedH[$h['id']] = true;
                    $vs = array_values(array_filter($vars, fn($v) => (int) $v['hypothesis_id'] === (int) $h['id']));
                    if (!$vs) {
                        $rows[] = $make($o, $q, $h, null);
                        continue;
                    }
                    foreach ($vs as $v) {
                        $usedV[$v['id']] = true;
                        $rows[] = $make($o, $q, $h, $v);
                    }
                }
            }
        }
        foreach ($questions as $q) {
            if (!isset($usedQ[$q['id']])) { $rows[] = $make(null, $q, null, null); }
        }
        foreach ($hyps as $h) {
            if (!isset($usedH[$h['id']])) { $rows[] = $make(null, null, $h, null); }
        }
        foreach ($vars as $v) {
            if (!isset($usedV[$v['id']])) { $rows[] = $make(null, null, null, $v); }
        }
        return array_slice($rows, 0, 60);
    }

    public function variables(AiRunContext $ctx, string $documentText): array
    {
        return ['alignment_skeleton' => json_encode(self::skeleton($ctx->project), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)];
    }

    public function afterSave(AiRunContext $ctx, array $reviewRow, array $review): void
    {
        $mid = AlignmentMatrix::create(['project_id' => $ctx->project['id'], 'review_id' => $reviewRow['id'], 'status' => 'ai_draft']);
        $pos = 0;
        foreach ($review['alignment_rows'] ?? [] as $r) {
            Database::insert('alignment_rows', [
                'matrix_id' => $mid, 'position' => ++$pos, 'objective' => $r['objective'], 'research_question' => $r['research_question'],
                'hypothesis' => $r['hypothesis'], 'variable_name' => $r['variable'], 'indicator' => $r['indicator'], 'instrument' => $r['instrument'],
                'data_source' => $r['data_source'], 'analysis' => $r['analysis'], 'mismatch_note' => $r['mismatch_note'], 'origin' => 'ai_suggestion',
            ]);
        }
    }
}
