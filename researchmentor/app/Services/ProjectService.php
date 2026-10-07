<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{AcademicYear, Department, Faculty, Institution, Programme, ProjectMember, ResearchMilestone, ResearchProject, StageHistory, User};
use App\Policies\{Gate, ProjectAccess};
use App\Support\{Database, HttpException, Request, Roles};

final class ProjectService
{
    public const STAGES = ['topic_selection', 'proposal_development', 'proposal_defense', 'ethics_approval', 'data_collection', 'data_analysis', 'thesis_writing', 'internal_review', 'examination', 'viva', 'completed'];
    public const STATUSES = ['draft', 'active', 'on_hold', 'completed', 'archived'];

    /** Columns a student/supervisor may edit through PUT. */
    public const EDITABLE = ['title', 'research_area', 'problem_statement', 'purpose', 'background', 'scope', 'significance', 'paradigm', 'approach', 'design', 'population', 'sampling_approach', 'data_collection_methods', 'data_analysis_methods', 'ethics_information'];

    public static function createRules(): array
    {
        return [
            'faculty_id' => 'required|uuid', 'department_id' => 'required|uuid', 'programme_id' => 'required|uuid',
            'academic_year_id' => 'required|uuid', 'degree_level' => 'required|in:masters,phd',
            'title' => 'required|string|max:500', 'research_area' => 'required|string|max:255',
            'problem_statement' => 'required|string|max:20000', 'purpose' => 'required|string|max:20000',
            'approach' => 'required|in:quantitative,qualitative,mixed,other', 'design' => 'required|string|max:255',
            'supervisor_id' => 'nullable|uuid', 'student_id' => 'nullable|uuid',
            'stage' => 'nullable|in:' . implode(',', self::STAGES),
        ] + self::optionalTextRules();
    }

    public static function updateRules(): array
    {
        return [
            'title' => 'required|string|max:500', 'research_area' => 'required|string|max:255', 'problem_statement' => 'required|string|max:20000',
            'purpose' => 'required|string|max:20000', 'approach' => 'required|in:quantitative,qualitative,mixed,other', 'design' => 'required|string|max:255',
        ] + self::optionalTextRules();
    }

    private static function optionalTextRules(): array
    {
        return [
            'background' => 'nullable|string|max:20000', 'scope' => 'nullable|string|max:20000', 'significance' => 'nullable|string|max:20000',
            'paradigm' => 'nullable|string|max:100', 'population' => 'nullable|string|max:20000', 'sampling_approach' => 'nullable|string|max:20000',
            'data_collection_methods' => 'nullable|string|max:20000', 'data_analysis_methods' => 'nullable|string|max:20000',
            'ethics_information' => 'nullable|string|max:20000',
        ];
    }

    /** Resolve and validate the institutional hierarchy; returns internal ids. */
    public static function resolveHierarchy(array $data, int $institutionId): array
    {
        $faculty = Faculty::findByUuid($data['faculty_id']);
        $dept = Department::findByUuid($data['department_id']);
        $prog = Programme::findByUuid($data['programme_id']);
        $year = AcademicYear::findByUuid($data['academic_year_id']);
        $errors = [];
        if (!$faculty || (int) $faculty['institution_id'] !== $institutionId) $errors['faculty_id'][] = 'Unknown faculty.';
        if (!$dept || (int) $dept['institution_id'] !== $institutionId) $errors['department_id'][] = 'Unknown department.';
        if (!$prog || (int) $prog['institution_id'] !== $institutionId) $errors['programme_id'][] = 'Unknown programme.';
        if (!$year || (int) $year['institution_id'] !== $institutionId) $errors['academic_year_id'][] = 'Unknown academic year.';
        if (!$errors) {
            if ((int) $dept['faculty_id'] !== (int) $faculty['id']) $errors['department_id'][] = 'Department does not belong to the selected faculty.';
            if ((int) $prog['department_id'] !== (int) $dept['id']) $errors['programme_id'][] = 'Programme does not belong to the selected department.';
            if ($prog['degree_level'] !== $data['degree_level']) $errors['degree_level'][] = 'Degree level does not match the programme.';
        }
        if ($errors) {
            throw HttpException::unprocessable('Invalid institutional selection.', $errors);
        }
        return ['faculty_id' => $faculty['id'], 'department_id' => $dept['id'], 'programme_id' => $prog['id'], 'academic_year_id' => $year['id']];
    }

