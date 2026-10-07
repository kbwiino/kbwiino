<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\{Document, DocumentComment, ResearchProject, User};
use App\Policies\{DocumentPolicy, Gate, ProjectAccess};
use App\Services\{AuditLogger, DocumentService, QueueDispatcher};
use App\Support\{Database, FileStorage, HttpException, Request, Response, Validator};

final class DocumentController extends BaseController
{
    private function doc(Request $r, string $ability): array
    {
        $user = $this->user($r);
        $doc = Document::findByUuid((string) $r->param('id'));
        $project = $doc ? ResearchProject::find((int) $doc['project_id']) : null;
        // Existence is only revealed to people who have some relation to the project.
        if (!$doc || !$project || !ProjectAccess::relations($user, $project) || $doc['deleted_at'] !== null) {
            throw HttpException::notFound('Document');
        }
        $policy = new DocumentPolicy($project);
        if (!$policy->view($user, $doc)) {
            // Related to the project but not entitled to this document: do not confirm it exists.
            throw HttpException::notFound('Document');
        }
        if ($ability !== 'view' && !$policy->$ability($user, $doc)) {
            throw HttpException::forbidden();
        }
        return $doc;
    }

    private function projectOf(array $doc): array
    {
        return ResearchProject::findOrFail((int) $doc['project_id']);
    }

    public function index(Request $r): Response
    {
        $project = $this->project($r);
        $user = $this->user($r);
        $policy = new DocumentPolicy($project);
        $rows = Document::where(['project_id' => $project['id'], 'deleted_at' => null], 'updated_at DESC');
        $out = [];
        foreach ($rows as $d) {
            if ($policy->view($user, $d)) {
                $out[] = DocumentService::present($d);
            }
        }
        if ($cat = $r->input('category')) {
            $out = array_values(array_filter($out, fn($d) => $d['category'] === $cat));
        }
        return $this->ok($out);
    }

    public function upload(Request $r): Response
    {
        $user = $this->user($r);
        $data = Validator::make($r->body, [
            'project_id' => 'nullable|uuid', 'document_id' => 'nullable|uuid',
            'category' => 'nullable|in:' . implode(',', DocumentService::CATEGORIES),
            'title' => 'nullable|string|max:300', 'description' => 'nullable|string|max:5000',
            'visibility' => 'nullable|in:private,shared', 'change_note' => 'nullable|string|max:500',
        ])->validate();
        $file = $r->file('file') ?? throw HttpException::unprocessable('No file supplied.', ['file' => ['Choose a file to upload.']]);
        $existing = null;
        if (!empty($data['document_id'])) {
            $existing = Document::findByUuid($data['document_id']);
            if (!$existing || $existing['deleted_at'] !== null) {
                throw HttpException::notFound('Document');
            }
            $project = ResearchProject::findOrFail((int) $existing['project_id']);
        } else {
            $project = ResearchProject::findByUuid((string) ($data['project_id'] ?? ''));
        }
        if (!$project || !ProjectAccess::relations($user, $project)) {
            throw HttpException::notFound('Project');
        }
        if ($existing && !(new DocumentPolicy($project))->view($user, $existing)) {
            throw HttpException::notFound('Document');
        }
        $res = DocumentService::upload($r, $user, $project, $data, $file, $existing);
        return $this->created(DocumentService::present($res['document'], true));
    }

    public function show(Request $r): Response
    {
        $doc = $this->doc($r, 'view');
        return $this->ok(DocumentService::present($doc, true) + ['permissions' => [
            'download' => (new DocumentPolicy($this->projectOf($doc)))->download($this->user($r), $doc),
            'new_version' => (new DocumentPolicy($this->projectOf($doc)))->newVersion($this->user($r), $doc),
            'delete' => (new DocumentPolicy($this->projectOf($doc)))->delete($this->user($r), $doc),
            'comment' => (new DocumentPolicy($this->projectOf($doc)))->comment($this->user($r), $doc),
            'run_review' => (new DocumentPolicy($this->projectOf($doc)))->runReview($this->user($r), $doc),
            'manage_access' => (new DocumentPolicy($this->projectOf($doc)))->manageAccess($this->user($r), $doc),
        ]]);
    }

    public function download(Request $r): Response
    {
        $doc = $this->doc($r, 'download');
        $n = (int) ($r->param('version') ?? 0);
        $ver = $n ? DocumentService::version($doc, $n) : DocumentService::currentVersion($doc);
        if (!$ver || $ver['file_deleted_at'] !== null) {
            throw HttpException::notFound('File');
        }
        $path = FileStorage::path($ver['stored_filename']);
        if (!is_file($path)) {
            throw HttpException::notFound('File');
        }
        AuditLogger::log($r, 'document.download', 'document', $doc['uuid'], ['project' => $this->projectOf($doc)['uuid'], 'version' => (int) $ver['version_number']]);
        // Inline preview is only offered for PDFs; everything else downloads as an attachment.
        $inline = (bool) $r->input('inline') && $ver['extension'] === 'pdf';
        return Response::download($path, $ver['original_filename'], $ver['mime_type'], $inline);
    }

    /** Text preview built from extracted segments (page/section aware). */
    public function preview(Request $r): Response
    {
        $doc = $this->doc($r, 'view');
        $n = (int) ($r->input('version') ?: 0);
        $ver = $n ? DocumentService::version($doc, $n) : DocumentService::currentVersion($doc);
        if (!$ver) {
            throw HttpException::notFound('Version');
        }
        $segments = $ver['extraction_status'] === 'completed' ? DocumentService::segments((int) $ver['id']) : [];
        $per = 20;
        $page = $this->page($r);
        return $this->ok([
            'version' => (int) $ver['version_number'], 'extraction_status' => $ver['extraction_status'], 'extraction_error' => $ver['extraction_error'],
            'segments' => array_slice($segments, ($page - 1) * $per, $per),
            'inline_pdf_available' => $ver['extension'] === 'pdf' && (new DocumentPolicy($this->projectOf($doc)))->download($this->user($r), $doc),
        ], ['total_segments' => count($segments), 'page' => $page, 'per_page' => $per]);
    }

