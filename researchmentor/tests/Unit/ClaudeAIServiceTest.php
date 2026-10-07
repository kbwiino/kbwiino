<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AiUsageLog;
use App\Services\AI\{AIException, ClaudeAIService, ReviewSchema};
use Tests\Support\{Factory, FakeTransport};
use Tests\TestCase;

final class ClaudeAIServiceTest extends TestCase
{
    private array $sleeps = [];

    private function svc(FakeTransport $t, array $over = []): ClaudeAIService
    {
        $this->sleeps = [];
        return new ClaudeAIService($t, function (float $s) { $this->sleeps[] = $s; }, $over + [
            'enabled' => true, 'api_key' => 'sk-ant-test-SECRET-123456', 'model' => 'claude-sonnet-5-5', 'api_version' => '2023-06-01',
            'base_url' => 'https://api.anthropic.com', 'max_tokens' => 4000, 'timeout' => 30, 'max_retries' => 3, 'effort' => 'medium',
        ]);
    }

    public function testRequestFormatMatchesMessagesApi(): void
    {
        $t = (new FakeTransport())->push(200, FakeTransport::message(FakeTransport::review())) ;
        $res = $this->svc($t)->createReview('proposal', 'SYSTEM', 'USER', ['purpose' => 'review:proposal']);
        $req = $t->requests[0];
        $this->assertSame('https://api.anthropic.com/v1/messages', $req['url']);
        $this->assertContains('x-api-key: sk-ant-test-SECRET-123456', $req['headers']);
        $this->assertContains('anthropic-version: 2023-06-01', $req['headers']);
        $this->assertContains('Content-Type: application/json', $req['headers']);
        $b = $req['body'];
        $this->assertSame('claude-sonnet-5-5', $b['model']);
        $this->assertSame(4000, $b['max_tokens']);
        $this->assertSame('SYSTEM', $b['system']);
        $this->assertSame([['role' => 'user', 'content' => 'USER']], $b['messages']);
        $this->assertSame('json_schema', $b['output_config']['format']['type']);
        $this->assertSame('medium', $b['output_config']['effort']);
        $this->assertFalse($b['output_config']['format']['schema']['additionalProperties']);
        foreach (['temperature', 'top_p', 'top_k', 'thinking'] as $k) {
            $this->assertArrayNotHasKey($k, $b, "{$k} must not be sent (rejected by current models)");
        }
        $this->assertSame('Real-looking summary.', $res->json['summary']);
        $this->assertSame(120, $res->inputTokens);
        $this->assertSame('msg_test', $res->requestId, 'falls back to the message id when no request-id header');
    }

    public function testRetriesOnOverloadThenSucceeds(): void
    {
        $t = (new FakeTransport())->push(529, ['error' => ['message' => 'Overloaded']])->push(429, ['error' => ['message' => 'slow down']], ['retry-after' => '7'])->push(200, FakeTransport::message('hello'));
        $res = $this->svc($t)->generateText('s', 'u', ['purpose' => 't']);
        $this->assertSame('hello', $res->text);
        $this->assertSame(3, $res->attempts);
        $this->assertCount(3, $t->requests);
        $this->assertCount(2, $this->sleeps);
        $this->assertSame(7.0, $this->sleeps[1], 'retry-after honoured');
    }

    public function testGivesUpAfterRetryLimitWithSafeMessage(): void
    {
        $t = new FakeTransport();
        for ($i = 0; $i < 10; $i++) $t->push(503, ['error' => ['message' => 'upstream']]);
        try {
            $this->svc($t, ['max_retries' => 2])->generateText('s', 'u', ['purpose' => 't']);
            $this->fail('expected exception');
        } catch (AIException $e) {
            $this->assertSame('provider_error', $e->errorCode);
            $this->assertCount(3, $t->requests, '1 try + 2 retries');
            $this->assertStringNotContainsString('sk-ant', $e->getMessage());
        }
    }

    public function testNetworkErrorsAreRetried(): void
    {
        $t = (new FakeTransport())->push(new AIException('timeout', 'The AI provider timed out.', true))->push(200, FakeTransport::message('ok'));
        $this->assertSame('ok', $this->svc($t)->generateText('s', 'u')->text);
    }