    private static function userInInstitution(?string $uuid, array $actor, string $role, string $field): array
    {
        $u = $uuid ? User::findByUuid($uuid) : null;
        if (!$u || (int) $u['institution_id'] !== (int) $actor['institution_id'] || $u['deleted_at'] !== null) {
            throw HttpException::unprocessable('Invalid user.', [$field => ['Unknown user.']]);
        }
        $full = AuthService::loadUser((int) $u['id']);
        if (!in_array($role, $full['roles'], true)) {
            throw HttpException::unprocessable('Invalid user.', [$field => ["User does not have the {$role} role."]]);
        }
        return $full;
    }

    public static function create(Request $r, array $actor, array $data): array
    {
        Gate::authorize($actor, 'project.create');
        $isStudent = in_array(Roles::STUDENT, $actor['roles'], true);
        if ($isStudent) {
            $student = $actor;
            if (empty($data['supervisor_id'])) {
                throw HttpException::unprocessable('A supervisor is required.', ['supervisor_id' => ['Select your supervisor.']]);
            }
        } else {
            $student = self::userInInstitution($data['student_id'] ?? null, $actor, Roles::STUDENT, 'student_id');
            if (in_array(Roles::COORDINATOR, $actor['roles'], true) && !in_array(Roles::GRAD_ADMIN, $actor['roles'], true)
                && $actor['department_id'] !== null && (int) $student['department_id'] !== (int) $actor['department_id']) {
                throw HttpException::forbidden('You can only create projects for students in your department.');
            }
        }
        $supervisor = !empty($data['supervisor_id']) ? self::userInInstitution($data['supervisor_id'], $actor, Roles::SUPERVISOR, 'supervisor_id') : null;
        $ids = self::resolveHierarchy($data, (int) $actor['institution_id']);
        if (!$isStudent && in_array(Roles::COORDINATOR, $actor['roles'], true) && !in_array(Roles::GRAD_ADMIN, $actor['roles'], true)
            && $actor['department_id'] !== null && (int) $ids['department_id'] !== (int) $actor['department_id']) {
            throw HttpException::forbidden('You can only create projects in your department.');
        }

        $stage = $data['stage'] ?? 'topic_selection';
        $fields = array_intersect_key($data, array_flip(array_merge(self::EDITABLE, ['degree_level'])));

        $id = Database::transaction(function () use ($actor, $student, $supervisor, $ids, $fields, $stage) {
            $id = ResearchProject::create($fields + $ids + [
                'institution_id' => $actor['institution_id'],
                'owner_user_id' => $student['id'],
                'stage' => $stage,
                'status' => 'active',
                'created_by' => $actor['id'],
            ]);
            ProjectMember::create(['project_id' => $id, 'user_id' => $student['id'], 'member_role' => 'student', 'assigned_by' => $actor['id']]);
            if ($supervisor) {
                ProjectMember::create(['project_id' => $id, 'user_id' => $supervisor['id'], 'member_role' => 'supervisor', 'assigned_by' => $actor['id']]);
            }
            StageHistory::create(['project_id' => $id, 'from_stage' => null, 'to_stage' => $stage, 'changed_by' => $actor['id'], 'notes' => 'Project created']);
            return $id;
        });
        ProjectAccess::flush();
        $project = ResearchProject::find($id);
        AuditLogger::log($r, 'project.create', 'project', $project['uuid'], ['project' => $project['uuid']]);
        if ($supervisor) {
            NotificationService::notify($supervisor['id'], 'project_assigned', 'New supervisee project', $student['name'] . ': ' . $project['title'], '/projects/' . $project['uuid']);
        }
        return $project;
    }

    public static function update(Request $r, array $actor, array $project, array $data): array
    {
        Gate::authorize($actor, 'project.update', $project);
        $changes = array_intersect_key($data, array_flip(self::EDITABLE));
        $changed = [];
        foreach ($changes as $k => $v) {
            if (($project[$k] ?? null) !== $v) {
                $changed[] = $k;
            }
        }
        if ($changes) {
            ResearchProject::updateById((int) $project['id'], $changes);
            AuditLogger::log($r, 'project.update', 'project', $project['uuid'], ['project' => $project['uuid'], 'fields' => $changed]);
        }
        return ResearchProject::find((int) $project['id']);
    }

    public static function archive(Request $r, array $actor, array $project): array
    {
        Gate::authorize($actor, 'project.archive', $project);
        if ($project['status'] === 'archived') {
            throw HttpException::conflict('Project is already archived.');
        }
        ResearchProject::updateById((int) $project['id'], ['status' => 'archived', 'archived_at' => gmdate('Y-m-d H:i:s')]);
        AuditLogger::log($r, 'project.archive', 'project', $project['uuid'], ['project' => $project['uuid'], 'previous_status' => $project['status']]);
        return ResearchProject::find((int) $project['id']);
    }

