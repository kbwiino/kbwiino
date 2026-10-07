<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{ConceptualFramework, ConstitutionProposal, ResearchConstitution, ResearchHypothesis, ResearchObjective, ResearchProject, ResearchQuestion, ResearchTheory, ResearchVariable};
use App\Policies\Gate;
use App\Support\{Database, HttpException, Request, TextDiff, Uuid};

/**
 * The Research Constitution is the latest *officially approved* research structure.
 * Versions are append-only: approved rows are never updated in place (only flagged 'superseded').
 * Official changes require an authorised human (supervisor); AI findings are only ever suggestions.
 */
final class ConstitutionService
{
    /** field => [constitution column, kind] */
    public const FIELDS = [
        'title' => ['approved_title', 'text'],
        'problem' => ['approved_problem', 'text'],
        'purpose' => ['approved_purpose', 'text'],
        'objectives' => ['approved_objectives', 'list'],
        'questions' => ['approved_questions', 'list'],
        'hypotheses' => ['approved_hypotheses', 'list'],
        'variables' => ['approved_variables', 'list'],
        'theories' => ['approved_theories', 'list'],
        'conceptual_framework' => ['approved_conceptual_framework', 'text'],
        'paradigm' => ['approved_paradigm', 'text'],
        'approach' => ['approved_approach', 'text'],
        'design' => ['approved_design', 'text'],
        'population' => ['approved_population', 'text'],
        'sampling_plan' => ['approved_sampling_plan', 'text'],
        'data_collection_plan' => ['approved_data_collection_plan', 'text'],
        'analysis_plan' => ['approved_analysis_plan', 'text'],
    ];

    public static function current(array $project): ?array
    {
        $row = Database::fetchOne('SELECT * FROM research_constitutions WHERE project_id = ? ORDER BY version_number DESC LIMIT 1', [$project['id']]);
        return $row ? self::decode($row) : null;
    }

    public static function version(array $project, int $n): ?array
    {
        $row = Database::fetchOne('SELECT * FROM research_constitutions WHERE project_id = ? AND version_number = ?', [$project['id'], $n]);
        return $row ? self::decode($row) : null;
    }

    private static function decode(array $row): array
    {
        return ResearchConstitution::decodeJson($row, ['approved_objectives', 'approved_questions', 'approved_hypotheses', 'approved_variables', 'approved_theories']);
    }

    /** Field => value map for a constitution row (public field names). */
    public static function values(array $c): array
    {
        $out = [];
        foreach (self::FIELDS as $field => [$col]) {
            $out[$field] = $c[$col] ?? null;
        }
        return $out;
    }

    /** Snapshot of the project's working (unapproved) research design, in constitution field format. */
    public static function workingSnapshot(array $project): array
    {
        $id = (int) $project['id'];
        $fw = ConceptualFramework::first(['project_id' => $id]);
        return [
            'title' => $project['title'],
            'problem' => $project['problem_statement'],
            'purpose' => $project['purpose'],
            'objectives' => array_map(fn($o) => ['type' => $o['objective_type'], 'text' => $o['body']], ResearchObjective::where(['project_id' => $id], 'position ASC, id ASC')),
            'questions' => array_map(fn($q) => ['text' => $q['body']], ResearchQuestion::where(['project_id' => $id], 'position ASC, id ASC')),
            'hypotheses' => array_map(fn($h) => ['kind' => $h['kind'], 'text' => $h['body']], ResearchHypothesis::where(['project_id' => $id], 'position ASC, id ASC')),
            'variables' => array_map(fn($v) => ['name' => $v['name'], 'role' => $v['variable_role'], 'indicators' => $v['indicators'], 'instrument' => $v['instrument']], ResearchVariable::where(['project_id' => $id])),
            'theories' => array_map(fn($t) => ['name' => $t['name'], 'proponents' => $t['proponents']], ResearchTheory::where(['project_id' => $id])),
            'conceptual_framework' => $fw['description'] ?? null,
            'paradigm' => $project['paradigm'],
            'approach' => $project['approach'],
            'design' => $project['design'],
            'population' => $project['population'],
            'sampling_plan' => $project['sampling_approach'],
            'data_collection_plan' => $project['data_collection_methods'],
            'analysis_plan' => $project['data_analysis_methods'],
        ];
    }

