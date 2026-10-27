<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

final class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $user = AuthService::currentUser($request);
        if ($user === null) {
            throw HttpException::unauthenticated();
        }
        $request->attributes['user'] = $user;
        return $next($request);
    }
}
