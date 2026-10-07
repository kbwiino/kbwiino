<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\{ResearchConstitution, ResearchObjective};
use App\Services\ConstitutionService;
use Tests\Support\Factory;
use Tests\TestCase;

final class ConstitutionTest extends TestCase
{
    private function initialised(): array
    {
        $a = $this->scenario();
        ResearchObjective::create(['project_id' => $a['project']['id'], 'body' => 'Assess usage', 'position' => 1]);
        $this->actingAs($a['sup']);
        $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/constitution/initialize', ['notes' => 'Initial approval']));
        return $a;
    }

    public function testOnlySupervisorCanApproveInitialConstitution(): void
    {
        $a = $this->scenario();
        $id = $a['project']['uuid'];
        $this->actingAs($a['student']);
        $this->assertError($this->postJson("/api/projects/$id/constitution/initialize"), 403);
        $this->actingAs($a['sup']);
        $c = $this->assertOk($this->postJson("/api/projects/$id/constitution/initialize", ['notes' => 'ok']));
        $this->assertSame(1, $c['version']);
        $this->assertTrue($c['official']);
        $this->assertSame($a['project']['title'], $c['values']['title']);
        $this->assertSame($a['sup']['uuid'], $c['approved_by']['uuid']);
        $this->assertError($this->postJson("/api/projects/$id/constitution/initialize"), 409);
    }

    public function testStudentProposalDoesNotChangeApprovedVersion(): void
    {
        $a = $this->initialised();
        $id = $a['project']['uuid'];
        $this->actingAs($a['student']);
        $p = $this->assertOk($this->postJson("/api/projects/$id/constitution/proposals", ['changes' => ['purpose' => 'A revised purpose'], 'rationale' => 'Scope narrowed']));
        $this->assertSame('pending', $p['status']);
        $this->assertSame('Student proposal', $p['label']);
        $cur = $this->assertOk($this->getJson("/api/projects/$id/constitution"))['current'];
        $this->assertSame(1, $cur['version']);
        $this->assertNotSame('A revised purpose', $cur['values']['purpose']);
        // student cannot approve own proposal
        $this->assertError($this->postJson("/api/projects/$id/constitution/proposals/{$p['id']}/decision", ['decision' => 'approve']), 403);
        $this->assertSame(1, ResearchConstitution::count(['project_id' => $a['project']['id']]));
    }

    public function testApprovalCreatesNewVersionAndKeepsHistory(): void
    {
        $a = $this->initialised();
        $id = $a['project']['uuid'];
        $this->actingAs($a['student']);
        $p = $this->assertOk($this->postJson("/api/projects/$id/constitution/proposals", ['changes' => ['purpose' => 'A revised purpose', 'questions' => [['text' => 'RQ1']]]]));
        $this->actingAs($a['sup']);
        $res = $this->assertOk($this->postJson("/api/projects/$id/constitution/proposals/{$p['id']}/decision", ['decision' => 'approve', 'notes' => 'Agreed in meeting']));
        $this->assertSame(2, $res['constitution']['version']);
        $this->assertSame('A revised purpose', $res['constitution']['values']['purpose']);
        $this->assertSame($a['project']['title'], $res['constitution']['values']['title'], 'unchanged fields carry over');
        $this->assertSame('approved', $res['proposal']['status']);
        $rows = ResearchConstitution::where(['project_id' => $a['project']['id']], 'version_number ASC');
        $this->assertSame(['superseded', 'approved'], array_column($rows, 'status'));
        $this->assertSame($a['project']['purpose'], $rows[0]['approved_purpose'], 'v1 content must be untouched');
        $this->assertSame(1, \App\Models\SupervisionApproval::count(['project_id' => $a['project']['id'], 'subject_type' => 'constitution', 'subject_id' => $rows[1]['id']]));
        $this->assertNotNull(\App\Models\AuditLog::first(['action' => 'constitution.approve', 'entity_uuid' => $rows[1]['uuid']]));
        $v1 = $this->assertOk($this->getJson("/api/projects/$id/constitution/versions/1"));
        $this->assertSame($a['project']['purpose'], $v1['values']['purpose']);
        // already decided
        $this->assertError($this->postJson("/api/projects/$id/constitution/proposals/{$p['id']}/decision", ['decision' => 'approve']), 409);
    }

