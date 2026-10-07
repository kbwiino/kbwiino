<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\{AcademicYear, Department, Faculty, Programme};
use App\Services\AuditLogger;
use App\Support\{Database, HttpException, Request, Response, Roles};

/** Institutional structure (faculties, departments, programmes, academic years) managed by the Graduate School. */
final class StructureController extends BaseController
{
    private function admin(Request $r): array
    {
        $u = $this->user($r);
        if (!in_array(Roles::GRAD_ADMIN, $u['roles'], true)) {
            throw HttpException::forbidden();
        }
        return $u;
    }

    private function uniqueCode(string $table, int $institutionId, string $code, string $field): void
    {
        if (Database::fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE institution_id = ? AND code = ?", [$institutionId, $code])) {
            throw HttpException::unprocessable('Duplicate code.', [$field => ['This code is already in use.']]);
        }
    }

    public function faculty(Request $r): Response
    {
        $u = $this->admin($r);
        $d = $this->validate($r, ['name' => 'required|string|max:255', 'code' => 'required|string|max:32|regex:/^[A-Za-z0-9_\-]+$/']);
        $code = strtoupper($d['code']);
        $this->uniqueCode('faculties', (int) $u['institution_id'], $code, 'code');
        $f = Faculty::find(Faculty::create(['institution_id' => $u['institution_id'], 'name' => $d['name'], 'code' => $code]));
        AuditLogger::log($r, 'structure.faculty_create', 'faculty', $f['uuid']);
        return $this->created(['id' => $f['uuid'], 'name' => $f['name'], 'code' => $f['code']]);
    }

    public function department(Request $r): Response
    {
        $u = $this->admin($r);
        $d = $this->validate($r, ['faculty_id' => 'required|uuid', 'name' => 'required|string|max:255', 'code' => 'required|string|max:32|regex:/^[A-Za-z0-9_\-]+$/']);
        $fac = Faculty::findByUuid($d['faculty_id']);
        if (!$fac || (int) $fac['institution_id'] !== (int) $u['institution_id']) {
            throw HttpException::unprocessable('Unknown faculty.', ['faculty_id' => ['Unknown faculty.']]);
        }
        $code = strtoupper($d['code']);
        $this->uniqueCode('departments', (int) $u['institution_id'], $code, 'code');
        $x = Department::find(Department::create(['institution_id' => $u['institution_id'], 'faculty_id' => $fac['id'], 'name' => $d['name'], 'code' => $code]));
        AuditLogger::log($r, 'structure.department_create', 'department', $x['uuid']);
        return $this->created(['id' => $x['uuid'], 'name' => $x['name'], 'code' => $x['code']]);
    }

    public function programme(Request $r): Response
    {
        $u = $this->admin($r);
        $d = $this->validate($r, ['department_id' => 'required|uuid', 'name' => 'required|string|max:255', 'code' => 'required|string|max:32|regex:/^[A-Za-z0-9_\-]+$/', 'degree_level' => 'required|in:masters,phd']);
        $dep = Department::findByUuid($d['department_id']);
        if (!$dep || (int) $dep['institution_id'] !== (int) $u['institution_id']) {
            throw HttpException::unprocessable('Unknown department.', ['department_id' => ['Unknown department.']]);
        }
        $code = strtoupper($d['code']);
        $this->uniqueCode('programmes', (int) $u['institution_id'], $code, 'code');
        $x = Programme::find(Programme::create(['institution_id' => $u['institution_id'], 'department_id' => $dep['id'], 'name' => $d['name'], 'code' => $code, 'degree_level' => $d['degree_level']]));
        AuditLogger::log($r, 'structure.programme_create', 'programme', $x['uuid']);
        return $this->created(['id' => $x['uuid'], 'name' => $x['name'], 'code' => $x['code'], 'degree_level' => $x['degree_level']]);
    }

    public function academicYear(Request $r): Response
    {
        $u = $this->admin($r);
        $d = $this->validate($r, ['label' => 'required|string|max:32', 'starts_on' => 'required|date', 'ends_on' => 'required|date', 'is_current' => 'nullable|bool']);
        if (strtotime($d['ends_on']) <= strtotime($d['starts_on'])) {
            throw HttpException::unprocessable('Invalid dates.', ['ends_on' => ['Must be after the start date.']]);
        }
        if (Database::fetchValue('SELECT COUNT(*) FROM academic_years WHERE institution_id = ? AND label = ?', [$u['institution_id'], $d['label']])) {
            throw HttpException::unprocessable('Duplicate label.', ['label' => ['This academic year already exists.']]);
        }
        $current = !empty($d['is_current']);
        $y = Database::transaction(function () use ($u, $d, $current) {
            if ($current) {
                Database::execute('UPDATE academic_years SET is_current = 0 WHERE institution_id = ?', [$u['institution_id']]);
            }
            return AcademicYear::find(AcademicYear::create(['institution_id' => $u['institution_id'], 'label' => $d['label'], 'starts_on' => substr($d['starts_on'], 0, 10), 'ends_on' => substr($d['ends_on'], 0, 10), 'is_current' => $current ? 1 : 0]));
        });
        AuditLogger::log($r, 'structure.year_create', 'academic_year', $y['uuid']);
        return $this->created(['id' => $y['uuid'], 'label' => $y['label'], 'is_current' => (bool) $y['is_current']]);
    }
}
