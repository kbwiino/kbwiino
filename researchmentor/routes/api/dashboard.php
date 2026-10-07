<?php
declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Support\Router;

return function (Router $r): void {
    $r->get('/dashboard', [DashboardController::class, 'home'], ['auth']);
    $r->get('/dashboard/student', [DashboardController::class, 'student'], ['auth']);
    $r->get('/dashboard/supervisor', [DashboardController::class, 'supervisor'], ['auth']);
    $r->get('/dashboard/administrator', [DashboardController::class, 'administrator'], ['auth']);
    $r->get('/admin/health', [DashboardController::class, 'health'], ['auth']);
};
