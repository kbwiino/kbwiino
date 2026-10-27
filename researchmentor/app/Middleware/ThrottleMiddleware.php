<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\RateLimiter;
use App\Support\{HttpException, Request, Response};

/** Coarse abuse protection for the whole API: per-user (or per-IP when anonymous) request budget per minute. */
final class ThrottleMiddleware
{
    public function __construct(private int $perMinute = 300)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $key = $request->session?->get('user_id') ? 'api-u:' . $request->session->get('user_id') : 'api-ip:' . $request->ip();
        if (!RateLimiter::hit($key, $this->perMinute, 60)) {
            throw HttpException::tooMany('Too many requests. Please slow down.');
        }
        return $next($request);
    }
}
