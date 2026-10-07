<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\{KnowledgeDocument, KnowledgeRetrievalLog};
use App\Services\AI\{ClaudeAIService};
use App\Services\KnowledgeService;
use App\Support\{Container, Database};
use Tests\Support\{Factory, FakeTransport, Fixtures};
use Tests\TestCase;

final class KnowledgeTest extends TestCase
{
    protected function tearDown(): void { Fixtures::cleanup(); parent::tearDown(); }

    private function guideline(array $org, string $text, string $title = 'Thesis Handbook', array $extra = [], bool $approve = true): array
    {
        $admin = Factory::user($org, 'grad_admin');
        $this->actingAs($admin);
        $d = $this->assertOk($this->call('POST', '/api/knowledge/documents', $extra + ['title' => $title, 'category' => 'graduate_guideline'], [], Fixtures::txt('g.txt', $text)));
        if ($approve) {
            $d = $this->assertOk($this->postJson('/api/knowledge/documents/' . $d['id'] . '/approve'));
        }
        return [$admin, $d];
    }

    private const HANDBOOK = "Chapter 3 Methodology\n\n3.1 Sample size\nMaster's students conducting quantitative surveys must justify the sample size using a recognised formula such as Yamane or Krejcie and Morgan.\n\n3.2 Ethics\nAll research involving human participants requires clearance from the institutional research ethics committee before data collection.\n\nChapter 4 Referencing\nThe university uses APA 7th edition for all theses.";

    public function testAdminUploadsIndexesAndApproves(): void
    {
        $org = Factory::institution();
        [$admin, $d] = $this->guideline($org, self::HANDBOOK);
        $this->assertSame('approved', $d['status']);
        $this->assertGreaterThan(0, $d['chunks']);
        $this->assertSame('completed', $d['extraction_status']);
        $this->assertNotNull(\App\Models\AuditLog::first(['action' => 'knowledge.approve']));
    }

    public function testOnlyGraduateSchoolAdminsManageGuidelines(): void
    {
        $org = Factory::institution();
        foreach (['student', 'supervisor', 'coordinator', 'sysadmin'] as $role) {
            $this->actingAs(Factory::user($org, $role));
            $this->assertError($this->call('POST', '/api/knowledge/documents', ['title' => 'x'], [], Fixtures::txt()), 403);
        }
        [, $d] = $this->guideline($org, self::HANDBOOK, 'Draft', [], false);
        $this->actingAs(Factory::user($org, 'supervisor'));
        $this->assertError($this->postJson('/api/knowledge/documents/' . $d['id'] . '/approve'), 404, 'not_found');
        $this->assertCount(0, $this->assertOk($this->getJson('/api/knowledge/documents')), 'drafts are hidden from non-admins');
    }

    public function testSearchFindsRelevantGuidelineWithSourceInfo(): void
    {
        $org = Factory::institution();
        [, $d] = $this->guideline($org, self::HANDBOOK);
        $student = Factory::user($org, 'student');
        $this->actingAs($student);
        $hits = $this->assertOk($this->getJson('/api/knowledge/search?q=' . urlencode('sample size formula for surveys')));
        $this->assertNotEmpty($hits);
        $this->assertSame($d['id'], $hits[0]['document_id']);
        $this->assertSame('S1', $hits[0]['source_id']);
        $this->assertStringContainsString('Yamane', $hits[0]['excerpt']);
        $this->assertStringContainsString('3.1', (string) $hits[0]['heading']);
        $this->assertSame(1, KnowledgeRetrievalLog::count(['user_id' => $student['id'], 'purpose' => 'search']));
        $this->assertError($this->getJson('/api/knowledge/search?q=ab'), 422);
    }

    public function testInstitutionIsolationInRetrievalListingAndDownload(): void
    {
        $orgA = Factory::institution('Alpha University');
        $orgB = Factory::institution('Beta University');
        [, $dA] = $this->guideline($orgA, "Alpha policy: students must submit three bound copies of the thesis.\n", 'Alpha Rules');
        $this->guideline($orgB, "Beta policy: electronic submission only.\n", 'Beta Rules');
        $studentB = Factory::user($orgB, 'student');
        $this->actingAs($studentB);
        $hits = $this->assertOk($this->getJson('/api/knowledge/search?q=' . urlencode('bound copies of the thesis')));
        $this->assertSame([], $hits, 'another institution\'s private guidelines must never be retrieved');
        $list = $this->assertOk($this->getJson('/api/knowledge/documents'));
        $this->assertSame(['Beta Rules'], array_column($list, 'title'));
        $this->assertError($this->getJson('/api/knowledge/documents/' . $dA['id']), 404);
        $this->assertError($this->getJson('/api/knowledge/documents/' . $dA['id'] . '/download'), 404);
        // B's admin cannot manage A's document either
        $adminB = Factory::user($orgB, 'grad_admin');
        $this->actingAs($adminB);
        $this->assertError($this->postJson('/api/knowledge/documents/' . $dA['id'] . '/archive'), 404);
        $this->assertSame('approved', KnowledgeDocument::findByUuid($dA['id'])['status']);
        // the AI prompt for B must not contain A's text
        $t = new FakeTransport(fn() => FakeTransport::ok(['answered' => true, 'answer' => 'x', 'source_ids' => ['S1']]));
        Container::instance('ai.service', new ClaudeAIService($t, fn() => null, ['enabled' => true, 'api_key' => 'k', 'model' => 'm', 'api_version' => '2023-06-01', 'base_url' => 'https://x', 'max_tokens' => 100, 'timeout' => 5, 'max_retries' => 0, 'effort' => '']));
        $this->postJson('/api/knowledge/ask', ['question' => 'How many bound copies must be submitted?']);
        $this->assertStringNotContainsString('bound copies', json_encode($t->requests));
    }

