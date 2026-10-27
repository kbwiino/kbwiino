<?php
declare(strict_types=1);

namespace App\Services\Reports;

use App\Agents\{ProgressAgent, VivaPreparationAgent};
use App\Models\{AiReview, AiReviewIssue, AlignmentMatrix, AlignmentRow, Document, DocumentVersion, ResearchProject, User};
use App\Services\{AiReviewService, ProjectService, SupervisionService, VivaService};
use App\Support\Database;

final class ReportBuilder
{
    public const AI_DISCLAIMER = 'AI-assistance disclaimer: the analysis in this report was generated with the help of an artificial-intelligence system. It consists of suggestions only. It is not a grade, an academic assessment, an originality or plagiarism certificate, an approval of the work, or an examination decision. Every finding must be verified by a qualified human supervisor before it is relied upon.';
    public const NO_AI_DISCLAIMER = 'AI-assistance disclaimer: this report was compiled from recorded project data without generative AI. It is a monitoring summary, not an official academic record, grade or decision.';

    private static function header(Report $r, array $project, string $type): void
    {
        $owner = User::find((int) $project['owner_user_id']);
        $sup = Database::fetchOne("SELECT u.first_name, u.last_name FROM research_project_members m JOIN users u ON u.id = m.user_id WHERE m.project_id = ? AND m.member_role = 'supervisor' AND m.ended_at IS NULL LIMIT 1", [$project['id']]);
        $r->meta('Report date', gmdate('d M Y H:i') . ' UTC')->meta('Report type', $type);
        $r->section('Project information');
        $r->kv('Title', $project['title'])->kv('Student', trim($owner['first_name'] . ' ' . $owner['last_name']))
            ->kv('Supervisor', $sup ? trim($sup['first_name'] . ' ' . $sup['last_name']) : 'Unassigned')
            ->kv('Programme', Database::fetchValue('SELECT name FROM programmes WHERE id = ?', [$project['programme_id']]))
            ->kv('Research area', $project['research_area'])->kv('Degree level', $project['degree_level'])
            ->kv('Approach / design', ucfirst($project['approach']) . ' / ' . $project['design'])->kv('Research stage', str_replace('_', ' ', $project['stage']));
    }

    public static function titleFor(string $reviewType): string
    {
        return match ($reviewType) {
            'proposal' => 'Proposal Review Report', 'methodology' => 'Methodology Audit', 'alignment' => 'Research Alignment Report',
            default => 'Chapter Review Report',
        };
    }

    private static function humanStatus(array $review): string
    {
        if ($review['human_status'] === 'unreviewed') {
            return 'NOT YET REVIEWED by a supervisor. Treat all findings as unverified AI suggestions.';
        }
        $by = $review['human_reviewed_by'] ? User::find((int) $review['human_reviewed_by']) : null;
        return ucfirst($review['human_status']) . ' by ' . ($by ? trim($by['first_name'] . ' ' . $by['last_name']) : 'a supervisor') . ' on ' . $review['human_reviewed_at'] . ' UTC' . ($review['human_notes'] ? '. Notes: ' . $review['human_notes'] : '');
    }

