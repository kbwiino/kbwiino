<?php
declare(strict_types=1);

namespace Tests;

use App\Policies\ProjectAccess;
use App\Support\{Application, Config, Container, Database, Request, Response, Session};
use PHPUnit\Framework\TestCase as BaseTestCase;
use Tests\Support\Factory;

abstract class TestCase extends BaseTestCase
{
    protected Application $app;
    /** Per-"browser" session storage so CSRF/session state persists across calls. */
    protected Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        Container::reset();
        ProjectAccess::flush();
        Database::connection()->beginTransaction();   // each test rolls back
        $this->app = new Application();
        $this->session = Session::array();
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        parent::tearDown();
    }

    protected function newBrowser(): void
    {
        $this->session = Session::array();
    }

    /** Authenticate this "browser" as $user without the login round-trip. */
    protected function actingAs(array $user): static
    {
        $this->newBrowser();
        $this->session->set('user_id', $user['id']);
        $this->session->set('last_activity', time());
        return $this;
    }

    protected function call(string $method, string $uri, array $body = [], array $headers = [], array $files = [], bool $csrf = true): Response
    {
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        if ($csrf && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $headers['X-CSRF-Token'] = $this->session->csrfToken();
        }
        $req = new Request($method, $parts['path'], $query, $body, $headers + ['Accept' => 'application/json'], $files, ['REMOTE_ADDR' => '127.0.0.1'], $this->session);
        return $this->app->handle($req);
    }

    protected function getJson(string $uri): Response { return $this->call('GET', $uri); }
    protected function postJson(string $uri, array $body = [], bool $csrf = true): Response { return $this->call('POST', $uri, $body, [], [], $csrf); }
    protected function putJson(string $uri, array $body = []): Response { return $this->call('PUT', $uri, $body); }
    protected function deleteJson(string $uri): Response { return $this->call('DELETE', $uri); }

    protected function assertOk(Response $r, string $msg = ''): array
    {
        $this->assertContains($r->status, [200, 201, 202], $msg . ' Unexpected status ' . $r->status . ': ' . $r->body);
        $json = $r->decoded();
        $this->assertTrue($json['success'] ?? false, $msg . ' ' . $r->body);
        return $json['data'] ?? [];
    }

    protected function assertError(Response $r, int $status, ?string $code = null): array
    {
        $this->assertSame($status, $r->status, 'Body: ' . $r->body);
        $json = $r->decoded();
        $this->assertFalse($json['success'] ?? true);
        if ($code) {
            $this->assertSame($code, $json['error']['code']);
        }
        return $json['error'];
    }

    /** @return array<string,mixed> a typical institution with student + supervisor + project */
    protected function scenario(): array
    {
        $org = Factory::institution();
        $student = Factory::user($org, 'student');
        $sup = Factory::user($org, 'supervisor');
        $project = Factory::project($org, $student, $sup);
        return compact('org', 'student', 'sup', 'project');
    }
}
