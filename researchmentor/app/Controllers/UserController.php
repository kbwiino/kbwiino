<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\{Department, Institution, Programme, Role, User};
use App\Services\{AuditLogger, AuthService};
use App\Support\{Database, HttpException, Request, Response, Roles};

final class UserController extends BaseController
{
    /** Directory of people in the caller's institution (limited fields), e.g. for choosing a supervisor. */
    public function directory(Request $r): Response
    {
        $u = $this->user($r);
        $role = (string) $r->input('role', Roles::SUPERVISOR);
        if (!in_array($role, Roles::all(), true)) {
            throw HttpException::badRequest('Unknown role.');
        }
        $isStaff = (bool) array_intersect($u['roles'], [Roles::SUPERVISOR, Roles::COORDINATOR, Roles::GRAD_ADMIN]);
        // Students may only look up supervisors; nobody can enumerate system administrators.
        if (!$isStaff && $role !== Roles::SUPERVISOR) {
            throw HttpException::forbidden();
        }
        if ($role === Roles::SYSADMIN) {
            throw HttpException::forbidden();
        }
        $params = [$u['institution_id'], $role];
        $sql = "SELECT u.uuid, u.first_name, u.last_name, u.email, d.name AS department FROM users u
                JOIN user_roles ur ON ur.user_id = u.id JOIN roles ro ON ro.id = ur.role_id
                LEFT JOIN departments d ON d.id = u.department_id
                WHERE u.institution_id = ? AND ro.slug = ? AND u.status = 'active' AND u.deleted_at IS NULL";
        if ($q = trim((string) $r->input('q', ''))) {
            $sql .= ' AND CONCAT(u.first_name, " ", u.last_name) LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $rows = Database::fetchAll($sql . ' ORDER BY u.last_name, u.first_name LIMIT 100', $params);
        return $this->ok(array_map(fn($x) => [
            'id' => $x['uuid'], 'name' => trim($x['first_name'] . ' ' . $x['last_name']), 'department' => $x['department'],
        ] + ($isStaff ? ['email' => $x['email']] : []), $rows));
    }

    private function requireAdmin(Request $r): array
    {
        $u = $this->user($r);
        if (!array_intersect($u['roles'], [Roles::GRAD_ADMIN, Roles::SYSADMIN])) {
            throw HttpException::forbidden();
        }
        return $u;
    }

    private function present(array $row): array
    {
        $full = AuthService::loadUser((int) $row['id']);
        return [
            'id' => $row['uuid'], 'name' => trim($row['first_name'] . ' ' . $row['last_name']), 'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
            'email' => $row['email'], 'status' => $row['status'], 'roles' => $full['roles'], 'last_login_at' => $row['last_login_at'],
            'department' => $row['department_id'] ? Department::find((int) $row['department_id'])['name'] : null,
        ];
    }

    private function scoped(array $admin, string $uuid): array
    {
        $t = User::findByUuid($uuid);
        // Only a system administrator may manage another system administrator (prevents takeover by lower-privileged admins).
        if ($t && !in_array(Roles::SYSADMIN, $admin['roles'], true) && in_array(Roles::SYSADMIN, AuthService::loadUser((int) $t['id'])['roles'] ?? [], true)) {
            throw HttpException::notFound('User');
        }
        // Graduate School admins are confined to their own institution.
        if (!$t || $t['deleted_at'] !== null || (!in_array(Roles::SYSADMIN, $admin['roles'], true) && (int) $t['institution_id'] !== (int) $admin['institution_id'])) {
            throw HttpException::notFound('User');
        }
        return $t;
    }

    public function adminIndex(Request $r): Response
    {
        $a = $this->requireAdmin($r);
        $where = 'u.deleted_at IS NULL';
        $params = [];
        if (!in_array(Roles::SYSADMIN, $a['roles'], true)) {
            $where .= ' AND u.institution_id = ?';
            $params[] = $a['institution_id'];
        }
        if ($q = trim((string) $r->input('q', ''))) {
            $where .= ' AND (u.email LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        $rows = Database::fetchAll("SELECT u.* FROM users u WHERE {$where} ORDER BY u.last_name, u.first_name LIMIT 200", $params);
        return $this->ok(array_map([$this, 'present'], $rows));
    }

    public function adminStore(Request $r): Response
    {
        $a = $this->requireAdmin($r);
        $data = $this->validate($r, [
            'email' => 'required|email', 'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100',
            'password' => 'required|string|max:255', 'roles' => 'required|array|min:1', 'department_id' => 'nullable|uuid', 'programme_id' => 'nullable|uuid',
            'institution_id' => 'nullable|uuid',
        ]);
        AuthService::validatePasswordStrength($data['password']);
        $roles = $this->checkRoles($a, $data['roles']);
        $instId = (int) $a['institution_id'];
        if (in_array(Roles::SYSADMIN, $a['roles'], true) && !empty($data['institution_id'])) {
            $inst = Institution::findByUuid($data['institution_id']) ?? throw HttpException::unprocessable('Unknown institution.', ['institution_id' => ['Unknown institution.']]);
            $instId = (int) $inst['id'];
        }
        $dept = !empty($data['department_id']) ? Department::findByUuid($data['department_id']) : null;
        $prog = !empty($data['programme_id']) ? Programme::findByUuid($data['programme_id']) : null;
        if (($dept && (int) $dept['institution_id'] !== $instId) || ($prog && (int) $prog['institution_id'] !== $instId)) {
            throw HttpException::unprocessable('Invalid department or programme.');
        }
        if (User::first(['email' => strtolower($data['email'])])) {
            throw HttpException::unprocessable('Email already registered.', ['email' => ['This email is already in use.']]);
        }
        $id = Database::transaction(function () use ($data, $roles, $instId, $dept, $prog) {
            $id = User::create([
                'institution_id' => $instId, 'department_id' => $dept['id'] ?? null, 'programme_id' => $prog['id'] ?? null,
                'email' => strtolower($data['email']), 'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
            ]);
            foreach ($roles as $slug) {
                Database::execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, Role::first(['slug' => $slug])['id']]);
            }
            return $id;
        });
        $row = User::find($id);
        AuditLogger::log($r, 'user.create', 'user', $row['uuid'], ['roles' => $roles]);
        return $this->created($this->present($row));
    }

