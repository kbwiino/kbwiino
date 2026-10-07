<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{Document, DocumentVersion, Institution};
use App\Policies\{DocumentPolicy, Gate, ProjectAccess};
use App\Services\Extraction\TextExtractor;
use App\Support\{Database, FileStorage, HttpException, Logger, Request, TextDiff, UploadedFile, Uuid};

final class DocumentService
{
    public const CATEGORIES = ['proposal', 'chapter_1', 'chapter_2', 'chapter_3', 'chapter_4', 'chapter_5', 'complete_thesis', 'dissertation', 'journal_article', 'dataset', 'supervisor_feedback', 'institutional_guideline', 'other'];

    /** Upload a new document, or a new version of $existing. */
    public static function upload(Request $r, array $actor, array $project, array $meta, UploadedFile $file, ?array $existing = null): array
    {
        $category = $existing['category'] ?? ($meta['category'] ?? 'other');
        if ($existing) {
            if (!(new DocumentPolicy($project))->newVersion($actor, $existing)) {
                throw HttpException::forbidden();
            }
        } elseif (!(new DocumentPolicy($project))->upload($actor, $project, $category)) {
            throw HttpException::forbidden();
        }
        $allowed = $category === 'dataset' ? null : null; // all configured types for every category
        $info = UploadValidator::validate($file, $allowed);
        if ($category === 'dataset' && !in_array($info['extension'], ['csv', 'xlsx', 'txt', 'pdf'], true)) {
            throw HttpException::unprocessable('Datasets must be CSV, XLSX, TXT or PDF statistical output.', ['file' => ['Unsupported dataset format.']]);
        }
        $stored = FileStorage::storeUpload($file);
        try {
            $result = Database::transaction(function () use ($actor, $project, $meta, $existing, $category, $info, $stored) {
                $retentionDays = (int) (Institution::find((int) $project['institution_id'])['retention_days'] ?? 1825);
                if ($existing) {
                    $docId = (int) $existing['id'];
                    // Lock to serialise concurrent version numbering.
                    Database::fetchValue('SELECT id FROM documents WHERE id = ? FOR UPDATE', [$docId]);
                } else {
                    $docId = Document::create([
                        'project_id' => $project['id'], 'owner_user_id' => $actor['id'], 'category' => $category,
                        'title' => $meta['title'] ?? $info['display_name'], 'description' => $meta['description'] ?? null,
                        'visibility' => $meta['visibility'] ?? 'shared',
                        'retention_until' => gmdate('Y-m-d', strtotime("+{$retentionDays} days")),
                    ]);
                }
                $next = (int) Database::fetchValue('SELECT COALESCE(MAX(version_number),0)+1 FROM document_versions WHERE document_id = ?', [$docId]);
                $vid = DocumentVersion::create([
                    'document_id' => $docId, 'version_number' => $next, 'original_filename' => $info['display_name'],
                    'stored_filename' => $stored, 'extension' => $info['extension'], 'mime_type' => $info['mime'],
                    'size_bytes' => $info['size'], 'sha256' => $info['sha256'], 'change_note' => $meta['change_note'] ?? null,
                    'uploaded_by' => $actor['id'],
                    'extraction_status' => 'pending',
                ]);
                Document::updateById($docId, ['current_version_id' => $vid]);
                return [$docId, $vid];
            });
        } catch (\Throwable $e) {
            FileStorage::delete($stored);
            throw $e;
        }
        [$docId, $vid] = $result;
        $doc = Document::find($docId);
        $ver = DocumentVersion::find($vid);
        AuditLogger::log($r, $existing ? 'document.new_version' : 'document.upload', 'document', $doc['uuid'], ['project' => $project['uuid'], 'version' => (int) $ver['version_number'], 'category' => $category, 'size' => $info['size'], 'ext' => $info['extension']]);
        QueueDispatcher::dispatch('extract_document', ['version_id' => $vid]);

        // Notify the other side of the supervision relationship.
        foreach (Database::fetchAll("SELECT user_id FROM research_project_members WHERE project_id = ? AND ended_at IS NULL AND member_role IN ('student','supervisor','co_supervisor')", [$project['id']]) as $m) {
            if ((int) $m['user_id'] !== (int) $actor['id'] && $doc['visibility'] === 'shared') {
                NotificationService::notify((int) $m['user_id'], 'document_uploaded', 'New document: ' . $doc['title'], 'Version ' . $ver['version_number'] . ' uploaded.', '/documents/' . $doc['uuid']);
            }
        }
        return ['document' => Document::find($docId), 'version' => DocumentVersion::find($vid)];
    }

