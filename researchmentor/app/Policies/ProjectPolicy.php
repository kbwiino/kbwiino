<?php
declare(strict_types=1);

namespace App\Policies;

use App\Support\Roles;

final class ProjectPolicy
{
    /** Any relation grants at least summary-level visibility. */
    public function view(array $u, array $p): bool
    {
        return ProjectAccess::relations($u, $p) !== [];
    }

    public function viewContent(array $u, array $p): bool
    {
        return ProjectAccess::hasContentAccess($u, $p);
    }

    /** Progress monitoring data (stage, milestones, counts) - includes oversight roles. */
    public function viewProgress(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'student', 'supervisor', 'coordinator', 'grad_admin');
    }

    public function update(array $u, array $p): bool
    {
        return $p['status'] !== 'archived' && ProjectAccess::has($u, $p, 'student', 'supervisor');
    }

    public function archive(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'supervisor', 'grad_admin');
    }

    public function assignSupervisor(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'coordinator', 'grad_admin');
    }

    public function assignExaminer(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'grad_admin', 'coordinator');
    }

    /** Official stage changes are made by staff, not by the AI or by students. */
    public function changeStage(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'supervisor', 'coordinator', 'grad_admin');
    }

    public function giveAiConsent(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'student') && (int) $p['owner_user_id'] === (int) $u['id'];
    }

    public function runAi(array $u, array $p): bool
    {
        return $p['status'] !== 'archived' && ProjectAccess::has($u, $p, 'student', 'supervisor');
    }

    public function manageMilestones(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'student', 'supervisor');
    }

    public function proposeConstitutionChange(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'student', 'supervisor');
    }

    /** Only a supervisor can make an official (approved) change to the Research Constitution. */
    public function approveConstitution(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'supervisor');
    }

    public function export(array $u, array $p): bool
    {
        return ProjectAccess::hasContentAccess($u, $p);
    }

    public function create(array $u): bool
    {
        return (bool) array_intersect($u['roles'], [Roles::STUDENT, Roles::GRAD_ADMIN, Roles::COORDINATOR]);
    }
}
