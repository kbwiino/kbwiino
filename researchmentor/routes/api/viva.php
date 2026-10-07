<?php
declare(strict_types=1);

use App\Controllers\VivaController;
use App\Support\Router;

return function (Router $r): void {
    $a = ['auth'];
    $w = ['auth', 'csrf'];
    $r->get('/viva/modes', [VivaController::class, 'modes'], $a);
    $r->post('/ai/viva-session', [VivaController::class, 'start'], $w);
    $r->get('/projects/{id}/viva', [VivaController::class, 'history'], $a);
    $r->get('/viva/sessions/{id}', [VivaController::class, 'show'], $a);
    $r->post('/viva/sessions/{id}/answer', [VivaController::class, 'answer'], $w);
    $r->post('/viva/sessions/{id}/end', [VivaController::class, 'end'], $w);
};