    private function checkRoles(array $admin, array $roles): array
    {
        $roles = array_values(array_unique(array_map('strval', $roles)));
        foreach ($roles as $role) {
            if (!in_array($role, Roles::all(), true)) {
                throw HttpException::unprocessable('Unknown role.', ['roles' => ["Unknown role: {$role}"]]);
            }
            if ($role === Roles::SYSADMIN && !in_array(Roles::SYSADMIN, $admin['roles'], true)) {
                throw HttpException::forbidden('Only system administrators can grant that role.');
            }
        }
        return $roles;
    }

    public function adminUpdate(Request $r): Response
    {
        $a = $this->requireAdmin($r);
        $target = $this->scoped($a, (string) $r->param('id'));
        $data = $this->validate($r, array_intersect_key([
            'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100', 'status' => 'required|in:active,disabled',
            'roles' => 'required|array|min:1', 'password' => 'required|string|max:255',
        ], $r->body));
        $upd = array_intersect_key($data, array_flip(['first_name', 'last_name', 'status']));
        if (isset($data['roles']) && (int) $target['id'] === (int) $a['id']) {
            throw HttpException::forbidden('You cannot change your own roles. Ask another administrator.');
        }
        if (isset($data['status']) && $data['status'] === 'disabled' && (int) $target['id'] === (int) $a['id']) {
            throw HttpException::unprocessable('You cannot disable your own account.');
        }
        if (isset($data['password'])) {
            AuthService::validatePasswordStrength($data['password']);
            $upd['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            $upd['failed_logins'] = 0;
            $upd['locked_until'] = null;
        }
        Database::transaction(function () use ($data, $target, $a, $upd) {
            if ($upd) {
                User::updateById((int) $target['id'], $upd);
            }
            if (isset($data['roles'])) {
                $roles = $this->checkRoles($a, $data['roles']);
                Database::execute('DELETE FROM user_roles WHERE user_id = ?', [$target['id']]);
                foreach ($roles as $slug) {
                    Database::execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$target['id'], Role::first(['slug' => $slug])['id']]);
                }
            }
        });
        \App\Policies\ProjectAccess::flush();
        AuditLogger::log($r, 'user.update', 'user', $target['uuid'], ['fields' => array_keys($data), 'password_reset' => isset($data['password'])]);
        return $this->ok($this->present(User::find((int) $target['id'])));
    }
}
