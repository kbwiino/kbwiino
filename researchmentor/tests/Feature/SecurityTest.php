<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\{AuditLog, Institution, User};
use App\Support\{Application, Router};
use Tests\Support\{Factory, Fixtures};
use Tests\TestCase;

final class SecurityTest extends TestCase
{
    protected function tearDown(): void { Fixtures::cleanup(); parent::tearDown(); }

    /** Every API route must be authenticated, and every state-changing route must be CSRF-protected. */
    public function testEveryApiRouteIsAuthenticatedAndStateChangingRoutesRequireCsrf(): void
    {
        $public = ['GET /api/auth/csrf', 'POST /api/auth/login'];
        $app = new Application();
        $checked = 0;
        foreach ($app->router()->routes() as $r) {
            if (!str_starts_with($r['pattern'], '/api')) continue;
            $sig = $r['method'] . ' ' . $r['pattern'];
            $checked++;
            if (!in_array($sig, $public, true)) {
                $this->assertContains('auth', $r['middleware'], "{$sig} must require authentication");
            }
            if (in_array($r['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $this->assertContains('csrf', $r['middleware'], "{$sig} must require a CSRF token");
            }
            $this->assertNotEmpty(array_filter($r['middleware'], fn($m) => str_starts_with($m, 'throttle')), "{$sig} must be rate limited");
        }
        $this->assertGreaterThan(100, $checked);
    }

    public function testEveryPageRouteHasItsScriptAndRequiresLoginExceptPublicPages(): void
    {
        $app = new Application();
        foreach ($app->router()->routes() as $r) {
            if (str_starts_with($r['pattern'], '/api') || $r['method'] !== 'GET') continue;
            if (!in_array($r['pattern'], ['/', '/login', '/privacy'], true)) {
                $this->assertContains('auth', $r['middleware'], "page {$r['pattern']} must require login");
            }
        }
        $map = \App\Support\Container::get('page.map');
        foreach ($map as $pattern => [$page]) {
            $this->assertFileExists(dirname(__DIR__, 2) . "/public/assets/js/pages/{$page}.js", "script for {$pattern}");
        }
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/assets/js/pages/dashboard.js');
    }

    public function testLoginPageLoadsScriptsInTheRightOrderAndNeverUsesInlineScript(): void
    {
        $html = $this->call('GET', '/login', [], ['Accept' => 'text/html'])->body;
        $this->assertLessThan(strpos($html, '/assets/js/login.js'), strpos($html, '/assets/js/app.js'), 'app.js (defines RM) must load before login.js');
        $this->assertStringContainsString('method="post"', $html, 'a script failure must never put credentials in the URL');
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html, 'no inline scripts (CSP)');
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, 'no inline event handlers');
        $this->assertStringContainsString('name="csrf-token"', $html);
    }

    public function testHeadRequestsWork(): void
    {
        $r = $this->call('HEAD', '/login', [], ['Accept' => 'text/html']);
        $this->assertSame(200, $r->status);
    }

    public function testPasswordChange(): void
    {
        $org = Factory::institution();
        $u = Factory::user($org);
        $this->actingAs($u);
        $this->assertError($this->postJson('/api/auth/change-password', ['current_password' => 'wrong-pass-123', 'new_password' => 'Another-Pass-77']), 403, 'wrong_password');
        $this->assertError($this->postJson('/api/auth/change-password', ['current_password' => Factory::PASSWORD, 'new_password' => 'short']), 422);
        $this->assertError($this->postJson('/api/auth/change-password', ['current_password' => Factory::PASSWORD, 'new_password' => Factory::PASSWORD]), 422);
        $before = $this->session->csrfToken();
        $this->assertOk($this->postJson('/api/auth/change-password', ['current_password' => Factory::PASSWORD, 'new_password' => 'Another-Pass-77']));
        $this->assertTrue($this->session->regenerated);
        $this->assertNotSame($before, $this->session->csrfToken());
        $this->assertTrue(password_verify('Another-Pass-77', User::find($u['id'])['password_hash']));
        $this->assertNotNull(AuditLog::first(['action' => 'auth.password_changed']));
        $this->newBrowser();
        $this->assertOk($this->postJson('/api/auth/login', ['email' => $u['email'], 'password' => 'Another-Pass-77']));
    }

