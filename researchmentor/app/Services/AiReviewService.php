<?php
declare(strict_types=1);

namespace App\Services;

use App\Agents\{AgentRegistry, AiRunContext};
use App\Models\{AiReview, AiReviewIssue, AiReviewJob, Document, DocumentVersion, ResearchProject, User};
use App\Policies\{DocumentPolicy, Gate};
use App\Services\AI\{AIException, AiGuard, ReviewSchema};
use App\Support\{Database, HttpException, Logger, Request, Uuid};

final class AiReviewService
{
    /** Create a review job (authorises, checks consent/rate limit) and dispatch it. */
    public static function request(Request $r, array $user, array $project, string $type, ?array $doc, array $options = []): array
    {
        if (!in_array($type, ReviewSchema::TYPES, true)) {
            throw HttpException::unprocessable('Unknown review type.', ['review_type' => ['Unsupported review type.']]);
        }
        Gate::authorize($user, 'project.runAi', $project);
        $agent = AgentRegistry::for($type);
        $version = null;
        if ($agent->needsDocument() || $doc) {
            if (!$doc) {
                throw HttpException::unprocessable('A document is required for this review.', ['document_id' => ['Select a document.']]);
            }
            if ((int) $doc['project_id'] !== (int) $project['id'] || $doc['deleted_at'] !== null) {
                throw HttpException::notFound('Document');
            }
            if (!(new DocumentPolicy($project))->runReview($user, $doc)) {
                throw HttpException::forbidden();
            }
            $version = DocumentService::currentVersion($doc);
            if (!$version || $version['extraction_status'] !== 'completed') {
                throw HttpException::unprocessable('Text has not been extracted from this document yet (status: ' . ($version['extraction_status'] ?? 'none') . '). ' . ($version['extraction_error'] ?? ''));
            }
        }
        if ($type === 'constitution_check' && !ConstitutionService::current($project)) {
            throw HttpException::conflict('No approved Research Constitution exists to compare against.');
        }
        $svc = AI\AiGuard::assertAllowed($user, $project, 'review:' . $type);

        $jobId = AiReviewJob::create([
            'project_id' => $project['id'], 'document_id' => $doc['id'] ?? null, 'version_id' => $version['id'] ?? null,
            'review_type' => $type, 'status' => 'queued', 'requested_by' => $user['id'], 'provider' => $svc->provider(), 'model' => $svc->model(),
            'options' => $options ?: null,
        ]);
        $job = AiReviewJob::find($jobId);
        AuditLogger::log($r, 'ai.review_requested', 'ai_review_job', $job['uuid'], ['project' => $project['uuid'], 'type' => $type, 'document' => $doc['uuid'] ?? null, 'provider' => $svc->provider()]);
        QueueDispatcher::dispatch('ai_review', ['job_id' => $jobId]);
        return AiReviewJob::find($jobId);
    }