    /** Supervisor approves the first Constitution from the project's current working design. */
    public static function initialize(Request $r, array $actor, array $project, ?string $notes): array
    {
        Gate::authorize($actor, 'project.approveConstitution', $project);
        if (self::current($project)) {
            throw HttpException::conflict('A Research Constitution already exists. Propose and approve a change instead.');
        }
        $snap = self::workingSnapshot($project);
        $missing = array_filter(['title', 'problem', 'purpose'], fn($f) => empty($snap[$f]));
        if ($missing) {
            throw HttpException::unprocessable('The project is incomplete.', ['fields' => $missing]);
        }
        return self::writeVersion($r, $actor, $project, $snap, $notes, null);
    }

    public static function propose(Request $r, array $actor, array $project, array $changes, ?string $rationale, string $source = 'student'): array
    {
        Gate::authorize($actor, 'project.proposeConstitutionChange', $project);
        $current = self::current($project) ?? throw HttpException::conflict('No approved Constitution exists yet. Ask your supervisor to approve the initial version.');
        $clean = self::sanitizeChanges($changes);
        if (!$clean) {
            throw HttpException::unprocessable('No valid changes supplied.', ['changes' => ['Provide at least one Constitution field.']]);
        }
        // Only keep fields that actually differ from the approved version.
        $base = self::values($current);
        $clean = array_filter($clean, fn($v, $k) => json_encode($v) !== json_encode($base[$k]), ARRAY_FILTER_USE_BOTH);
        if (!$clean) {
            throw HttpException::unprocessable('Proposed values are identical to the approved Constitution.');
        }
        $isSupervisor = in_array('supervisor', \App\Policies\ProjectAccess::relations($actor, $project), true);
        $id = ConstitutionProposal::create([
            'project_id' => $project['id'], 'base_constitution_id' => $current['id'], 'proposed_by' => $actor['id'],
            'source' => $source === 'ai_suggestion' ? 'ai_suggestion' : ($isSupervisor ? 'supervisor' : 'student'),
            'changes' => $clean, 'rationale' => $rationale,
        ]);
        $p = ConstitutionProposal::find($id);
        AuditLogger::log($r, 'constitution.propose', 'constitution_proposal', $p['uuid'], ['project' => $project['uuid'], 'fields' => array_keys($clean)]);
        foreach (Database::fetchAll("SELECT user_id FROM research_project_members WHERE project_id = ? AND member_role IN ('supervisor','co_supervisor') AND ended_at IS NULL", [$project['id']]) as $m) {
            if ((int) $m['user_id'] !== (int) $actor['id']) {
                NotificationService::notify((int) $m['user_id'], 'constitution_proposal', 'Constitution change proposed', $project['title'], '/projects/' . $project['uuid'] . '/constitution');
            }
        }
        return $p;
    }

    private static function sanitizeChanges(array $changes): array
    {
        $out = [];
        foreach ($changes as $field => $value) {
            if (!isset(self::FIELDS[$field])) {
                continue;
            }
            [, $kind] = self::FIELDS[$field];
            if ($kind === 'text') {
                if ($value !== null && !is_string($value)) {
                    throw HttpException::unprocessable('Invalid value.', [$field => ['Must be text.']]);
                }
                $out[$field] = $value === null ? null : mb_substr(trim($value), 0, 20000);
            } else {
                if (!is_array($value) || count($value) > 100) {
                    throw HttpException::unprocessable('Invalid value.', [$field => ['Must be a list (max 100 items).']]);
                }
                $out[$field] = array_values(array_map(fn($i) => is_array($i) ? array_map(fn($x) => is_string($x) ? mb_substr($x, 0, 5000) : $x, $i) : mb_substr((string) $i, 0, 5000), $value));
            }
        }
        return $out;
    }

