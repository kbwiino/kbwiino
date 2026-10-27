<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\Config;
use App\Support\Request;
use App\Support\Response;

final class SecurityHeaders
{
    public function decorate(Response $r, Request $request): Response
    {
        $r->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if (Config::get('app.env') === 'production') {
            $r->headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        if ($request->wantsJson() || $request->user() !== null) {
            $r->headers += ['Cache-Control' => 'no-store'];
        }
        return $r;
    }
}