    public static function runJob(int $jobId): void
    {
        $job = AiReviewJob::find($jobId);
        if (!$job || !in_array($job['status'], ['queued', 'running'], true)) {
            return;
        }
        AiReviewJob::updateById($jobId, ['status' => 'running', 'started_at' => gmdate('Y-m-d H:i:s'), 'attempts' => (int) $job['attempts'] + 1, 'error_message' => null]);
        $project = ResearchProject::find((int) $job['project_id']);
        $user = AuthService::loadUser((int) $job['requested_by']);
        try {
            if (!$project || !$user) {
                throw new AIException('invalid_job', 'The project or requesting user no longer exists.');
            }
            // Re-check at execution time: consent may have been withdrawn while the job was queued.
            $svc = AiGuard::assertAllowed($user, $project, 'review:' . $job['review_type'], false);
            $agent = AgentRegistry::for($job['review_type']);
            $doc = $job['document_id'] ? Document::find((int) $job['document_id']) : null;
            $version = $job['version_id'] ? DocumentVersion::find((int) $job['version_id']) : null;
            if ($version && $version['file_deleted_at'] !== null) {
                throw new AIException('file_removed', 'The document file has been removed under the retention policy.');
            }
            $ctx = new AiRunContext($user, $project, $doc, $version, $svc, $jobId, json_decode((string) $job['options'], true) ?: []);

            $segments = $version ? DocumentService::segments((int) $version['id']) : [];
            if ($agent->needsDocument() && !$segments) {
                throw new AIException('no_text', 'No extracted text is available for this document.');
            }
            if (!$agent->needsDocument() && !$segments) {
                $segments = [['seq' => 1, 'page' => null, 'section' => 'Project data', 'content' => \App\Agents\ResearchProjectAgent::promptContext($project, 12000)]];
            }
            $out = \App\Agents\StagedAnalyzer::run($agent, $ctx, $segments);
            $reviewId = self::persist($job, $project, $svc, $agent, $ctx, $out['review'], $out['meta']);
            AiReviewJob::updateById($jobId, ['status' => 'completed', 'finished_at' => gmdate('Y-m-d H:i:s'), 'review_id' => $reviewId, 'progress_note' => null]);
            $review = AiReview::find($reviewId);
            NotificationService::notify((int) $user['id'], 'ai_review_ready', 'AI review ready: ' . $agent->label(), 'Review the AI suggestions - they are not an official assessment.', '/reviews/' . $review['uuid']);
            foreach (Database::fetchAll("SELECT user_id FROM research_project_members WHERE project_id = ? AND member_role IN ('supervisor','co_supervisor') AND ended_at IS NULL", [$project['id']]) as $m) {
                if ((int) $m['user_id'] !== (int) $user['id']) {
                    NotificationService::notify((int) $m['user_id'], 'ai_review_ready', 'AI review available: ' . $project['title'], $agent->label(), '/reviews/' . $review['uuid']);
                }
            }
        } catch (AIException $e) {
            self::fail($jobId, $e->getMessage());
        } catch (HttpException $e) {
            self::fail($jobId, $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('AI review job ' . $jobId . ' crashed: ' . get_class($e) . ': ' . $e->getMessage());
            self::fail($jobId, 'The review could not be completed because of an internal error.');
        }
    }

    private static function fail(int $jobId, string $message): void
    {
        AiReviewJob::updateById($jobId, ['status' => 'failed', 'finished_at' => gmdate('Y-m-d H:i:s'), 'error_message' => mb_substr($message, 0, 480)]);
        $job = AiReviewJob::find($jobId);
        NotificationService::notify((int) $job['requested_by'], 'ai_review_failed', 'AI review could not be completed', mb_substr($message, 0, 300), null);
    }

    private static function persist(array $job, array $project, AI\AIServiceInterface $svc, \App\Agents\ReviewAgent $agent, AiRunContext $ctx, array $review, array $meta): int
    {
        return Database::transaction(function () use ($job, $project, $svc, $agent, $ctx, $review, $meta) {
            $meta += ['provider' => $svc->provider(), 'model' => $svc->model(), 'mock' => $svc->isMock(), 'generated_at' => gmdate('c')];
            $id = AiReview::create([
                'job_id' => $job['id'], 'project_id' => $project['id'], 'document_id' => $job['document_id'], 'version_id' => $job['version_id'],
                'review_type' => $job['review_type'], 'provider' => $svc->provider(), 'model' => mb_substr($svc->model(), 0, 80), 'is_mock' => $svc->isMock() ? 1 : 0,
                'summary' => $review['summary'], 'result_json' => json_encode(['review' => $review, 'meta' => $meta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by' => $job['requested_by'],
            ]);
            foreach (array_merge($review['major_issues'], $review['minor_issues']) as $i) {
                AiReviewIssue::create([
                    'review_id' => $id, 'issue_code' => $i['issue_code'], 'category' => $i['category'], 'severity' => $i['severity'],
                    'section' => $i['section'] ?: null, 'page_number' => $i['page'], 'location' => $i['location'] ?: null, 'issue' => $i['issue'],
                    'evidence' => $i['evidence'] ?: null, 'evidence_verified' => $i['evidence_verified'] === null ? null : (int) $i['evidence_verified'],
                    'implication' => $i['implication'] ?: null, 'recommendation' => $i['recommendation'] ?: null, 'supervisor_question' => $i['supervisor_question'] ?: null,
                    'requires_human_verification' => $i['requires_human_verification'] ? 1 : 0,
                ]);
            }
            $row = AiReview::find($id);
            $agent->afterSave($ctx, $row, $review);
            return $id;
        });
    }

    // ------------------------------------------------------------ presentation

    public static function presentJob(array $job): array
    {
        $review = $job['review_id'] ? AiReview::find((int) $job['review_id']) : null;
        return [
            'id' => $job['uuid'], 'type' => $job['review_type'], 'status' => $job['status'], 'error' => $job['error_message'],
            'review_id' => $review['uuid'] ?? null, 'provider' => $job['provider'], 'created_at' => $job['created_at'], 'finished_at' => $job['finished_at'],
        ];
    }

    public static function presentIssue(array $i): array
    {
        return [
            'id' => $i['uuid'], 'code' => $i['issue_code'], 'category' => $i['category'], 'severity' => $i['severity'], 'section' => $i['section'], 'page' => $i['page_number'],
            'location' => $i['location'], 'issue' => $i['issue'], 'evidence' => $i['evidence'],
            'evidence_verified' => $i['evidence_verified'] === null ? null : (bool) $i['evidence_verified'],
            'implication' => $i['implication'], 'recommendation' => $i['recommendation'], 'supervisor_question' => $i['supervisor_question'],
            'requires_human_verification' => (bool) $i['requires_human_verification'],
            'status' => $i['status'], 'resolution_note' => $i['resolution_note'], 'resolved_at' => $i['resolved_at'],
            'origin' => 'ai_suggestion',
        ];
    }

    public static function present(array $review, bool $full = true): array
    {
        $project = ResearchProject::find((int) $review['project_id']);
        $doc = $review['document_id'] ? Document::find((int) $review['document_id']) : null;
        $ver = $review['version_id'] ? DocumentVersion::find((int) $review['version_id']) : null;
        $by = $review['human_reviewed_by'] ? User::find((int) $review['human_reviewed_by']) : null;
        $decoded = json_decode($review['result_json'], true) ?: [];
        $out = [
            'id' => $review['uuid'], 'type' => $review['review_type'], 'project_id' => $project['uuid'], 'project_title' => $project['title'],
            'document' => $doc ? ['id' => $doc['uuid'], 'title' => $doc['title'], 'version' => $ver ? (int) $ver['version_number'] : null] : null,
            'summary' => $review['summary'], 'created_at' => $review['created_at'],
            'origin' => 'ai_suggestion',
            'label' => 'AI-generated suggestions - not an official assessment, grade or decision.',
            'ai' => ['provider' => $review['provider'], 'model' => $review['model'], 'mock' => (bool) $review['is_mock']],
            'mock_warning' => $review['is_mock'] ? 'This review was produced by the development mock AI and contains no real analysis.' : null,
            'human_review' => ['status' => $review['human_status'], 'by' => $by ? ProjectService::person($by) : null, 'at' => $review['human_reviewed_at'], 'notes' => $review['human_notes']],
        ];
        if ($full) {
            $r = $decoded['review'] ?? [];
            foreach (['major_issues', 'minor_issues'] as $k) {
                unset($r[$k]); // issues are served from their own table (they carry live review status)
            }
            $out['result'] = $r;
            $out['meta'] = $decoded['meta'] ?? [];
            $out['issues'] = array_map([self::class, 'presentIssue'], AiReviewIssue::where(['review_id' => $review['id']], "FIELD(category,'major','minor'), FIELD(severity,'critical','high','medium','low'), id"));
        }
        return $out;
    }

    // ------------------------------------------------------------ human decisions

    public static function decide(Request $r, array $user, array $review, string $status, ?string $notes): array
    {
        Gate::authorize($user, 'review.decide', $review);
        AiReview::updateById((int) $review['id'], ['human_status' => $status, 'human_reviewed_by' => $user['id'], 'human_reviewed_at' => gmdate('Y-m-d H:i:s'), 'human_notes' => $notes]);
        $project = ResearchProject::find((int) $review['project_id']);
        Database::insert('supervision_approvals', [
            'uuid' => Uuid::v4(), 'project_id' => $project['id'], 'subject_type' => 'ai_review', 'subject_id' => $review['id'],
            'decision' => $status === 'rejected' ? 'rejected' : 'approved', 'notes' => $notes, 'decided_by' => $user['id'],
        ]);
        AuditLogger::log($r, 'ai.review_decision', 'ai_review', $review['uuid'], ['project' => $project['uuid'], 'status' => $status]);
        NotificationService::notify((int) $project['owner_user_id'], 'ai_review_decision', 'Supervisor reviewed an AI review', 'Decision: ' . $status, '/reviews/' . $review['uuid']);
        return AiReview::find((int) $review['id']);
    }

    public static function updateIssue(Request $r, array $user, array $review, array $issue, string $status, ?string $note): array
    {
        $supervisorOnly = in_array($status, ['accepted', 'modified', 'rejected'], true);
        Gate::authorize($user, $supervisorOnly ? 'review.decide' : 'review.markAddressed', $review);
        AiReviewIssue::updateById((int) $issue['id'], [
            'status' => $status, 'resolution_note' => $note, 'resolved_by' => $user['id'],
            'resolved_at' => in_array($status, ['rejected', 'resolved'], true) ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $project = ResearchProject::find((int) $review['project_id']);
        AuditLogger::log($r, 'ai.issue_' . $status, 'ai_review_issue', $issue['uuid'], ['project' => $project['uuid'], 'review' => $review['uuid'], 'code' => $issue['issue_code']]);
        return AiReviewIssue::find((int) $issue['id']);
    }
}
