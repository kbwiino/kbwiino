<?php
declare(strict_types=1);

namespace App\Policies;

final class SupervisionPolicy
{
    public function view(array $u, array $p): bool
    {
        return ProjectAccess::hasContentAccess($u, $p);
    }

    public function createMeeting(array $u, array $p): bool
    {
        return $p['status'] !== 'archived' && ProjectAccess::hasContentAccess($u, $p);
    }

    /** Official outcome / decisions are recorded by supervisors only. */
    public function recordOutcome(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'supervisor');
    }

    public function createTask(array $u, array $p): bool
    {
        return $p['status'] !== 'archived' && ProjectAccess::hasContentAccess($u, $p);
    }

    public function assignTaskToOthers(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'supervisor');
    }

    public function updateTask(array $u, array $p, array $task): bool
    {
        if (ProjectAccess::has($u, $p, 'supervisor')) {
            return true;
        }
        return ProjectAccess::has($u, $p, 'student') && (int) $task['assigned_to'] === (int) $u['id'];
    }

    public function approve(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'supervisor');
    }
}
