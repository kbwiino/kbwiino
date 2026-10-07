<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\KnowledgeDocument;
use App\Policies\{Gate, KnowledgePolicy};
use App\Services\{AuditLogger, KnowledgeService};
use App\Support\{Database, FileStorage, HttpException, Request, Response, Validator};

final class KnowledgeController extends BaseController
{
    private function doc(Request $r): array
    {
        $user = $this->user($r);
        $d = KnowledgeDocument::findByUuid((string) $r->param('id'));
        // Other institutions' documents do not exist as far as this user is concerned.
        if (!$d || !(new KnowledgePolicy())->view($user, $d)) {
            throw HttpException::notFound('Document');
        }
        return $d;
    }

    public function index(Request $r): Response
    {
        $user = $this->user($r);
        $admin = Gate::allows($user, 'knowledge.manage', (int) $user['institution_id']);
        $where = 'institution_id = ?';
        $params = [$user['institution_id']];
        if ($admin) {
            if ($s = $r->input('status')) {
                $where .= ' AND status = ?';
                $params[] = $s;
            } else {
                $where .= " AND status <> 'archived'";
            }
        } else {
            $levels = (new KnowledgePolicy())->allowedLevels($user);
            $where .= " AND status = 'approved' AND access_level IN (" . Database::placeholders($levels) . ')';
            array_push($params, ...$levels);
        }
        if ($c = $r->input('category')) {
            $where .= ' AND category = ?';
            $params[] = $c;
        }
        $rows = Database::fetchAll("SELECT * FROM knowledge_documents WHERE $where ORDER BY category, title, id DESC", $params);
        return $this->ok(array_map(fn($d) => KnowledgeService::present($d, $admin), $rows), ['can_manage' => $admin, 'categories' => KnowledgeService::CATEGORIES]);
    }

    public function show(Request $r): Response
    {
        $d = $this->doc($r);
        return $this->ok(KnowledgeService::present($d, Gate::allows($this->user($r), 'knowledge.manage', (int) $d['institution_id'])));
    }

    public function upload(Request $r): Response
    {
        $user = $this->user($r);
        Gate::authorize($user, 'knowledge.manage', (int) $user['institution_id']);
        if ((int) ($r->server['CONTENT_LENGTH'] ?? 0) > 0 && !$r->body && !$r->files) {
            throw new HttpException(413, 'file_too_large', 'The upload exceeds the maximum request size.');
        }
        $data = Validator::make($r->body, [
            'title' => 'nullable|string|max:300', 'category' => 'nullable|in:' . implode(',', KnowledgeService::CATEGORIES),
            'access_level' => 'nullable|in:all_users,staff_only,admins_only', 'version_label' => 'nullable|string|max:40', 'supersedes_id' => 'nullable|uuid',
        ])->validate();
        $file = $r->file('file') ?? throw HttpException::unprocessable('No file supplied.', ['file' => ['Choose a file.']]);
        $sup = null;
        if (!empty($data['supersedes_id'])) {
            $sup = KnowledgeDocument::findByUuid($data['supersedes_id']);
            if (!$sup || (int) $sup['institution_id'] !== (int) $user['institution_id']) {
                throw HttpException::unprocessable('Unknown document to supersede.', ['supersedes_id' => ['Unknown document.']]);
            }
        }
        $doc = KnowledgeService::upload($r, $user, $data, $file, $sup);
        return $this->created(KnowledgeService::present($doc, true));
    }

    public function approve(Request $r): Response
    {
        $d = $this->doc($r);
        return $this->ok(KnowledgeService::present(KnowledgeService::approve($r, $this->user($r), $d), true));
    }

    public function archive(Request $r): Response
    {
        $d = $this->doc($r);
        return $this->ok(KnowledgeService::present(KnowledgeService::archive($r, $this->user($r), $d), true));
    }

    public function download(Request $r): Response
    {
        $d = $this->doc($r);
        $path = FileStorage::path($d['stored_filename'], 'kb');
        if (!is_file($path)) {
            throw HttpException::notFound('File');
        }
        AuditLogger::log($r, 'knowledge.download', 'knowledge_document', $d['uuid']);
        return Response::download($path, $d['original_filename'], $d['mime_type']);
    }

    public function search(Request $r): Response
    {
        $user = $this->user($r);
        $q = trim((string) $r->input('q', ''));
        if (mb_strlen($q) < 3) {
            throw HttpException::unprocessable('Enter at least 3 characters.', ['q' => ['Too short.']]);
        }
        $hits = KnowledgeService::search($user, mb_substr($q, 0, 300), 'search', $r->input('category') ?: null, 10);
        return $this->ok(array_map([KnowledgeService::class, 'presentHit'], $hits), ['count' => count($hits)]);
    }

    public function ask(Request $r): Response
    {
        $user = $this->user($r);
        $data = $this->validate($r, ['question' => 'required|string|min:5|max:1000']);
        return $this->ok(KnowledgeService::ask($r, $user, $data['question']));
    }
}