    public function testAccessLevelsAreEnforced(): void
    {
        $org = Factory::institution();
        $this->guideline($org, "Examiner marking rubric: weighting of the methodology chapter is 30 percent.\n", 'Staff rubric', ['access_level' => 'staff_only']);
        $this->guideline($org, "Internal moderation procedure for appeals is confidential.\n", 'Admin only', ['access_level' => 'admins_only']);
        $this->actingAs(Factory::user($org, 'student'));
        $this->assertSame([], $this->assertOk($this->getJson('/api/knowledge/search?q=' . urlencode('methodology chapter weighting rubric'))));
        $this->assertCount(0, $this->assertOk($this->getJson('/api/knowledge/documents')));
        $this->actingAs(Factory::user($org, 'supervisor'));
        $this->assertNotEmpty($this->assertOk($this->getJson('/api/knowledge/search?q=' . urlencode('methodology chapter weighting rubric'))));
        $this->assertSame([], $this->assertOk($this->getJson('/api/knowledge/search?q=' . urlencode('moderation procedure appeals'))));
    }

    public function testVersioningArchivesPreviousApprovedVersion(): void
    {
        $org = Factory::institution();
        [$admin, $v1] = $this->guideline($org, "Old rule: use Harvard referencing style.\n", 'Referencing');
        $v2 = $this->assertOk($this->call('POST', '/api/knowledge/documents', ['supersedes_id' => $v1['id']], [], Fixtures::txt('g2.txt', "New rule: use APA seventh edition referencing style.\n")));
        $this->assertSame('1.1', $v2['version']);
        $this->assertSame($v1['id'], $v2['supersedes']);
        $this->assertOk($this->postJson('/api/knowledge/documents/' . $v2['id'] . '/approve'));
        $this->assertSame('archived', KnowledgeDocument::findByUuid($v1['id'])['status']);
        $this->actingAs(Factory::user($org, 'student'));
        $hits = $this->assertOk($this->getJson('/api/knowledge/search?q=' . urlencode('referencing style rule')));
        $this->assertCount(1, $hits, 'archived versions are not retrieved');
        $this->assertStringContainsString('APA', $hits[0]['excerpt']);
    }

    public function testAskUsesRetrievedSourcesAndDisplaysThem(): void
    {
        $org = Factory::institution();
        $this->guideline($org, self::HANDBOOK);
        $this->actingAs(Factory::user($org, 'student'));
        $r = $this->assertOk($this->postJson('/api/knowledge/ask', ['question' => 'Do I need ethics clearance before collecting data?']));
        $this->assertTrue($r['answered']);
        $this->assertNotEmpty($r['cited_sources']);
        $this->assertNotEmpty($r['retrieved_sources']);
        $this->assertSame('S1', $r['cited_sources'][0]['source_id']);
        $this->assertTrue($r['ai']['mock']);
        $this->assertStringContainsString('verify against the source', $r['ai']['label']);
    }

    public function testAskSaysSoWhenGuidelinesDoNotAnswerAndSkipsTheAi(): void
    {
        $org = Factory::institution();
        $this->guideline($org, self::HANDBOOK);
        $t = new FakeTransport(fn() => FakeTransport::ok(['answered' => true, 'answer' => 'fabricated', 'source_ids' => ['S1']]));
        Container::instance('ai.service', new ClaudeAIService($t, fn() => null, ['enabled' => true, 'api_key' => 'k', 'model' => 'm', 'api_version' => '2023-06-01', 'base_url' => 'https://x', 'max_tokens' => 100, 'timeout' => 5, 'max_retries' => 0, 'effort' => '']));
        $this->actingAs(Factory::user($org, 'student'));
        $r = $this->assertOk($this->postJson('/api/knowledge/ask', ['question' => 'What is the cafeteria opening schedule on weekends?']));
        $this->assertFalse($r['answered']);
        $this->assertStringContainsString('do not answer this question', $r['answer']);
        $this->assertSame([], $r['retrieved_sources']);
        $this->assertCount(0, $t->requests, 'no AI call when nothing was retrieved');
    }

