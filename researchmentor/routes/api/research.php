<?php
declare(strict_types=1);

use App\Controllers\{ComponentController, ConstitutionController, LookupController, MilestoneController, NotificationController, ProjectController, UserController};
use App\Support\Router;

return function (Router $r): void {
    $auth = ['auth'];
    $w = ['auth', 'csrf'];

    $r->get('/lookups/structure', [LookupController::class, 'structure'], $auth);
    $r->get('/users', [UserController::class, 'directory'], $auth);
    $r->get('/admin/users', [UserController::class, 'adminIndex'], $auth);
    $r->post('/admin/users', [UserController::class, 'adminStore'], $w);
    $r->put('/admin/users/{id}', [UserController::class, 'adminUpdate'], $w);

    $r->get('/notifications', [NotificationController::class, 'index'], $auth);
    $r->post('/notifications/read-all', [NotificationController::class, 'readAll'], $w);
    $r->post('/notifications/{id}/read', [NotificationController::class, 'read'], $w);

    $r->get('/projects', [ProjectController::class, 'index'], $auth);
    $r->post('/projects', [ProjectController::class, 'store'], $w);
    $r->get('/projects/{id}', [ProjectController::class, 'show'], $auth);
    $r->put('/projects/{id}', [ProjectController::class, 'update'], $w);
    $r->get('/projects/{id}/overview', [ProjectController::class, 'overview'], $auth);
    $r->post('/projects/{id}/archive', [ProjectController::class, 'archive'], $w);
    $r->post('/projects/{id}/members', [ProjectController::class, 'assign'], $w);
    $r->post('/projects/{id}/members/remove', [ProjectController::class, 'unassign'], $w);
    $r->post('/projects/{id}/stage', [ProjectController::class, 'stage'], $w);
    $r->get('/projects/{id}/stage-history', [ProjectController::class, 'stageHistory'], $auth);
    $r->post('/projects/{id}/ai-consent', [ProjectController::class, 'aiConsent'], $w);
    $r->get('/projects/{id}/timeline', [ProjectController::class, 'timeline'], $auth);
    $r->get('/projects/{id}/history', [ProjectController::class, 'history'], $auth);
    $r->get('/projects/{id}/export', [ProjectController::class, 'export'], $auth);

    foreach (['objectives', 'questions', 'hypotheses', 'variables', 'theories'] as $c) {
        $r->get("/projects/{id}/$c", [ComponentController::class, 'index'], $auth);
        $r->post("/projects/{id}/$c", [ComponentController::class, 'store'], $w);
        $r->put("/projects/{id}/$c/{item}", [ComponentController::class, 'update'], $w);
        $r->delete("/projects/{id}/$c/{item}", [ComponentController::class, 'destroy'], $w);
    }
    $r->get('/projects/{id}/conceptual-framework', [ComponentController::class, 'framework'], $auth);
    $r->put('/projects/{id}/conceptual-framework', [ComponentController::class, 'saveFramework'], $w);

    $r->get('/projects/{id}/milestones', [MilestoneController::class, 'index'], $auth);
    $r->post('/projects/{id}/milestones', [MilestoneController::class, 'store'], $w);
    $r->put('/projects/{id}/milestones/{mid}', [MilestoneController::class, 'update'], $w);
    $r->post('/projects/{id}/milestones/{mid}/complete', [MilestoneController::class, 'complete'], $w);
    $r->delete('/projects/{id}/milestones/{mid}', [MilestoneController::class, 'destroy'], $w);

    $r->get('/projects/{id}/constitution', [ConstitutionController::class, 'show'], $auth);
    $r->post('/projects/{id}/constitution/initialize', [ConstitutionController::class, 'initialize'], $w);
    $r->get('/projects/{id}/constitution/compare', [ConstitutionController::class, 'compare'], $auth);
    $r->get('/projects/{id}/constitution/inconsistencies', [ConstitutionController::class, 'inconsistencies'], $auth);
    $r->get('/projects/{id}/constitution/versions/{version}', [ConstitutionController::class, 'version'], $auth);
    $r->get('/projects/{id}/constitution/proposals', [ConstitutionController::class, 'proposals'], $auth);
    $r->post('/projects/{id}/constitution/proposals', [ConstitutionController::class, 'propose'], $w);
    $r->post('/projects/{id}/constitution/proposals/{pid}/decision', [ConstitutionController::class, 'decide'], $w);
    $r->post('/projects/{id}/constitution/proposals/{pid}/withdraw', [ConstitutionController::class, 'withdraw'], $w);
};
