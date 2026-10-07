<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Institution;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/** Server-rendered page shells. Data is loaded by JavaScript from the JSON API; authorization is enforced by the API. */
final class PageController extends BaseController
{
    private function render(Request $r, string $view, array $data = [], string $layout = 'layouts/app'): Response
    {
        $data += ['request' => $r, 'user' => $r->user(), 'csrf' => $r->session->csrfToken()];
        return Response::html(View::render($view, $data, $layout));
    }

    public function home(Request $r): Response
    {
        return Response::redirect(\App\Services\AuthService::currentUser($r) ? '/dashboard' : '/login');
    }

    public function login(Request $r): Response
    {
        if (\App\Services\AuthService::currentUser($r)) {
            return Response::redirect('/dashboard');
        }
        return $this->render($r, 'auth/login', ['title' => 'Sign in', 'script' => '/assets/js/login.js'], 'layouts/plain');
    }

    public function dashboard(Request $r): Response
    {
        return $this->render($r, 'pages/dashboard', ['title' => 'Dashboard', 'page' => 'dashboard']);
    }

    /** Generic shell for JS-driven pages; $page maps to /assets/js/pages/{page}.js */
    public function shell(Request $r, string $page, string $title): Response
    {
        return $this->render($r, 'pages/shell', [
            'title' => $title,
            'page' => $page,
            'resourceId' => $r->param('id'),
            'resourceId2' => $r->param('id2'),
        ]);
    }

    /** Resolve the page definition from the matched route pattern. */
    public function shellFor(Request $r): Response
    {
        $map = \App\Support\Container::get('page.map');
        $uri = $r->path;
        foreach ($map as $pattern => [$page, $title]) {
            $regex = '#^' . preg_replace('#\{[a-z0-9_]+\}#i', '[^/]+', $pattern) . '$#';
            if (preg_match($regex, $uri)) {
                return $this->shell($r, $page, $title);
            }
        }
        throw \App\Support\HttpException::notFound('Page');
    }

    public function privacy(Request $r): Response
    {
        $user = \App\Services\AuthService::currentUser($r);
        $r->attributes['user'] = $user;
        $notice = null;
        if ($user) {
            $inst = Institution::find($user['institution_id']);
            $notice = $inst['privacy_notice'] ?? null;
        }
        return $this->render($r, 'pages/privacy', ['title' => 'Privacy notice', 'notice' => $notice, 'user' => $user], $user ? 'layouts/app' : 'layouts/plain');
    }
}
