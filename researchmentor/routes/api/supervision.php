<?php
declare(strict_types=1);

use App\Controllers\SupervisionController;
use App\Support\Router;

return function (Router $r): void {
    $a = ['auth'];
    $w = ['auth', 'csrf'];
    $r->get('/projects/{id}/supervision', [SupervisionController::class, 'overview'], $a);
    $r->post('/projects/{id}/supervision/comments', [SupervisionController::class, 'addComment'], $w);
    $r->post('/projects/{id}/supervision/comments/{cid}/resolve', [SupervisionController::class, 'resolveComment'], $w);
    $r->post('/projects/{id}/approvals', [SupervisionController::class, 'approveDocument'], $w);

    $r->post('/supervision/meetings', [SupervisionController::class, 'createMeeting'], $w);
    $r->get('/supervision/meetings/{id}', [SupervisionController::class, 'showMeeting'], $a);
    $r->put('/supervision/meetings/{id}', [SupervisionController::class, 'updateMeeting'], $w);
    $r->post('/supervision/meetings/{id}/brief', [SupervisionController::class, 'brief'], $w);
    $r->post('/supervision/meetings/{id}/outcome', [SupervisionController::class, 'outcome'], $w);
    $r->post('/supervision/meetings/{id}/agenda/{aid}/decision', [SupervisionController::class, 'agendaDecision'], $w);

    $r->get('/supervision/tasks', [SupervisionController::class, 'myTasks'], $a);
    $r->post('/supervision/tasks', [SupervisionController::class, 'createTask'], $w);
    $r->get('/supervision/tasks/{id}', [SupervisionController::class, 'showTask'], $a);
    $r->put('/supervision/tasks/{id}', [SupervisionController::class, 'updateTask'], $w);
};