    public static function unarchive(Request $r, array $actor, array $project): array
    {
        Gate::authorize($actor, 'project.archive', $project);
        ResearchProject::updateById((int) $project['id'], ['status' => 'active', 'archived_at' => null]);
        AuditLogger::log($r, 'project.unarchive', 'project', $project['uuid'], ['project' => $project['uuid']]);
        return ResearchProject::find((int) $project['id']);
    }

    public static function assignMember(Request $r, array $actor, array $project, string $userUuid, string $memberRole): void
    {
        $ability = $memberRole === 'examiner' ? 'project.assignExaminer' : 'project.assignSupervisor';
        Gate::authorize($actor, $ability, $project);
        $needRole = $memberRole === 'examiner' ? Roles::EXAMINER : Roles::SUPERVISOR;
        $target = self::userInInstitution($userUuid, $actor, $needRole, 'user_id');
        if (ProjectMember::first(['project_id' => $project['id'], 'user_id' => $target['id'], 'member_role' => $memberRole, 'ended_at' => null])) {
            throw HttpException::conflict('This person is already assigned.');
        }
        if ($memberRole === 'supervisor') {
            // A project has one lead supervisor; reassigning ends the previous assignment (history is kept).
            Database::execute("UPDATE research_project_members SET ended_at = UTC_TIMESTAMP() WHERE project_id = ? AND member_role = 'supervisor' AND ended_at IS NULL", [$project['id']]);
        }
        ProjectMember::create(['project_id' => $project['id'], 'user_id' => $target['id'], 'member_role' => $memberRole, 'assigned_by' => $actor['id']]);
        ProjectAccess::flush();
        AuditLogger::log($r, 'project.assign_' . $memberRole, 'project', $project['uuid'], ['project' => $project['uuid'], 'assignee' => $target['uuid']]);
        NotificationService::notify($target['id'], 'project_assigned', 'You have been assigned to a project', $project['title'], '/projects/' . $project['uuid']);
        NotificationService::notify((int) $project['owner_user_id'], 'project_assigned', ucfirst($memberRole) . ' assigned', $target['name'] . ' is now your ' . $memberRole . '.', '/projects/' . $project['uuid']);
    }

    public static function removeMember(Request $r, array $actor, array $project, string $userUuid, string $memberRole): void
    {
        Gate::authorize($actor, $memberRole === 'examiner' ? 'project.assignExaminer' : 'project.assignSupervisor', $project);
        $target = User::findByUuid($userUuid);
        $row = $target ? ProjectMember::first(['project_id' => $project['id'], 'user_id' => $target['id'], 'member_role' => $memberRole, 'ended_at' => null]) : null;
        if (!$row || $memberRole === 'student') {
            throw HttpException::notFound('Assignment');
        }
        ProjectMember::updateById((int) $row['id'], ['ended_at' => gmdate('Y-m-d H:i:s')]);
        ProjectAccess::flush();
        AuditLogger::log($r, 'project.unassign_' . $memberRole, 'project', $project['uuid'], ['project' => $project['uuid'], 'assignee' => $target['uuid']]);
    }

    public static function changeStage(Request $r, array $actor, array $project, string $stage, ?string $notes): array
    {
        Gate::authorize($actor, 'project.changeStage', $project);
        if (!in_array($stage, self::STAGES, true)) {
            throw HttpException::unprocessable('Unknown stage.', ['stage' => ['Invalid stage.']]);
        }
        if ($stage === $project['stage']) {
            throw HttpException::conflict('The project is already at this stage.');
        }
        Database::transaction(function () use ($project, $stage, $notes, $actor) {
            ResearchProject::updateById((int) $project['id'], ['stage' => $stage] + ($stage === 'completed' ? ['status' => 'completed'] : []));
            StageHistory::create(['project_id' => $project['id'], 'from_stage' => $project['stage'], 'to_stage' => $stage, 'changed_by' => $actor['id'], 'notes' => $notes]);
            Database::insert('supervision_approvals', [
                'uuid' => \App\Support\Uuid::v4(), 'project_id' => $project['id'], 'subject_type' => 'stage_change',
                'subject_id' => $project['id'], 'decision' => 'approved', 'notes' => $notes, 'decided_by' => $actor['id'],
            ]);
        });
        AuditLogger::log($r, 'project.stage_change', 'project', $project['uuid'], ['project' => $project['uuid'], 'from' => $project['stage'], 'to' => $stage]);
        NotificationService::notify((int) $project['owner_user_id'], 'stage_changed', 'Research stage updated', 'Your project is now at: ' . str_replace('_', ' ', $stage), '/projects/' . $project['uuid']);
        return ResearchProject::find((int) $project['id']);
    }

