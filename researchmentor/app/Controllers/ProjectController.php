<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\{ResearchProject, User};
use App\Policies\{Gate, ProjectAccess};
use App\Services\{AuditLogger, ProjectService};
use App\Support\{Database, HttpException, Request, Response, Roles};

final class ProjectController extends BaseController
{
    public function index(Request $r): Response
    {
        $user = $this->user($r);
        $ids = ProjectAccess::visibleProjectIds($user);
        if (!$ids) {
            return $this->ok([], ['total' => 0, 'page' => 1, 'per_page' => $this->perPage($r)]);
        }
        $where = ['p.id IN (' . Database::placeholders($ids) . ')'];
        $params = $ids;
        if ($r->input('stage') && in_array($r->input('stage'), ProjectService::STAGES, true)) {
            $where[] = 'p.stage = ?'; $params[] = $r->input('stage');
        }
        $status = $r->input('status');
        if ($status && in_array($status, ProjectService::STATUSES, true)) {
            $where[] = 'p.status = ?'; $params[] = $status;
        } elseif (!$status) {
            $where[] = "p.status <> 'archived'";
        }
        if ($q = trim((string) $r->input('q', ''))) {
            $where[] = '(p.title LIKE ? OR p.research_area LIKE ? OR CONCAT(o.first_name, " ", o.last_name) LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $sql = ' FROM research_projects p JOIN users o ON o.id = p.owner_user_id WHERE ' . implode(' AND ', $where);
        $total = (int) Database::fetchValue('SELECT COUNT(*)' . $sql, $params);
        $per = $this->perPage($r);
        $offset = ($this->page($r) - 1) * $per;
        $rows = Database::fetchAll('SELECT p.*, CONCAT(o.first_name, " ", o.last_name) AS student_name' . $sql . ' ORDER BY p.updated_at DESC LIMIT ' . $per . ' OFFSET ' . $offset, $params);
        return $this->ok(array_map([ProjectService::class, 'listRow'], $rows), ['total' => $total, 'page' => $this->page($r), 'per_page' => $per]);
    }

    public function store(Request $r): Response
    {
        $user = $this->user($r);
        Gate::authorize($user, 'project.create');
        $data = $this->validate($r, ProjectService::createRules());
        $project = ProjectService::create($r, $user, $data);
        return $this->created(ProjectService::present($project, $user));
    }

    public function show(Request $r): Response
    {
        $project = $this->project($r);
        $user = $this->user($r);
        $out = ProjectService::present($project, $user);
        if (Gate::allows($user, 'project.viewProgress', $project)) {
            $out['progress'] = ProjectService::progressSnapshot($project);
        }
        return $this->ok($out);
    }

    public function update(Request $r): Response
    {
        $project = $this->project($r, 'project.update');
        $user = $this->user($r);
        $data = $this->validate($r, array_intersect_key(ProjectService::updateRules(), $r->body)); // partial update
        $updated = ProjectService::update($r, $user, $project, $data);
        return $this->ok(ProjectService::present($updated, $user));
    }

    public function archive(Request $r): Response
    {
        $project = $this->project($r, 'project.archive');
        $user = $this->user($r);
        $updated = $r->input('restore') ? ProjectService::unarchive($r, $user, $project) : ProjectService::archive($r, $user, $project);
        return $this->ok(ProjectService::present($updated, $user));
    }

    public function assign(Request $r): Response
    {
        $project = $this->project($r);
        $user = $this->user($r);
        $data = $this->validate($r, ['user_id' => 'required|uuid', 'role' => 'required|in:supervisor,co_supervisor,examiner']);
        ProjectService::assignMember($r, $user, $project, $data['user_id'], $data['role']);
        return $this->ok(['members' => ProjectService::members($project)]);
    }

    public function unassign(Request $r): Response
    {
        $project = $this->project($r);
        $user = $this->user($r);
        $data = $this->validate($r, ['user_id' => 'required|uuid', 'role' => 'required|in:co_supervisor,examiner']);
        ProjectService::removeMember($r, $user, $project, $data['user_id'], $data['role']);
        return $this->ok(['members' => ProjectService::members($project)]);
    }

    public function stage(Request $r): Response
    {
        $project = $this->project($r, 'project.changeStage');
        $user = $this->user($r);
        $data = $this->validate($r, ['stage' => 'required|string', 'notes' => 'nullable|string|max:2000']);
        $updated = ProjectService::changeStage($r, $user, $project, $data['stage'], $data['notes'] ?? null);
        return $this->ok(ProjectService::present($updated, $user));
    }

    public function stageHistory(Request $r): Response
    {
        $project = $this->project($r, 'project.viewProgress');
        $rows = Database::fetchAll('SELECT h.from_stage, h.to_stage, h.notes, h.created_at, u.first_name, u.last_name FROM research_stage_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.project_id = ? ORDER BY h.id DESC', [$project['id']]);
        return $this->ok(array_map(fn($h) => ['from' => $h['from_stage'], 'to' => $h['to_stage'], 'notes' => $h['notes'], 'at' => $h['created_at'], 'by' => trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''))], $rows));
    }

    public function aiConsent(Request $r): Response
    {
        $project = $this->project($r, 'project.giveAiConsent');
        $data = $this->validate($r, ['consent' => 'required|bool']);
        $updated = ProjectService::setAiConsent($r, $this->user($r), $project, $data['consent']);
        return $this->ok(['ai_consent' => $updated['ai_consent_at'] !== null, 'ai_consent_at' => $updated['ai_consent_at']]);
    }

    public function timeline(Request $r): Response
    {
        $project = $this->project($r, 'project.viewProgress');
        return $this->ok(ProjectService::timeline($project));
    }

    public function history(Request $r): Response
    {
        $project = $this->project($r, 'project.viewContent');
        return $this->ok(ProjectService::history($project));
    }

    public function export(Request $r): Response
    {
        $project = $this->project($r, 'project.export');
        $user = $this->user($r);
        AuditLogger::log($r, 'project.export', 'project', $project['uuid'], ['project' => $project['uuid'], 'format' => 'json']);
        $summary = \App\Services\ProjectSummary::build($project, $user);
        return Response::json(['success' => true, 'data' => $summary], 200, ['Content-Disposition' => 'attachment; filename="project-summary.json"']);
    }

    public function overview(Request $r): Response
    {
        $project = $this->project($r, 'project.viewProgress');
        $user = $this->user($r);
        $out = ProjectService::present($project, $user);
        $out['progress'] = ProjectService::progressSnapshot($project);
        $out['timeline'] = array_slice(ProjectService::timeline($project), 0, 10);
        if (Gate::allows($user, 'project.viewContent', $project)) {
            $out['open_review_issues'] = (int) Database::fetchValue("SELECT COUNT(*) FROM ai_review_issues i JOIN ai_reviews r ON r.id = i.review_id WHERE r.project_id = ? AND i.status = 'open'", [$project['id']]);
            $out['documents'] = (int) Database::fetchValue('SELECT COUNT(*) FROM documents WHERE project_id = ? AND deleted_at IS NULL', [$project['id']]);
            $out['constitution_version'] = (int) Database::fetchValue('SELECT COALESCE(MAX(version_number),0) FROM research_constitutions WHERE project_id = ?', [$project['id']]);
        }
        return $this->ok($out);
    }
}