    public static function review(array $review): Report
    {
        $project = ResearchProject::findOrFail((int) $review['project_id']);
        $doc = $review['document_id'] ? Document::find((int) $review['document_id']) : null;
        $ver = $review['version_id'] ? DocumentVersion::find((int) $review['version_id']) : null;
        $decoded = json_decode($review['result_json'], true)['review'] ?? [];
        $label = \App\Agents\AgentRegistry::for($review['review_type'])->label();

        $r = new Report(self::titleFor($review['review_type']), 'AI-assisted review - ' . $label);
        self::header($r, $project, self::titleFor($review['review_type']));
        $r->meta('Review type', $label);
        $r->section('Review details');
        $r->kv('Document', $doc ? $doc['title'] . ($ver ? ' (version ' . $ver['version_number'] . ')' : '') : 'Project data (no document)')
            ->kv('AI provider / model', $review['provider'] . ' / ' . $review['model'])->kv('Review generated', $review['created_at'] . ' UTC');
        if ($review['is_mock']) {
            $r->note('DEVELOPMENT MOCK: this review was produced by a placeholder AI service and contains no real analysis.');
        }
        $r->section('Human review status');
        $r->p(self::humanStatus($review));
        $r->section('Summary');
        $r->p($review['summary']);
        if (!empty($decoded['strengths'])) {
            $r->section('Strengths');
            $r->bullets($decoded['strengths']);
        }
        $issues = AiReviewIssue::where(['review_id' => $review['id']], "FIELD(category,'major','minor'), FIELD(severity,'critical','high','medium','low'), id");
        foreach (['major' => 'Findings - major concerns', 'minor' => 'Findings - minor concerns'] as $cat => $heading) {
            $set = array_values(array_filter($issues, fn($i) => $i['category'] === $cat));
            if (!$set) continue;
            $r->section($heading);
            foreach ($set as $i) {
                $r->p('[' . $i['issue_code'] . '] ' . strtoupper($i['severity']) . ($i['section'] ? ' - ' . $i['section'] : '') . ($i['page_number'] ? ', page ' . $i['page_number'] : '') . ($i['location'] && $i['location'] !== $i['section'] ? ' (' . $i['location'] . ')' : ''));
                $r->p($i['issue']);
                if ($i['evidence']) $r->kv('Evidence' . ($i['evidence_verified'] === null ? '' : ($i['evidence_verified'] ? ' (quote found in document)' : ' (quote NOT found in document - verify)')), '"' . $i['evidence'] . '"');
                $r->kv('Why it matters', $i['implication'])->kv('Recommendation', $i['recommendation'])->kv('Question for the supervisor', $i['supervisor_question'])
                    ->kv('Status', ucfirst($i['status']) . ($i['requires_human_verification'] ? ' - requires human verification' : ''));
            }
        }
        // type-specific extensions
        if (!empty($decoded['gaps'])) {
            $r->section('Possible research gaps (unverified suggestions)');
            foreach ($decoded['gaps'] as $g) {
                $r->p(ucfirst($g['gap_type']) . ' gap - confidence: ' . $g['confidence']);
                $r->p($g['statement']);
                $r->kv('Supporting evidence', $g['supporting_evidence'])->kv('Limitations', $g['limitations']);
                $r->bullets($g['verification_questions'] ?? []);
            }
        }
        if (!empty($decoded['analysis_modes'])) {
            $r->section('Literature analysis modes');
            $r->table(['Mode', 'Assessment', 'Example'], array_map(fn($m) => [ucfirst(str_replace('_', ' ', $m['mode'])), $m['assessment'], $m['example_excerpt']], $decoded['analysis_modes']));
        }
        if (!empty($decoded['explanations'])) {
            $r->section('Explanations of statistical content');
            foreach ($decoded['explanations'] as $e) {
                $r->p(($e['subject'] ?: 'Item') . ': ' . $e['explanation']);
                $r->kv('Caveats', $e['caveats']);
            }
            if (!empty($decoded['needs_statistician'])) $r->note('Consult a qualified statistician before relying on any statistical interpretation. This tool is not a substitute for SPSS, SmartPLS or R.');
        }
        if ($review['review_type'] === 'alignment') {
            $m = Database::fetchOne('SELECT * FROM alignment_matrices WHERE review_id = ? ORDER BY id DESC LIMIT 1', [$review['id']]);
            if ($m) {
                $r->section('Alignment matrix (' . ($m['status'] === 'supervisor_reviewed' ? 'reviewed by a supervisor' : 'AI draft - awaiting supervisor review') . ')');
                $r->table(['Objective', 'Question', 'Hypothesis', 'Variable / indicator', 'Instrument', 'Analysis', 'Mismatch note'], array_map(
                    fn($x) => [$x['objective'], $x['research_question'], $x['hypothesis'], trim($x['variable_name'] . ($x['indicator'] ? ' / ' . $x['indicator'] : '')), $x['instrument'], $x['analysis'], $x['mismatch_note']],
                    AlignmentRow::where(['matrix_id' => $m['id']], 'position, id')
                ));
            }
        }
        if (!empty($decoded['action_items'])) {
            $r->section('Recommendations and action items');
            foreach ($decoded['action_items'] as $a) {
                $r->b($a['title'] . ' [' . $a['owner'] . ', ' . $a['priority'] . ' priority]' . ($a['description'] ? ': ' . $a['description'] : ''));
            }
        }
        if (!empty($decoded['uncertainties'])) {
            $r->section('Uncertainties and limitations');
            $r->bullets($decoded['uncertainties']);
        }
        $refs = $decoded['source_references'] ?? [];
        if ($refs) {
            $r->section('Evidence references');
            foreach ($refs as $s) {
                $r->b($s['label'] . ($s['page'] ? ' (page ' . $s['page'] . ')' : '') . ($s['section'] ? ', ' . $s['section'] : '') . ($s['excerpt'] ? ': "' . $s['excerpt'] . '"' : ''));
            }
        }
        $r->section('AI-assistance disclaimer');
        $r->note(self::AI_DISCLAIMER);
        return $r;
    }