    public static function setAiConsent(Request $r, array $actor, array $project, bool $consent): array
    {
        Gate::authorize($actor, 'project.giveAiConsent', $project);
        ResearchProject::updateById((int) $project['id'], $consent
            ? ['ai_consent_at' => gmdate('Y-m-d H:i:s'), 'ai_consent_by' => $actor['id']]
            : ['ai_consent_at' => null, 'ai_consent_by' => null]);
        AuditLogger::log($r, $consent ? 'project.ai_consent_given' : 'project.ai_consent_withdrawn', 'project', $project['uuid'], ['project' => $project['uuid']]);
        return ResearchProject::find((int) $project['id']);
    }

    // ---------------------------------------------------------------- presentation

    public static function person(?array $u): ?array
    {
        return $u ? ['uuid' => $u['uuid'], 'name' => trim($u['first_name'] . ' ' . $u['last_name'])] : null;
    }

    public static function members(array $project): array
    {
        $rows = Database::fetchAll(
            'SELECT m.member_role, m.assigned_at, u.uuid, u.first_name, u.last_name FROM research_project_members m
             JOIN users u ON u.id = m.user_id WHERE m.project_id = ? AND m.ended_at IS NULL ORDER BY m.id',
            [$project['id']]
        );
        return array_map(fn($m) => ['uuid' => $m['uuid'], 'name' => trim($m['first_name'] . ' ' . $m['last_name']), 'role' => $m['member_role'], 'assigned_at' => $m['assigned_at']], $rows);
    }

    public static function present(array $project, array $user): array
    {
        $rel = ProjectAccess::relations($user, $project);
        $detailed = Gate::allows($user, 'project.viewDetails', $project);
        $owner = User::find((int) $project['owner_user_id']);
        $out = [
            'id' => $project['uuid'],
            'title' => $project['title'],
            'research_area' => $project['research_area'],
            'degree_level' => $project['degree_level'],
            'approach' => $project['approach'],
            'design' => $project['design'],
            'stage' => $project['stage'],
            'status' => $project['status'],
            'institution' => ['name' => Institution::find((int) $project['institution_id'])['name']],
            'faculty' => self::named(Faculty::find((int) $project['faculty_id'])),
            'department' => self::named(Department::find((int) $project['department_id'])),
            'programme' => self::named(Programme::find((int) $project['programme_id'])),
            'academic_year' => ['uuid' => AcademicYear::find((int) $project['academic_year_id'])['uuid'], 'label' => AcademicYear::find((int) $project['academic_year_id'])['label']],
            'owner' => self::person($owner),
            'members' => self::members($project),
            'created_at' => $project['created_at'],
            'updated_at' => $project['updated_at'],
            'detail_level' => $detailed ? 'full' : 'summary',
            'relations' => $rel,
            'permissions' => self::permissions($user, $project),
        ];
        if ($detailed) {
            foreach (['problem_statement', 'purpose', 'background', 'scope', 'significance', 'paradigm', 'population', 'sampling_approach', 'data_collection_methods', 'data_analysis_methods', 'ethics_information'] as $f) {
                $out[$f] = $project[$f];
            }
            $out['ai_consent'] = $project['ai_consent_at'] !== null;
            $out['ai_consent_at'] = $project['ai_consent_at'];
        }
        return $out;
    }

    private static function named(array $row): array
    {
        return ['uuid' => $row['uuid'], 'name' => $row['name']];
    }

    public static function permissions(array $user, array $project): array
    {
        $map = [
            'edit' => 'project.update', 'archive' => 'project.archive', 'assign_supervisor' => 'project.assignSupervisor',
            'assign_examiner' => 'project.assignExaminer', 'change_stage' => 'project.changeStage', 'give_ai_consent' => 'project.giveAiConsent',
            'run_ai' => 'project.runAi', 'approve_constitution' => 'project.approveConstitution', 'propose_constitution' => 'project.proposeConstitutionChange',
            'view_content' => 'project.viewContent', 'export' => 'project.export', 'manage_milestones' => 'project.manageMilestones',
        ];
        $out = [];
        foreach ($map as $k => $ability) {
            $out[$k] = Gate::allows($user, $ability, $project);
        }
        return $out;
    }

