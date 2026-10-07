<?php
declare(strict_types=1);

namespace App\Services;

use App\Agents\ProgressAgent;
use App\Models\ResearchProject;
use App\Policies\ProjectAccess;
use App\Services\AI\AIFactory;
use App\Support\{Config, Database, FileStorage, Roles};

final class DashboardService
{
    public static function student(array $user): array
    {
        $projects = Database::fetchAll(
            "SELECT p.* FROM research_projects p WHERE (p.owner_user_id = ? OR p.id IN (SELECT project_id FROM research_project_members WHERE user_id = ? AND member_role = 'student' AND ended_at IS NULL))
             AND p.status <> 'archived' ORDER BY p.updated_at DESC LIMIT 5",
            [$user['id'], $user['id']]
        );
        $out = [];
        foreach ($projects as $p) {
            $id = (int) $p['id'];
            $sup = Database::fetchOne("SELECT u.first_name, u.last_name, u.uuid FROM research_project_members m JOIN users u ON u.id = m.user_id WHERE m.project_id = ? AND m.member_role = 'supervisor' AND m.ended_at IS NULL LIMIT 1", [$id]);
            $prog = Database::fetchOne('SELECT name FROM programmes WHERE id = ?', [$p['programme_id']]);
            $out[] = [
                'id' => $p['uuid'], 'title' => $p['title'], 'programme' => $prog['name'], 'stage' => $p['stage'], 'status' => $p['status'],
                'supervisor' => $sup ? ['uuid' => $sup['uuid'], 'name' => trim($sup['first_name'] . ' ' . $sup['last_name'])] : null,
                'progress' => ProgressAgent::report($p),
                'milestones' => array_map(fn($m) => ['title' => $m['title'], 'due_date' => $m['due_date'], 'done' => $m['completed_at'] !== null], Database::fetchAll('SELECT title, due_date, completed_at FROM research_milestones WHERE project_id = ? ORDER BY due_date LIMIT 12', [$id])),
                'pending_tasks' => array_map([SupervisionService::class, 'presentTask'], Database::fetchAll("SELECT * FROM supervision_action_items WHERE project_id = ? AND assigned_to = ? AND state IN ('open','in_progress') ORDER BY due_date IS NULL, due_date LIMIT 10", [$id, $user['id']])),
                'recent_documents' => array_map(fn($d) => ['id' => $d['uuid'], 'title' => $d['title'], 'category' => $d['category'], 'updated_at' => $d['updated_at']], Database::fetchAll('SELECT uuid, title, category, updated_at FROM documents WHERE project_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 5', [$id])),
                'supervisor_feedback' => array_map(fn($c) => ['body' => mb_substr($c['body'], 0, 300), 'at' => $c['created_at'], 'status' => $c['status'], 'origin' => 'supervisor', 'source' => $c['src']], Database::fetchAll(
                    "(SELECT body, created_at, status, 'meeting' AS src FROM supervision_comments WHERE project_id = ? AND origin = 'supervisor')
                     UNION ALL (SELECT c.body, c.created_at, c.status, 'document' FROM document_comments c JOIN documents d ON d.id = c.document_id WHERE d.project_id = ? AND c.origin = 'supervisor' AND d.deleted_at IS NULL)
                     ORDER BY created_at DESC LIMIT 6", [$id, $id])),
                'ai_review_history' => array_map(fn($r) => ['id' => $r['uuid'], 'type' => $r['review_type'], 'at' => $r['created_at'], 'human_status' => $r['human_status'], 'mock' => (bool) $r['is_mock']], Database::fetchAll('SELECT uuid, review_type, created_at, human_status, is_mock FROM ai_reviews WHERE project_id = ? ORDER BY id DESC LIMIT 6', [$id])),
                'upcoming_meetings' => array_map(fn($s) => SupervisionService::presentSession($s), Database::fetchAll("SELECT * FROM supervision_sessions WHERE project_id = ? AND status = 'scheduled' AND scheduled_at >= UTC_TIMESTAMP() ORDER BY scheduled_at LIMIT 3", [$id])),
            ];
        }
        $svc = AIFactory::make();
        return [
            'student' => ['name' => $user['name']], 'projects' => $out,
            'research_assistant' => ['available' => $svc->checkAvailability()['available'] && Config::get('ai.enabled'), 'mock' => $svc->isMock(), 'note' => 'The AI research assistant offers suggestions; your supervisor makes the decisions.'],
            'unread_notifications' => (int) Database::fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$user['id']]),
        ];
    }

