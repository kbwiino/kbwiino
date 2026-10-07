<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\ResearchProject;
use App\Policies\Gate;
use App\Policies\ProjectAccess;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

abstract class BaseController
{
    protected function user(Request $r): array
    {
        return $r->user() ?? throw HttpException::unauthenticated();
    }

    protected function ok(mixed $data = null, array $meta = [], int $status = 200): Response
    {
        return Response::ok($data, $meta, $status);
    }

    protected function created(mixed $data = null): Response
    {
        return Response::ok($data, [], 201);
    }

    protected function validate(Request $r, array $rules): array
    {
        return Validator::make($r->body, $rules)->validate();
    }

    /**
     * Load a project by its opaque id and enforce an ability.
     * Users with no relation to the project get 404 (no existence leak); related users lacking the ability get 403.
     */
    protected function project(Request $r, string $ability = 'project.view', ?string $uuid = null): array
    {
        $user = $this->user($r);
        $project = ResearchProject::findByUuid($uuid ?? (string) $r->param('id'));
        if (!$project || !ProjectAccess::relations($user, $project)) {
            throw HttpException::notFound('Project');
        }
        Gate::authorize($user, $ability, $project);
        return $project;
    }

    protected function page(Request $r): int
    {
        return max(1, (int) $r->input('page', 1));
    }

    protected function perPage(Request $r, int $default = 20): int
    {
        return min(100, max(1, (int) $r->input('per_page', $default)));
    }

    /** Remove internal ids from a row before returning it to clients. */
    protected function publicRow(array $row, array $hide = []): array
    {
        foreach (array_merge(['id'], $hide) as $k) {
            unset($row[$k]);
        }
        return $row;
    }
}