    /** Compact row for list views. */
    public static function listRow(array $p): array
    {
        $sup = Database::fetchOne(
            "SELECT u.uuid, u.first_name, u.last_name FROM research_project_members m JOIN users u ON u.id = m.user_id
             WHERE m.project_id = ? AND m.member_role = 'supervisor' AND m.ended_at IS NULL LIMIT 1",
            [$p['id']]
        );
        return [
            'id' => $p['uuid'], 'title' => $p['title'], 'research_area' => $p['research_area'], 'stage' => $p['stage'], 'status' => $p['status'],
            'degree_level' => $p['degree_level'], 'student' => $p['student_name'] ?? null,
            'supervisor' => $sup ? ['uuid' => $sup['uuid'], 'name' => trim($sup['first_name'] . ' ' . $sup['last_name'])] : null,
            'updated_at' => $p['updated_at'],
        ];
    }

    // ---------------------------------------------------------------- timeline / history / export

    public static function timeline(array $project): array
    {
        $events = [];
        foreach (Database::fetchAll('SELECT h.*, u.first_name, u.last_name FROM research_stage_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.project_id = ?', [$project['id']]) as $h) {
            $events[] = ['type' => 'stage', 'at' => $h['created_at'], 'title' => 'Stage: ' . str_replace('_', ' ', $h['to_stage']), 'detail' => $h['notes'], 'actor' => trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''))];
        }
        foreach (ResearchMilestone::where(['project_id' => $project['id']]) as $m) {
            $events[] = ['type' => 'milestone', 'at' => $m['completed_at'] ?? ($m['due_date'] . ' 00:00:00'), 'title' => 'Milestone: ' . $m['title'], 'detail' => $m['completed_at'] ? 'Completed' : 'Due ' . $m['due_date'], 'state' => $m['completed_at'] ? 'completed' : 'pending'];
        }
        foreach (Database::fetchAll('SELECT version_number, approved_at, approval_notes FROM research_constitutions WHERE project_id = ?', [$project['id']]) as $c) {
            $events[] = ['type' => 'constitution', 'at' => $c['approved_at'], 'title' => 'Research Constitution v' . $c['version_number'] . ' approved', 'detail' => $c['approval_notes']];
        }
        foreach (Database::fetchAll('SELECT title, scheduled_at, status FROM supervision_sessions WHERE project_id = ?', [$project['id']]) as $s) {
            $events[] = ['type' => 'meeting', 'at' => $s['scheduled_at'], 'title' => 'Meeting: ' . $s['title'], 'detail' => $s['status']];
        }
        foreach (Database::fetchAll('SELECT d.title, v.version_number, v.created_at FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.project_id = ? AND d.deleted_at IS NULL', [$project['id']]) as $d) {
            $events[] = ['type' => 'document', 'at' => $d['created_at'], 'title' => 'Document: ' . $d['title'] . ' (v' . $d['version_number'] . ')', 'detail' => null];
        }
        usort($events, fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return $events;
    }

    public static function history(array $project, int $limit = 100): array
    {
        $rows = Database::fetchAll(
            "SELECT a.action, a.created_at, a.metadata, u.first_name, u.last_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity_uuid = ? OR JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.project')) = ? ORDER BY a.id DESC LIMIT " . (int) $limit,
            [$project['uuid'], $project['uuid']]
        );
        return array_map(function ($a) {
            $m = $a['metadata'] ? json_decode($a['metadata'], true) : [];
            unset($m['project']);
            return ['action' => $a['action'], 'at' => $a['created_at'], 'actor' => trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'System', 'details' => $m];
        }, $rows);
    }

    public static function progressSnapshot(array $project): array
    {
        $ms = ResearchMilestone::where(['project_id' => $project['id']], 'due_date ASC');
        $total = count($ms);
        $done = count(array_filter($ms, fn($m) => $m['completed_at'] !== null));
        $overdue = count(array_filter($ms, fn($m) => $m['completed_at'] === null && $m['due_date'] < gmdate('Y-m-d')));
        $tasks = Database::fetchOne("SELECT COUNT(*) total, SUM(state IN ('open','in_progress')) open_tasks, SUM(state IN ('open','in_progress') AND due_date < UTC_DATE()) overdue FROM supervision_action_items WHERE project_id = ?", [$project['id']]);
        return [
            'stage' => $project['stage'],
            'milestones' => ['total' => $total, 'completed' => $done, 'overdue' => $overdue],
            'tasks' => ['total' => (int) $tasks['total'], 'open' => (int) $tasks['open_tasks'], 'overdue' => (int) $tasks['overdue']],
        ];
    }
}
