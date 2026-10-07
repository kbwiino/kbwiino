<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Institution;
use App\Services\AuditLogger;
use App\Support\{HttpException, Request, Response, Roles};

/** Institution-level configuration owned by the Graduate School: data retention, privacy notice, AI switch. */
final class InstitutionController extends BaseController
{
    private function admin(Request $r): array
    {
        $u = $this->user($r);
        if (!in_array(Roles::GRAD_ADMIN, $u['roles'], true)) {
            throw HttpException::forbidden();
        }
        return $u;
    }

    private function present(array $i): array
    {
        return ['name' => $i['name'], 'code' => $i['code'], 'retention_days' => (int) $i['retention_days'], 'ai_enabled' => (bool) $i['ai_enabled'], 'privacy_notice' => $i['privacy_notice']];
    }

    public function show(Request $r): Response
    {
        return $this->ok($this->present(Institution::findOrFail((int) $this->admin($r)['institution_id'])));
    }

    public function update(Request $r): Response
    {
        $u = $this->admin($r);
        $d = $this->validate($r, array_intersect_key([
            'retention_days' => 'required|int|min:30|max:7300', 'ai_enabled' => 'required|bool', 'privacy_notice' => 'nullable|string|max:20000',
        ], $r->body));
        $i = Institution::findOrFail((int) $u['institution_id']);
        if (array_key_exists('ai_enabled', $d)) {
            $d['ai_enabled'] = $d['ai_enabled'] ? 1 : 0;
        }
        Institution::updateById((int) $i['id'], $d);
        AuditLogger::log($r, 'institution.settings_update', 'institution', $i['uuid'], ['fields' => array_keys($d)]);
        return $this->ok($this->present(Institution::find((int) $i['id'])) + ['note' => 'A new retention period applies to documents uploaded from now on; existing documents keep the retention date set at upload.']);
    }
}
