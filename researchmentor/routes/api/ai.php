<?php
declare(strict_types=1);

use App\Controllers\ReviewController;
use App\Support\Router;

return function (Router $r): void {
    $a = ['auth'];
    $w = ['auth', 'csrf'];
    $r->get('/ai/status', [ReviewController::class, 'status'], $a);
    foreach (['proposal-review', 'literature-review', 'methodology-review', 'alignment-review', 'gap-analysis', 'writing-review', 'integrity-review', 'statistics-review', 'constitution-check'] as $k) {
        $r->post("/ai/$k", [ReviewController::class, 'request'], $w);
    }
    $r->post('/documents/{id}/review', [ReviewController::class, 'documentReview'], $w);
    $r->get('/review-jobs/{id}', [ReviewController::class, 'job'], $a);
    $r->get('/projects/{id}/reviews', [ReviewController::class, 'index'], $a);
    $r->get('/reviews/{id}', [ReviewController::class, 'show'], $a);
    $r->get('/reviews/{id}/issues', [ReviewController::class, 'issues'], $a);
    $r->post('/reviews/{id}/decision', [ReviewController::class, 'decide'], $w);
    $r->put('/reviews/{id}/issues/{iid}', [ReviewController::class, 'updateIssue'], $w);

    $r->get('/projects/{id}/summary', [ReviewController::class, 'projectSummary'], $a);
    $r->get('/projects/{id}/progress', [ReviewController::class, 'progress'], $a);
    $r->get('/projects/{id}/alignment', [ReviewController::class, 'alignment'], $a);
    $r->post('/projects/{id}/alignment/{mid}/rows', [ReviewController::class, 'addAlignmentRow'], $w);
    $r->put('/projects/{id}/alignment/{mid}/rows/{rid}', [ReviewController::class, 'updateAlignmentRow'], $w);
    $r->delete('/projects/{id}/alignment/{mid}/rows/{rid}', [ReviewController::class, 'deleteAlignmentRow'], $w);
    $r->post('/projects/{id}/alignment/{mid}/approve', [ReviewController::class, 'approveAlignment'], $w);
};
