<?php
declare(strict_types=1);

namespace App\Policies;

/** Practice vivas are private to the student who runs them. */
final class VivaPolicy
{
    public function start(array $u, array $p): bool
    {
        return ProjectAccess::has($u, $p, 'student');
    }

    public function view(array $u, array $session): bool
    {
        return (int) $session['student_id'] === (int) $u['id'];
    }
}
