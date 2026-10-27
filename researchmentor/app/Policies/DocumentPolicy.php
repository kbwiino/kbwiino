<?php
declare(strict_types=1);

namespace App\Policies;

use App\Support\Database;

final class DocumentPolicy
{
    public function __construct(private ?array $project = null)
    {
    }

    private function project(array $doc): array
    {
        if ($this->project && (int) $this->project['id'] === (int) $doc['project_id']) {
            return $this->project;
        }
        return \App\Models\ResearchProject::findOrFail((int) $doc['project_id']);
    }

    /** Explicit, unexpired per-document grant. Any grant implies view; download/comment need their own. */
    private function explicitGrant(array $u, array $doc, string $permission): bool
    {
        $accepted = $permission === 'view' ? ['view', 'download', 'comment'] : [$permission];
        $row = Database::fetchOne(
            'SELECT id FROM document_access_permissions WHERE document_id = ? AND user_id = ? AND permission IN (' . Database::placeholders($accepted) . ')
             AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())',
            array_merge([$doc['id'], $u['id']], $accepted)
        );
        return $row !== null;
    }

    public function view(array $u, array $doc): bool
    {
        if ($doc['deleted_at'] !== null) {
            return false;
        }
        $p = $this->project($doc);
        $rel = ProjectAccess::relations($u, $p);
        if (in_array('student', $rel, true) && (int) $doc['owner_user_id'] === (int) $u['id']) {
            return true;
        }
        if (in_array('student', $rel, true)) {
            return true; // student members see project documents
        }
        if (in_array('supervisor', $rel, true)) {
            return $doc['visibility'] === 'shared' || (int) $doc['owner_user_id'] === (int) $u['id'];
        }
        // examiners, coordinators and others: explicit per-document grant only
        return $rel !== [] && $this->explicitGrant($u, $doc, 'view');
    }

    public function download(array $u, array $doc): bool
    {
        if (!$this->view($u, $doc)) {
            return false;
        }
        $rel = ProjectAccess::relations($u, $this->project($doc));
        if (array_intersect($rel, ['student', 'supervisor'])) {
            return true;
        }
        return $this->explicitGrant($u, $doc, 'download');
    }

    public function upload(array $u, array $p, string $category = 'other'): bool
    {
        if ($p['status'] === 'archived') {
            return false;
        }
        if (ProjectAccess::has($u, $p, 'student')) {
            return true;
        }
        return ProjectAccess::has($u, $p, 'supervisor') && in_array($category, ['supervisor_feedback', 'institutional_guideline', 'other'], true);
    }

    public function newVersion(array $u, array $doc): bool
    {
        return $doc['deleted_at'] === null && (int) $doc['owner_user_id'] === (int) $u['id'] && $this->view($u, $doc);
    }

    public function delete(array $u, array $doc): bool
    {
        return $doc['deleted_at'] === null && (int) $doc['owner_user_id'] === (int) $u['id'] && $this->view($u, $doc);
    }

    public function comment(array $u, array $doc): bool
    {
        if (!$this->view($u, $doc)) {
            return false;
        }
        $rel = ProjectAccess::relations($u, $this->project($doc));
        return (bool) array_intersect($rel, ['student', 'supervisor']) || $this->explicitGrant($u, $doc, 'comment');
    }

    /** Sharing / granting access is limited to the owner or a supervisor of the project. */
    public function manageAccess(array $u, array $doc): bool
    {
        $rel = ProjectAccess::relations($u, $this->project($doc));
        return in_array('supervisor', $rel, true) || (in_array('student', $rel, true) && (int) $doc['owner_user_id'] === (int) $u['id']);
    }

    public function runReview(array $u, array $doc): bool
    {
        return $this->view($u, $doc) && ProjectAccess::has($u, $this->project($doc), 'student', 'supervisor');
    }
}
