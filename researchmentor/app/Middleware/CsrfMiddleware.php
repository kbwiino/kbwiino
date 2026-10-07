<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

final class CsrfMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $expected = $request->session?->csrfToken() ?? '';
            $given = (string) ($request->header('x-csrf-token') ?? $request->body['_csrf'] ?? '');
            if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
                throw new HttpException(419, 'csrf_mismatch', 'Your session has expired or the request was not trusted. Reload the page and try again.');
            }
        }
        return $next($request);
    }
}
