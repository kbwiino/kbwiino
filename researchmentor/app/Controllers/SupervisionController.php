<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\{Document, DocumentVersion, ResearchProject, SupervisionActionItem, SupervisionComment, SupervisionSession};
use App\Policies\{Gate, ProjectAccess};
use App\Services\{AuditLogger, NotificationService, ProjectService, SupervisionService};
use App\Support\{Database, HttpException, Request, Response, Uuid};

final class SupervisionController extends BaseController
{
    private function projectByUuid(Request $r, string $uuid, string $ability): array
    {
        return $this->project($r, $ability, $uuid);
    }

    private function sessionOf(Request $r, string $ability = 'supervision.view'): array
    {
        $s = SupervisionSession::findByUuid((string) $r->param('id'));
        $project = $s ? ResearchProject::find((int) $s['project_id']) : null;
        $user = $this->user($r);
        if (!$s || !$project || !ProjectAccess::relations($user, $project) || !Gate::allows($user, 'supervision.view', $project)) {
            throw HttpException::notFound('Meeting');
        }
        Gate::authorize($user, $ability, $project);
        return [$s, $project];
    }

    private function taskOf(Request $r): array
    {
        $t = SupervisionActionItem::findByUuid((string) $r->param('id'));
        $project = $t ? ResearchProject::find((int) $t['project_id']) : null;
        $user = $this->user($r);
        if (!$t || !$project || !ProjectAccess::relations($user, $project) || !Gate::allows($user, 'supervision.view', $project)) {
            throw HttpException::notFound('Task');
        }
        return [$t, $project];
    }

    public function overview(Request $r): Response
    {
        $project = $this->project($r, 'supervision.view');
        $user = $this->user($r);
        $sessions = SupervisionSession::where(['project_id' => $project['id']], 'scheduled_at DESC');
        $tasks = SupervisionActionItem::where(['project_id' => $project['id']], "FIELD(state,'proposed','open','in_progress','submitted','completed','cancelled'), due_date IS NULL, due_date, id");
        $comments = Database::fetchAll('SELECT c.*, u.first_name, u.last_name FROM supervision_comments c JOIN users u ON u.id = c.author_id WHERE c.project_id = ? ORDER BY c.id DESC LIMIT 100', [$project['id']]);
        $approvals = Database::fetchAll('SELECT a.*, u.first_name, u.last_name FROM supervision_approvals a JOIN users u ON u.id = a.decided_by WHERE a.project_id = ? ORDER BY a.id DESC LIMIT 50', [$project['id']]);
        return $this->ok([
            'sessions' => array_map(fn($s) => SupervisionService::presentSession($s), $sessions),
            'tasks' => array_map([SupervisionService::class, 'presentTask'], $tasks),
            'comments' => array_map(fn($c) => ['id' => $c['uuid'], 'author' => trim($c['first_name'] . ' ' . $c['last_name']), 'origin' => $c['origin'], 'body' => $c['body'], 'status' => $c['status'], 'created_at' => $c['created_at']], $comments),
            'approvals' => array_map(fn($a) => ['id' => $a['uuid'], 'subject_type' => $a['subject_type'], 'decision' => $a['decision'], 'notes' => $a['notes'], 'by' => trim($a['first_name'] . ' ' . $a['last_name']), 'at' => $a['decided_at'], 'origin' => 'official'], $approvals),
            'permissions' => [
                'create_meeting' => Gate::allows($user, 'supervision.createMeeting', $project), 'record_outcome' => Gate::allows($user, 'supervision.recordOutcome', $project),
                'assign_tasks' => Gate::allows($user, 'supervision.assignTaskToOthers', $project), 'approve' => Gate::allows($user, 'supervision.approve', $project),
            ],
            'notice' => 'Official decisions are recorded by supervisors. AI-generated briefs and suggestions are never official until a supervisor accepts them.',
        ]);
    }

    // ----------------------------------------------------------------- meetings

    public function createMeeting(Request $r): Response
    {
        $data = $this->validate($r, [
            'project_id' => 'required|uuid', 'title' => 'required|string|max:255', 'scheduled_at' => 'required|datetime',
            'duration_minutes' => 'nullable|int|min:5|max:480', 'location' => 'nullable|string|max:255', 'agenda_items' => 'nullable|array|max:30',
        ]);
        $data['agenda_items'] = array_slice(array_map('strval', $r->body['agenda_items'] ?? []), 0, 30);
        $project = $this->projectByUuid($r, $data['project_id'], 'supervision.createMeeting');
        $s = SupervisionService::createMeeting($r, $this->user($r), $project, $data);
        return $this->created(SupervisionService::presentSession($s, true));
    }

