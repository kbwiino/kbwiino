<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Support\Router;

return function (Router $router): void {
    $router->group('/api', [], function (Router $r) {
        // --- Authentication ---
        $r->get('/auth/csrf', [AuthController::class, 'csrf']);
        $r->post('/auth/login', [AuthController::class, 'login'], ['csrf']);
        $r->post('/auth/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);
        $r->get('/auth/me', [AuthController::class, 'me'], ['auth']);

        // Module routes are registered by their own route files.
        foreach (glob(dirname(__DIR__) . '/routes/api/*.php') ?: [] as $file) {
            (require $file)($r);
        }
    });
};
