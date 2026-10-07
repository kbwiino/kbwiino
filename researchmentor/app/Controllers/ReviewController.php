<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Agents\{AgentRegistry, ProgressAgent, ResearchProjectAgent};
use App\Models\{AiReview, AiReviewIssue, AiReviewJob, AlignmentMatrix, AlignmentRow, Document, ResearchProject};
use App\Policies\{DocumentPolicy, Gate, ProjectAccess};
use App\Services\{AiReviewService, AuditLogger};
use App\Services\AI\{AIFactory, AiGuard, ReviewSchema};
use App\Support\{Config, Database, HttpException, Request, Response};

final class ReviewController extends BaseController
{
    private const PATH_TYPES = [
        'proposal-review' => 'proposal', 'literature-review' => 'literature', 'methodology-review' => 'methodology', 'alignment-review' => 'alignment',
        'gap-analysis' => 'gap', 'writing-review' => 'writing', 'integrity-review' => 'integrity', 'statistics-review' => 'statistics', 'constitution-check' => 'constitution_check',
    ];

    /** POST /api/ai/{kind} */
    public function request(Request $r): Response
    {
        $kind = explode('/', $r->path)[3] ?? '';
        $type = self::PATH_TYPES[$kind] ?? throw HttpException::notFound('Review type');
        $user = $this->user($r);
        $data = $this->validate($r, ['project_id' => 'required|uuid', 'document_id' => 'nullable|uuid']);
        $project = ResearchProject::findByUuid($data['project_id']);
        if (!$project || !ProjectAccess::relations($user, $project)) {
            throw HttpException::notFound('Project');
        }
        $doc = !empty($data['document_id']) ? Document::findByUuid($data['document_id']) : null;
        if (!empty($data['document_id']) && (!$doc || !(new DocumentPolicy($project))->view($user, $doc))) {
            throw HttpException::notFound('Document');
        }
        $job = AiReviewService::request($r, $user, $project, $type, $doc);
        return $this->ok(AiReviewService::presentJob($job), [], $job['status'] === 'completed' ? 201 : 202);
    }

    /** POST /api/documents/{id}/review */
    public function documentReview(Request $r): Response
    {
        $user = $this->user($r);
        $data = $this->validate($r, ['review_type' => 'required|in:' . implode(',', ReviewSchema::TYPES)]);
        $doc = Document::findByUuid((string) $r->param('id'));
        $project = $doc ? ResearchProject::find((int) $doc['project_id']) : null;
        if (!$doc || !$project || !ProjectAccess::relations($user, $project) || !(new DocumentPolicy($project))->view($user, $doc)) {
            throw HttpException::notFound('Document');
        }
        $job = AiReviewService::request($r, $user, $project, $data['review_type'], $doc);
        return $this->ok(AiReviewService::presentJob($job), [], $job['status'] === 'completed' ? 201 : 202);
    }

    public function job(Request $r): Response
    {
        $user = $this->user($r);
        $job = AiReviewJob::findByUuid((string) $r->param('id'));
        $project = $job ? ResearchProject::find((int) $job['project_id']) : null;
        if (!$job || !Gate::allows($user, 'project.viewContent', $project)) {
            throw HttpException::notFound('Job');
        }
        return $this->ok(AiReviewService::presentJob($job));
    }

    private function review(Request $r, string $ability = 'view'): array
    {
        $user = $this->user($r);
        $review = AiReview::findByUuid((string) $r->param('id'));
        $project = $review ? ResearchProject::find((int) $review['project_id']) : null;
        if (!$review || !$project || !ProjectAccess::relations($user, $project) || !Gate::allows($user, 'review.view', $review)) {
            throw HttpException::notFound('Review');
        }
        if ($ability !== 'view') {
            Gate::authorize($user, 'review.' . $ability, $review);
        }
        return $review;
    }

    public function show(Request $r): Response
    {
        $review = $this->review($r);
        AuditLogger::log($r, 'ai.review_view', 'ai_review', $review['uuid'], ['project' => ResearchProject::find((int) $review['project_id'])['uuid']]);
        return $this->ok(AiReviewService::present($review) + ['can_decide' => Gate::allows($this->user($r), 'review.decide', $review)]);
    }

