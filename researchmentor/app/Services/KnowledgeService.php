<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{Institution, KnowledgeBase, KnowledgeDocument, KnowledgeRetrievalLog};
use App\Policies\{Gate, KnowledgePolicy};
use App\Services\AI\{AIException, AIFactory, PromptManager, Schemas};
use App\Services\Extraction\{SectionDetector, TextExtractor};
use App\Services\Retrieval\{MysqlRetriever, RetrieverInterface};
use App\Support\{Config, Container, Database, FileStorage, HttpException, Logger, Request, UploadedFile};

final class KnowledgeService
{
    public const CATEGORIES = ['graduate_guideline', 'research_manual', 'proposal_template', 'thesis_template', 'dissertation_template', 'ethics_policy', 'examination_guideline', 'assessment_rubric', 'referencing_guideline', 'institutional_form', 'other'];
    public const NO_ANSWER = 'The institution\'s guidelines available in this system do not answer this question. Please consult your supervisor or the Graduate School.';

    public static function retriever(): RetrieverInterface
    {
        return Container::has('retriever') ? Container::get('retriever') : new MysqlRetriever();
    }

    public static function defaultBase(int $institutionId, ?int $userId = null): array
    {
        $kb = KnowledgeBase::first(['institution_id' => $institutionId, 'status' => 'active']);
        if (!$kb) {
            $id = KnowledgeBase::create(['institution_id' => $institutionId, 'name' => 'Institutional guidelines', 'description' => 'Approved institutional research guidelines, templates and policies.', 'created_by' => $userId]);
            $kb = KnowledgeBase::find($id);
        }
        return $kb;
    }

    public static function upload(Request $r, array $actor, array $meta, UploadedFile $file, ?array $supersedes = null): array
    {
        Gate::authorize($actor, 'knowledge.manage', (int) $actor['institution_id']);
        $info = UploadValidator::validate($file, ['pdf', 'docx', 'txt']);
        $kb = self::defaultBase((int) $actor['institution_id'], (int) $actor['id']);
        $stored = FileStorage::storeUpload($file, 'kb');
        $version = $meta['version_label'] ?? ($supersedes ? self::nextVersion($supersedes['version_label']) : '1.0');
        $id = KnowledgeDocument::create([
            'knowledge_base_id' => $kb['id'], 'institution_id' => $actor['institution_id'], 'title' => $meta['title'] ?? $info['display_name'],
            'category' => $meta['category'] ?? ($supersedes['category'] ?? 'other'), 'version_label' => $version, 'supersedes_id' => $supersedes['id'] ?? null,
            'status' => 'draft', 'access_level' => $meta['access_level'] ?? ($supersedes['access_level'] ?? 'all_users'),
            'original_filename' => $info['display_name'], 'stored_filename' => $stored, 'extension' => $info['extension'], 'mime_type' => $info['mime'],
            'size_bytes' => $info['size'], 'sha256' => $info['sha256'], 'uploaded_by' => $actor['id'], 'metadata' => $meta['metadata'] ?? null,
        ]);
        $doc = KnowledgeDocument::find($id);
        AuditLogger::log($r, 'knowledge.upload', 'knowledge_document', $doc['uuid'], ['category' => $doc['category'], 'version' => $version]);
        QueueDispatcher::dispatch('index_knowledge_document', ['document_id' => $id]);
        return KnowledgeDocument::find($id);
    }

    private static function nextVersion(string $v): string
    {
        return preg_match('/^(\d+)\.(\d+)$/', $v, $m) ? $m[1] . '.' . ((int) $m[2] + 1) : $v . '.1';
    }

    /** Extract, chunk (with page/heading) and store chunks. Idempotent. */
    public static function indexDocument(int $docId): void
    {
        $doc = KnowledgeDocument::find($docId);
        if (!$doc) {
            return;
        }
        KnowledgeDocument::updateById($docId, ['extraction_status' => 'processing']);
        Database::execute('DELETE FROM knowledge_document_chunks WHERE knowledge_document_id = ?', [$docId]);
        try {
            $out = TextExtractor::extract(FileStorage::path($doc['stored_filename'], 'kb'), $doc['extension']);
            $i = 0;
            Database::transaction(function () use ($out, $doc, &$i) {
                foreach ($out['segments'] as $seg) {
                    foreach (self::splitByHeading($seg->content, $seg->section) as [$heading, $body]) {
                        foreach (self::chunkText($body) as $piece) {
                            Database::insert('knowledge_document_chunks', [
                                'knowledge_document_id' => $doc['id'], 'institution_id' => $doc['institution_id'], 'chunk_index' => ++$i,
                                'page_number' => $seg->page, 'heading' => $heading !== null ? mb_substr($heading, 0, 250) : null, 'content' => $piece,
                            ]);
                        }
                    }
                }
            });
            KnowledgeDocument::updateById($docId, ['extraction_status' => 'completed']);
        } catch (\Throwable $e) {
            Logger::warning('Knowledge indexing failed: ' . $e->getMessage());
            KnowledgeDocument::updateById($docId, ['extraction_status' => 'failed']);
        }
    }

