<?php
declare(strict_types=1);

use App\Controllers\PageController;
use App\Support\Request;
use App\Support\Router;

/** Page routes. Each shell is thin; data & authorization live in the API. */
return function (Router $router): void {
    $router->get('/', [PageController::class, 'home']);
    $router->get('/login', [PageController::class, 'login']);
    $router->get('/privacy', [PageController::class, 'privacy']);
    $router->get('/dashboard', [PageController::class, 'dashboard'], ['auth']);

    $pages = [
        '/projects' => ['projects.index', 'Research projects'],
        '/projects/new' => ['projects.create', 'New research project'],
        '/projects/{id}' => ['projects.show', 'Project overview'],
        '/projects/{id}/constitution' => ['constitution.show', 'Research Constitution'],
        '/projects/{id}/constitution/compare' => ['constitution.compare', 'Constitution comparison'],
        '/projects/{id}/documents' => ['documents.index', 'Documents'],
        '/documents/{id}' => ['documents.show', 'Document'],
        '/projects/{id}/reviews' => ['reviews.index', 'AI reviews'],
        '/reviews/{id}' => ['reviews.show', 'AI review'],
        '/projects/{id}/alignment' => ['alignment.show', 'Alignment matrix'],
        '/projects/{id}/supervision' => ['supervision.show', 'Supervision'],
        '/projects/{id}/viva' => ['viva.show', 'Viva practice'],
        '/projects/{id}/literature' => ['literature.show', 'Literature'],
        '/knowledge' => ['knowledge.index', 'Institutional knowledge base'],
        '/admin/users' => ['admin.users', 'User administration'],
        '/admin/system' => ['admin.system', 'System settings'],
        '/admin/institution' => ['admin.institution', 'Institution settings'],
        '/notifications' => ['notifications.index', 'Notifications'],
    ];
    foreach ($pages as $path => [$page, $title]) {
        $router->get($path, [PageController::class, 'shellFor'], ['auth']);
    }
    // Route map consumed by PageController::shellFor
    \App\Support\Container::instance('page.map', new ArrayObject($pages));
};