    public function issues(Request $r): Response
    {
        $review = $this->review($r);
        $q = ['review_id' => $review['id']];
        foreach (['severity', 'status', 'category'] as $f) {
            if ($v = $r->input($f)) {
                $q[$f] = $v;
            }
        }
        return $this->ok(array_map([AiReviewService::class, 'presentIssue'], AiReviewIssue::where($q, "FIELD(category,'major','minor'), FIELD(severity,'critical','high','medium','low'), id")));
    }

    public function index(Request $r): Response
    {
        $project = $this->project($r, 'project.viewContent');
        $user = $this->user($r);
        $rows = AiReview::where(['project_id' => $project['id']], 'id DESC', 100);
        $out = [];
        foreach ($rows as $rv) {
            if (Gate::allows($user, 'review.view', $rv)) {
                $out[] = AiReviewService::present($rv, false) + ['open_issues' => AiReviewIssue::count(['review_id' => $rv['id'], 'status' => 'open'])];
            }
        }
        return $this->ok($out);
    }

    public function decide(Request $r): Response
    {
        $review = $this->review($r, 'decide');
        $data = $this->validate($r, ['status' => 'required|in:accepted,modified,rejected', 'notes' => 'nullable|string|max:5000']);
        $updated = AiReviewService::decide($r, $this->user($r), $review, $data['status'], $data['notes'] ?? null);
        return $this->ok(AiReviewService::present($updated, false));
    }

    public function updateIssue(Request $r): Response
    {
        $review = $this->review($r);
        $data = $this->validate($r, ['status' => 'required|in:open,accepted,modified,rejected,resolved', 'note' => 'nullable|string|max:3000']);
        $issue = AiReviewIssue::findByUuid((string) $r->param('iid'));
        if (!$issue || (int) $issue['review_id'] !== (int) $review['id']) {
            throw HttpException::notFound('Issue');
        }
        $updated = AiReviewService::updateIssue($r, $this->user($r), $review, $issue, $data['status'], $data['note'] ?? null);
        return $this->ok(AiReviewService::presentIssue($updated));
    }

    /** GET /api/ai/status */
    public function status(Request $r): Response
    {
        $user = $this->user($r);
        $svc = AIFactory::make();
        $av = $svc->checkAvailability();
        $limit = (int) Config::get('ai.rate_limit_per_hour');
        return $this->ok([
            'enabled' => (bool) Config::get('ai.enabled'), 'available' => $av['available'] && Config::get('ai.enabled'), 'mock' => $svc->isMock(),
            'provider' => $svc->provider(), 'reason' => $av['reason'],
            'remaining_this_hour' => \App\Services\RateLimiter::remaining('ai:' . $user['id'], $limit, 3600),
            'disclosure' => AiGuard::DISCLOSURE,
            'review_types' => array_map(fn($a) => ['type' => $a->type(), 'label' => $a->label(), 'needs_document' => $a->needsDocument()], array_values(AgentRegistry::all())),
        ]);
    }

    // ------------------------------------------------------------ project context / progress (non-generative agents)

    public function projectSummary(Request $r): Response
    {
        $project = $this->project($r, 'project.viewDetails');
        return $this->ok(ResearchProjectAgent::summary($project));
    }

    public function progress(Request $r): Response
    {
        $project = $this->project($r, 'project.viewProgress');
        return $this->ok(ProgressAgent::report($project));
    }

    // ------------------------------------------------------------ alignment matrix (editable by humans)

    private function matrixRows(array $m): array
    {
        return array_map(fn($row) => [
            'id' => (int) $row['id'], 'objective' => $row['objective'], 'research_question' => $row['research_question'], 'hypothesis' => $row['hypothesis'],
            'variable' => $row['variable_name'], 'indicator' => $row['indicator'], 'instrument' => $row['instrument'], 'data_source' => $row['data_source'],
            'analysis' => $row['analysis'], 'mismatch_note' => $row['mismatch_note'], 'origin' => $row['origin'],
        ], AlignmentRow::where(['matrix_id' => $m['id']], 'position, id'));
    }