    public static function meeting(array $session): Report
    {
        $project = ResearchProject::findOrFail((int) $session['project_id']);
        $r = new Report('Supervision Meeting Report', $session['title']);
        self::header($r, $project, 'Supervision meeting report');
        $r->section('Meeting details');
        $r->kv('Scheduled', $session['scheduled_at'] . ' UTC')->kv('Duration', $session['duration_minutes'] . ' minutes')->kv('Location', $session['location'])->kv('Status', ucfirst($session['status']));
        $agenda = Database::fetchAll('SELECT * FROM supervision_agendas WHERE session_id = ? ORDER BY position', [$session['id']]);
        if ($agenda) {
            $r->section('Agenda');
            foreach ($agenda as $a) {
                $r->b($a['item'] . ' [' . ($a['origin'] === 'ai_suggestion' ? 'AI suggestion, ' : '') . $a['state'] . ']');
            }
        }
        if ($session['discussion_notes']) { $r->section('Discussion notes (recorded by participants)'); $r->p($session['discussion_notes']); }
        if ($session['decisions']) { $r->section('Decisions (recorded by the supervisor)'); $r->p($session['decisions']); }
        $tasks = Database::fetchAll('SELECT * FROM supervision_action_items WHERE session_id = ? ORDER BY id', [$session['id']]);
        if ($tasks) {
            $r->section('Action items');
            $r->table(['Task', 'Assigned to', 'Due', 'Status', 'Origin'], array_map(fn($t) => [$t['title'], ($u = User::find((int) $t['assigned_to'])) ? $u['first_name'] . ' ' . $u['last_name'] : '', (string) $t['due_date'], $t['state'], $t['origin'] === 'ai_suggestion' ? 'AI suggestion' : $t['origin']], $tasks));
        }
        if ($session['ai_brief']) { $r->section('AI-generated brief (suggestion only)'); $r->note($session['ai_brief']); }
        $r->section('Official supervision outcome');
        if ($session['outcome']) {
            $by = $session['outcome_recorded_by'] ? User::find((int) $session['outcome_recorded_by']) : null;
            $r->p($session['outcome']);
            $r->kv('Recorded by', ($by ? $by['first_name'] . ' ' . $by['last_name'] : '') . ' on ' . $session['outcome_recorded_at'] . ' UTC');
        } else {
            $r->p('No official outcome has been recorded by a supervisor.');
        }
        $r->section('Human review status');
        $r->p($session['outcome'] ? 'The outcome above was recorded by a supervisor. AI-generated material in this report is labelled and has not been adopted unless stated in the outcome.' : 'Awaiting supervisor outcome. AI-generated material is unreviewed.');
        $r->section('AI-assistance disclaimer');
        $r->note($session['ai_brief'] ? self::AI_DISCLAIMER : self::NO_AI_DISCLAIMER);
        return $r;
    }