    public function compare(Request $r): Response
    {
        $doc = $this->doc($r, 'view');
        $a = DocumentService::version($doc, (int) $r->input('from', 1)) ?? throw HttpException::notFound('Version');
        $b = DocumentService::version($doc, (int) $r->input('to', 2)) ?? throw HttpException::notFound('Version');
        return $this->ok(DocumentService::compare($doc, $a, $b));
    }

    public function reextract(Request $r): Response
    {
        $doc = $this->doc($r, 'newVersion');
        $ver = DocumentService::currentVersion($doc) ?? throw HttpException::notFound('Version');
        QueueDispatcher::dispatch('extract_document', ['version_id' => $ver['id']]);
        return $this->ok(['queued' => true]);
    }

    public function destroy(Request $r): Response
    {
        $doc = $this->doc($r, 'delete');
        DocumentService::delete($r, $this->user($r), $doc);
        return $this->ok(['deleted' => true, 'note' => 'The document is hidden now; stored files are purged when the retention period ends.']);
    }

    public function comments(Request $r): Response
    {
        $doc = $this->doc($r, 'view');
        $rows = Database::fetchAll('SELECT c.*, u.first_name, u.last_name FROM document_comments c JOIN users u ON u.id = c.author_id WHERE c.document_id = ? ORDER BY c.id', [$doc['id']]);
        return $this->ok(array_map(fn($c) => [
            'id' => $c['uuid'], 'origin' => $c['origin'], 'author' => trim($c['first_name'] . ' ' . $c['last_name']), 'body' => $c['body'],
            'page' => $c['page_number'], 'location' => $c['location'], 'status' => $c['status'], 'created_at' => $c['created_at'],
            'parent_id' => $c['parent_id'] ? (DocumentComment::find((int) $c['parent_id'])['uuid'] ?? null) : null,
        ], $rows));
    }

    public function addComment(Request $r): Response
    {
        $doc = $this->doc($r, 'comment');
        $user = $this->user($r);
        $data = $this->validate($r, ['body' => 'required|string|max:5000', 'page' => 'nullable|int|min:1', 'location' => 'nullable|string|max:255', 'parent_id' => 'nullable|uuid']);
        $project = $this->projectOf($doc);
        $rel = ProjectAccess::relations($user, $project);
        $origin = in_array('supervisor', $rel, true) ? 'supervisor' : (in_array('student', $rel, true) ? 'student' : (in_array('examiner', $rel, true) ? 'examiner' : 'coordinator'));
        $parent = null;
        if (!empty($data['parent_id'])) {
            $parent = DocumentComment::findByUuid($data['parent_id']);
            if (!$parent || (int) $parent['document_id'] !== (int) $doc['id']) {
                throw HttpException::unprocessable('Invalid parent comment.');
            }
        }
        $id = DocumentComment::create([
            'document_id' => $doc['id'], 'version_id' => $doc['current_version_id'], 'parent_id' => $parent['id'] ?? null, 'author_id' => $user['id'],
            'origin' => $origin, 'page_number' => $data['page'] ?? null, 'location' => $data['location'] ?? null, 'body' => $data['body'],
        ]);
        $c = DocumentComment::find($id);
        AuditLogger::log($r, 'document.comment', 'document', $doc['uuid'], ['project' => $project['uuid']]);
        if ((int) $doc['owner_user_id'] !== (int) $user['id']) {
            \App\Services\NotificationService::notify((int) $doc['owner_user_id'], 'document_comment', 'New comment on ' . $doc['title'], mb_substr($data['body'], 0, 200), '/documents/' . $doc['uuid']);
        }
        return $this->created(['id' => $c['uuid'], 'origin' => $origin, 'body' => $c['body']]);
    }

    public function resolveComment(Request $r): Response
    {
        $doc = $this->doc($r, 'comment');
        $c = DocumentComment::findByUuid((string) $r->param('cid'));
        if (!$c || (int) $c['document_id'] !== (int) $doc['id']) {
            throw HttpException::notFound('Comment');
        }
        DocumentComment::updateById((int) $c['id'], ['status' => 'resolved', 'resolved_by' => $this->user($r)['id'], 'resolved_at' => gmdate('Y-m-d H:i:s')]);
        return $this->ok(['resolved' => true]);
    }

    public function grant(Request $r): Response
    {
        $doc = $this->doc($r, 'manageAccess');
        $data = $this->validate($r, ['user_id' => 'required|uuid', 'permission' => 'required|in:view,download,comment', 'expires_at' => 'nullable|datetime']);
        DocumentService::grantAccess($r, $this->user($r), $doc, $data['user_id'], $data['permission'], $data['expires_at'] ?? null);
        return $this->ok(['granted' => true]);
    }

    public function revoke(Request $r): Response
    {
        $doc = $this->doc($r, 'manageAccess');
        $target = User::findByUuid((string) $r->param('uid'));
        if ($target) {
            Database::execute('DELETE FROM document_access_permissions WHERE document_id = ? AND user_id = ?', [$doc['id'], $target['id']]);
            AuditLogger::log($r, 'document.revoke_access', 'document', $doc['uuid'], ['project' => $this->projectOf($doc)['uuid'], 'grantee' => $target['uuid']]);
        }
        return $this->ok(['revoked' => true]);
    }
}