    public function testGraduateAdminCannotTakeOverSystemAdminOrEscalateSelf(): void
    {
        $org = Factory::institution();
        $sys = Factory::user($org, 'sysadmin');
        $admin = Factory::user($org, 'grad_admin');
        $this->actingAs($admin);
        $this->assertError($this->putJson('/api/admin/users/' . $sys['uuid'], ['password' => 'Takeover-Pass-1']), 404);
        $this->assertError($this->putJson('/api/admin/users/' . $sys['uuid'], ['status' => 'disabled']), 404);
        $this->assertError($this->putJson('/api/admin/users/' . $admin['uuid'], ['roles' => ['grad_admin', 'supervisor']]), 403);
        $this->assertError($this->postJson('/api/admin/users', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'new@x.test', 'password' => 'Valid-Pass-123', 'roles' => ['sysadmin']]), 403);
        $this->assertFalse(password_verify('Takeover-Pass-1', User::find($sys['id'])['password_hash']));
        // another institution's admin cannot see or modify users here
        $other = Factory::user(Factory::institution(), 'grad_admin');
        $this->actingAs($other);
        $student = Factory::user($org, 'student');
        $this->assertError($this->putJson('/api/admin/users/' . $student['uuid'], ['status' => 'disabled']), 404);
        $emails = array_column($this->assertOk($this->getJson('/api/admin/users')), 'email');
        $this->assertNotContains($student['email'], $emails);
        // non-admins are locked out
        $this->actingAs($student);
        $this->assertError($this->getJson('/api/admin/users'), 403);
        $this->assertError($this->postJson('/api/admin/users', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'x@y.test', 'password' => 'Valid-Pass-123', 'roles' => ['student']]), 403);
    }

    public function testAdminUserCreationValidationAndAudit(): void
    {
        $org = Factory::institution();
        $admin = Factory::user($org, 'grad_admin');
        $this->actingAs($admin);
        $this->assertError($this->postJson('/api/admin/users', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.test', 'password' => 'weak', 'roles' => ['student']]), 422);
        $this->assertError($this->postJson('/api/admin/users', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.test', 'password' => 'Valid-Pass-123', 'roles' => ['wizard']]), 422);
        $u = $this->assertOk($this->postJson('/api/admin/users', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'Ada@B.test', 'password' => 'Valid-Pass-123', 'roles' => ['supervisor']]));
        $this->assertSame('ada@b.test', $u['email']);
        $this->assertArrayNotHasKey('password_hash', $u);
        $this->assertError($this->postJson('/api/admin/users', ['first_name' => 'Ada', 'last_name' => 'Two', 'email' => 'ada@b.test', 'password' => 'Valid-Pass-123', 'roles' => ['student']]), 422);
        $this->assertNotNull(AuditLog::first(['action' => 'user.create']));
        $this->assertSame($org['institution']['id'], User::findByUuid($u['id'])['institution_id']);
    }

    public function testInstitutionSettingsRetentionAndAiSwitch(): void
    {
        $a = $this->scenario();
        $admin = Factory::user($a['org'], 'grad_admin');
        $this->actingAs($a['student']);
        $this->assertError($this->getJson('/api/admin/institution'), 403);
        $this->assertError($this->putJson('/api/admin/institution', ['retention_days' => 10]), 403);
        $this->actingAs($admin);
        $this->assertError($this->putJson('/api/admin/institution', ['retention_days' => 5]), 422);
        $r = $this->assertOk($this->putJson('/api/admin/institution', ['retention_days' => 365, 'ai_enabled' => false]));
        $this->assertSame(365, $r['retention_days']);
        $this->assertSame(0, (int) Institution::find((int) $a['org']['institution']['id'])['ai_enabled']);
        $this->assertNotNull(AuditLog::first(['action' => 'institution.settings_update']));
        // new documents pick up the new retention period
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->call('POST', '/api/documents/upload', ['project_id' => $a['project']['uuid']], [], Fixtures::txt()));
        $this->assertSame(gmdate('Y-m-d', strtotime('+365 days')), $d['retention_until']);
        // AI disabled for the institution blocks AI requests
        $this->assertError($this->postJson('/api/ai/writing-review', ['project_id' => $a['project']['uuid'], 'document_id' => $d['id']]), 403, 'ai_disabled_for_institution');
    }

    public function testMyDataExportContainsOnlyOwnData(): void
    {
        $a = $this->scenario();
        $other = Factory::user($a['org'], 'student');
        Factory::project($a['org'], $other, $a['sup'], ['title' => 'OTHER-STUDENT-SECRET']);
        $this->actingAs($a['student']);
        $r = $this->getJson('/api/account/my-data');
        $this->assertSame(200, $r->status);
        $this->assertStringNotContainsString('OTHER-STUDENT-SECRET', $r->body);
        $this->assertStringNotContainsString('password', $r->body);
        $d = $r->decoded()['data'];
        $this->assertSame($a['student']['email'], $d['profile']['email']);
        $this->assertCount(1, $d['projects']);
        $this->assertNotNull(AuditLog::first(['action' => 'account.data_export']));
    }

    public function testApiThrottleBlocksFloods(): void
    {
        $this->actingAs(Factory::user(Factory::institution()));
        \App\Support\Config::set('security.noop', 1);
        $limit = 600;
        \App\Support\Database::execute('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, ?)', ['api-u:' . $this->session->get('user_id'), intdiv(time(), 60) * 60, $limit]);
        $this->assertError($this->getJson('/api/auth/me'), 429, 'rate_limited');
    }

    public function testNoSecretsOrInternalPathsLeakInErrorsOrResponses(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->call('POST', '/api/documents/upload', ['project_id' => $a['project']['uuid']], [], Fixtures::txt()));
        $bodies = [json_encode($d), $this->getJson('/api/projects/' . $a['project']['uuid'])->body, $this->getJson('/api/ai/status')->body, $this->getJson('/api/auth/me')->body];
        foreach ($bodies as $b) {
            $this->assertStringNotContainsString('stored_filename', $b);
            $this->assertStringNotContainsString('password_hash', $b);
            $this->assertStringNotContainsString('ANTHROPIC', $b);
            $this->assertStringNotContainsString(\App\Support\Config::get('storage.private_path'), $b);
            $this->assertDoesNotMatchRegularExpression('/"id":\s*\d+[,}]/', $b, 'internal numeric ids must not be exposed');
        }
        $err = $this->getJson('/api/projects/not-a-uuid');
        $this->assertSame(404, $err->status);
        $this->assertStringNotContainsString('SQLSTATE', $err->body);
    }

    public function testSqlInjectionAttemptsAreInert(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        foreach (["' OR 1=1 --", "'; DROP TABLE users; --", '%', '" OR ""="', "1) UNION SELECT password_hash FROM users -- "] as $payload) {
            $r = $this->getJson('/api/projects?q=' . urlencode($payload) . '&stage=' . urlencode($payload) . '&status=' . urlencode($payload));
            $this->assertContains($r->status, [200], $payload);
            $this->assertStringNotContainsString('password_hash', $r->body);
        }
        $this->assertError($this->getJson('/api/projects/' . urlencode("x' OR '1'='1")), 404);
        $this->assertGreaterThan(0, User::count());
    }

    public function testHtmlPayloadsAreStoredVerbatimAndEscapedByTheViewLayer(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $p = $this->assertOk($this->putJson('/api/projects/' . $a['project']['uuid'], ['title' => '<img src=x onerror=alert(1)>']));
        $this->assertSame('<img src=x onerror=alert(1)>', $p['title'], 'API returns JSON data as-is; output is escaped where rendered');
        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', e($p['title']));
        $this->assertStringContainsString('application/json', $this->getJson('/api/projects/' . $a['project']['uuid'])->headers['Content-Type']);
    }

    public function testGraduateAdminManagesInstitutionStructureWithinOwnInstitution(): void
    {
        $org = Factory::institution();
        $other = Factory::institution();
        $admin = Factory::user($org, 'grad_admin');
        $this->actingAs(Factory::user($org, 'sysadmin'));
        $this->assertError($this->postJson('/api/admin/faculties', ['name' => 'X', 'code' => 'X1']), 403);
        $this->actingAs($admin);
        $f = $this->assertOk($this->postJson('/api/admin/faculties', ['name' => 'Faculty of Law', 'code' => 'law']));
        $this->assertSame('LAW', $f['code']);
        $this->assertError($this->postJson('/api/admin/faculties', ['name' => 'Dup', 'code' => 'LAW']), 422);
        $this->assertError($this->postJson('/api/admin/faculties', ['name' => 'Bad', 'code' => 'bad code!']), 422);
        $d = $this->assertOk($this->postJson('/api/admin/departments', ['faculty_id' => $f['id'], 'name' => 'Public Law', 'code' => 'PUB']));
        $p = $this->assertOk($this->postJson('/api/admin/programmes', ['department_id' => $d['id'], 'name' => 'LLM Public Law', 'code' => 'LLM-PUB', 'degree_level' => 'masters']));
        $this->assertSame('masters', $p['degree_level']);
        // cannot attach to another institution's faculty/department
        $this->assertError($this->postJson('/api/admin/departments', ['faculty_id' => $other['faculty']['uuid'], 'name' => 'Hijack', 'code' => 'HJ']), 422);
        $this->assertError($this->postJson('/api/admin/programmes', ['department_id' => $other['department']['uuid'], 'name' => 'Hijack', 'code' => 'HJ2', 'degree_level' => 'phd']), 422);
        $y = $this->assertOk($this->postJson('/api/admin/academic-years', ['label' => '2027/2028', 'starts_on' => '2027-08-01', 'ends_on' => '2028-07-31', 'is_current' => true]));
        $this->assertTrue($y['is_current']);
        $this->assertSame(1, (int) \App\Support\Database::fetchValue('SELECT COUNT(*) FROM academic_years WHERE institution_id = ? AND is_current = 1', [$org['institution']['id']]), 'only one current year');
        $this->assertError($this->postJson('/api/admin/academic-years', ['label' => '2027/2029', 'starts_on' => '2028-08-01', 'ends_on' => '2027-07-31']), 422);
        $struct = $this->assertOk($this->getJson('/api/lookups/structure'));
        $this->assertContains('Faculty of Law', array_column($struct['faculties'], 'name'));
        // the new programme can be used to create a project
        $student = Factory::user($org, 'student');
        $sup = Factory::user($org, 'supervisor');
        $this->actingAs($student);
        $this->assertOk($this->postJson('/api/projects', ['faculty_id' => $f['id'], 'department_id' => $d['id'], 'programme_id' => $p['id'], 'academic_year_id' => $y['id'], 'degree_level' => 'masters', 'title' => 'Law project', 'research_area' => 'Law', 'problem_statement' => 'p', 'purpose' => 'q', 'approach' => 'qualitative', 'design' => 'Doctrinal', 'supervisor_id' => $sup['uuid']]));
        $this->assertNotNull(AuditLog::first(['action' => 'structure.programme_create']));
    }
}
