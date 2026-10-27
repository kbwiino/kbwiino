<?php
declare(strict_types=1);

namespace App\Agents;

use App\Support\Database;

/**
 * Progress Agent: derives progress from recorded project data (milestones, tasks, submissions, meetings).
 * No AI and no guessing. Attention indicators are explainable rules - never a judgement about a student.
 */
final class ProgressAgent
{
    public const STALE_SUBMISSION_DAYS = 45;
    public const STALE_MEETING_DAYS = 60;

    public static function report(array $p): array
    {
        $id = (int) $p['id'];
        $today = gmdate('Y-m-d');
        $ms = Database::fetchAll('SELECT title, due_date, completed_at FROM research_milestones WHERE project_id = ? ORDER BY due_date', [$id]);
        $overdueMs = array_values(array_filter($ms, fn($m) => $m['completed_at'] === null && $m['due_date'] < $today));
        $upcomingMs = array_values(array_filter($ms, fn($m) => $m['completed_at'] === null && $m['due_date'] >= $today));
        $tasks = Database::fetchAll("SELECT title, due_date, state FROM supervision_action_items WHERE project_id = ? AND state IN ('open','in_progress','submitted') ORDER BY due_date IS NULL, due_date", [$id]);
        $overdueTasks = array_values(array_filter($tasks, fn($t) => $t['due_date'] !== null && $t['due_date'] < $today && $t['state'] !== 'submitted'));
        $submissions = Database::fetchAll(
            'SELECT d.title, d.category, v.version_number, v.created_at FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.project_id = ? AND d.deleted_at IS NULL ORDER BY v.id DESC LIMIT 20',
            [$id]
        );
        $lastSubmission = $submissions[0]['created_at'] ?? null;
        $lastMeeting = Database::fetchValue("SELECT MAX(scheduled_at) FROM supervision_sessions WHERE project_id = ? AND status = 'completed'", [$id]);
        $nextMeeting = Database::fetchValue("SELECT MIN(scheduled_at) FROM supervision_sessions WHERE project_id = ? AND status = 'scheduled' AND scheduled_at >= UTC_TIMESTAMP()", [$id]);
        $unresolvedComments = (int) Database::fetchValue("SELECT COUNT(*) FROM supervision_comments WHERE project_id = ? AND status = 'open'", [$id])
            + (int) Database::fetchValue("SELECT COUNT(*) FROM document_comments c JOIN documents d ON d.id = c.document_id WHERE d.project_id = ? AND c.status = 'open' AND c.origin = 'supervisor'", [$id]);
        $openFindings = (int) Database::fetchValue("SELECT COUNT(*) FROM ai_review_issues i JOIN ai_reviews r ON r.id = i.review_id WHERE r.project_id = ? AND i.status = 'open'", [$id]);

        $reasons = [];
        if ($overdueMs) $reasons[] = count($overdueMs) . ' overdue milestone(s)';
        if ($overdueTasks) $reasons[] = count($overdueTasks) . ' overdue task(s)';
        if ($p['status'] === 'active') {
            if ($lastSubmission === null) {
                $reasons[] = 'no documents submitted yet';
            } elseif (strtotime($lastSubmission . ' UTC') < time() - self::STALE_SUBMISSION_DAYS * 86400) {
                $reasons[] = 'no submission in the last ' . self::STALE_SUBMISSION_DAYS . ' days';
            }
            if ($lastMeeting && !$nextMeeting && strtotime($lastMeeting . ' UTC') < time() - self::STALE_MEETING_DAYS * 86400) {
                $reasons[] = 'no meeting held or scheduled in the last ' . self::STALE_MEETING_DAYS . ' days';
            }
        }
        return [
            'stage' => $p['stage'], 'status' => $p['status'],
            // Explainable label derived from the rules above; deliberately not a score.
            'attention' => ['label' => $reasons ? 'Needs attention' : 'No attention flags', 'needs_attention' => (bool) $reasons, 'reasons' => $reasons],
            'milestones' => ['total' => count($ms), 'completed' => count($ms) - count($overdueMs) - count($upcomingMs), 'overdue' => $overdueMs, 'upcoming' => array_slice($upcomingMs, 0, 5)],
            'tasks' => ['outstanding' => count($tasks), 'overdue' => $overdueTasks],
            'submissions' => ['count' => count($submissions), 'last_at' => $lastSubmission, 'recent' => array_slice($submissions, 0, 5)],
            'meetings' => ['last_completed_at' => $lastMeeting, 'next_scheduled_at' => $nextMeeting],
            'open_supervisor_comments' => $unresolvedComments,
            'open_ai_findings' => $openFindings,
            'basis' => 'Derived only from recorded milestones, tasks, submissions and meetings.',
        ];
    }
}
