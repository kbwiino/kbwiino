<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\{AuditLog, Notification, SupervisionActionItem, SupervisionApproval, SupervisionSession};
use Tests\Support\{Factory, Fixtures};
use Tests\TestCase;

final class SupervisionTest extends TestCase
{
    protected function tearDown(): void { Fixtures::cleanup(); parent::tearDown(); }

    private function meeting(array $a, string $as = 'sup'): array
    {
        $this->actingAs($a[$as]);
        return $this->assertOk($this->postJson('/api/supervision/meetings', [
            'project_id' => $a['project']['uuid'], 'title' => 'Proposal progress', 'scheduled_at' => '2026-11-01T09:00:00Z', 'duration_minutes' => 45, 'agenda_items' => ['Review chapter 1', 'Plan data collection'],
        ]));
    }

    public function testMeetingWorkflowEndToEnd(): void
    {
        $a = $this->scenario();
        $m = $this->meeting($a);
        $this->assertSame('2026-11-01 09:00:00', SupervisionSession::findByUuid($m['id'])['scheduled_at']);
        $this->assertCount(2, $m['agenda']);
        $this->assertSame(1, Notification::count(['user_id' => $a['student']['id'], 'type' => 'meeting_scheduled']));
        // record discussion (either member), decisions + outcome (supervisor only)
        $this->actingAs($a['student']);
        $this->assertOk($this->putJson('/api/supervision/meetings/' . $m['id'], ['discussion_notes' => 'We discussed the sampling frame.']));
        $this->assertError($this->putJson('/api/supervision/meetings/' . $m['id'], ['decisions' => 'Student-written decision']), 403);
        $this->assertError($this->postJson('/api/supervision/meetings/' . $m['id'] . '/outcome', ['outcome' => 'x']), 403);
        $this->actingAs($a['sup']);
        $this->assertOk($this->putJson('/api/supervision/meetings/' . $m['id'], ['decisions' => 'Use stratified sampling.']));
        $done = $this->assertOk($this->postJson('/api/supervision/meetings/' . $m['id'] . '/outcome', ['outcome' => 'Student to proceed to ethics application.']));
        $this->assertSame('completed', $done['status']);
        $this->assertSame('official', $done['official_outcome']['origin']);
        $this->assertSame(1, SupervisionApproval::count(['project_id' => $a['project']['id'], 'subject_type' => 'session_outcome']));
        $this->assertNotNull(AuditLog::first(['action' => 'supervision.outcome_recorded']));
    }

    public function testAiBriefIsSuggestionOnlyAndNeverOfficial(): void
    {
        $a = $this->scenario();
        $m = $this->meeting($a);
        $this->actingAs($a['student']);
        $this->assertError($this->postJson('/api/supervision/meetings/' . $m['id'] . '/brief'), 403);
        $this->actingAs($a['sup']);
        $b = $this->assertOk($this->postJson('/api/supervision/meetings/' . $m['id'] . '/brief'));
        $this->assertSame('ai_suggestion', $b['ai_brief']['origin']);
        $this->assertStringContainsString('not an official record', $b['ai_brief']['label']);
        $this->assertNull($b['decisions'], 'AI brief must not populate decisions');
        $this->assertNull($b['official_outcome'], 'AI brief must not populate the official outcome');
        $proposedAgenda = array_filter($b['agenda'], fn($x) => $x['origin'] === 'ai_suggestion');
        $this->assertNotEmpty($proposedAgenda);
        foreach ($proposedAgenda as $x) $this->assertSame('proposed', $x['state']);
        $proposedTasks = array_filter($b['tasks'], fn($t) => $t['origin'] === 'ai_suggestion');
        $this->assertNotEmpty($proposedTasks);
        foreach ($proposedTasks as $t) { $this->assertSame('proposed', $t['state']); $this->assertTrue($t['awaiting_supervisor_acceptance']); }
        // student's own task list must not contain AI-proposed tasks as actionable work until accepted
        $this->actingAs($a['student']);
        $tasks = $this->assertOk($this->getJson('/api/supervision/tasks'));
        $this->assertCount(0, $tasks);
        // student cannot accept AI suggestions
        $tid = array_values($proposedTasks)[0]['id'];
        $this->assertError($this->putJson("/api/supervision/tasks/$tid", ['state' => 'open']), 403);
        // supervisor accepts one agenda item and one task, rejects another agenda item
        $this->actingAs($a['sup']);
        $aid = array_values($proposedAgenda)[0]['id'];
        $this->assertOk($this->postJson('/api/supervision/meetings/' . $m['id'] . "/agenda/$aid/decision", ['decision' => 'accept']));
        $this->assertSame('open', $this->assertOk($this->putJson("/api/supervision/tasks/$tid", ['state' => 'open']))['state']);
        $this->actingAs($a['student']);
        $this->assertCount(1, $this->assertOk($this->getJson('/api/supervision/tasks')));
    }