    public static function supervisor(array $user): array
    {
        $uid = (int) $user['id'];
        $ids = array_column(Database::fetchAll("SELECT DISTINCT m.project_id AS id FROM research_project_members m JOIN research_projects p ON p.id = m.project_id WHERE m.user_id = ? AND m.member_role IN ('supervisor','co_supervisor') AND m.ended_at IS NULL AND p.status <> 'archived'", [$uid]), 'id');
        if (!$ids) {
            return self::emptySupervisor();
        }
        $in = Database::placeholders($ids);
        $projects = Database::fetchAll("SELECT p.*, CONCAT(o.first_name,' ',o.last_name) AS student_name FROM research_projects p JOIN users o ON o.id = p.owner_user_id WHERE p.id IN ($in) ORDER BY p.updated_at DESC", $ids);
        $students = [];
        $stages = [];
        foreach ($projects as $p) {
            $rep = ProgressAgent::report($p);
            $stages[$p['stage']] = ($stages[$p['stage']] ?? 0) + 1;
            $students[] = [
                'project_id' => $p['uuid'], 'student' => $p['student_name'], 'title' => $p['title'], 'stage' => $p['stage'], 'status' => $p['status'],
                // Explainable indicators (never a score or a "weak student" label)
                'attention' => $rep['attention'], 'overdue_tasks' => count($rep['tasks']['overdue']), 'open_findings' => $rep['open_ai_findings'],
                'last_submission_at' => $rep['submissions']['last_at'], 'next_meeting_at' => $rep['meetings']['next_scheduled_at'],
            ];
        }
        $pendingSubs = Database::fetchAll("SELECT t.uuid, t.title, t.submitted_at, p.uuid AS project_uuid, p.title AS project_title FROM supervision_action_items t JOIN research_projects p ON p.id = t.project_id WHERE t.project_id IN ($in) AND t.state = 'submitted' ORDER BY t.submitted_at LIMIT 20", $ids);
        $overdue = Database::fetchAll("SELECT t.uuid, t.title, t.due_date, p.uuid AS project_uuid, p.title AS project_title FROM supervision_action_items t JOIN research_projects p ON p.id = t.project_id WHERE t.project_id IN ($in) AND t.state IN ('open','in_progress') AND t.due_date < UTC_DATE() ORDER BY t.due_date LIMIT 20", $ids);
        $meetings = Database::fetchAll("SELECT s.*, p.uuid AS project_uuid, p.title AS project_title FROM supervision_sessions s JOIN research_projects p ON p.id = s.project_id WHERE s.project_id IN ($in) AND s.status = 'scheduled' AND s.scheduled_at >= UTC_TIMESTAMP() ORDER BY s.scheduled_at LIMIT 10", $ids);
        $unresolved = (int) Database::fetchValue("SELECT COUNT(*) FROM supervision_comments WHERE project_id IN ($in) AND status = 'open' AND origin = 'student'", $ids)
            + (int) Database::fetchValue("SELECT COUNT(*) FROM document_comments c JOIN documents d ON d.id = c.document_id WHERE d.project_id IN ($in) AND c.status = 'open' AND c.origin = 'student'", $ids);
        $activity = Database::fetchAll(
            "(SELECT 'document' AS type, CONCAT('Uploaded ', d.title, ' v', v.version_number) AS text, v.created_at AS at, p.uuid AS project_uuid FROM document_versions v JOIN documents d ON d.id = v.document_id JOIN research_projects p ON p.id = d.project_id WHERE d.project_id IN ($in) AND d.visibility = 'shared' AND d.deleted_at IS NULL)
             UNION ALL (SELECT 'task', CONCAT('Task ', t.state, ': ', t.title), COALESCE(t.submitted_at, t.updated_at), p.uuid FROM supervision_action_items t JOIN research_projects p ON p.id = t.project_id WHERE t.project_id IN ($in) AND t.state IN ('submitted','completed'))
             ORDER BY at DESC LIMIT 12", array_merge($ids, $ids)
        );
        return [
            'counts' => [
                'assigned_students' => count($ids), 'active_projects' => count(array_filter($projects, fn($p) => $p['status'] === 'active')),
                'upcoming_meetings' => count($meetings), 'pending_submissions' => count($pendingSubs), 'overdue_tasks' => count($overdue), 'unresolved_comments' => $unresolved,
            ],
            'students' => $students,
            'stage_distribution' => array_map(fn($stage, $n) => ['stage' => $stage, 'count' => $n], array_keys($stages), $stages),
            'pending_submissions' => array_map(fn($t) => ['id' => $t['uuid'], 'title' => $t['title'], 'submitted_at' => $t['submitted_at'], 'project' => ['id' => $t['project_uuid'], 'title' => $t['project_title']]], $pendingSubs),
            'overdue_tasks' => array_map(fn($t) => ['id' => $t['uuid'], 'title' => $t['title'], 'due_date' => $t['due_date'], 'project' => ['id' => $t['project_uuid'], 'title' => $t['project_title']]], $overdue),
            'upcoming_meetings' => array_map(fn($s) => SupervisionService::presentSession($s) + ['project' => ['id' => $s['project_uuid'], 'title' => $s['project_title']]], $meetings),
            'recent_activity' => array_map(fn($a) => ['type' => $a['type'], 'text' => $a['text'], 'at' => $a['at'], 'project_id' => $a['project_uuid']], $activity),
            'note' => 'Attention indicators are explainable rules over recorded activity. They are not an assessment of a student\'s ability.',
        ];
    }

