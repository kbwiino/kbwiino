<?php
declare(strict_types=1);

namespace App\Policies;

use App\Support\Database;
use App\Support\Roles;

/**
 * Resolves how a user relates to a project. Relations are derived from data
 * (membership rows, institution, department) - never from request input.
 *
 *  student     - owner or student member
 *  supervisor  - supervisor / co-supervisor member
 *  examiner    - explicitly assigned examiner
 *  coordinator - Research Coordinator within institution (and department, if the coordinator has one)
 *  grad_admin  - Graduate School Administrator within institution (metadata only)
 *
 * System administrators receive NO implicit relation: they see research content only if
 * they are explicitly a project member.
 */
final class ProjectAccess
{
    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return string[] */
    public static function relations(array $user, array $project): array
    {
        $key = $user['id'] . ':' . $project['id'];
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $rel = [];
        // Tenant isolation: every non-member relation requires the same institution.
        $sameInstitution = (int) $project['institution_id'] === (int) $user['institution_id'];

        $members = Database::fetchAll(
            'SELECT member_role FROM research_project_members WHERE project_id = ? AND user_id = ? AND ended_at IS NULL',
            [$project['id'], $user['id']]
        );
        foreach ($members as $m) {
            $rel[] = match ($m['member_role']) {
                'student' => 'student',
                'supervisor', 'co_supervisor' => 'supervisor',
                'examiner' => 'examiner',
            };
        }
        if (in_array(Roles::STUDENT, $user['roles'], true) && (int) $project['owner_user_id'] === (int) $user['id']) {
            $rel[] = 'student';
        }
        // Role-based, institution scoped relations (members of the matching role only).
        if ($sameInstitution) {
            if (in_array(Roles::COORDINATOR, $user['roles'], true)
                && ($user['department_id'] === null || (int) $user['department_id'] === (int) $project['department_id'])) {
                $rel[] = 'coordinator';
            }
            if (in_array(Roles::GRAD_ADMIN, $user['roles'], true)) {
                $rel[] = 'grad_admin';
            }
        }
        // Members must hold the matching global role to exercise the relation.
        $rel = array_values(array_unique(array_filter($rel, fn($r) => self::roleBacksRelation($user, $r))));
        return self::$cache[$key] = $rel;
    }

    private static function roleBacksRelation(array $user, string $relation): bool
    {
        return match ($relation) {
            'student' => in_array(Roles::STUDENT, $user['roles'], true),
            'supervisor' => in_array(Roles::SUPERVISOR, $user['roles'], true),
            'examiner' => in_array(Roles::EXAMINER, $user['roles'], true),
            default => true,
        };
    }

    public static function has(array $user, array $project, string ...$relations): bool
    {
        return (bool) array_intersect($relations, self::relations($user, $project));
    }

    /** Access to private research content (documents, reviews, constitution, supervision notes). */
    public static function hasContentAccess(array $user, array $project): bool
    {
        return self::has($user, $project, 'student', 'supervisor');
    }

    /** Aggregate projects visible to a user (id list) - used by list endpoints. */
    public static function visibleProjectIds(array $user): array
    {
        $ids = [];
        $rows = Database::fetchAll(
            "SELECT DISTINCT p.id FROM research_projects p
             LEFT JOIN research_project_members m ON m.project_id = p.id AND m.user_id = ? AND m.ended_at IS NULL
             WHERE m.id IS NOT NULL OR p.owner_user_id = ?",
            [$user['id'], $user['id']]
        );
        foreach ($rows as $r) {
            $ids[(int) $r['id']] = true;
        }
        if (array_intersect([Roles::COORDINATOR, Roles::GRAD_ADMIN], $user['roles'])) {
            $sql = 'SELECT id, department_id FROM research_projects WHERE institution_id = ?';
            foreach (Database::fetchAll($sql, [$user['institution_id']]) as $r) {
                $coordOk = in_array(Roles::COORDINATOR, $user['roles'], true)
                    && ($user['department_id'] === null || (int) $user['department_id'] === (int) $r['department_id']);
                if ($coordOk || in_array(Roles::GRAD_ADMIN, $user['roles'], true)) {
                    $ids[(int) $r['id']] = true;
                }
            }
        }
        return array_keys($ids);
    }
}