    public function testAnswerWithoutValidCitationIsNotAccepted(): void
    {
        $org = Factory::institution();
        $this->guideline($org, self::HANDBOOK);
        $t = new FakeTransport(fn() => FakeTransport::ok(['answered' => true, 'answer' => 'Yes, always, per section 99.', 'source_ids' => ['S99']]));
        Container::instance('ai.service', new ClaudeAIService($t, fn() => null, ['enabled' => true, 'api_key' => 'k', 'model' => 'm', 'api_version' => '2023-06-01', 'base_url' => 'https://x', 'max_tokens' => 100, 'timeout' => 5, 'max_retries' => 0, 'effort' => '']));
        $this->actingAs(Factory::user($org, 'student'));
        $r = $this->assertOk($this->postJson('/api/knowledge/ask', ['question' => 'Do I need ethics clearance before collecting data?']));
        $this->assertFalse($r['answered'], 'an AI claim with invented source ids is rejected');
        $this->assertSame([], $r['cited_sources']);
        $this->assertNotEmpty($r['retrieved_sources'], 'retrieved sources are still shown to the user');
        $sent = $t->requests[0]['body']['messages'][0]['content'];
        $this->assertStringContainsString('<source id="S1"', $sent);
        $this->assertStringContainsString('ONLY the guideline excerpts', $t->requests[0]['body']['system']);
    }

    public function testReviewPromptsIncludeInstitutionGuidelinesForTheSameInstitutionOnly(): void
    {
        $orgA = Factory::institution();
        $orgB = Factory::institution();
        $this->guideline($orgA, "Methodology rule: quantitative surveys must justify sample size with Yamane formula.\n", 'A methodology');
        $this->guideline($orgB, "Methodology rule: B institution requires pilot testing of every survey instrument.\n", 'B methodology');
        $student = Factory::user($orgA, 'student');
        $sup = Factory::user($orgA, 'supervisor');
        $project = Factory::project($orgA, $student, $sup);
        $t = new FakeTransport(function ($p) { $r = FakeTransport::review('methodology'); $r['major_issues'][0]['evidence'] = ''; return FakeTransport::ok($r); });
        Container::instance('ai.service', new ClaudeAIService($t, fn() => null, ['enabled' => true, 'api_key' => 'k', 'model' => 'm', 'api_version' => '2023-06-01', 'base_url' => 'https://x', 'max_tokens' => 100, 'timeout' => 5, 'max_retries' => 0, 'effort' => '']));
        $this->actingAs($student);
        $d = $this->assertOk($this->call('POST', '/api/documents/upload', ['project_id' => $project['uuid'], 'category' => 'chapter_3'], [], Fixtures::txt('m.txt', "Chapter 3 Methodology\n\nWe will run a cross-sectional survey of rural households in two districts.\n")));
        $job = $this->assertOk($this->postJson('/api/ai/methodology-review', ['project_id' => $project['uuid'], 'document_id' => $d['id']]));
        $this->assertSame('completed', $job['status']);
        $user = $t->requests[0]['body']['messages'][0]['content'];
        $this->assertStringContainsString('Yamane', $user);
        $this->assertStringContainsString('<sources>', $user);
        $this->assertStringNotContainsString('pilot testing', $user);
    }

    public function testChunkingKeepsPagesAndSplitsLongText(): void
    {
        $chunks = KnowledgeService::chunkText(str_repeat('This is a sentence about thesis formatting rules. ', 100));
        $this->assertGreaterThan(3, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(1300, mb_strlen($c));
        }
        $this->assertSame([], KnowledgeService::chunkText("  \n "));
    }

    public function testRealFulltextIndexPathAfterCommit(): void
    {
        $this->resetCommittedDatabase();
        try {
            $org = Factory::institution();
            [, $d] = $this->guideline($org, self::HANDBOOK);
            Database::connection()->commit();   // make FULLTEXT index entries visible
            Database::connection()->beginTransaction();
            $hits = \App\Services\Retrieval\MysqlRetriever::class;
            $r = (new $hits())->retrieve('ethics committee clearance human participants', ['institution_id' => $org['institution']['id'], 'access_levels' => ['all_users']], 5);
            $this->assertNotEmpty($r);
            $this->assertStringContainsString('ethics committee', $r[0]['content']);
            $ft = Database::fetchValue("SELECT COUNT(*) FROM knowledge_document_chunks WHERE MATCH(heading, content) AGAINST ('ethics committee' IN NATURAL LANGUAGE MODE)");
            $this->assertGreaterThan(0, (int) $ft, 'the InnoDB FULLTEXT index itself returns the chunk');
        } finally {
            $this->resetCommittedDatabase();
        }
    }
}
