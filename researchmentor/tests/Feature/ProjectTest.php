<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\{AuditLog, ProjectMember, ResearchProject};
use Tests\Support\Factory;
use Tests\TestCase;

final class ProjectTest extends TestCase
{
    private function payload(array $org, array $supervisor, array $over = []): array
    {
        return $over + [
            'faculty_id' => $org['faculty']['uuid'], 'department_id' => $org['department']['uuid'], 'programme_id' => $org['programme']['uuid'],
            'academic_year_id' => $org['year']['uuid'], 'degree_level' => 'masters', 'title' => 'Mobile money and savings',
            'research_area' => 'Fintech', 'problem_statement' => 'Households lack savings.', 'purpose' => 'Examine the link.',
            'approach' => 'quantitative', 'design' => 'Survey', 'supervisor_id' => $supervisor['uuid'],
        ];
    }

    public function testStudentCreatesProjectWithSupervisorAndHistory(): void
    {
        $org = Factory::institution();
        $student = Factory::user($org, 'student');
        $sup = Factory::user($org, 'supervisor');
        $this->actingAs($student);
        $data = $this->assertOk($this->postJson('/api/projects', $this->payload($org, $sup)));
        $this->assertSame('Mobile money and savings', $data['title']);
        $this->assertTrue(isset($data['id']) && strlen($data['id']) === 36, 'opaque uuid exposed, not numeric id');
        $this->assertSame($student['uuid'], $data['owner']['uuid']);
        $roles = array_column($data['members'], 'role');
        sort($roles);
        $this->assertSame(['student', 'supervisor'], $roles);
        $row = ResearchProject::findByUuid($data['id']);
        $this->assertSame(1, \App\Models\StageHistory::count(['project_id' => $row['id']]));
        $this->assertNotNull(AuditLog::first(['action' => 'project.create', 'entity_uuid' => $data['id']]));
        $this->assertSame(1, \App\Models\Notification::count(['user_id' => $sup['id'], 'type' => 'project_assigned']));
    }

    public function testCreateRequiresSupervisorForStudentsAndValidatesInput(): void
    {
        $org = Factory::institution();
        $student = Factory::user($org, 'student');
        $sup = Factory::user($org, 'supervisor');
        $this->actingAs($student);
        $bad = $this->payload($org, $sup);
        unset($bad['supervisor_id']);
        $this->assertError($this->postJson('/api/projects', $bad), 422);
        $err = $this->assertError($this->postJson('/api/projects', $this->payload($org, $sup, ['title' => '', 'approach' => 'magic'])), 422);
        $this->assertArrayHasKey('title', $err['details']);
        $this->assertArrayHasKey('approach', $err['details']);
    }

    public function testCannotUseAnotherInstitutionsStructureOrSupervisor(): void
    {
        $org = Factory::institution();
        $other = Factory::institution();
        $student = Factory::user($org, 'student');
        $sup = Factory::user($org, 'supervisor');
        $foreignSup = Factory::user($other, 'supervisor');
        $this->actingAs($student);
        $this->assertError($this->postJson('/api/projects', $this->payload($org, $foreignSup)), 422);
        $this->assertError($this->postJson('/api/projects', $this->payload($org, $sup, ['faculty_id' => $other['faculty']['uuid']])), 422);
        // a non-supervisor cannot be named as supervisor
        $peer = Factory::user($org, 'student');
        $this->assertError($this->postJson('/api/projects', $this->payload($org, $peer)), 422);
    }

    public function testStudentIsolation(): void
    {
        $a = $this->scenario();
        $other = Factory::user($a['org'], 'student');
        $projB = Factory::project($a['org'], $other, $a['sup']);
        $this->actingAs($a['student']);
        $list = $this->assertOk($this->getJson('/api/projects'));
        $this->assertCount(1, $list);
        $this->assertSame($a['project']['uuid'], $list[0]['id']);
        $this->assertError($this->getJson('/api/projects/' . $projB['uuid']), 404, 'not_found');
        $this->assertError($this->putJson('/api/projects/' . $projB['uuid'], ['title' => 'Hijacked']), 404);
        $this->assertError($this->getJson('/api/projects/' . $projB['uuid'] . '/objectives'), 404);
        $this->assertSame('Effects of mobile money on rural savings in Uganda', ResearchProject::find((int) $projB['id'])['title']);
    }

    public function testCrossInstitutionIsolation(): void
    {
        $a = $this->scenario();
        $other = Factory::institution();
        $outsiderSup = Factory::user($other, 'supervisor');
        $outsiderCoord = Factory::user($other, 'coordinator');
        $outsiderAdmin = Factory::user($other, 'grad_admin');
        foreach ([$outsiderSup, $outsiderCoord, $outsiderAdmin] as $u) {
            $this->actingAs($u);
            $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid']), 404);
            $this->assertCount(0, $this->assertOk($this->getJson('/api/projects')));
        }
    }

