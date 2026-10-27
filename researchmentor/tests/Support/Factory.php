<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Models\{AcademicYear, Department, Faculty, Institution, Programme, ProjectMember, ResearchProject, Role, StageHistory, User};
use App\Support\Database;

/** Test data builders (real rows, real foreign keys). */
final class Factory
{
    private static int $seq = 0;
    public const PASSWORD = 'Correct-Horse-9';

    public static function n(): int { return ++self::$seq; }

    /** @return array{institution:array,faculty:array,department:array,programme:array,year:array} */
    public static function institution(string $name = null): array
    {
        $n = self::n();
        $iid = Institution::create(['name' => $name ?? "Test University $n", 'code' => "TU$n", 'privacy_notice' => 'Test privacy notice']);
        $fid = Faculty::create(['institution_id' => $iid, 'name' => 'Faculty of Science', 'code' => 'FOS']);
        $did = Department::create(['institution_id' => $iid, 'faculty_id' => $fid, 'name' => 'Computer Science', 'code' => 'CS']);
        $pid = Programme::create(['institution_id' => $iid, 'department_id' => $did, 'name' => 'MSc Computer Science', 'code' => 'MSCCS', 'degree_level' => 'masters']);
        $yid = AcademicYear::create(['institution_id' => $iid, 'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'is_current' => 1]);
        return [
            'institution' => Institution::find($iid), 'faculty' => Faculty::find($fid), 'department' => Department::find($did),
            'programme' => Programme::find($pid), 'year' => AcademicYear::find($yid),
        ];
    }

    public static function user(array $org, string|array $roles = 'student', array $overrides = []): array
    {
        $n = self::n();
        $id = User::create($overrides + [
            'institution_id' => $org['institution']['id'],
            'department_id' => $org['department']['id'],
            'programme_id' => $org['programme']['id'],
            'email' => "user{$n}@example.test",
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT, ['cost' => 4]),
            'first_name' => 'First' . $n,
            'last_name' => 'Last' . $n,
        ]);
        foreach ((array) $roles as $slug) {
            $rid = Role::first(['slug' => $slug])['id'];
            Database::execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $rid]);
        }
        return \App\Services\AuthService::loadUser($id);
    }

    public static function project(array $org, array $student, ?array $supervisor = null, array $overrides = []): array
    {
        $id = ResearchProject::create($overrides + [
            'institution_id' => $org['institution']['id'],
            'faculty_id' => $org['faculty']['id'],
            'department_id' => $org['department']['id'],
            'programme_id' => $org['programme']['id'],
            'academic_year_id' => $org['year']['id'],
            'owner_user_id' => $student['id'],
            'degree_level' => 'masters',
            'title' => 'Effects of mobile money on rural savings in Uganda',
            'research_area' => 'Financial inclusion',
            'problem_statement' => 'Rural households have limited access to formal savings products.',
            'purpose' => 'To examine the relationship between mobile money use and household savings.',
            'approach' => 'quantitative',
            'design' => 'Cross-sectional survey',
            'stage' => 'proposal_development',
            'status' => 'active',
            'ai_consent_at' => gmdate('Y-m-d H:i:s'),
            'ai_consent_by' => $student['id'],
            'created_by' => $student['id'],
        ]);
        ProjectMember::create(['project_id' => $id, 'user_id' => $student['id'], 'member_role' => 'student']);
        if ($supervisor) {
            ProjectMember::create(['project_id' => $id, 'user_id' => $supervisor['id'], 'member_role' => 'supervisor']);
        }
        StageHistory::create(['project_id' => $id, 'to_stage' => $overrides['stage'] ?? 'proposal_development', 'changed_by' => $student['id']]);
        \App\Policies\ProjectAccess::flush();
        return ResearchProject::find($id);
    }
}
