<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\{Institution, User};
use App\Services\{AuditLogger, AuthService, RateLimiter};
use App\Support\{Database, HttpException, Request, Response};

/** Self-service account actions: change password and export my data (data-subject access). */
final class AccountController extends BaseController
{
    public function changePassword(Request $r): Response
    {
        $u = $this->user($r);
        $d = $this->validate($r, ['current_password' => 'required|string|max:255', 'new_password' => 'required|string|max:255']);
        RateLimiter::enforce('pwchange:' . $u['id'], 5, 900, 'Too many attempts. Try again later.');
        $row = User::find((int) $u['id']);
        if (!password_verify($d['current_password'], $row['password_hash'])) {
            AuditLogger::log($r, 'auth.password_change_failed', 'user', $u['uuid']);
            throw new HttpException(403, 'wrong_password', 'The current password is incorrect.');
        }
        AuthService::validatePasswordStrength($d['new_password']);
        if (hash_equals($d['current_password'], $d['new_password'])) {
            throw HttpException::unprocessable('Choose a different password.', ['new_password' => ['Must differ from the current password.']]);
        }
        User::updateById((int) $u['id'], ['password_hash' => password_hash($d['new_password'], PASSWORD_DEFAULT)]);
        $r->session->regenerate(); // new session id after a credential change
        AuditLogger::log($r, 'auth.password_changed', 'user', $u['uuid']);
        return $this->ok(['changed' => true, 'csrf_token' => $r->session->csrfToken()]);
    }

    /** Everything the platform holds about the signed-in person (metadata and own records; not other people's data). */
    public function myData(Request $r): Response
    {
        $u = $this->user($r);
        $inst = Institution::find((int) $u['institution_id']);
        $projects = Database::fetchAll("SELECT p.uuid, p.title, p.stage, p.status, p.created_at, m.member_role FROM research_project_members m JOIN research_projects p ON p.id = m.project_id WHERE m.user_id = ?", [$u['id']]);
        $docs = Database::fetchAll('SELECT d.uuid, d.title, d.category, d.created_at FROM documents d WHERE d.owner_user_id = ? AND d.deleted_at IS NULL', [$u['id']]);
        $audit = Database::fetchAll('SELECT action, entity_type, created_at, ip_address FROM audit_logs WHERE user_id = ? ORDER BY id DESC LIMIT 500', [$u['id']]);
        $ai = Database::fetchAll('SELECT purpose, provider, model, input_tokens, output_tokens, status, created_at FROM ai_usage_logs WHERE user_id = ? ORDER BY id DESC LIMIT 500', [$u['id']]);
        AuditLogger::log($r, 'account.data_export', 'user', $u['uuid']);
        return Response::json(['success' => true, 'data' => [
            'generated_at' => gmdate('c'),
            'controller' => $inst['name'],
            'profile' => ['name' => $u['name'], 'email' => $u['email'], 'roles' => $u['roles'], 'uuid' => $u['uuid']],
            'projects' => $projects, 'documents' => $docs, 'activity_log' => $audit, 'ai_usage' => $ai,
            'note' => 'Document contents are available through the document download feature. To request correction or erasure contact your institution\'s data protection officer.',
        ]], 200, ['Content-Disposition' => 'attachment; filename="my-data.json"']);
    }
}
