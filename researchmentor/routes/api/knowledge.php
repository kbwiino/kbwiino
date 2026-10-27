<?php
declare(strict_types=1);

use App\Controllers\KnowledgeController;
use App\Support\Router;

return function (Router $r): void {
    $a = ['auth'];
    $w = ['auth', 'csrf'];
    $r->get('/knowledge/documents', [KnowledgeController::class, 'index'], $a);
    $r->post('/knowledge/documents', [KnowledgeController::class, 'upload'], $w);
    $r->get('/knowledge/documents/{id}', [KnowledgeController::class, 'show'], $a);
    $r->post('/knowledge/documents/{id}/approve', [KnowledgeController::class, 'approve'], $w);
    $r->post('/knowledge/documents/{id}/archive', [KnowledgeController::class, 'archive'], $w);
    $r->get('/knowledge/documents/{id}/download', [KnowledgeController::class, 'download'], $a);
    $r->get('/knowledge/search', [KnowledgeController::class, 'search'], $a);
    $r->post('/knowledge/ask', [KnowledgeController::class, 'ask'], $w);
};