    public function testStaleProposalCannotBeApproved(): void
    {
        $a = $this->initialised();
        $id = $a['project']['uuid'];
        $this->actingAs($a['student']);
        $p1 = $this->assertOk($this->postJson("/api/projects/$id/constitution/proposals", ['changes' => ['purpose' => 'P1']]));
        $p2 = $this->assertOk($this->postJson("/api/projects/$id/constitution/proposals", ['changes' => ['design' => 'Case study']]));
        $this->actingAs($a['sup']);
        $this->assertOk($this->postJson("/api/projects/$id/constitution/proposals/{$p1['id']}/decision", ['decision' => 'approve']));
        $this->assertError($this->postJson("/api/projects/$id/constitution/proposals/{$p2['id']}/decision", ['decision' => 'approve']), 409);
    }

    public function testRejectedProposalLeavesConstitutionUnchanged(): void
    {
        $a = $this->initialised();
        $id = $a['project']['uuid'];
        $this->actingAs($a['student']);
        $p = $this->assertOk($this->postJson("/api/projects/$id/constitution/proposals", ['changes' => ['title' => 'New title']]));
        $this->actingAs($a['sup']);
        $this->assertSame('rejected', $this->assertOk($this->postJson("/api/projects/$id/constitution/proposals/{$p['id']}/decision", ['decision' => 'reject', 'notes' => 'No']))['proposal']['status']);
        $this->assertSame(1, ResearchConstitution::count(['project_id' => $a['project']['id']]));
    }

    public function testProposalValidation(): void
    {
        $a = $this->initialised();
        $id = $a['project']['uuid'];
        $this->actingAs($a['student']);
        $this->assertError($this->postJson("/api/projects/$id/constitution/proposals", ['changes' => ['bogus_field' => 'x']]), 422);
        $this->assertError($this->postJson("/api/projects/$id/constitution/proposals", ['changes' => ['title' => $a['project']['title']]]), 422);
        $this->assertError($this->postJson("/api/projects/$id/constitution/proposals", ['changes' => ['objectives' => 'not a list']]), 422);
    }

    public function testProposalRequiresExistingConstitution(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $this->assertError($this->postJson('/api/projects/' . $a['project']['uuid'] . '/constitution/proposals', ['changes' => ['purpose' => 'x']]), 409);
    }

    public function testComparisonAndInconsistencyDetectionAreLabelledSuggestions(): void
    {
        $a = $this->initialised();
        $id = $a['project']['uuid'];
        $this->actingAs($a['student']);
        $this->assertOk($this->putJson("/api/projects/$id", ['purpose' => 'Completely different purpose now']));
        $cmp = $this->assertOk($this->getJson("/api/projects/$id/constitution/compare?from=current&to=working"));
        $this->assertFalse($cmp['to']['official']);
        $changed = array_column(array_filter($cmp['rows'], fn($r) => $r['changed']), 'field');
        $this->assertContains('purpose', $changed);
        $this->assertNotContains('title', $changed);
        $inc = $this->assertOk($this->getJson("/api/projects/$id/constitution/inconsistencies"));
        $this->assertStringContainsString('suggestions requiring human review', $inc['label']);
        $item = array_values(array_filter($inc['items'], fn($i) => $i['field'] === 'purpose'))[0];
        $this->assertTrue($item['requires_human_review']);
        $this->assertSame('system_check', $item['origin']);
        // detection must not alter the approved Constitution
        $this->assertSame(1, ResearchConstitution::count(['project_id' => $a['project']['id']]));
        $this->assertSame($a['project']['purpose'], ConstitutionService::current($a['project'])['approved_purpose']);
    }

    public function testConstitutionIsolation(): void
    {
        $a = $this->initialised();
        $other = Factory::user($a['org'], 'student');
        $this->actingAs($other);
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid'] . '/constitution'), 404);
    }
}
