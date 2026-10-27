<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Tests\Support\Factory;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    public function testLoginRequiresCsrfToken(): void
    {
        $org = Factory::institution();
        $u = Factory::user($org);
        $r = $this->postJson('/api/auth/login', ['email' => $u['email'], 'password' => Factory::PASSWORD], false);
        $this->assertError($r, 419, 'csrf_mismatch');
    }

    public function testSuccessfulLoginRotatesSessionAndReturnsUser(): void
    {
        $org = Factory::institution();
        $u = Factory::user($org, 'supervisor');
        $before = $this->session->csrfToken();
        $r = $this->postJson('/api/auth/login', ['email' => $u['email'], 'password' => Factory::PASSWORD]);
        $data = $this->assertOk($r);
        $this->assertSame($u['uuid'], $data['user']['uuid']);
        $this->assertSame(['supervisor'], $data['user']['roles']);
        $this->assertArrayNotHasKey('password_hash', $data['user']);
        $this->assertArrayNotHasKey('id', $data['user']);
        $this->assertTrue($this->session->regenerated, 'session id must be regenerated after login');
        $this->assertNotSame($before, $this->session->csrfToken(), 'CSRF token must rotate on login');
        $this->assertOk($this->getJson('/api/auth/me'));
    }

    public function testWrongPasswordIsRejectedWithGenericMessage(): void
    {
        $org = Factory::institution();
        $u = Factory::user($org);
        $err = $this->assertError($this->postJson('/api/auth/login', ['email' => $u['email'], 'password' => 'nope-nope-1']), 401, 'invalid_credentials');
        $unknown = $this->assertError($this->postJson('/api/auth/login', ['email' => 'ghost@example.test', 'password' => 'nope-nope-1']), 401, 'invalid_credentials');
        $this->assertSame($err['message'], $unknown['message'], 'must not reveal whether the account exists');
    }

    public function testAccountLocksAfterRepeatedFailures(): void
    {
        $org = Factory::institution();
        $u = Factory::user($org);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $u['email'], 'password' => 'wrong-pass-1']);
        }
        $this->assertNotNull(User::find($u['id'])['locked_until']);
        $this->assertError($this->postJson('/api/auth/login', ['email' => $u['email'], 'password' => Factory::PASSWORD]), 423, 'account_locked');
    }

    public function testApiRequiresAuthentication(): void
    {
        $this->assertError($this->getJson('/api/auth/me'), 401, 'unauthenticated');
    }

    public function testLogoutDestroysSession(): void
    {
        $org = Factory::institution();
        $this->actingAs(Factory::user($org));
        $this->assertOk($this->postJson('/api/auth/logout'));
        $this->assertTrue($this->session->destroyed);
        $this->assertError($this->getJson('/api/auth/me'), 401);
    }

    public function testLogoutRequiresCsrf(): void
    {
        $org = Factory::institution();
        $this->actingAs(Factory::user($org));
        $this->assertError($this->postJson('/api/auth/logout', [], false), 419);
    }

    public function testDisabledUserCannotUseExistingSession(): void
    {
        $org = Factory::institution();
        $u = Factory::user($org);
        $this->actingAs($u);
        User::updateById($u['id'], ['status' => 'disabled']);
        $this->assertError($this->getJson('/api/auth/me'), 401);
    }

    public function testUnknownRouteReturnsJson404(): void
    {
        $this->assertError($this->getJson('/api/nope'), 404, 'not_found');
    }

    public function testSecurityHeadersPresent(): void
    {
        $r = $this->getJson('/api/auth/csrf');
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $r->headers['X-Frame-Options']);
        $this->assertStringContainsString("default-src 'self'", $r->headers['Content-Security-Policy']);
    }

    public function testLoginPageRenders(): void
    {
        $r = $this->call('GET', '/login', [], ['Accept' => 'text/html']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Sign in', $r->body);
    }

    public function testProtectedPageRedirectsToLogin(): void
    {
        $r = $this->call('GET', '/dashboard', [], ['Accept' => 'text/html']);
        $this->assertSame(302, $r->status);
        $this->assertSame('/login', $r->headers['Location']);
    }
}