    /** @return array<int,array{0:?string,1:string}> [heading, text] blocks, starting a new block at each detected heading */
    public static function splitByHeading(string $text, ?string $inherited): array
    {
        $blocks = [];
        $heading = $inherited;
        $buf = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $h = SectionDetector::heading($line);
            if ($h !== null) {
                if (trim(implode("\n", $buf)) !== '') {
                    $blocks[] = [$heading, implode("\n", $buf)];
                }
                $heading = $h;
                $buf = [];
                continue;
            }
            $buf[] = $line;
        }
        if (trim(implode("\n", $buf)) !== '') {
            $blocks[] = [$heading, implode("\n", $buf)];
        }
        return $blocks;
    }

    /** @return string[] chunks of ~1000 chars with sentence-aware splitting and small overlap */
    public static function chunkText(string $text, int $size = 1100, int $overlap = 120): array
    {
        $text = trim(preg_replace('/[ \t]+/', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $size) {
            return [$text];
        }
        $sentences = preg_split('/(?<=[.!?])\s+|\n{2,}/u', $text) ?: [$text];
        $chunks = [];
        $cur = '';
        foreach ($sentences as $s) {
            while (mb_strlen($s) > $size) { // hard split very long sentences
                if ($cur !== '') { $chunks[] = trim($cur); $cur = ''; }
                $chunks[] = mb_substr($s, 0, $size);
                $s = mb_substr($s, $size - $overlap);
            }
            if ($cur !== '' && mb_strlen($cur) + mb_strlen($s) + 1 > $size) {
                $chunks[] = trim($cur);
                $cur = mb_substr($cur, -$overlap) . ' ';
                $cur = preg_replace('/^\S*\s/u', '', $cur) ?? $cur; // start overlap on a word boundary
            }
            $cur .= ($cur === '' ? '' : ' ') . $s;
        }
        if (trim($cur) !== '') {
            $chunks[] = trim($cur);
        }
        return $chunks;
    }

    public static function approve(Request $r, array $actor, array $doc): array
    {
        Gate::authorize($actor, 'knowledge.manage', (int) $doc['institution_id']);
        if ($doc['extraction_status'] !== 'completed') {
            throw HttpException::unprocessable('The document has not been indexed yet (status: ' . $doc['extraction_status'] . ').');
        }
        Database::transaction(function () use ($doc, $actor) {
            KnowledgeDocument::updateById((int) $doc['id'], ['status' => 'approved', 'approved_by' => $actor['id'], 'approved_at' => gmdate('Y-m-d H:i:s')]);
            // A new approved version supersedes (archives) the previous one.
            if ($doc['supersedes_id']) {
                KnowledgeDocument::updateById((int) $doc['supersedes_id'], ['status' => 'archived']);
            }
        });
        AuditLogger::log($r, 'knowledge.approve', 'knowledge_document', $doc['uuid'], ['version' => $doc['version_label']]);
        return KnowledgeDocument::find((int) $doc['id']);
    }

    public static function archive(Request $r, array $actor, array $doc): array
    {
        Gate::authorize($actor, 'knowledge.manage', (int) $doc['institution_id']);
        KnowledgeDocument::updateById((int) $doc['id'], ['status' => 'archived']);
        AuditLogger::log($r, 'knowledge.archive', 'knowledge_document', $doc['uuid']);
        return KnowledgeDocument::find((int) $doc['id']);
    }

    public static function present(array $d, bool $admin = false): array
    {
        $out = [
            'id' => $d['uuid'], 'title' => $d['title'], 'category' => $d['category'], 'version' => $d['version_label'], 'status' => $d['status'], 'access_level' => $d['access_level'],
            'type' => $d['extension'], 'size_bytes' => (int) $d['size_bytes'], 'created_at' => $d['created_at'], 'approved_at' => $d['approved_at'],
        ];
        if ($admin) {
            $out['extraction_status'] = $d['extraction_status'];
            $out['chunks'] = (int) Database::fetchValue('SELECT COUNT(*) FROM knowledge_document_chunks WHERE knowledge_document_id = ?', [$d['id']]);
            $out['supersedes'] = $d['supersedes_id'] ? (KnowledgeDocument::find((int) $d['supersedes_id'])['uuid'] ?? null) : null;
        }
        return $out;
    }

    // ----------------------------------------------------------------- retrieval

    public static function scopeFor(array $user): array
    {
        return ['institution_id' => (int) $user['institution_id'], 'access_levels' => (new KnowledgePolicy())->allowedLevels($user)];
    }

    /** Search and log. Returned sources are labelled S1..Sn for citation. */
    public static function search(array $user, string $query, string $purpose = 'search', ?string $category = null, int $limit = 6): array
    {
        $scope = self::scopeFor($user) + ['category' => $category];
        $hits = self::retriever()->retrieve($query, $scope, $limit);
        KnowledgeRetrievalLog::create([
            'user_id' => $user['id'], 'institution_id' => $user['institution_id'], 'query_text' => mb_substr($query, 0, 1000), 'purpose' => $purpose,
            'result_chunk_ids' => array_column($hits, 'chunk_id'), 'result_count' => count($hits),
        ]);
        foreach ($hits as $i => &$h) {
            $h['source_id'] = 'S' . ($i + 1);
        }
        return $hits;
    }

    public static function presentHit(array $h): array
    {
        return [
            'source_id' => $h['source_id'], 'document_id' => $h['document_uuid'], 'title' => $h['title'], 'category' => $h['category'], 'version' => $h['version'],
            'page' => $h['page'], 'heading' => $h['heading'], 'excerpt' => mb_substr($h['content'], 0, 700), 'score' => round($h['score'], 3),
        ];
    }

    /** Answer an institution-specific question from retrieved guidelines only. */
    public static function ask(Request $r, array $user, string $question): array
    {
        $hits = self::search($user, $question, 'ask');
        $sources = array_map([self::class, 'presentHit'], $hits);
        if (!$hits) {
            return ['answered' => false, 'answer' => self::NO_ANSWER, 'cited_sources' => [], 'retrieved_sources' => [], 'ai' => null];
        }
        $svc = AIFactory::make();
        $av = $svc->checkAvailability();
        if (!Config::get('ai.enabled') || !$av['available']) {
            return ['answered' => false, 'answer' => 'AI answers are unavailable right now. The relevant guideline excerpts are listed below.', 'cited_sources' => [], 'retrieved_sources' => $sources, 'ai' => null];
        }
        $inst = Institution::find((int) $user['institution_id']);
        if (!$inst || !(int) $inst['ai_enabled']) {
            return ['answered' => false, 'answer' => 'AI answers are disabled for your institution. The relevant guideline excerpts are listed below.', 'cited_sources' => [], 'retrieved_sources' => $sources, 'ai' => null];
        }
        $limit = (int) Config::get('ai.rate_limit_per_hour');
        if (!RateLimiter::hit('ai:' . $user['id'], $limit, 3600)) {
            throw HttpException::tooMany("AI request limit reached ({$limit} per hour).");
        }
        $block = implode("\n", array_map(fn($h) => '<source id="' . $h['source_id'] . '" title="' . htmlspecialchars($h['title'], ENT_QUOTES) . '" version="' . htmlspecialchars($h['version'], ENT_QUOTES) . '"' . ($h['page'] ? ' page="' . $h['page'] . '"' : '') . ">\n" . PromptManager::neutralise($h['content']) . "\n</source>", $hits));
        $prompt = PromptManager::render('kb.answer', ['question' => $question, 'sources_raw' => $block]);
        try {
            $res = $svc->generateStructuredResponse($prompt['system'], $prompt['user'], Schemas::kbAnswer(), ['purpose' => 'kb_answer', 'user_id' => $user['id']]);
            $ans = Schemas::kb($res->json ?? [], array_column($hits, 'source_id'));
        } catch (AIException $e) {
            return ['answered' => false, 'answer' => 'The AI could not answer: ' . $e->getMessage() . ' The relevant guideline excerpts are listed below.', 'cited_sources' => [], 'retrieved_sources' => $sources, 'ai' => null];
        }
        $cited = array_values(array_filter($sources, fn($s) => in_array($s['source_id'], $ans['source_ids'], true)));
        if (!$ans['answered']) {
            $ans['answer'] = self::NO_ANSWER . ($ans['answer'] !== '' ? ' (' . $ans['answer'] . ')' : '');
        }
        return [
            'answered' => $ans['answered'], 'answer' => $ans['answer'], 'cited_sources' => $cited, 'retrieved_sources' => $sources,
            'ai' => ['provider' => $svc->provider(), 'mock' => $svc->isMock(), 'label' => 'AI-generated from the cited institutional guidelines - verify against the source document.'],
        ];
    }

    /** Guideline excerpts for review prompts (institution-scoped, approved only). */
    public static function guidelineBlock(array $user, array $project, string $topic): string
    {
        $scopeUser = $user;
        $scopeUser['institution_id'] = (int) $project['institution_id']; // project institution, never another
        if ((int) $user['institution_id'] !== (int) $project['institution_id']) {
            return '';
        }
        $query = $topic . ' ' . ($project['approach'] ?? '') . ' ' . ($project['design'] ?? '');
        $hits = self::search($scopeUser, $query, 'review_context', null, 4);
        if (!$hits) {
            return '';
        }
        $lines = ['Institutional guideline excerpts (cite the source id when you rely on one; if the guidelines do not cover a point, say so):', '<sources>'];
        foreach ($hits as $h) {
            $lines[] = '<source id="' . $h['source_id'] . '" title="' . htmlspecialchars($h['title'], ENT_QUOTES) . '">' . PromptManager::neutralise(mb_substr($h['content'], 0, 900)) . '</source>';
        }
        $lines[] = '</sources>';
        return implode("\n", $lines);
    }
}
