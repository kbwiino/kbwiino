<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\{ProjectMember, ResearchMilestone, SupervisionActionItem, SupervisionSession};
use Tests\Support\{Factory, Fixtures};
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    protected function tearDown(): void { Fixtures::cleanup(); parent::tearDown(); }

    public function testStudentDashboardShowsOwnProjectOnly(): void
    {
        $a = $this->scenario();
        $other = Factory::user($a['org'], 'student');
        $b = Factory::project($a['org'], $other, $a['sup'], ['title' => 'Someone else research']);
        ResearchMilestone::create(['project_id' => $a['project']['id'], 'title' => 'Chapter 1', 'due_date' => '2030-01-01']);
        SupervisionActionItem::create(['project_id' => $a['project']['id'], 'title' => 'Fix intro', 'assigned_to' => $a['student']['id'], 'created_by' => $a['sup']['id'], 'due_date' => '2020-01-01']);
        SupervisionSession::create(['project_id' => $a['project']['id'], 'title' => 'Next meeting', 'scheduled_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'created_by' => $a['sup']['id']]);
        $this->actingAs($a['student']);
        $this->assertOk($this->call('POST', '/api/documents/upload', ['project_id' => $a['project']['uuid']], [], Fixtures::txt()));
        $d = $this->assertOk($this->getJson('/api/dashboard/student'));
        $this->assertCount(1, $d['projects']);
        $p = $d['projects'][0];
        $this->assertSame($a['project']['title'], $p['title']);
        $this->assertSame($a['sup']['uuid'], $p['supervisor']['uuid']);
        $this->assertSame('MSc Computer Science', $p['programme']);
        $this->assertCount(1, $p['milestones']);
        $this->assertCount(1, $p['pending_tasks']);
        $this->assertTrue($p['pending_tasks'][0]['overdue']);
        $this->assertCount(1, $p['recent_documents']);
        $this->assertCount(1, $p['upcoming_meetings']);
        $this->assertStringNotContainsString('Someone else research', json_encode($d));
        $this->assertTrue($d['research_assistant']['mock']);
        // role gate
        $this->assertError($this->getJson('/api/dashboard/supervisor'), 403);
        $this->assertError($this->getJson('/api/dashboard/administrator'), 403);
    }

    public function testSupervisorDashboardAndExplainableAttention(): void
    {
        $a = $this->scenario();
        $s2 = Factory::user($a['org'], 'student');
        $p2 = Factory::project($a['org'], $s2, $a['sup'], ['stage' => 'data_collection']);
        $s3 = Factory::user($a['org'], 'student');
        $foreign = Factory::project($a['org'], $s3, Factory::user($a['org'], 'supervisor'));
        ResearchMilestone::create(['project_id' => $a['project']['id'], 'title' => 'Late', 'due_date' => '2020-01-01']);
        SupervisionActionItem::create(['project_id' => $p2['id'], 'title' => 'Submitted work', 'assigned_to' => $s2['id'], 'created_by' => $a['sup']['id'], 'state' => 'submitted', 'submitted_at' => gmdate('Y-m-d H:i:s')]);
        $this->actingAs($a['sup']);
        $d = $this->assertOk($this->getJson('/api/dashboard/supervisor'));
        $this->assertSame(2, $d['counts']['assigned_students']);
        $this->assertSame(2, $d['counts']['active_projects']);
        $this->assertSame(1, $d['counts']['pending_submissions']);
        $stages = array_column($d['stage_distribution'], 'count', 'stage');
        $this->assertSame(['proposal_development' => 1, 'data_collection' => 1], $stages);
        $this->assertStringNotContainsString($foreign['uuid'], json_encode($d), 'unassigned students never appear');
        foreach ($d['students'] as $s) {
            $this->assertArrayNotHasKey('score', $s);
            $this->assertArrayNotHasKey('risk', $s);
            $this->assertIsArray($s['attention']['reasons']);
        }
        $flagged = array_values(array_filter($d['students'], fn($s) => $s['project_id'] === $a['project']['uuid']))[0];
        $this->assertTrue($flagged['attention']['needs_attention']);
        $this->assertContains('1 overdue milestone(s)', $flagged['attention']['reasons']);
        $this->assertStringContainsString('not an assessment of a student', $d['note']);
        // a supervisor with no students gets an empty dashboard, not an error
        $this->actingAs(Factory::user($a['org'], 'supervisor'));
        $this->assertSame(0, $this->assertOk($this->getJson('/api/dashboard/supervisor'))['counts']['assigned_students']);
        $this->actingAs($a['student']);
        $this->assertError($this->getJson('/api/dashboard/supervisor'), 403);
    }

    public function testAdministratorDashboardIsAggregateOnly(): void
    {
        $a = $this->scenario();
        Factory::project($a['org'], Factory::user($a['org'], 'student'), null, ['title' => 'SECRET-TITLE-ABC']);
        $other = Factory::institution();
        Factory::project($other, Factory::user($other, 'student'), Factory::user($other, 'supervisor'));
        $admin = Factory::user($a['org'], 'grad_admin');
        $this->actingAs($admin);
        $d = $this->assertOk($this->getJson('/api/dashboard/administrator'));
        $this->assertSame('institution', $d['scope']);
        $this->assertSame(2, $d['totals']['projects'], 'only own institution counted');
        $this->assertSame(2, $d['totals']['students']);
        $this->assertSame(1, $d['totals']['supervisors']);
        $this->assertSame(1, $d['outstanding_admin_tasks']['projects_without_supervisor']);
        $json = json_encode($d);
        foreach (['SECRET-TITLE-ABC', $a['project']['title'], $a['student']['email'], $a['project']['problem_statement']] as $needle) {
            $this->assertStringNotContainsString($needle, $json, 'no research content or personal data');
        }
        $this->assertArrayHasKey('ai_usage_30_days', $d);
        $this->assertArrayNotHasKey('system_health', $d, 'system health is for system administrators');
        $this->assertStringContainsString('aggregate', $d['privacy_note']);
    }

    public function testSystemAdminSeesHealthButNoContent(): void
    {
        $a = $this->scenario();
        $sys = Factory::user($a['org'], 'sysadmin');
        $this->actingAs($sys);
        $d = $this->assertOk($this->getJson('/api/dashboard/administrator'));
        $this->assertSame('platform', $d['scope']);
        $this->assertArrayHasKey('system_health', $d);
        $this->assertTrue($d['system_health']['database']['ok']);
        $this->assertTrue($d['system_health']['ai']['mock']);
        $this->assertStringNotContainsString($a['project']['title'], json_encode($d));
        $h = $this->assertOk($this->getJson('/api/admin/health'));
        $this->assertArrayHasKey('overall_ok', $h);
        $this->actingAs($a['sup']);
        $this->assertError($this->getJson('/api/admin/health'), 403);
    }

    public function testCoordinatorSeesDepartmentAggregatesOnly(): void
    {
        $a = $this->scenario();
        $dept2 = \App\Models\Department::create(['institution_id' => $a['org']['institution']['id'], 'faculty_id' => $a['org']['faculty']['id'], 'name' => 'Maths', 'code' => 'MTH']);
        $coord = Factory::user($a['org'], 'coordinator', ['department_id' => $dept2]);
        $this->actingAs($coord);
        $d = $this->assertOk($this->getJson('/api/dashboard/administrator'));
        $this->assertSame('department', $d['scope']);
        $this->assertSame(0, $d['totals']['projects']);
        $this->assertArrayNotHasKey('ai_usage_30_days', $d);
    }

    public function testDashboardHomeListsApplicableDashboards(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['sup']);
        $this->assertSame(['supervisor'], $this->assertOk($this->getJson('/api/dashboard'))['dashboards']);
        $this->actingAs(Factory::user($a['org'], ['supervisor', 'coordinator']));
        $this->assertEqualsCanonicalizing(['supervisor', 'administrator'], $this->assertOk($this->getJson('/api/dashboard'))['dashboards']);
    }

    public function testNotificationsApi(): void
    {
        $a = $this->scenario();
        \App\Services\NotificationService::notify($a['student']['id'], 'x', 'Hello', 'body', '/dashboard');
        $this->actingAs($a['student']);
        $n = $this->assertOk($this->getJson('/api/notifications?unread=1'));
        $this->assertSame(1, $n['unread_count']);
        $this->assertOk($this->postJson('/api/notifications/' . $n['items'][0]['id'] . '/read'));
        $this->assertSame(0, $this->assertOk($this->getJson('/api/notifications'))['unread_count']);
        $this->actingAs($a['sup']);
        $this->assertError($this->postJson('/api/notifications/' . $n['items'][0]['id'] . '/read'), 404, 'not_found');
    }
}
