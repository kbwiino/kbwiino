<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

/** Coarse role gate. Resource-level checks are always repeated in policies. */
final class RoleMiddleware
{
    public function __construct(private array $roles)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw HttpException::unauthenticated();
        }
        if (!array_intersect($this->roles, $user['roles'])) {
            throw HttpException::forbidden();
        }
        return $next($request);
    }
}
