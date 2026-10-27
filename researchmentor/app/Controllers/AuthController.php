<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Institution;
use App\Services\AuthService;
use App\Support\Request;
use App\Support\Response;

final class AuthController extends BaseController
{
    public function csrf(Request $r): Response
    {
        return $this->ok(['csrf_token' => $r->session->csrfToken()]);
    }

    public function login(Request $r): Response
    {
        $data = $this->validate($r, ['email' => 'required|email', 'password' => 'required|string|max:255']);
        $user = AuthService::attempt($r, $data['email'], $data['password']);
        return $this->ok([
            'user' => self::publicUser($user),
            'csrf_token' => $r->session->csrfToken(), // rotated with the session
            'redirect' => '/dashboard',
        ]);
    }

    public function logout(Request $r): Response
    {
        AuthService::logout($r);
        return $this->ok(['logged_out' => true]);
    }

    public function me(Request $r): Response
    {
        $user = $this->user($r);
        $inst = Institution::find($user['institution_id']);
        return $this->ok([
            'user' => self::publicUser($user),
            'institution' => ['uuid' => $inst['uuid'], 'name' => $inst['name']],
            'csrf_token' => $r->session->csrfToken(),
        ]);
    }

    public static function publicUser(array $u): array
    {
        return [
            'uuid' => $u['uuid'],
            'name' => $u['name'],
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
            'email' => $u['email'],
            'roles' => $u['roles'],
        ];
    }
}