    public function testAuthErrorsAreNotRetriedAndNeverLeakKey(): void
    {
        $t = (new FakeTransport())->push(401, ['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key sk-ant-test-SECRET-123456']]);
        try {
            $this->svc($t)->generateText('s', 'u', ['purpose' => 't']);
            $this->fail('expected exception');
        } catch (AIException $e) {
            $this->assertSame('auth_error', $e->errorCode);
            $this->assertCount(1, $t->requests);
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
        }
        $log = file_get_contents(\App\Support\Config::get('storage.log_path') . '/app-' . gmdate('Y-m-d') . '.log');
        $this->assertStringNotContainsString('SECRET-123456', $log, 'log must be redacted');
    }

    public function testRefusalIsSurfacedNotTrusted(): void
    {
        $org = Factory::institution();
        $u = Factory::user($org);
        $t = (new FakeTransport())->push(200, FakeTransport::message('', 'refusal'));
        try {
            $this->svc($t)->generateText('s', 'u', ['purpose' => 't', 'user_id' => $u['id']]);
            $this->fail('expected exception');
        } catch (AIException $e) {
            $this->assertSame('refused', $e->errorCode);
        }
        $this->assertSame('refused', AiUsageLog::first(['user_id' => $u['id']])['status']);
    }

    public function testTruncatedAndInvalidStructuredOutputRejected(): void
    {
        $t = (new FakeTransport())->push(200, FakeTransport::message('{"summary": "cut', 'max_tokens'));
        try { $this->svc($t)->createReview('proposal', 's', 'u'); $this->fail(); } catch (AIException $e) { $this->assertSame('truncated', $e->errorCode); }
        $t = (new FakeTransport())->push(200, FakeTransport::message('Sorry, here is prose, not JSON.'));
        try { $this->svc($t)->createReview('proposal', 's', 'u'); $this->fail(); } catch (AIException $e) { $this->assertSame('invalid_response', $e->errorCode); }
    }

    public function testFencedJsonIsAccepted(): void
    {
        $t = (new FakeTransport())->push(200, FakeTransport::message("```json\n" . json_encode(FakeTransport::review()) . "\n```"));
        $this->assertSame('proposal', $this->svc($t)->createReview('proposal', 's', 'u')->json['review_type']);
    }

    public function testUnsupportedOptionalFeaturesAreDroppedOnce(): void
    {
        $t = (new FakeTransport())->push(400, ['error' => ['type' => 'invalid_request_error', 'message' => 'output_config.effort: not supported for this model']])->push(200, FakeTransport::message(FakeTransport::review()));
        $this->svc($t)->createReview('proposal', 's', 'u');
        $this->assertArrayHasKey('effort', $t->requests[0]['body']['output_config']);
        $this->assertArrayNotHasKey('effort', $t->requests[1]['body']['output_config']);
        $this->assertArrayHasKey('format', $t->requests[1]['body']['output_config']);
    }

    public function testUsageIsLoggedWithoutContent(): void
    {
        $org = Factory::institution();
        $u = Factory::user($org);
        $t = (new FakeTransport())->push(200, FakeTransport::message('answer text', 'end_turn', 321, 45));
        $this->svc($t)->generateText('system secret prompt', 'private thesis text', ['purpose' => 'unit', 'user_id' => $u['id']]);
        $log = AiUsageLog::first(['user_id' => $u['id']]);
        $this->assertSame(321, (int) $log['input_tokens']);
        $this->assertSame(45, (int) $log['output_tokens']);
        $this->assertSame('success', $log['status']);
        $this->assertSame('claude', $log['provider']);
        $this->assertStringNotContainsString('private thesis', json_encode($log));
    }

    public function testAvailabilityReflectsConfig(): void
    {
        $t = new FakeTransport();
        $this->assertTrue($this->svc($t)->checkAvailability()['available']);
        $this->assertFalse($this->svc($t, ['api_key' => ''])->checkAvailability()['available']);
        $this->assertFalse($this->svc($t, ['enabled' => false])->checkAvailability()['available']);
        try { $this->svc($t, ['api_key' => ''])->generateText('s', 'u'); $this->fail(); } catch (AIException $e) { $this->assertSame('unavailable', $e->errorCode); }
        $this->assertCount(0, $t->requests, 'no request without a key');
    }

    public function testCountTokens(): void
    {
        $t = (new FakeTransport())->push(200, ['input_tokens' => 777]);
        $this->assertSame(777, $this->svc($t)->countTokensIfSupported('s', 'u'));
        $this->assertStringEndsWith('/v1/messages/count_tokens', $t->requests[0]['url']);
        $this->assertArrayNotHasKey('max_tokens', $t->requests[0]['body']);
    }
}