    public function showMeeting(Request $r): Response
    {
        [$s] = $this->sessionOf($r);
        return $this->ok(SupervisionService::presentSession($s, true));
    }

    public function updateMeeting(Request $r): Response
    {
        [$s, $project] = $this->sessionOf($r, 'supervision.createMeeting');
        $data = $this->validate($r, array_intersect_key([
            'title' => 'required|string|max:255', 'scheduled_at' => 'required|datetime', 'duration_minutes' => 'int|min:5|max:480', 'location' => 'nullable|string|max:255',
            'discussion_notes' => 'nullable|string|max:50000', 'decisions' => 'nullable|string|max:20000', 'status' => 'required|in:scheduled,completed,cancelled',
        ], $r->body));
        $updated = SupervisionService::updateMeeting($r, $this->user($r), $project, $s, $data);
        return $this->ok(SupervisionService::presentSession($updated, true));
    }

    public function brief(Request $r): Response
    {
        [$s, $project] = $this->sessionOf($r, 'supervision.recordOutcome');
        $updated = SupervisionService::generateBrief($r, $this->user($r), $project, $s);
        return $this->ok(SupervisionService::presentSession($updated, true));
    }

    public function outcome(Request $r): Response
    {
        [$s, $project] = $this->sessionOf($r, 'supervision.recordOutcome');
        $data = $this->validate($r, ['outcome' => 'required|string|max:20000']);
        $updated = SupervisionService::recordOutcome($r, $this->user($r), $project, $s, $data['outcome']);
        return $this->ok(SupervisionService::presentSession($updated, true));
    }

    public function agendaDecision(Request $r): Response
    {
        [$s, $project] = $this->sessionOf($r, 'supervision.recordOutcome');
        $data = $this->validate($r, ['decision' => 'required|in:accept,reject']);
        SupervisionService::decideAgenda($r, $this->user($r), $project, $s, (int) $r->param('aid'), $data['decision']);
        return $this->ok(SupervisionService::presentSession(SupervisionSession::find((int) $s['id']), true));
    }

    // ----------------------------------------------------------------- tasks

    public function createTask(Request $r): Response
    {
        $data = $this->validate($r, [
            'project_id' => 'required|uuid', 'title' => 'required|string|max:300', 'description' => 'nullable|string|max:5000', 'due_date' => 'nullable|date',
            'assigned_to' => 'nullable|uuid', 'session_id' => 'nullable|uuid', 'document_id' => 'nullable|uuid', 'kind' => 'nullable|in:task,revision_request',
        ]);
        $project = $this->projectByUuid($r, $data['project_id'], 'supervision.createTask');
        $t = SupervisionService::createTask($r, $this->user($r), $project, $data);
        return $this->created(SupervisionService::presentTask($t));
    }

    public function showTask(Request $r): Response
    {
        [$t] = $this->taskOf($r);
        return $this->ok(SupervisionService::presentTask($t));
    }

    public function updateTask(Request $r): Response
    {
        [$t, $project] = $this->taskOf($r);
        $data = $this->validate($r, array_intersect_key([
            'title' => 'required|string|max:300', 'description' => 'nullable|string|max:5000', 'due_date' => 'nullable|date',
            'state' => 'required|in:open,in_progress,submitted,completed,cancelled', 'student_response' => 'nullable|string|max:10000', 'review_note' => 'nullable|string|max:3000',
        ], $r->body));
        $updated = SupervisionService::updateTask($r, $this->user($r), $project, $t, $data);
        return $this->ok(SupervisionService::presentTask($updated));
    }

    /** My tasks across projects (dashboard). */
    public function myTasks(Request $r): Response
    {
        $u = $this->user($r);
        $rows = Database::fetchAll(
            "SELECT t.*, p.uuid AS project_uuid, p.title AS project_title FROM supervision_action_items t JOIN research_projects p ON p.id = t.project_id
             WHERE t.assigned_to = ? AND t.state IN ('open','in_progress','submitted') AND p.status <> 'archived' ORDER BY t.due_date IS NULL, t.due_date LIMIT 100",
            [$u['id']]
        );
        return $this->ok(array_map(fn($t) => SupervisionService::presentTask($t) + ['project' => ['id' => $t['project_uuid'], 'title' => $t['project_title']]], $rows));
    }

