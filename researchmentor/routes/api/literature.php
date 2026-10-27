<?php
declare(strict_types=1);

use App\Controllers\LiteratureController;
use App\Support\Router;

return function (Router $r): void {
    $a = ['auth'];
    $w = ['auth', 'csrf'];
    $r->get('/projects/{id}/literature', [LiteratureController::class, 'index'], $a);
    $r->post('/projects/{id}/literature', [LiteratureController::class, 'store'], $w);
    $r->get('/projects/{id}/literature/evidence', [LiteratureController::class, 'evidence'], $a);
    $r->post('/projects/{id}/literature/evidence', [LiteratureController::class, 'addEvidence'], $w);
    $r->delete('/projects/{id}/literature/{sid}', [LiteratureController::class, 'destroy'], $w);
    $r->post('/projects/{id}/literature/{sid}/verify', [LiteratureController::class, 'verify'], $w);
};