    /** Run text extraction for a stored version and persist segments with page/section info. */
    public static function extractVersion(int $versionId): void
    {
        $v = DocumentVersion::find($versionId);
        if (!$v || $v['file_deleted_at'] !== null) {
            return;
        }
        DocumentVersion::updateById($versionId, ['extraction_status' => 'processing', 'extraction_error' => null]);
        Database::execute('DELETE FROM document_extractions WHERE version_id = ?', [$versionId]);
        try {
            $path = FileStorage::path($v['stored_filename']);
            $out = TextExtractor::extract($path, $v['extension']);
            $seq = 0;
            $words = 0;
            Database::transaction(function () use ($out, $versionId, &$seq, &$words) {
                foreach ($out['segments'] as $s) {
                    $seq++;
                    $words += str_word_count($s->content);
                    Database::insert('document_extractions', [
                        'version_id' => $versionId, 'seq' => $seq, 'page_number' => $s->page,
                        'section_label' => $s->section !== null ? mb_substr($s->section, 0, 250) : null,
                        'content' => $s->content, 'char_count' => mb_strlen($s->content),
                    ]);
                }
            });
            DocumentVersion::updateById($versionId, ['extraction_status' => 'completed', 'word_count' => $words, 'page_count' => $out['pages']]);
        } catch (\Throwable $e) {
            Logger::warning("Extraction failed for version {$versionId}: " . $e->getMessage());
            DocumentVersion::updateById($versionId, ['extraction_status' => 'failed', 'extraction_error' => mb_substr($e->getMessage(), 0, 480)]);
        }
    }

    /** @return array<int,array{seq:int,page:?int,section:?string,content:string}> */
    public static function segments(int $versionId): array
    {
        $rows = Database::fetchAll('SELECT seq, page_number, section_label, content FROM document_extractions WHERE version_id = ? ORDER BY seq', [$versionId]);
        return array_map(fn($r) => ['seq' => (int) $r['seq'], 'page' => $r['page_number'] !== null ? (int) $r['page_number'] : null, 'section' => $r['section_label'], 'content' => $r['content']], $rows);
    }

    public static function fullText(int $versionId): string
    {
        return implode("\n\n", array_column(self::segments($versionId), 'content'));
    }

    public static function currentVersion(array $doc): ?array
    {
        return $doc['current_version_id'] ? DocumentVersion::find((int) $doc['current_version_id']) : null;
    }

    public static function version(array $doc, int $n): ?array
    {
        return Database::fetchOne('SELECT * FROM document_versions WHERE document_id = ? AND version_number = ?', [$doc['id'], $n]);
    }

    public static function presentVersion(array $v): array
    {
        $uploader = \App\Models\User::find((int) $v['uploaded_by']);
        return [
            'id' => $v['uuid'], 'version' => (int) $v['version_number'], 'filename' => $v['original_filename'], 'type' => $v['extension'],
            'size_bytes' => (int) $v['size_bytes'], 'sha256' => $v['sha256'], 'change_note' => $v['change_note'],
            'extraction_status' => $v['extraction_status'], 'extraction_error' => $v['extraction_error'],
            'word_count' => $v['word_count'] !== null ? (int) $v['word_count'] : null, 'page_count' => $v['page_count'] !== null ? (int) $v['page_count'] : null,
            'uploaded_by' => $uploader ? ['uuid' => $uploader['uuid'], 'name' => trim($uploader['first_name'] . ' ' . $uploader['last_name'])] : null,
            'uploaded_at' => $v['created_at'], 'file_available' => $v['file_deleted_at'] === null,
        ];
    }