    private static function emptySupervisor(): array
    {
        return ['counts' => ['assigned_students' => 0, 'active_projects' => 0, 'upcoming_meetings' => 0, 'pending_submissions' => 0, 'overdue_tasks' => 0, 'unresolved_comments' => 0],
            'students' => [], 'stage_distribution' => [], 'pending_submissions' => [], 'overdue_tasks' => [], 'upcoming_meetings' => [], 'recent_activity' => [], 'note' => ''];
    }

    /** Aggregate-only view for coordinators / Graduate School administrators / system administrators. */
    public static function administrator(array $user): array
    {
        $isSys = in_array(Roles::SYSADMIN, $user['roles'], true);
        $isGrad = in_array(Roles::GRAD_ADMIN, $user['roles'], true);
        // Scope: sysadmin = platform-wide aggregates; grad admin = institution; coordinator = department (or institution).
        $pWhere = '1=1';
        $pParams = [];
        $uWhere = '1=1';
        $uParams = [];
        if (!$isSys) {
            $pWhere = 'p.institution_id = ?';
            $pParams = [$user['institution_id']];
            $uWhere = 'u.institution_id = ?';
            $uParams = [$user['institution_id']];
            if (!$isGrad && $user['department_id'] !== null) {
                $pWhere .= ' AND p.department_id = ?';
                $pParams[] = $user['department_id'];
                $uWhere .= ' AND u.department_id = ?';
                $uParams[] = $user['department_id'];
            }
        }
        $role = fn(string $slug) => (int) Database::fetchValue("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug = ? AND u.status = 'active' AND u.deleted_at IS NULL AND $uWhere", array_merge([$slug], $uParams));
        $byStage = Database::fetchAll("SELECT p.stage, COUNT(*) n FROM research_projects p WHERE $pWhere AND p.status <> 'archived' GROUP BY p.stage", $pParams);
        $activity = [
            'meetings_last_30_days' => (int) Database::fetchValue("SELECT COUNT(*) FROM supervision_sessions s JOIN research_projects p ON p.id = s.project_id WHERE $pWhere AND s.scheduled_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) AND s.scheduled_at <= UTC_TIMESTAMP() AND s.status <> 'cancelled'", $pParams),
            'tasks_completed_last_30_days' => (int) Database::fetchValue("SELECT COUNT(*) FROM supervision_action_items t JOIN research_projects p ON p.id = t.project_id WHERE $pWhere AND t.completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)", $pParams),
            'documents_submitted_last_30_days' => (int) Database::fetchValue("SELECT COUNT(*) FROM document_versions v JOIN documents d ON d.id = v.document_id JOIN research_projects p ON p.id = d.project_id WHERE $pWhere AND v.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)", $pParams),
        ];
        $outstanding = [
            'projects_without_supervisor' => (int) Database::fetchValue("SELECT COUNT(*) FROM research_projects p WHERE $pWhere AND p.status = 'active' AND NOT EXISTS (SELECT 1 FROM research_project_members m WHERE m.project_id = p.id AND m.member_role = 'supervisor' AND m.ended_at IS NULL)", $pParams),
            'pending_constitution_proposals' => (int) Database::fetchValue("SELECT COUNT(*) FROM constitution_proposals c JOIN research_projects p ON p.id = c.project_id WHERE $pWhere AND c.status = 'pending'", $pParams),
            'locked_accounts' => (int) Database::fetchValue("SELECT COUNT(*) FROM users u WHERE $uWhere AND u.locked_until > UTC_TIMESTAMP()", $uParams),
            'projects_overdue_milestones' => (int) Database::fetchValue("SELECT COUNT(DISTINCT p.id) FROM research_projects p JOIN research_milestones m ON m.project_id = p.id WHERE $pWhere AND p.status = 'active' AND m.completed_at IS NULL AND m.due_date < UTC_DATE()", $pParams),
        ];
        $jobs = Database::fetchAll("SELECT j.status, j.review_type, COUNT(*) n FROM ai_review_jobs j JOIN research_projects p ON p.id = j.project_id WHERE $pWhere AND j.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) GROUP BY j.status, j.review_type", $pParams);
        $out = [
            'scope' => $isSys ? 'platform' : ($isGrad ? 'institution' : 'department'),
            'totals' => ['students' => $role(Roles::STUDENT), 'supervisors' => $role(Roles::SUPERVISOR), 'projects' => (int) Database::fetchValue("SELECT COUNT(*) FROM research_projects p WHERE $pWhere AND p.status <> 'archived'", $pParams)],
            'projects_by_stage' => array_map(fn($r) => ['stage' => $r['stage'], 'count' => (int) $r['n']], $byStage),
            'supervision_activity' => $activity,
            'outstanding_admin_tasks' => $outstanding,
            'review_jobs' => ['by_status' => self::group($jobs, 'status'), 'by_type' => self::group($jobs, 'review_type')],
            'privacy_note' => 'This dashboard shows aggregate figures only. Research content is not accessible from here.',
        ];
        if ($isSys || $isGrad) {
            $out['ai_usage_30_days'] = self::aiUsage($isSys ? null : (int) $user['institution_id']);
        }
        if ($isSys) {
            $out['system_health'] = self::health();
        }
        return $out;
    }

