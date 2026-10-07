<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\{AiReview, SupervisionSession, VivaSession};
use App\Policies\{Gate, ProjectAccess};
use App\Models\ResearchProject;
use App\Services\Reports\{ReportBuilder, ReportService};
use App\Support\{HttpException, Request, Response};

final class ReportController extends BaseController
{
    private function format(Request $r): string
    {
        $f = strtolower((string) $r->input('format', 'pdf'));
        if (!in_array($f, ['pdf', 'docx'], true)) {
            throw HttpException::unprocessable('Unsupported format.', ['format' => ['Use pdf or docx.']]);
        }
        return $f;
    }

    public function review(Request $r): Response
    {
        $user = $this->user($r);
        $review = AiReview::findByUuid((string) $r->param('id'));
        $project = $review ? ResearchProject::find((int) $review['project_id']) : null;
        if (!$review || !$project || !ProjectAccess::relations($user, $project) || !Gate::allows($user, 'review.view', $review)) {
            throw HttpException::notFound('Review');
        }
        $report = ReportBuilder::review($review);
        return ReportService::deliver($r, $user, $project, $review, 'review-' . $review['review_type'], $report, $this->format($r));
    }

    public function meeting(Request $r): Response
    {
        $user = $this->user($r);
        $s = SupervisionSession::findByUuid((string) $r->param('id'));
        $project = $s ? ResearchProject::find((int) $s['project_id']) : null;
        if (!$s || !$project || !ProjectAccess::relations($user, $project) || !Gate::allows($user, 'supervision.view', $project)) {
            throw HttpException::notFound('Meeting');
        }
        return ReportService::deliver($r, $user, $project, null, 'supervision-meeting', ReportBuilder::meeting($s), $this->format($r));
    }

    public function progress(Request $r): Response
    {
        $project = $this->project($r, 'project.viewProgress');
        return ReportService::deliver($r, $this->user($r), $project, null, 'research-progress', ReportBuilder::progress($project), $this->format($r));
    }

    public function viva(Request $r): Response
    {
        $user = $this->user($r);
        $s = VivaSession::findByUuid((string) $r->param('id'));
        if (!$s || !Gate::allows($user, 'viva.view', $s)) {
            throw HttpException::notFound('Session');
        }
        $project = ResearchProject::findOrFail((int) $s['project_id']);
        return ReportService::deliver($r, $user, $project, null, 'viva-preparation', ReportBuilder::viva($s), $this->format($r));
    }
}
