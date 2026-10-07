<?php
declare(strict_types=1);

namespace App\Services;

use App\Agents\SupervisionAgent;
use App\Models\{Document, DocumentVersion, ResearchProject, SupervisionActionItem, SupervisionSession, User};
use App\Policies\{Gate, ProjectAccess};
use App\Services\AI\AiGuard;
use App\Support\{Database, HttpException, Request, Uuid};

final class SupervisionService
{
    public static function utc(string $when): string
    {
        $ts = strtotime($when);
        if ($ts === false) {
            throw HttpException::unprocessable('Invalid date.', ['scheduled_at' => ['Invalid date/time.']]);
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function origin(array $user, array $project): string
    {
        return in_array('supervisor', ProjectAccess::relations($user, $project), true) ? 'supervisor' : 'student';
    }

    private static function counterparts(array $project, array $actor): array
    {
        $rows = Database::fetchAll("SELECT user_id FROM research_project_members WHERE project_id = ? AND member_role IN ('student','supervisor','co_supervisor') AND ended_at IS NULL", [$project['id']]);
        return array_values(array_filter(array_map(fn($r) => (int) $r['user_id'], $rows), fn($id) => $id !== (int) $actor['id']));
    }

    // ----------------------------------------------------------------- meetings

    public static function createMeeting(Request $r, array $actor, array $project, array $data): array
    {
        Gate::authorize($actor, 'supervision.createMeeting', $project);
        $id = Database::transaction(function () use ($actor, $project, $data) {
            $id = SupervisionSession::create([
                'project_id' => $project['id'], 'title' => $data['title'], 'scheduled_at' => self::utc($data['scheduled_at']),
                'duration_minutes' => $data['duration_minutes'] ?? 60, 'location' => $data['location'] ?? null, 'created_by' => $actor['id'],
            ]);
            $pos = 0;
            foreach ($data['agenda_items'] ?? [] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    Database::insert('supervision_agendas', ['session_id' => $id, 'position' => ++$pos, 'item' => mb_substr(trim($item), 0, 500), 'origin' => self::origin($actor, $project), 'state' => 'accepted', 'decided_by' => $actor['id']]);
                }
            }
            return $id;
        });
        $s = SupervisionSession::find($id);
        AuditLogger::log($r, 'supervision.meeting_create', 'supervision_session', $s['uuid'], ['project' => $project['uuid']]);
        NotificationService::notifyMany(self::counterparts($project, $actor), 'meeting_scheduled', 'Meeting scheduled: ' . $s['title'], 'On ' . $s['scheduled_at'] . ' (UTC)', '/projects/' . $project['uuid'] . '/supervision');
        return $s;
    }

    public static function updateMeeting(Request $r, array $actor, array $project, array $session, array $data): array
    {
        Gate::authorize($actor, 'supervision.createMeeting', $project);
        $upd = array_intersect_key($data, array_flip(['title', 'duration_minutes', 'location', 'discussion_notes', 'status']));
        if (isset($data['scheduled_at'])) {
            $upd['scheduled_at'] = self::utc($data['scheduled_at']);
        }
        // Decisions are an official record: supervisors only.
        if (array_key_exists('decisions', $data)) {
            Gate::authorize($actor, 'supervision.recordOutcome', $project);
            $upd['decisions'] = $data['decisions'];
        }
        SupervisionSession::updateById((int) $session['id'], $upd);
        AuditLogger::log($r, 'supervision.meeting_update', 'supervision_session', $session['uuid'], ['project' => $project['uuid'], 'fields' => array_keys($upd)]);
        return SupervisionSession::find((int) $session['id']);
    }

    /** Official supervision outcome - supervisor only; recorded as a human approval. */
    public static function recordOutcome(Request $r, array $actor, array $project, array $session, string $outcome): array
    {
        Gate::authorize($actor, 'supervision.recordOutcome', $project);
        Database::transaction(function () use ($actor, $project, $session, $outcome) {
            SupervisionSession::updateById((int) $session['id'], ['outcome' => $outcome, 'outcome_recorded_by' => $actor['id'], 'outcome_recorded_at' => gmdate('Y-m-d H:i:s'), 'status' => 'completed']);
            Database::insert('supervision_approvals', ['uuid' => Uuid::v4(), 'project_id' => $project['id'], 'subject_type' => 'session_outcome', 'subject_id' => $session['id'], 'decision' => 'acknowledged', 'notes' => mb_substr($outcome, 0, 2000), 'decided_by' => $actor['id']]);
        });
        AuditLogger::log($r, 'supervision.outcome_recorded', 'supervision_session', $session['uuid'], ['project' => $project['uuid']]);
        NotificationService::notifyMany(self::counterparts($project, $actor), 'meeting_outcome', 'Supervision outcome recorded', $session['title'], '/projects/' . $project['uuid'] . '/supervision');
        return SupervisionSession::find((int) $session['id']);
    }

    /** Optional AI brief. Stored separately from notes/decisions; suggestions need human acceptance. */
    public static function generateBrief(Request $r, array $actor, array $project, array $session): array
    {
        Gate::authorize($actor, 'supervision.recordOutcome', $project); // supervisors only
        $svc = AiGuard::assertAllowed($actor, $project, 'supervision_brief');
        try {
            $out = SupervisionAgent::brief($svc, $actor, $project);
        } catch (AI\AIException $e) {
            throw new HttpException(502, 'ai_failed', $e->getMessage());
        }
        $b = $out['brief'];
        $text = ($svc->isMock() ? '[MOCK AI - development substitute] ' : '') . $b['summary'];
        if ($b['follow_up_questions']) {
            $text .= "\n\nFollow-up questions:\n- " . implode("\n- ", $b['follow_up_questions']);
        }
        if ($b['unresolved_issues']) {
            $text .= "\n\nUnresolved issues:\n- " . implode("\n- ", $b['unresolved_issues']);
        }
        Database::transaction(function () use ($session, $b, $text, $actor) {
            SupervisionSession::updateById((int) $session['id'], ['ai_brief' => $text, 'ai_brief_generated_at' => gmdate('Y-m-d H:i:s')]);
            $pos = (int) Database::fetchValue('SELECT COALESCE(MAX(position),0) FROM supervision_agendas WHERE session_id = ?', [$session['id']]);
            foreach ($b['agenda_items'] as $item) {
                Database::insert('supervision_agendas', ['session_id' => $session['id'], 'position' => ++$pos, 'item' => mb_substr($item, 0, 500), 'origin' => 'ai_suggestion', 'state' => 'proposed']);
            }
            foreach ($b['suggested_actions'] as $a) {
                // Suggested actions are PROPOSED items: they only become tasks when a supervisor accepts them.
                $assignee = Database::fetchValue("SELECT user_id FROM research_project_members WHERE project_id = ? AND member_role = 'student' AND ended_at IS NULL LIMIT 1", [$session['project_id']]);
                SupervisionActionItem::create([
                    'project_id' => $session['project_id'], 'session_id' => $session['id'], 'kind' => 'task', 'title' => $a['title'], 'description' => $a['description'],
                    'assigned_to' => $assignee, 'created_by' => $actor['id'], 'origin' => 'ai_suggestion', 'state' => 'proposed',
                ]);
            }
        });
        AuditLogger::log($r, 'supervision.brief_generated', 'supervision_session', $session['uuid'], ['project' => $project['uuid'], 'provider' => $svc->provider()]);
        return SupervisionSession::find((int) $session['id']);
    }

    public static function decideAgenda(Request $r, array $actor, array $project, array $session, int $agendaId, string $decision): void
    {
        Gate::authorize($actor, 'supervision.recordOutcome', $project);
        $row = Database::fetchOne('SELECT * FROM supervision_agendas WHERE id = ? AND session_id = ?', [$agendaId, $session['id']]);
        if (!$row) {
            throw HttpException::notFound('Agenda item');
        }
        Database::execute('UPDATE supervision_agendas SET state = ?, decided_by = ? WHERE id = ?', [$decision === 'accept' ? 'accepted' : 'rejected', $actor['id'], $agendaId]);
        AuditLogger::log($r, 'supervision.agenda_' . $decision, 'supervision_session', $session['uuid'], ['project' => $project['uuid']]);
    }

    public static function presentSession(array $s, bool $full = false): array
    {
        $out = [
            'id' => $s['uuid'], 'title' => $s['title'], 'scheduled_at' => $s['scheduled_at'], 'duration_minutes' => (int) $s['duration_minutes'], 'location' => $s['location'],
            'status' => $s['status'], 'has_ai_brief' => $s['ai_brief'] !== null, 'outcome_recorded' => $s['outcome'] !== null,
        ];
        if ($full) {
            $out += [
                'discussion_notes' => $s['discussion_notes'], 'decisions' => $s['decisions'],
                'ai_brief' => $s['ai_brief'] === null ? null : ['text' => $s['ai_brief'], 'origin' => 'ai_suggestion', 'generated_at' => $s['ai_brief_generated_at'], 'label' => 'AI-generated suggestion - not an official record'],
                'official_outcome' => $s['outcome'] === null ? null : ['text' => $s['outcome'], 'origin' => 'official', 'recorded_at' => $s['outcome_recorded_at'], 'recorded_by' => ProjectService::person(User::find((int) $s['outcome_recorded_by']))],
                'agenda' => array_map(fn($a) => ['id' => (int) $a['id'], 'item' => $a['item'], 'origin' => $a['origin'], 'state' => $a['state']], Database::fetchAll('SELECT * FROM supervision_agendas WHERE session_id = ? ORDER BY position, id', [$s['id']])),
                'tasks' => array_map([self::class, 'presentTask'], SupervisionActionItem::where(['session_id' => $s['id']], 'id')),
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------- tasks / revision workflow

    public static function createTask(Request $r, array $actor, array $project, array $data): array
    {
        Gate::authorize($actor, 'supervision.createTask', $project);
        $isSup = ProjectAccess::has($actor, $project, 'supervisor');
        $assignee = $actor;
        if (!empty($data['assigned_to']) && $data['assigned_to'] !== $actor['uuid']) {
            Gate::authorize($actor, 'supervision.assignTaskToOthers', $project);
            $assignee = self::memberByUuid($project, $data['assigned_to']);
        } elseif (empty($data['assigned_to']) && $isSup) {
            // default: assign to the student
            $assignee = AuthService::loadUser((int) Database::fetchValue("SELECT user_id FROM research_project_members WHERE project_id = ? AND member_role = 'student' AND ended_at IS NULL LIMIT 1", [$project['id']]));
        }
        $kind = $data['kind'] ?? 'task';
        if ($kind === 'revision_request' && !$isSup) {
            throw HttpException::forbidden('Only supervisors can request revisions.');
        }
        $session = null;
        if (!empty($data['session_id'])) {
            $session = SupervisionSession::findByUuid($data['session_id']);
            if (!$session || (int) $session['project_id'] !== (int) $project['id']) {
                throw HttpException::unprocessable('Unknown meeting.', ['session_id' => ['Unknown meeting.']]);
            }
        }
        $doc = null;
        if (!empty($data['document_id'])) {
            $doc = Document::findByUuid($data['document_id']);
            if (!$doc || (int) $doc['project_id'] !== (int) $project['id'] || $doc['deleted_at'] !== null) {
                throw HttpException::unprocessable('Unknown document.', ['document_id' => ['Unknown document.']]);
            }
        }
        if ($kind === 'revision_request' && !$doc) {
            throw HttpException::unprocessable('A revision request must reference a document.', ['document_id' => ['Select the document to revise.']]);
        }
        $id = SupervisionActionItem::create([
            'project_id' => $project['id'], 'session_id' => $session['id'] ?? null, 'document_id' => $doc['id'] ?? null, 'kind' => $kind,
            'title' => $data['title'], 'description' => $data['description'] ?? null, 'assigned_to' => $assignee['id'], 'created_by' => $actor['id'],
            'origin' => $isSup ? 'supervisor' : 'student', 'due_date' => $data['due_date'] ?? null, 'state' => 'open',
        ]);
        $t = SupervisionActionItem::find($id);
        AuditLogger::log($r, 'supervision.task_create', 'supervision_task', $t['uuid'], ['project' => $project['uuid'], 'kind' => $kind]);
        if ((int) $assignee['id'] !== (int) $actor['id']) {
            NotificationService::notify((int) $assignee['id'], $kind === 'revision_request' ? 'revision_requested' : 'task_assigned', ($kind === 'revision_request' ? 'Revision requested: ' : 'New task: ') . $t['title'], $t['due_date'] ? 'Due ' . $t['due_date'] : null, '/projects/' . $project['uuid'] . '/supervision');
        }
        return $t;
    }

    private static function memberByUuid(array $project, string $uuid): array
    {
        $u = User::findByUuid($uuid);
        $ok = $u && Database::fetchValue('SELECT COUNT(*) FROM research_project_members WHERE project_id = ? AND user_id = ? AND ended_at IS NULL AND member_role IN (\'student\',\'supervisor\',\'co_supervisor\')', [$project['id'], $u['id']]);
        if (!$ok) {
            throw HttpException::unprocessable('Assignee must be a member of the project.', ['assigned_to' => ['Not a project member.']]);
        }
        return AuthService::loadUser((int) $u['id']);
    }

    /**
     * Transitions:
     *  proposed -> open | cancelled          (supervisor accepts / rejects an AI suggestion)
     *  open|in_progress -> in_progress|submitted (assignee or supervisor)
     *  submitted -> completed | open          (supervisor accepts the work / asks for more)
     *  any -> cancelled, completed -> open   (supervisor)
     */
    public static function updateTask(Request $r, array $actor, array $project, array $task, array $data): array
    {
        Gate::authorize($actor, 'supervision.updateTask', $project, $task);
        $isSup = ProjectAccess::has($actor, $project, 'supervisor');
        $upd = [];
        foreach (['title', 'description', 'due_date'] as $f) {
            if (array_key_exists($f, $data)) {
                if (!$isSup && !((int) $task['created_by'] === (int) $actor['id'])) {
                    throw HttpException::forbidden('You can only edit tasks you created.');
                }
                $upd[$f] = $data[$f];
            }
        }
        if (array_key_exists('student_response', $data)) {
            $upd['student_response'] = $data['student_response'];
        }
        $notifyTo = [];
        if (!empty($data['state']) && $data['state'] !== $task['state']) {
            $to = $data['state'];
            $from = $task['state'];
            $selfOwned = $task['origin'] === 'student' && (int) $task['created_by'] === (int) $actor['id'];
            $allowed = match (true) {
                $isSup => true,
                $from === 'proposed' => false,
                in_array($from, ['open', 'in_progress'], true) => in_array($to, ['in_progress', 'submitted'], true) || ($selfOwned && in_array($to, ['completed', 'cancelled'], true)),
                $from === 'submitted' => $to === 'in_progress',
                default => false,
            };
            if (!$allowed) {
                throw HttpException::forbidden($from === 'proposed' ? 'Only a supervisor can accept AI-suggested tasks.' : 'You are not permitted to make this change.');
            }
            if ($from === 'proposed' && !in_array($to, ['open', 'cancelled'], true)) {
                throw HttpException::unprocessable('Proposed tasks can only be accepted (open) or rejected (cancelled).');
            }
            $upd['state'] = $to;
            if ($to === 'submitted') {
                $upd['submitted_at'] = gmdate('Y-m-d H:i:s');
                $notifyTo = array_merge($notifyTo, array_map(fn($m) => (int) $m['user_id'], Database::fetchAll("SELECT user_id FROM research_project_members WHERE project_id = ? AND member_role IN ('supervisor','co_supervisor') AND ended_at IS NULL", [$project['id']])));
            }
            if ($to === 'completed') {
                $upd['completed_at'] = gmdate('Y-m-d H:i:s');
                $upd['completed_by'] = $actor['id'];
                if ($isSup && $task['state'] === 'submitted') {
                    $notifyTo[] = (int) $task['assigned_to'];
                }
            }
            if ($to === 'open' && $from !== 'proposed') {
                $upd['completed_at'] = null;
                $notifyTo[] = (int) $task['assigned_to'];
            }
            if ($from === 'proposed' && $to === 'open') {
                $notifyTo[] = (int) $task['assigned_to'];
            }
        }
        if (!$upd) {
            return $task;
        }
        SupervisionActionItem::updateById((int) $task['id'], $upd);
        $fresh = SupervisionActionItem::find((int) $task['id']);
        AuditLogger::log($r, 'supervision.task_update', 'supervision_task', $task['uuid'], ['project' => $project['uuid'], 'from' => $task['state'], 'to' => $fresh['state']]);
        if ($isSup && isset($upd['state']) && in_array($upd['state'], ['completed', 'open', 'cancelled'], true) && $task['state'] === 'submitted') {
            Database::insert('supervision_approvals', [
                'uuid' => Uuid::v4(), 'project_id' => $project['id'], 'subject_type' => $task['document_id'] ? 'document_version' : 'proposal_change',
                'subject_id' => $task['document_id'] ? (int) (Document::find((int) $task['document_id'])['current_version_id'] ?? 0) : $task['id'],
                'decision' => $upd['state'] === 'completed' ? 'approved' : 'revision_requested', 'notes' => $data['review_note'] ?? null, 'decided_by' => $actor['id'],
            ]);
        }
        $me = (int) $actor['id'];
        NotificationService::notifyMany(array_filter(array_unique($notifyTo), fn($id) => $id !== $me), 'task_updated', 'Task update: ' . $fresh['title'], 'Now: ' . str_replace('_', ' ', $fresh['state']), '/projects/' . $project['uuid'] . '/supervision');
        return $fresh;
    }

    public static function presentTask(array $t): array
    {
        $assignee = User::find((int) $t['assigned_to']);
        $doc = $t['document_id'] ? Document::find((int) $t['document_id']) : null;
        $overdue = $t['due_date'] !== null && $t['due_date'] < gmdate('Y-m-d') && in_array($t['state'], ['open', 'in_progress'], true);
        return [
            'id' => $t['uuid'], 'kind' => $t['kind'], 'title' => $t['title'], 'description' => $t['description'], 'state' => $t['state'], 'origin' => $t['origin'],
            'due_date' => $t['due_date'], 'overdue' => $overdue, 'student_response' => $t['student_response'],
            'assigned_to' => ProjectService::person($assignee), 'document' => $doc ? ['id' => $doc['uuid'], 'title' => $doc['title']] : null,
            'submitted_at' => $t['submitted_at'], 'completed_at' => $t['completed_at'], 'created_at' => $t['created_at'],
            'awaiting_supervisor_acceptance' => $t['state'] === 'proposed',
            'label' => $t['origin'] === 'ai_suggestion' ? 'AI-suggested - needs supervisor acceptance' : null,
        ];
    }
}