    /** Approve or reject a proposal. Only an authorised supervisor may do this. */
    public static function decide(Request $r, array $actor, array $project, array $proposal, string $decision, ?string $notes): array
    {
        Gate::authorize($actor, 'project.approveConstitution', $project);
        if ($proposal['status'] !== 'pending') {
            throw HttpException::conflict('This proposal has already been decided.');
        }
        $current = self::current($project);
        if ($decision === 'reject') {
            ConstitutionProposal::updateById((int) $proposal['id'], ['status' => 'rejected', 'decided_by' => $actor['id'], 'decided_at' => gmdate('Y-m-d H:i:s'), 'decision_notes' => $notes]);
            AuditLogger::log($r, 'constitution.reject', 'constitution_proposal', $proposal['uuid'], ['project' => $project['uuid']]);
            NotificationService::notify((int) $proposal['proposed_by'], 'constitution_decision', 'Constitution change not approved', $notes, '/projects/' . $project['uuid'] . '/constitution');
            return ['proposal' => ConstitutionProposal::find((int) $proposal['id']), 'constitution' => $current];
        }
        if (!$current || (int) $proposal['base_constitution_id'] !== (int) $current['id']) {
            throw HttpException::conflict('The Constitution has changed since this proposal was made. Ask for it to be re-submitted against the current version.');
        }
        $changes = json_decode($proposal['changes'], true);
        $values = array_merge(self::values($current), $changes);
        $new = self::writeVersion($r, $actor, $project, $values, $notes, (int) $proposal['id']);
        ConstitutionProposal::updateById((int) $proposal['id'], ['status' => 'approved', 'decided_by' => $actor['id'], 'decided_at' => gmdate('Y-m-d H:i:s'), 'decision_notes' => $notes, 'resulting_constitution_id' => $new['id']]);
        NotificationService::notify((int) $proposal['proposed_by'], 'constitution_decision', 'Constitution change approved', 'Version ' . $new['version_number'] . ' is now in force.', '/projects/' . $project['uuid'] . '/constitution');
        return ['proposal' => ConstitutionProposal::find((int) $proposal['id']), 'constitution' => $new];
    }

    /** Append a new approved version; the previous one is marked superseded, never edited. */
    private static function writeVersion(Request $r, array $actor, array $project, array $values, ?string $notes, ?int $proposalId): array
    {
        return Database::transaction(function () use ($r, $actor, $project, $values, $notes, $proposalId) {
            // Serialise concurrent approvals for this project.
            Database::fetchValue('SELECT id FROM research_projects WHERE id = ? FOR UPDATE', [$project['id']]);
            $next = (int) Database::fetchValue('SELECT COALESCE(MAX(version_number), 0) + 1 FROM research_constitutions WHERE project_id = ?', [$project['id']]);
            $row = ['project_id' => $project['id'], 'version_number' => $next, 'approved_at' => gmdate('Y-m-d H:i:s'), 'approved_by' => $actor['id'], 'approval_notes' => $notes, 'proposal_id' => $proposalId];
            foreach (self::FIELDS as $field => [$col]) {
                $row[$col] = $values[$field] ?? null;
            }
            if (empty($row['approved_title']) || empty($row['approved_problem']) || empty($row['approved_purpose'])) {
                throw HttpException::unprocessable('Title, problem statement and purpose are required.');
            }
            Database::execute("UPDATE research_constitutions SET status = 'superseded' WHERE project_id = ? AND status = 'approved'", [$project['id']]);
            $id = ResearchConstitution::create($row);
            $c = self::decode(ResearchConstitution::find($id));
            Database::insert('supervision_approvals', [
                'uuid' => Uuid::v4(), 'project_id' => $project['id'], 'subject_type' => 'constitution', 'subject_id' => $id,
                'decision' => 'approved', 'notes' => $notes, 'decided_by' => $actor['id'],
            ]);
            AuditLogger::log($r, 'constitution.approve', 'constitution', $c['uuid'], ['project' => $project['uuid'], 'version' => $next]);
            return $c;
        });
    }

