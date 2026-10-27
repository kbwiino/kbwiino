<?php
declare(strict_types=1);

use App\Controllers\DocumentController;
use App\Support\Router;

return function (Router $r): void {
    $a = ['auth'];
    $w = ['auth', 'csrf'];
    $r->get('/projects/{id}/documents', [DocumentController::class, 'index'], $a);
    $r->post('/documents/upload', [DocumentController::class, 'upload'], $w);
    $r->get('/documents/{id}', [DocumentController::class, 'show'], $a);
    $r->delete('/documents/{id}', [DocumentController::class, 'destroy'], $w);
    $r->get('/documents/{id}/download', [DocumentController::class, 'download'], $a);
    $r->get('/documents/{id}/versions/{version}/download', [DocumentController::class, 'download'], $a);
    $r->get('/documents/{id}/preview', [DocumentController::class, 'preview'], $a);
    $r->get('/documents/{id}/compare', [DocumentController::class, 'compare'], $a);
    $r->post('/documents/{id}/extract', [DocumentController::class, 'reextract'], $w);
    $r->get('/documents/{id}/comments', [DocumentController::class, 'comments'], $a);
    $r->post('/documents/{id}/comments', [DocumentController::class, 'addComment'], $w);
    $r->post('/documents/{id}/comments/{cid}/resolve', [DocumentController::class, 'resolveComment'], $w);
    $r->post('/documents/{id}/access', [DocumentController::class, 'grant'], $w);
    $r->delete('/documents/{id}/access/{uid}', [DocumentController::class, 'revoke'], $w);
};