    public static function present(array $doc, bool $withVersions = false): array
    {
        $cur = self::currentVersion($doc);
        $project = \App\Models\ResearchProject::find((int) $doc['project_id']);
        $owner = \App\Models\User::find((int) $doc['owner_user_id']);
        $latestReview = Database::fetchOne('SELECT uuid, review_type, human_status, created_at FROM ai_reviews WHERE document_id = ? ORDER BY id DESC LIMIT 1', [$doc['id']]);
        $out = [
            'id' => $doc['uuid'], 'project_id' => $project['uuid'], 'title' => $doc['title'], 'category' => $doc['category'], 'description' => $doc['description'],
            'visibility' => $doc['visibility'], 'owner' => ['uuid' => $owner['uuid'], 'name' => trim($owner['first_name'] . ' ' . $owner['last_name'])],
            'current_version' => $cur ? self::presentVersion($cur) : null,
            'ai_review' => $latestReview ? ['id' => $latestReview['uuid'], 'type' => $latestReview['review_type'], 'human_status' => $latestReview['human_status'], 'at' => $latestReview['created_at']] : null,
            'ai_review_status' => $latestReview ? ($latestReview['human_status'] === 'unreviewed' ? 'ai_reviewed_pending_human' : 'human_reviewed') : 'not_reviewed',
            'retention_until' => $doc['retention_until'], 'created_at' => $doc['created_at'], 'updated_at' => $doc['updated_at'],
        ];
        if ($withVersions) {
            $out['versions'] = array_map([self::class, 'presentVersion'], DocumentVersion::where(['document_id' => $doc['id']], 'version_number DESC'));
        }
        return $out;
    }

    public static function delete(Request $r, array $actor, array $doc): void
    {
        Gate::authorize($actor, 'document.delete', $doc);
        Document::updateById((int) $doc['id'], ['deleted_at' => gmdate('Y-m-d H:i:s'), 'deleted_by' => $actor['id']]);
        $project = \App\Models\ResearchProject::find((int) $doc['project_id']);
        AuditLogger::log($r, 'document.delete', 'document', $doc['uuid'], ['project' => $project['uuid'], 'retention_until' => $doc['retention_until']]);
    }

    /** Remove stored files + extracted text for deleted documents whose retention period has elapsed. */
    public static function purgeExpired(): int
    {
        $n = 0;
        $rows = Database::fetchAll(
            'SELECT v.id, v.stored_filename FROM document_versions v JOIN documents d ON d.id = v.document_id
             WHERE d.deleted_at IS NOT NULL AND d.retention_until IS NOT NULL AND d.retention_until < UTC_DATE() AND v.file_deleted_at IS NULL LIMIT 500'
        );
        foreach ($rows as $v) {
            FileStorage::delete($v['stored_filename']);
            Database::execute('DELETE FROM document_extractions WHERE version_id = ?', [$v['id']]);
            Database::execute('UPDATE document_versions SET file_deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$v['id']]);
            $n++;
        }
        return $n;
    }

    public static function compare(array $doc, array $a, array $b): array
    {
        $ta = mb_substr(self::fullText((int) $a['id']), 0, 200000);
        $tb = mb_substr(self::fullText((int) $b['id']), 0, 200000);
        if ($ta === '' || $tb === '') {
            throw HttpException::unprocessable('Text is not available for one of the versions (extraction incomplete).');
        }
        $ops = TextDiff::lines($ta, $tb);
        return [
            'from' => (int) $a['version_number'], 'to' => (int) $b['version_number'],
            'stats' => TextDiff::stats($ops) + ['word_count_from' => (int) $a['word_count'], 'word_count_to' => (int) $b['word_count']],
            'diff' => $ops,
        ];
    }

    public static function grantAccess(Request $r, array $actor, array $doc, string $userUuid, string $permission, ?string $expires): void
    {
        Gate::authorize($actor, 'document.manageAccess', $doc);
        $user = \App\Models\User::findByUuid($userUuid);
        $project = \App\Models\ResearchProject::find((int) $doc['project_id']);
        if (!$user || (int) $user['institution_id'] !== (int) $project['institution_id']) {
            throw HttpException::unprocessable('Unknown user.', ['user_id' => ['Unknown user.']]);
        }
        Database::execute(
            'INSERT INTO document_access_permissions (document_id, user_id, permission, granted_by, expires_at) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at), granted_by = VALUES(granted_by)',
            [$doc['id'], $user['id'], $permission, $actor['id'], $expires ? gmdate('Y-m-d H:i:s', strtotime($expires)) : null]
        );
        AuditLogger::log($r, 'document.grant_access', 'document', $doc['uuid'], ['project' => $project['uuid'], 'grantee' => $user['uuid'], 'permission' => $permission]);
    }
}
