<?php
declare(strict_types=1);

use App\Controllers\ReportController;
use App\Support\Router;

return function (Router $r): void {
    $a = ['auth'];
    $r->get('/reports/review/{id}', [ReportController::class, 'review'], $a);
    $r->get('/reports/meeting/{id}', [ReportController::class, 'meeting'], $a);
    $r->get('/reports/progress/{id}', [ReportController::class, 'progress'], $a);
    $r->get('/reports/viva/{id}', [ReportController::class, 'viva'], $a);
};