    public function testTaskLifecycleAndPermissions(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['sup']);
        $t = $this->assertOk($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'Draft chapter 3', 'due_date' => '2020-01-01']));
        $this->assertSame($a['student']['uuid'], $t['assigned_to']['uuid']);
        $this->assertTrue($t['overdue']);
        $this->assertSame(1, Notification::count(['user_id' => $a['student']['id'], 'type' => 'task_assigned']));
        $id = $t['id'];
        // student progresses and submits, cannot complete
        $this->actingAs($a['student']);
        $this->assertSame('in_progress', $this->assertOk($this->putJson("/api/supervision/tasks/$id", ['state' => 'in_progress']))['state']);
        $this->assertError($this->putJson("/api/supervision/tasks/$id", ['state' => 'completed']), 403);
        $this->assertError($this->putJson("/api/supervision/tasks/$id", ['title' => 'Renamed by student']), 403);
        $s = $this->assertOk($this->putJson("/api/supervision/tasks/$id", ['state' => 'submitted', 'student_response' => 'Uploaded draft']));
        $this->assertSame('submitted', $s['state']);
        $this->assertSame('Uploaded draft', $s['student_response']);
        $this->assertSame(1, Notification::count(['user_id' => $a['sup']['id'], 'type' => 'task_updated']));
        // supervisor sends back, student resubmits, supervisor completes
        $this->actingAs($a['sup']);
        $this->assertSame('open', $this->assertOk($this->putJson("/api/supervision/tasks/$id", ['state' => 'open', 'review_note' => 'Needs more detail']))['state']);
        $this->actingAs($a['student']);
        $this->assertOk($this->putJson("/api/supervision/tasks/$id", ['state' => 'submitted']));
        $this->actingAs($a['sup']);
        $c = $this->assertOk($this->putJson("/api/supervision/tasks/$id", ['state' => 'completed', 'review_note' => 'Good']));
        $this->assertSame('completed', $c['state']);
        $this->assertNotNull($c['completed_at']);
        $this->assertGreaterThanOrEqual(2, SupervisionApproval::count(['project_id' => $a['project']['id']]));
    }

    public function testStudentSelfTasksAndOtherStudentsCannotTouchTasks(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $own = $this->assertOk($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'Read 5 papers']));
        $this->assertSame('student', $own['origin']);
        $this->assertSame('completed', $this->assertOk($this->putJson('/api/supervision/tasks/' . $own['id'], ['state' => 'completed']))['state']);
        $this->assertError($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'x', 'assigned_to' => $a['sup']['uuid']]), 403);
        $this->assertError($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'x', 'kind' => 'revision_request']), 403);
        $other = Factory::user($a['org'], 'student');
        Factory::project($a['org'], $other, $a['sup']);
        $this->actingAs($other);
        $this->assertError($this->getJson('/api/supervision/tasks/' . $own['id']), 404);
        $this->assertError($this->putJson('/api/supervision/tasks/' . $own['id'], ['state' => 'cancelled']), 404);
        $this->assertError($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'intrusion']), 404);
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid'] . '/supervision'), 404);
    }

    public function testRevisionRequestWorkflowWithDocumentUpload(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->call('POST', '/api/documents/upload', ['project_id' => $a['project']['uuid'], 'category' => 'chapter_1'], [], Fixtures::txt()));
        $this->actingAs($a['sup']);
        $this->assertError($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'Revise', 'kind' => 'revision_request']), 422);
        $req = $this->assertOk($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'Revise problem statement', 'kind' => 'revision_request', 'document_id' => $d['id'], 'due_date' => '2026-12-01']));
        $this->assertSame('revision_request', $req['kind']);
        $this->assertSame($d['id'], $req['document']['id']);
        $this->assertSame(1, Notification::count(['user_id' => $a['student']['id'], 'type' => 'revision_requested']));
        $this->actingAs($a['student']);
        $this->assertOk($this->call('POST', '/api/documents/upload', ['document_id' => $d['id'], 'change_note' => 'Revised'], [], Fixtures::txt('c.txt', 'Revised content here.')));
        $this->assertOk($this->putJson('/api/supervision/tasks/' . $req['id'], ['state' => 'submitted', 'student_response' => 'See version 2']));
        $this->actingAs($a['sup']);
        $this->assertOk($this->putJson('/api/supervision/tasks/' . $req['id'], ['state' => 'completed']));
        $r = $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/approvals', ['document_id' => $d['id'], 'decision' => 'approved', 'notes' => 'Accepted v2']));
        $this->assertSame('official', $r['origin']);
        $this->assertSame(2, $r['document_version']);
        // student cannot create official approvals
        $this->actingAs($a['student']);
        $this->assertError($this->postJson('/api/projects/' . $a['project']['uuid'] . '/approvals', ['document_id' => $d['id'], 'decision' => 'approved']), 403);
    }

    public function testCommentsKeepAuthorshipSeparate(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['sup']);
        $c = $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/supervision/comments', ['body' => 'Please clarify the research gap.']));
        $this->assertSame('supervisor', $c['origin']);
        $this->actingAs($a['student']);
        $r = $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/supervision/comments', ['body' => 'Will do', 'parent_id' => $c['id']]));
        $this->assertSame('student', $r['origin']);
        $ov = $this->assertOk($this->getJson('/api/projects/' . $a['project']['uuid'] . '/supervision'));
        $this->assertCount(2, $ov['comments']);
        $this->assertFalse($ov['permissions']['record_outcome']);
        $this->assertStringContainsString('never official', $ov['notice']);
    }

    public function testSupervisionIsNotVisibleToOversightRoles(): void
    {
        $a = $this->scenario();
        $m = $this->meeting($a);
        foreach (['coordinator', 'grad_admin', 'sysadmin'] as $role) {
            $this->actingAs(Factory::user($a['org'], $role));
            $this->assertError($this->getJson('/api/supervision/meetings/' . $m['id']), 404);
            // oversight roles know the project exists (summary access) but may not read supervision records; sysadmins get no relation at all
            $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid'] . '/supervision'), $role === 'sysadmin' ? 404 : 403);
        }
    }

    public function testValidation(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['sup']);
        $this->assertError($this->postJson('/api/supervision/meetings', ['project_id' => $a['project']['uuid'], 'title' => '', 'scheduled_at' => 'not a date']), 422);
        $this->assertError($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'x', 'due_date' => 'tomorrow-ish']), 422);
        $this->assertError($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'x', 'assigned_to' => Factory::user($a['org'], 'student')['uuid']]), 422);
        $this->assertError($this->putJson('/api/supervision/tasks/' . $this->assertOk($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'ok']))['id'], ['state' => 'weird']), 422);
    }

    public function testArchivedProjectBlocksNewWork(): void
    {
        $a = $this->scenario();
        \App\Models\ResearchProject::updateById((int) $a['project']['id'], ['status' => 'archived']);
        $this->actingAs($a['sup']);
        $this->assertError($this->postJson('/api/supervision/tasks', ['project_id' => $a['project']['uuid'], 'title' => 'x']), 403);
        $this->assertError($this->postJson('/api/supervision/meetings', ['project_id' => $a['project']['uuid'], 'title' => 'x', 'scheduled_at' => '2026-11-01 10:00']), 403);
    }
}
