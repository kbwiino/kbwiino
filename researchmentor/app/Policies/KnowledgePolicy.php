<?php
declare(strict_types=1);

namespace App\Policies;

use App\Support\Roles;

final class KnowledgePolicy
{
    /** Manage guidelines: Graduate School Administrators of the same institution (not sysadmins). */
    public function manage(array $u, ?int $institutionId = null): bool
    {
        return in_array(Roles::GRAD_ADMIN, $u['roles'], true)
            && ($institutionId === null || $institutionId === (int) $u['institution_id']);
    }

    public function view(array $u, array $doc): bool
    {
        if ((int) $doc['institution_id'] !== (int) $u['institution_id']) {
            return false; // never cross institution boundaries
        }
        if ($this->manage($u, (int) $doc['institution_id'])) {
            return true;
        }
        if ($doc['status'] !== 'approved') {
            return false;
        }
        return match ($doc['access_level']) {
            'all_users' => true,
            'staff_only' => (bool) array_intersect($u['roles'], [Roles::SUPERVISOR, Roles::COORDINATOR, Roles::GRAD_ADMIN]),
            'admins_only' => false,
            default => false,
        };
    }

    /** Access levels a user may retrieve from (used to filter retrieval queries). */
    public function allowedLevels(array $u): array
    {
        $levels = ['all_users'];
        if (array_intersect($u['roles'], [Roles::SUPERVISOR, Roles::COORDINATOR, Roles::GRAD_ADMIN])) {
            $levels[] = 'staff_only';
        }
        if (in_array(Roles::GRAD_ADMIN, $u['roles'], true)) {
            $levels[] = 'admins_only';
        }
        return $levels;
    }
}
