<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\Factory;
use Tests\TestCase;

final class ApiEnvelopeTest extends TestCase
{
    public function testSuccessResponsesUseTheStandardEnvelope(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $pid = $a['project']['uuid'];
        foreach (['/api/projects', "/api/projects/$pid", "/api/projects/$pid/objectives", "/api/projects/$pid/milestones", "/api/projects/$pid/timeline", "/api/projects/$pid/constitution", "/api/projects/$pid/documents", "/api/projects/$pid/reviews", "/api/projects/$pid/supervision", "/api/projects/$pid/literature", "/api/projects/$pid/progress", "/api/dashboard/student", '/api/notifications', '/api/ai/status', '/api/viva/modes', '/api/lookups/structure', '/api/knowledge/documents', '/api/auth/me'] as $url) {
            $r = $this->getJson($url);
            $this->assertSame(200, $r->status, $url . ' ' . $r->body);
            $this->assertStringStartsWith('application/json', $r->headers['Content-Type'], $url);
            $json = $r->decoded();
            $this->assertIsArray($json, $url);
            $this->assertTrue($json['success'], $url);
            $this->assertArrayHasKey('data', $json, $url);
            $this->assertSame(['success', 'data'], array_values(array_intersect(array_keys($json), ['success', 'data'])), $url);
            $this->assertSame('no-store', $r->headers['Cache-Control'], $url . ' API responses must not be cached');
        }
    }

    public function testErrorResponsesUseTheStandardEnvelope(): void
    {
        $a = $this->scenario();
        $cases = [
            ['GET', '/api/projects/' . $a['project']['uuid'], 401, 'unauthenticated'],
            ['POST', '/api/auth/login', 419, 'csrf_mismatch'],
            ['GET', '/api/does-not-exist', 404, 'not_found'],
            ['DELETE', '/api/auth/csrf', 405, 'method_not_allowed'],
        ];
        foreach ($cases as [$m, $u, $status, $code]) {
            $r = $this->call($m, $u, [], [], [], false);
            $this->assertSame($status, $r->status, "$m $u");
            $j = $r->decoded();
            $this->assertFalse($j['success']);
            $this->assertSame($code, $j['error']['code']);
            $this->assertIsString($j['error']['message']);
            $this->assertArrayNotHasKey('data', $j);
        }
        $this->actingAs($a['student']);
        $r = $this->postJson('/api/projects', ['title' => '']);
        $this->assertSame(422, $r->status);
        $j = $r->decoded();
        $this->assertSame('validation_failed', $j['error']['code']);
        $this->assertIsArray($j['error']['details']);
    }

    public function testListEndpointsReturnPaginationMeta(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $j = $this->getJson('/api/projects?per_page=1&page=1')->decoded();
        $this->assertSame(1, $j['meta']['per_page']);
        $this->assertSame(1, $j['meta']['total']);
    }
}
