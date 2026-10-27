<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\{Database, Request, Response};

final class LookupController extends BaseController
{
    /** Institutional structure used by forms (faculty → department → programme, academic years). */
    public function structure(Request $r): Response
    {
        $iid = $this->user($r)['institution_id'];
        $faculties = Database::fetchAll('SELECT id, uuid, name FROM faculties WHERE institution_id = ? ORDER BY name', [$iid]);
        $departments = Database::fetchAll('SELECT id, uuid, name, faculty_id FROM departments WHERE institution_id = ? ORDER BY name', [$iid]);
        $programmes = Database::fetchAll('SELECT uuid, name, department_id, degree_level FROM programmes WHERE institution_id = ? ORDER BY name', [$iid]);
        $out = [];
        foreach ($faculties as $f) {
            $deps = [];
            foreach ($departments as $d) {
                if ((int) $d['faculty_id'] !== (int) $f['id']) continue;
                $progs = [];
                foreach ($programmes as $p) {
                    if ((int) $p['department_id'] === (int) $d['id']) {
                        $progs[] = ['id' => $p['uuid'], 'name' => $p['name'], 'degree_level' => $p['degree_level']];
                    }
                }
                $deps[] = ['id' => $d['uuid'], 'name' => $d['name'], 'programmes' => $progs];
            }
            $out[] = ['id' => $f['uuid'], 'name' => $f['name'], 'departments' => $deps];
        }
        $years = Database::fetchAll('SELECT uuid, label, is_current FROM academic_years WHERE institution_id = ? ORDER BY starts_on DESC', [$iid]);
        return $this->ok([
            'faculties' => $out,
            'academic_years' => array_map(fn($y) => ['id' => $y['uuid'], 'label' => $y['label'], 'is_current' => (bool) $y['is_current']], $years),
            'stages' => \App\Services\ProjectService::STAGES,
        ]);
    }
}