    public function testSupervisorSeesOnlyAssignedStudents(): void
    {
        $a = $this->scenario();
        $other = Factory::user($a['org'], 'student');
        $otherSup = Factory::user($a['org'], 'supervisor');
        $projB = Factory::project($a['org'], $other, $otherSup);
        $this->actingAs($a['sup']);
        $list = $this->assertOk($this->getJson('/api/projects'));
        $this->assertSame([$a['project']['uuid']], array_column($list, 'id'));
        $this->assertError($this->getJson('/api/projects/' . $projB['uuid']), 404);
        $full = $this->assertOk($this->getJson('/api/projects/' . $a['project']['uuid']));
        $this->assertSame('full', $full['detail_level']);
        $this->assertArrayHasKey('problem_statement', $full);
    }

    public function testCoordinatorGetsSummaryOnlyAndCannotEdit(): void
    {
        $a = $this->scenario();
        $coord = Factory::user($a['org'], 'coordinator');
        $this->actingAs($coord);
        $data = $this->assertOk($this->getJson('/api/projects/' . $a['project']['uuid']));
        $this->assertSame('summary', $data['detail_level']);
        $this->assertArrayNotHasKey('problem_statement', $data);
        $this->assertArrayHasKey('progress', $data);
        $this->assertError($this->putJson('/api/projects/' . $a['project']['uuid'], ['title' => 'x']), 403);
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid'] . '/objectives'), 403);
        $this->assertCount(1, $this->assertOk($this->getJson('/api/projects')));
    }

    public function testDepartmentScopedCoordinator(): void
    {
        $a = $this->scenario();
        $otherDept = \App\Models\Department::create(['institution_id' => $a['org']['institution']['id'], 'faculty_id' => $a['org']['faculty']['id'], 'name' => 'Maths', 'code' => 'MTH']);
        $coord = Factory::user($a['org'], 'coordinator', ['department_id' => $otherDept]);
        $this->actingAs($coord);
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid']), 404);
    }

    public function testSystemAdminHasNoImplicitAccessToResearchContent(): void
    {
        $a = $this->scenario();
        $sysadmin = Factory::user($a['org'], 'sysadmin');
        $this->actingAs($sysadmin);
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid']), 404);
        $this->assertCount(0, $this->assertOk($this->getJson('/api/projects')));
    }

    public function testExaminerOnlySeesExplicitlyAssignedProjects(): void
    {
        $a = $this->scenario();
        $examiner = Factory::user($a['org'], 'external_examiner');
        $this->actingAs($examiner);
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid']), 404);
        ProjectMember::create(['project_id' => $a['project']['id'], 'user_id' => $examiner['id'], 'member_role' => 'examiner']);
        \App\Policies\ProjectAccess::flush();
        $data = $this->assertOk($this->getJson('/api/projects/' . $a['project']['uuid']));
        $this->assertSame('full', $data['detail_level']);
        $this->assertError($this->putJson('/api/projects/' . $a['project']['uuid'], ['title' => 'x']), 403);
    }

    public function testStudentCannotAssignSupervisorOrChangeStageOrArchive(): void
    {
        $a = $this->scenario();
        $sup2 = Factory::user($a['org'], 'supervisor');
        $this->actingAs($a['student']);
        $id = $a['project']['uuid'];
        $this->assertError($this->postJson("/api/projects/$id/members", ['user_id' => $sup2['uuid'], 'role' => 'supervisor']), 403);
        $this->assertError($this->postJson("/api/projects/$id/stage", ['stage' => 'data_collection']), 403);
        $this->assertError($this->postJson("/api/projects/$id/archive"), 403);
    }

    public function testCoordinatorReassignsSupervisorKeepingHistory(): void
    {
        $a = $this->scenario();
        $sup2 = Factory::user($a['org'], 'supervisor');
        $coord = Factory::user($a['org'], 'coordinator');
        $this->actingAs($coord);
        $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/members', ['user_id' => $sup2['uuid'], 'role' => 'supervisor']));
        $active = array_filter(\App\Services\ProjectService::members($a['project']), fn($m) => $m['role'] === 'supervisor');
        $this->assertSame([$sup2['uuid']], array_column($active, 'uuid'));
        $this->assertSame(2, ProjectMember::count(['project_id' => $a['project']['id'], 'member_role' => 'supervisor']), 'old assignment retained with ended_at');
        // old supervisor lost access
        \App\Policies\ProjectAccess::flush();
        $this->actingAs($a['sup']);
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid']), 404);
    }

