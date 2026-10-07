<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Support\Router;

return function (Router $router): void {
    $router->group('/api', ['throttle:600'], function (Router $r) {
        // --- Authentication ---
        $r->get('/auth/csrf', [AuthController::class, 'csrf']);
        $r->post('/auth/login', [AuthController::class, 'login'], ['csrf']);
        $r->post('/auth/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);
        $r->get('/auth/me', [AuthController::class, 'me'], ['auth']);
        $r->post('/auth/change-password', [\App\Controllers\AccountController::class, 'changePassword'], ['auth', 'csrf']);
        $r->get('/account/my-data', [\App\Controllers\AccountController::class, 'myData'], ['auth']);
        $r->post('/admin/faculties', [\App\Controllers\StructureController::class, 'faculty'], ['auth', 'csrf']);
        $r->post('/admin/departments', [\App\Controllers\StructureController::class, 'department'], ['auth', 'csrf']);
        $r->post('/admin/programmes', [\App\Controllers\StructureController::class, 'programme'], ['auth', 'csrf']);
        $r->post('/admin/academic-years', [\App\Controllers\StructureController::class, 'academicYear'], ['auth', 'csrf']);
        $r->get('/admin/institution', [\App\Controllers\InstitutionController::class, 'show'], ['auth']);
        $r->put('/admin/institution', [\App\Controllers\InstitutionController::class, 'update'], ['auth', 'csrf']);

        // Module routes are registered by their own route files.
        foreach (glob(dirname(__DIR__) . '/routes/api/*.php') ?: [] as $file) {
            (require $file)($r);
        }
    });
};