    public static function progress(array $project): Report
    {
        $p = ProgressAgent::report($project);
        $r = new Report('Research Progress Report', $project['title']);
        self::header($r, $project, 'Research progress report');
        $r->section('Progress status');
        $r->kv('Attention indicators', $p['attention']['label'] . ($p['attention']['reasons'] ? ': ' . implode('; ', $p['attention']['reasons']) : ''));
        $r->kv('Basis', $p['basis']);
        $r->section('Milestones');
        $r->p($p['milestones']['completed'] . ' of ' . $p['milestones']['total'] . ' completed.');
        if ($p['milestones']['overdue']) { $r->p('Overdue:'); $r->bullets(array_map(fn($m) => $m['title'] . ' (due ' . $m['due_date'] . ')', $p['milestones']['overdue'])); }
        if ($p['milestones']['upcoming']) { $r->p('Upcoming:'); $r->bullets(array_map(fn($m) => $m['title'] . ' (due ' . $m['due_date'] . ')', $p['milestones']['upcoming'])); }
        $r->section('Outstanding tasks');
        $r->p($p['tasks']['outstanding'] . ' outstanding; ' . count($p['tasks']['overdue']) . ' overdue.');
        $r->bullets(array_map(fn($t) => $t['title'] . ' (due ' . $t['due_date'] . ')', $p['tasks']['overdue']));
        $r->section('Submission history');
        $r->table(['Document', 'Category', 'Version', 'Submitted (UTC)'], array_map(fn($s) => [$s['title'], $s['category'], (string) $s['version_number'], $s['created_at']], $p['submissions']['recent']));
        $r->section('Supervision');
        $r->kv('Last completed meeting', $p['meetings']['last_completed_at'])->kv('Next scheduled meeting', $p['meetings']['next_scheduled_at'])
            ->kv('Open supervisor comments', (string) $p['open_supervisor_comments'])->kv('Open AI findings (unreviewed suggestions)', (string) $p['open_ai_findings']);
        $r->section('Stage history');
        foreach (Database::fetchAll('SELECT to_stage, notes, created_at FROM research_stage_history WHERE project_id = ? ORDER BY id', [$project['id']]) as $h) {
            $r->b(str_replace('_', ' ', $h['to_stage']) . ' - ' . $h['created_at'] . ' UTC' . ($h['notes'] ? ': ' . $h['notes'] : ''));
        }
        $r->section('Human review status');
        $r->p('System-generated monitoring summary. It has not been reviewed or endorsed by a supervisor.');
        $r->section('AI-assistance disclaimer');
        $r->note(self::NO_AI_DISCLAIMER);
        return $r;
    }

    public static function viva(array $session): Report
    {
        $project = ResearchProject::findOrFail((int) $session['project_id']);
        $s = VivaService::present($session);
        $r = new Report('Viva Preparation Report', 'Practice session - ' . $s['mode_label']);
        self::header($r, $project, 'Viva preparation report');
        $r->section('Practice session');
        $r->note(VivaPreparationAgent::NOTICE . ' Nothing in this report is an examination result, mark or decision.');
        $r->kv('Mode', $s['mode_label'])->kv('Started', $s['started_at'] . ' UTC')->kv('Ended', $s['ended_at'] ? $s['ended_at'] . ' UTC' : 'In progress')->kv('Summary', $s['summary']);
        if ($s['mock']) $r->note('DEVELOPMENT MOCK: questions and feedback were produced by a placeholder AI service.');
        $strengths = [];
        $improve = [];
        $r->section('Questions, answers and formative feedback');
        foreach ($s['exchanges'] as $e) {
            $r->p('Q' . $e['seq'] . ($e['is_follow_up'] ? ' (follow-up)' : '') . ': ' . $e['question']);
            $r->kv('Your answer', $e['answer'] ?? 'Not answered');
            if ($e['feedback']) {
                $r->kv('AI feedback (formative)', $e['feedback']['text']);
                $strengths = array_merge($strengths, $e['feedback']['strengths']);
                $improve = array_merge($improve, $e['feedback']['improvements']);
            }
        }
        if ($strengths) { $r->section('Strengths noted'); $r->bullets(array_values(array_unique($strengths))); }
        if ($improve) { $r->section('Areas to practise'); $r->bullets(array_values(array_unique($improve))); }
        $r->section('Human review status');
        $r->p('Practice session only. No supervisor or examiner has reviewed this record.');
        $r->section('AI-assistance disclaimer');
        $r->note(self::AI_DISCLAIMER);
        return $r;
    }
}