    // ----------------------------------------------------------------- comments & approvals

    public function addComment(Request $r): Response
    {
        $project = $this->project($r, 'supervision.view');
        $user = $this->user($r);
        $data = $this->validate($r, ['body' => 'required|string|max:5000', 'session_id' => 'nullable|uuid', 'task_id' => 'nullable|uuid', 'parent_id' => 'nullable|uuid']);
        $session = !empty($data['session_id']) ? SupervisionSession::findByUuid($data['session_id']) : null;
        $task = !empty($data['task_id']) ? SupervisionActionItem::findByUuid($data['task_id']) : null;
        $parent = !empty($data['parent_id']) ? SupervisionComment::findByUuid($data['parent_id']) : null;
        foreach ([$session, $task, $parent] as $x) {
            if ($x !== null && (int) $x['project_id'] !== (int) $project['id']) {
                throw HttpException::unprocessable('Invalid reference.');
            }
        }
        $origin = ProjectAccess::has($user, $project, 'supervisor') ? 'supervisor' : 'student';
        $id = SupervisionComment::create(['project_id' => $project['id'], 'session_id' => $session['id'] ?? null, 'action_item_id' => $task['id'] ?? null, 'parent_id' => $parent['id'] ?? null, 'author_id' => $user['id'], 'origin' => $origin, 'body' => $data['body']]);
        $c = SupervisionComment::find($id);
        AuditLogger::log($r, 'supervision.comment', 'supervision_comment', $c['uuid'], ['project' => $project['uuid']]);
        $others = Database::fetchAll("SELECT user_id FROM research_project_members WHERE project_id = ? AND ended_at IS NULL AND member_role IN ('student','supervisor','co_supervisor') AND user_id <> ?", [$project['id'], $user['id']]);
        NotificationService::notifyMany(array_column($others, 'user_id'), 'supervision_comment', ($origin === 'supervisor' ? 'Supervisor comment' : 'Student comment'), mb_substr($data['body'], 0, 200), '/projects/' . $project['uuid'] . '/supervision');
        return $this->created(['id' => $c['uuid'], 'origin' => $origin, 'body' => $c['body'], 'status' => $c['status']]);
    }

    public function resolveComment(Request $r): Response
    {
        $project = $this->project($r, 'supervision.view');
        $c = SupervisionComment::findByUuid((string) $r->param('cid'));
        if (!$c || (int) $c['project_id'] !== (int) $project['id']) {
            throw HttpException::notFound('Comment');
        }
        SupervisionComment::updateById((int) $c['id'], ['status' => 'resolved']);
        return $this->ok(['resolved' => true]);
    }

    /** Official approval / revision request on a submitted document version (supervisors only). */
    public function approveDocument(Request $r): Response
    {
        $project = $this->project($r, 'supervision.approve');
        $user = $this->user($r);
        $data = $this->validate($r, ['document_id' => 'required|uuid', 'version' => 'nullable|int|min:1', 'decision' => 'required|in:approved,revision_requested,rejected,acknowledged', 'notes' => 'nullable|string|max:5000']);
        $doc = Document::findByUuid($data['document_id']);
        if (!$doc || (int) $doc['project_id'] !== (int) $project['id'] || $doc['deleted_at'] !== null || ($doc['visibility'] === 'private' && (int) $doc['owner_user_id'] !== (int) $user['id'])) {
            throw HttpException::notFound('Document');
        }
        $ver = !empty($data['version']) ? \App\Services\DocumentService::version($doc, (int) $data['version']) : DocumentVersion::find((int) $doc['current_version_id']);
        if (!$ver) {
            throw HttpException::notFound('Version');
        }
        $id = Database::insert('supervision_approvals', ['uuid' => Uuid::v4(), 'project_id' => $project['id'], 'subject_type' => 'document_version', 'subject_id' => $ver['id'], 'decision' => $data['decision'], 'notes' => $data['notes'] ?? null, 'decided_by' => $user['id']]);
        AuditLogger::log($r, 'supervision.document_decision', 'document', $doc['uuid'], ['project' => $project['uuid'], 'version' => (int) $ver['version_number'], 'decision' => $data['decision']]);
        NotificationService::notify((int) $doc['owner_user_id'], 'document_decision', 'Supervisor decision on ' . $doc['title'], str_replace('_', ' ', $data['decision']), '/documents/' . $doc['uuid']);
        return $this->created(['decision' => $data['decision'], 'document_version' => (int) $ver['version_number'], 'origin' => 'official']);
    }
}