    public function alignment(Request $r): Response
    {
        $project = $this->project($r, 'project.viewContent');
        $m = Database::fetchOne('SELECT * FROM alignment_matrices WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$project['id']]);
        if (!$m) {
            return $this->ok(null);
        }
        $review = $m['review_id'] ? AiReview::find((int) $m['review_id']) : null;
        return $this->ok([
            'id' => $m['uuid'], 'status' => $m['status'], 'rows' => $this->matrixRows($m),
            'label' => $m['status'] === 'ai_draft' ? 'AI draft - awaiting supervisor review' : 'Reviewed by a supervisor',
            'review_id' => $review['uuid'] ?? null, 'created_at' => $m['created_at'], 'reviewed_at' => $m['reviewed_at'],
        ]);
    }

    private function matrix(Request $r): array
    {
        $project = $this->project($r, 'project.viewContent');
        $m = AlignmentMatrix::findByUuid((string) $r->param('mid'));
        if (!$m || (int) $m['project_id'] !== (int) $project['id']) {
            throw HttpException::notFound('Matrix');
        }
        return [$project, $m];
    }

    public function updateAlignmentRow(Request $r): Response
    {
        [$project, $m] = $this->matrix($r);
        Gate::authorize($this->user($r), 'project.update', $project);
        $row = AlignmentRow::find((int) $r->param('rid'));
        if (!$row || (int) $row['matrix_id'] !== (int) $m['id']) {
            throw HttpException::notFound('Row');
        }
        $data = $this->validate($r, array_intersect_key([
            'objective' => 'nullable|string|max:3000', 'research_question' => 'nullable|string|max:3000', 'hypothesis' => 'nullable|string|max:3000', 'variable' => 'nullable|string|max:3000',
            'indicator' => 'nullable|string|max:3000', 'instrument' => 'nullable|string|max:3000', 'data_source' => 'nullable|string|max:3000', 'analysis' => 'nullable|string|max:3000', 'mismatch_note' => 'nullable|string|max:3000',
        ], $r->body));
        if (array_key_exists('variable', $data)) {
            $data['variable_name'] = $data['variable'];
            unset($data['variable']);
        }
        $user = $this->user($r);
        $isSup = in_array('supervisor', ProjectAccess::relations($user, $project), true);
        AlignmentRow::updateById((int) $row['id'], $data + ['origin' => $isSup ? 'supervisor' : 'student', 'modified_by' => $user['id']]);
        AuditLogger::log($r, 'alignment.row_edit', 'alignment_matrix', $m['uuid'], ['project' => $project['uuid']]);
        return $this->ok($this->matrixRows($m));
    }

    public function addAlignmentRow(Request $r): Response
    {
        [$project, $m] = $this->matrix($r);
        $user = $this->user($r);
        Gate::authorize($user, 'project.update', $project);
        $isSup = in_array('supervisor', ProjectAccess::relations($user, $project), true);
        Database::insert('alignment_rows', ['matrix_id' => $m['id'], 'position' => (int) Database::fetchValue('SELECT COALESCE(MAX(position),0)+1 FROM alignment_rows WHERE matrix_id = ?', [$m['id']]), 'origin' => $isSup ? 'supervisor' : 'student', 'modified_by' => $user['id']]);
        return $this->ok($this->matrixRows($m), [], 201);
    }

    public function deleteAlignmentRow(Request $r): Response
    {
        [$project, $m] = $this->matrix($r);
        Gate::authorize($this->user($r), 'project.update', $project);
        $row = AlignmentRow::find((int) $r->param('rid'));
        if ($row && (int) $row['matrix_id'] === (int) $m['id']) {
            AlignmentRow::deleteById((int) $row['id']);
        }
        return $this->ok($this->matrixRows($m));
    }

    /** A supervisor confirms the (possibly modified) matrix: an official record of human review. */
    public function approveAlignment(Request $r): Response
    {
        [$project, $m] = $this->matrix($r);
        $user = $this->user($r);
        Gate::authorize($user, 'supervision.approve', $project);
        AlignmentMatrix::updateById((int) $m['id'], ['status' => 'supervisor_reviewed', 'reviewed_by' => $user['id'], 'reviewed_at' => gmdate('Y-m-d H:i:s')]);
        AuditLogger::log($r, 'alignment.supervisor_reviewed', 'alignment_matrix', $m['uuid'], ['project' => $project['uuid']]);
        return $this->ok(['status' => 'supervisor_reviewed']);
    }
}