    private static function group(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r[$key]] = ($out[$r[$key]] ?? 0) + (int) $r['n'];
        }
        return array_map(fn($k, $v) => [$key => $k, 'count' => $v], array_keys($out), $out);
    }

    public static function aiUsage(?int $institutionId): array
    {
        $join = $institutionId ? 'JOIN users u ON u.id = l.user_id WHERE u.institution_id = ? AND' : 'WHERE';
        $params = $institutionId ? [$institutionId] : [];
        $row = Database::fetchOne("SELECT COUNT(*) calls, COALESCE(SUM(l.input_tokens),0) tin, COALESCE(SUM(l.output_tokens),0) tout, COALESCE(SUM(l.status <> 'success'),0) errors, COALESCE(AVG(l.latency_ms),0) lat FROM ai_usage_logs l $join l.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)", $params);
        $daily = Database::fetchAll("SELECT DATE(l.created_at) d, COUNT(*) n, SUM(l.input_tokens + l.output_tokens) tokens FROM ai_usage_logs l $join l.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) GROUP BY DATE(l.created_at) ORDER BY d", $params);
        return [
            'calls' => (int) $row['calls'], 'input_tokens' => (int) $row['tin'], 'output_tokens' => (int) $row['tout'], 'errors' => (int) $row['errors'], 'avg_latency_ms' => (int) $row['lat'],
            'daily' => array_map(fn($d) => ['date' => $d['d'], 'calls' => (int) $d['n'], 'tokens' => (int) $d['tokens']], $daily),
        ];
    }

    public static function health(): array
    {
        $checks = [];
        try {
            Database::fetchValue('SELECT 1');
            $checks['database'] = ['ok' => true];
        } catch (\Throwable $e) {
            $checks['database'] = ['ok' => false, 'detail' => 'unreachable'];
        }
        try {
            $dir = FileStorage::root('docs');
            $checks['private_storage'] = ['ok' => is_writable($dir)];
        } catch (\Throwable $e) {
            $checks['private_storage'] = ['ok' => false];
        }
        $backlog = (int) Database::fetchValue("SELECT COUNT(*) FROM background_jobs WHERE status = 'pending' AND available_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)");
        $failed = (int) Database::fetchValue("SELECT COUNT(*) FROM background_jobs WHERE status = 'failed'");
        $checks['queue'] = ['ok' => $backlog < 50, 'driver' => Config::get('app.queue_driver'), 'stale_pending' => $backlog, 'failed' => $failed];
        $svc = AIFactory::make();
        $av = $svc->checkAvailability();
        $checks['ai'] = ['ok' => $av['available'], 'provider' => $svc->provider(), 'mock' => $svc->isMock(), 'model' => $svc->model(), 'reason' => $av['reason']];
        $checks['php'] = ['ok' => PHP_VERSION_ID >= 80200, 'version' => PHP_VERSION];
        $checks['env'] = ['ok' => true, 'app_env' => Config::get('app.env'), 'debug' => (bool) Config::get('app.debug')];
        $free = @disk_free_space(Config::get('storage.private_path') ?: '/');
        $checks['disk'] = ['ok' => $free === false || $free > 500 * 1048576, 'free_mb' => $free === false ? null : (int) ($free / 1048576)];
        $checks['overall_ok'] = !in_array(false, array_column($checks, 'ok'), true);
        return $checks;
    }
}
