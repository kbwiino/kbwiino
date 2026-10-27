<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\{VivaSession};
use App\Services\AI\ClaudeAIService;
use App\Support\Container;
use Tests\Support\{Factory, FakeTransport};
use Tests\TestCase;

final class VivaTest extends TestCase
{
    public function testPracticeSessionWorkflowAndLabelling(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $s = $this->assertOk($this->postJson('/api/ai/viva-session', ['project_id' => $a['project']['uuid'], 'mode' => 'methodology']));
        $this->assertTrue($s['practice']);
        $this->assertSame('This is an AI practice exercise, not an official examination.', $s['notice']);
        $this->assertCount(1, $s['exchanges']);
        $q = $s['exchanges'][0];
        $this->assertNull($q['answer']);
        $r = $this->assertOk($this->postJson('/api/viva/sessions/' . $s['id'] . '/answer', ['question_id' => $q['id'], 'answer' => 'I used a cross-sectional survey because the objective is descriptive.']));
        $this->assertSame('ai_suggestion', $r['feedback']['origin']);
        $this->assertStringContainsString('not a mark', $r['feedback']['label']);
        $this->assertNotNull($r['next_question']);
        $this->assertTrue($r['next_question']['is_follow_up']);
        // answering twice is refused
        $this->assertError($this->postJson('/api/viva/sessions/' . $s['id'] . '/answer', ['question_id' => $q['id'], 'answer' => 'again']), 409);
        $end = $this->assertOk($this->postJson('/api/viva/sessions/' . $s['id'] . '/end'));
        $this->assertSame('completed', $end['status']);
        $this->assertStringContainsString('not an examination result', $end['summary']);
        $this->assertError($this->postJson('/api/viva/sessions/' . $s['id'] . '/answer', ['question_id' => $r['next_question']['id'], 'answer' => 'late']), 409);
        $hist = $this->assertOk($this->getJson('/api/projects/' . $a['project']['uuid'] . '/viva'));
        $this->assertCount(1, $hist);
        $json = json_encode($end);
        foreach (['grade', 'pass', 'fail', 'score', 'mark'] as $w) {
            $this->assertStringNotContainsStringIgnoringCase('"' . $w . '"', $json);
        }
    }

    public function testOnlyTheStudentOwnerSeesPracticeHistory(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $s = $this->assertOk($this->postJson('/api/ai/viva-session', ['project_id' => $a['project']['uuid'], 'mode' => 'general']));
        foreach ([$a['sup'], Factory::user($a['org'], 'student'), Factory::user($a['org'], 'coordinator')] as $u) {
            $this->actingAs($u);
            $this->assertError($this->getJson('/api/viva/sessions/' . $s['id']), 404);
            $this->assertError($this->postJson('/api/viva/sessions/' . $s['id'] . '/end'), 404);
        }
        $this->actingAs($a['sup']);
        $this->assertError($this->postJson('/api/ai/viva-session', ['project_id' => $a['project']['uuid'], 'mode' => 'general']), 403);
        $this->assertCount(0, $this->assertOk($this->getJson('/api/projects/' . $a['project']['uuid'] . '/viva')), 'supervisors cannot browse practice history');
    }

    public function testValidationAndConsent(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $this->assertError($this->postJson('/api/ai/viva-session', ['project_id' => $a['project']['uuid'], 'mode' => 'bogus']), 422);
        $t = new FakeTransport(fn() => FakeTransport::ok(['question' => 'Why?', 'topic' => 't']));
        Container::instance('ai.service', new ClaudeAIService($t, fn() => null, ['enabled' => true, 'api_key' => 'k', 'model' => 'm', 'api_version' => '2023-06-01', 'base_url' => 'https://x', 'max_tokens' => 100, 'timeout' => 5, 'max_retries' => 0, 'effort' => '']));
        \App\Models\ResearchProject::updateById((int) $a['project']['id'], ['ai_consent_at' => null]);
        $this->assertError($this->postJson('/api/ai/viva-session', ['project_id' => $a['project']['uuid'], 'mode' => 'general']), 403, 'ai_consent_required');
        $this->assertCount(0, $t->requests);
    }

    public function testPromptUsesApprovedConstitutionAndForbidsVerdicts(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['sup']);
        $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/constitution/initialize'));
        $t = new FakeTransport(fn() => FakeTransport::ok(['question' => 'Defend your sampling approach.', 'topic' => 'Sampling']));
        Container::instance('ai.service', new ClaudeAIService($t, fn() => null, ['enabled' => true, 'api_key' => 'k', 'model' => 'm', 'api_version' => '2023-06-01', 'base_url' => 'https://x', 'max_tokens' => 100, 'timeout' => 5, 'max_retries' => 0, 'effort' => '']));
        $this->actingAs($a['student']);
        $s = $this->assertOk($this->postJson('/api/ai/viva-session', ['project_id' => $a['project']['uuid'], 'mode' => 'external_examiner']));
        $body = $t->requests[0]['body'];
        $this->assertStringContainsString('Approved Research Constitution v1', $body['messages'][0]['content']);
        $this->assertStringContainsString('PRACTICE viva', $body['system']);
        $this->assertStringContainsString('Never give a verdict', $body['system']);
        $this->assertStringContainsString('Do not reveal hidden reasoning', $body['system']);
        $this->assertSame('Defend your sampling approach.', $s['exchanges'][0]['question']);
        $this->assertFalse($s['mock']);
    }

    public function testQuestionLimitIsEnforced(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $s = $this->assertOk($this->postJson('/api/ai/viva-session', ['project_id' => $a['project']['uuid'], 'mode' => 'theory']));
        $q = $s['exchanges'][0]['id'];
        $count = 1;
        while ($q) {
            $r = $this->assertOk($this->postJson('/api/viva/sessions/' . $s['id'] . '/answer', ['question_id' => $q, 'answer' => 'My answer ' . $count]));
            $q = $r['next_question']['id'] ?? null;
            $count++;
            $this->assertLessThanOrEqual(20, $count);
        }
        $this->assertSame(\App\Services\VivaService::MAX_QUESTIONS, $count - 1);
    }
}