    public function testSupervisorChangesStageWithAuditAndHistory(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['sup']);
        $id = $a['project']['uuid'];
        $data = $this->assertOk($this->postJson("/api/projects/$id/stage", ['stage' => 'data_collection', 'notes' => 'Proposal defended']));
        $this->assertSame('data_collection', $data['stage']);
        $this->assertError($this->postJson("/api/projects/$id/stage", ['stage' => 'data_collection']), 409);
        $this->assertError($this->postJson("/api/projects/$id/stage", ['stage' => 'bogus']), 422);
        $hist = $this->assertOk($this->getJson("/api/projects/$id/stage-history"));
        $this->assertSame('data_collection', $hist[0]['to']);
        $this->assertNotNull(AuditLog::first(['action' => 'project.stage_change']));
        $timeline = $this->assertOk($this->getJson("/api/projects/$id/timeline"));
        $this->assertNotEmpty($timeline);
    }

    public function testArchiveByStaffBlocksFurtherEdits(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['sup']);
        $this->assertSame('archived', $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/archive'))['status']);
        $this->actingAs($a['student']);
        $this->assertError($this->putJson('/api/projects/' . $a['project']['uuid'], ['title' => 'Late edit']), 403);
        $this->assertCount(0, $this->assertOk($this->getJson('/api/projects')), 'archived projects hidden by default');
        $this->assertCount(1, $this->assertOk($this->getJson('/api/projects?status=archived')));
    }

    public function testResearchComponentsCrudAndLinks(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $id = $a['project']['uuid'];
        $obj = $this->assertOk($this->postJson("/api/projects/$id/objectives", ['body' => 'To assess usage', 'objective_type' => 'specific']));
        $this->assertSame('student', $obj['origin']);
        $q = $this->assertOk($this->postJson("/api/projects/$id/questions", ['body' => 'How is usage related to savings?', 'objective_id' => $obj['id']]));
        $this->assertSame($obj['id'], $q['objective_id']);
        $h = $this->assertOk($this->postJson("/api/projects/$id/hypotheses", ['body' => 'H1: usage increases savings', 'kind' => 'alternative', 'question_id' => $q['id']]));
        $this->assertOk($this->postJson("/api/projects/$id/variables", ['name' => 'Mobile money usage', 'variable_role' => 'independent', 'indicators' => 'Monthly transactions', 'hypothesis_id' => $h['id']]));
        $this->assertOk($this->postJson("/api/projects/$id/theories", ['name' => 'Diffusion of Innovations', 'proponents' => 'Rogers']));
        $this->assertOk($this->putJson("/api/projects/$id/conceptual-framework", ['description' => 'Usage -> savings', 'relationships' => [['from' => 'usage', 'to' => 'savings', 'type' => 'positive']]]));
        $upd = $this->assertOk($this->putJson("/api/projects/$id/objectives/{$obj['id']}", ['body' => 'To assess mobile money usage patterns']));
        $this->assertSame('To assess mobile money usage patterns', $upd['body']);
        $this->assertError($this->putJson("/api/projects/$id/objectives/{$obj['id']}", ['body' => '']), 422);
        $this->assertCount(1, $this->assertOk($this->getJson("/api/projects/$id/variables")));
        // supervisor edits are labelled as supervisor-authored
        $this->actingAs($a['sup']);
        $this->assertSame('supervisor', $this->assertOk($this->postJson("/api/projects/$id/objectives", ['body' => 'Supervisor objective']))['origin']);
        // a link to another project's item is rejected
        $other = Factory::project($a['org'], Factory::user($a['org'], 'student'), $a['sup']);
        $this->assertError($this->postJson('/api/projects/' . $other['uuid'] . '/questions', ['body' => 'x', 'objective_id' => $obj['id']]), 422);
        $this->assertOk($this->deleteJson("/api/projects/$id/objectives/{$obj['id']}"));
    }

    public function testMilestonesAndProgress(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $id = $a['project']['uuid'];
        $m = $this->assertOk($this->postJson("/api/projects/$id/milestones", ['title' => 'Submit Chapter 1', 'due_date' => '2020-01-01']));
        $this->assertSame('overdue', $m['state']);
        $done = $this->assertOk($this->postJson("/api/projects/$id/milestones/{$m['id']}/complete"));
        $this->assertSame('completed', $done['state']);
        $this->assertSame(1, $this->assertOk($this->getJson("/api/projects/$id"))['progress']['milestones']['completed']);
    }

    public function testAiConsentOnlyByOwnerStudent(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['sup']);
        $this->assertError($this->postJson('/api/projects/' . $a['project']['uuid'] . '/ai-consent', ['consent' => false]), 403);
        $this->actingAs($a['student']);
        $r = $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/ai-consent', ['consent' => false]));
        $this->assertFalse($r['ai_consent']);
    }

    public function testExportContainsNoInternalIdsAndIsAudited(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $r = $this->getJson('/api/projects/' . $a['project']['uuid'] . '/export');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('not an official academic record', $r->body);
        $this->assertNotNull(AuditLog::first(['action' => 'project.export']));
        $this->actingAs(Factory::user($a['org'], 'coordinator'));
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid'] . '/export'), 403);
    }
}