    public static function present(array $c): array
    {
        $approver = \App\Models\User::find((int) $c['approved_by']);
        return [
            'id' => $c['uuid'], 'version' => (int) $c['version_number'], 'status' => $c['status'],
            'official' => true, // approved by an authorised human
            'values' => self::values($c),
            'approved_at' => $c['approved_at'], 'approved_by' => ProjectService::person($approver), 'approval_notes' => $c['approval_notes'],
        ];
    }

    public static function presentProposal(array $p): array
    {
        $by = \App\Models\User::find((int) $p['proposed_by']);
        return [
            'id' => $p['uuid'], 'status' => $p['status'], 'source' => $p['source'],
            'changes' => is_string($p['changes']) ? json_decode($p['changes'], true) : $p['changes'],
            'rationale' => $p['rationale'], 'proposed_by' => ProjectService::person($by), 'created_at' => $p['created_at'],
            'decided_at' => $p['decided_at'], 'decision_notes' => $p['decision_notes'],
            'label' => $p['source'] === 'ai_suggestion' ? 'AI suggestion - requires human review' : ($p['source'] === 'student' ? 'Student proposal' : 'Supervisor proposal'),
        ];
    }

    // ---------------------------------------------------------------- comparison & consistency

    /** Compare two value maps field by field with word-level diffs for text fields. */
    public static function compare(array $from, array $to): array
    {
        $rows = [];
        foreach (self::FIELDS as $field => [, $kind]) {
            $a = $from[$field] ?? null;
            $b = $to[$field] ?? null;
            $sa = $kind === 'list' ? self::listToText($a) : (string) $a;
            $sb = $kind === 'list' ? self::listToText($b) : (string) $b;
            $ops = TextDiff::words($sa, $sb);
            $rows[] = ['field' => $field, 'kind' => $kind, 'from' => $a, 'to' => $b, 'changed' => TextDiff::stats($ops)['changed'], 'diff' => $ops];
        }
        return $rows;
    }

    public static function listToText(mixed $list): string
    {
        if (!is_array($list)) {
            return (string) $list;
        }
        $lines = [];
        foreach ($list as $i) {
            $lines[] = is_array($i) ? implode(' | ', array_filter(array_map(fn($x) => is_scalar($x) ? (string) $x : '', $i), fn($x) => $x !== '')) : (string) $i;
        }
        return implode("\n", $lines);
    }

    /**
     * Rule-based detection of divergence between the working project data and the approved Constitution.
     * Everything returned is a *suggestion requiring human review*, never an automatic change.
     */
    public static function detectInconsistencies(array $project): array
    {
        $current = self::current($project);
        if (!$current) {
            return [];
        }
        $approved = self::values($current);
        $working = self::workingSnapshot($project);
        $out = [];
        foreach (self::FIELDS as $field => [, $kind]) {
            $a = $kind === 'list' ? self::listToText($approved[$field]) : trim((string) $approved[$field]);
            $b = $kind === 'list' ? self::listToText($working[$field]) : trim((string) $working[$field]);
            if (self::normalize($a) !== self::normalize($b)) {
                $out[] = [
                    'field' => $field,
                    'approved' => $approved[$field],
                    'working' => $working[$field],
                    'origin' => 'system_check',
                    'requires_human_review' => true,
                    'note' => 'The working project data differs from the approved Research Constitution (v' . $current['version_number'] . '). Confirm whether the working data should be corrected or a Constitution change proposed.',
                ];
            }
        }
        return $out;
    }

    private static function normalize(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? '');
    }
}
