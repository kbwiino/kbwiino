<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\{AuditLog, Document, DocumentVersion, ProjectMember};
use App\Services\DocumentService;
use App\Support\{Config, Database, FileStorage};
use Tests\Support\{Factory, Fixtures};
use Tests\TestCase;

final class DocumentTest extends TestCase
{
    protected function tearDown(): void
    {
        Fixtures::cleanup();
        parent::tearDown();
    }

    private function upload(array $a, array $file, array $extra = []): \App\Support\Response
    {
        return $this->call('POST', '/api/documents/upload', $extra + ['project_id' => $a['project']['uuid'], 'category' => 'chapter_1', 'title' => 'My chapter'], [], $file);
    }

    public function testTxtUploadIsStoredPrivatelyAndExtracted(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->upload($a, Fixtures::txt('../../etc/chapter one.txt')));
        $this->assertSame('completed', $d['current_version']['extraction_status']);
        $this->assertSame(1, $d['current_version']['version']);
        $this->assertStringNotContainsString('..', $d['current_version']['filename']);
        $this->assertStringNotContainsString('/', $d['current_version']['filename']);
        $json = json_encode($d);
        $this->assertStringNotContainsString('stored_filename', $json);
        $this->assertStringNotContainsString(Config::get('storage.private_path'), $json);
        // stored under a random hex name, not the original filename
        $ver = DocumentVersion::first(['document_id' => Document::findByUuid($d['id'])['id']]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ver['stored_filename']);
        $this->assertFileExists(FileStorage::path($ver['stored_filename']));
        $this->assertStringStartsNotWith(Config::get('app.url'), FileStorage::path($ver['stored_filename']));
        $prev = $this->assertOk($this->getJson('/api/documents/' . $d['id'] . '/preview'));
        $sections = array_filter(array_column($prev['segments'], 'section'));
        $this->assertNotEmpty($sections);
        $this->assertNotNull(AuditLog::first(['action' => 'document.upload']));
    }

    public function testPdfExtractionPreservesPageNumbers(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->upload($a, Fixtures::pdf()));
        $this->assertSame('completed', $d['current_version']['extraction_status'], json_encode($d['current_version']));
        $this->assertSame(2, $d['current_version']['page_count']);
        $segs = $this->assertOk($this->getJson('/api/documents/' . $d['id'] . '/preview'))['segments'];
        $pages = array_unique(array_column($segs, 'page'));
        sort($pages);
        $this->assertSame([1, 2], $pages);
        $this->assertStringContainsString('mobile money', strtolower(implode(' ', array_column($segs, 'content'))));
        $this->assertContains('3.4 Sampling design', array_column($segs, 'section'));
    }

    public function testDocxXlsxCsvExtraction(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $docx = $this->assertOk($this->upload($a, Fixtures::docx()));
        $this->assertSame('completed', $docx['current_version']['extraction_status']);
        $this->assertStringContainsString('stratified random sample', DocumentService::fullText(DocumentVersion::findByUuid($docx['current_version']['id'])['id']));
        $xlsx = $this->assertOk($this->upload($a, Fixtures::xlsx(), ['category' => 'dataset']));
        $this->assertSame('completed', $xlsx['current_version']['extraction_status']);
        $text = DocumentService::fullText(DocumentVersion::findByUuid($xlsx['current_version']['id'])['id']);
        $this->assertStringContainsString("age\tsavings", $text);
        $this->assertStringContainsString("31\t3400", $text);
        $csv = $this->assertOk($this->upload($a, Fixtures::csv(), ['category' => 'dataset']));
        $this->assertSame('completed', $csv['current_version']['extraction_status']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFiles')]
    public function testInvalidFilesAreRejected(string $name, string $bytes, int $status): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $this->assertSame($status, $this->upload($a, Fixtures::file($name, $bytes))->status);
        $this->assertSame(0, Document::count(['project_id' => $a['project']['id']]));
    }

    public static function badFiles(): array
    {
        return [
            'php script' => ['shell.php', '<?php system($_GET["c"]);', 422],
            'exe disguised as pdf' => ['report.pdf', "MZ\x90\x00\x03\x00\x00\x00" . str_repeat("\0", 200), 422],
            'html as pdf' => ['x.pdf', '<html><script>alert(1)</script></html>', 422],
            'plain zip as docx' => ['x.docx', "PK\x03\x04" . str_repeat("\0", 100), 422],
            'binary as txt' => ['x.txt', "abc\0def\0\0", 422],
            'no extension' => ['README', 'hello', 422],
            'js' => ['x.js', 'alert(1)', 422],
            'empty' => ['empty.txt', '', 422],
        ];
    }

    public function testOversizedFileRejected(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        Config::set('storage.max_upload_bytes', 100);
        $this->assertError($this->upload($a, Fixtures::txt('big.txt', str_repeat('a', 500))), 413, 'file_too_large');
    }

    public function testRequestBodyDroppedByPhpForSizeIsReportedAs413(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $req = new \App\Support\Request('POST', '/api/documents/upload', [], [], ['X-CSRF-Token' => $this->session->csrfToken(), 'Accept' => 'application/json'], [], ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_LENGTH' => '45000000'], $this->session);
        $r = $this->app->handle($req);
        $this->assertSame(413, $r->status);
        $this->assertSame('file_too_large', $r->decoded()['error']['code']);
    }

    public function testMissingFileAndBadCategory(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $this->assertError($this->call('POST', '/api/documents/upload', ['project_id' => $a['project']['uuid']]), 422);
        $this->assertError($this->upload($a, Fixtures::txt(), ['category' => 'virus']), 422);
    }

    public function testVersioningAndComparison(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->upload($a, Fixtures::txt('c.txt', "Line one\nLine two\nLine three")));
        $v2 = $this->assertOk($this->call('POST', '/api/documents/upload', ['document_id' => $d['id'], 'change_note' => 'Revised'], [], Fixtures::txt('c.txt', "Line one\nLine 2 revised\nLine three\nLine four")));
        $this->assertSame(2, $v2['current_version']['version']);
        $this->assertCount(2, $v2['versions']);
        $cmp = $this->assertOk($this->getJson('/api/documents/' . $d['id'] . '/compare?from=1&to=2'));
        $this->assertTrue($cmp['stats']['changed']);
        $ops = array_column($cmp['diff'], 'op');
        $this->assertContains('add', $ops);
        $this->assertContains('del', $ops);
        // supervisor cannot add versions to the student's document
        $this->actingAs($a['sup']);
        $this->assertError($this->call('POST', '/api/documents/upload', ['document_id' => $d['id']], [], Fixtures::txt()), 403);
    }

    public function testDocumentIsolationBetweenStudents(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->upload($a, Fixtures::txt()));
        $other = Factory::user($a['org'], 'student');
        Factory::project($a['org'], $other, $a['sup']);
        $this->actingAs($other);
        $this->assertError($this->getJson('/api/documents/' . $d['id']), 404);
        $this->assertError($this->getJson('/api/documents/' . $d['id'] . '/download'), 404);
        $this->assertError($this->getJson('/api/documents/' . $d['id'] . '/preview'), 404);
        $this->assertError($this->deleteJson('/api/documents/' . $d['id']), 404);
        $this->assertError($this->getJson('/api/projects/' . $a['project']['uuid'] . '/documents'), 404);
        // cannot upload into another student's project
        $this->assertError($this->upload($a, Fixtures::txt()), 404);
    }

    public function testSupervisorSeesSharedButNotPrivateDrafts(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $shared = $this->assertOk($this->upload($a, Fixtures::txt(), ['title' => 'Shared']));
        $private = $this->assertOk($this->upload($a, Fixtures::txt(), ['title' => 'Private draft', 'visibility' => 'private']));
        $this->assertCount(2, $this->assertOk($this->getJson('/api/projects/' . $a['project']['uuid'] . '/documents')));
        $this->actingAs($a['sup']);
        $list = $this->assertOk($this->getJson('/api/projects/' . $a['project']['uuid'] . '/documents'));
        $this->assertSame(['Shared'], array_column($list, 'title'));
        $this->assertOk($this->getJson('/api/documents/' . $shared['id']));
        $this->assertError($this->getJson('/api/documents/' . $private['id']), 404);
        $this->assertSame(200, $this->getJson('/api/documents/' . $shared['id'] . '/download')->status);
    }

    public function testCoordinatorAndSysadminCannotReadDocuments(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->upload($a, Fixtures::txt()));
        foreach (['coordinator', 'grad_admin', 'sysadmin'] as $role) {
            $this->actingAs(Factory::user($a['org'], $role));
            $this->assertError($this->getJson('/api/documents/' . $d['id']), 404, 'not_found');
            $this->assertError($this->getJson('/api/documents/' . $d['id'] . '/download'), 404);
        }
    }

    public function testExaminerNeedsExplicitDocumentGrant(): void
    {
        $a = $this->scenario();
        $examiner = Factory::user($a['org'], 'external_examiner');
        ProjectMember::create(['project_id' => $a['project']['id'], 'user_id' => $examiner['id'], 'member_role' => 'examiner']);
        \App\Policies\ProjectAccess::flush();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->upload($a, Fixtures::txt()));
        $this->actingAs($examiner);
        $this->assertError($this->getJson('/api/documents/' . $d['id']), 404);
        $this->actingAs($a['student']);
        $this->assertOk($this->postJson('/api/documents/' . $d['id'] . '/access', ['user_id' => $examiner['uuid'], 'permission' => 'view']));
        $this->actingAs($examiner);
        $this->assertOk($this->getJson('/api/documents/' . $d['id']));
        $this->assertError($this->getJson('/api/documents/' . $d['id'] . '/download'), 403);
        $this->actingAs($a['student']);
        $this->assertOk($this->postJson('/api/documents/' . $d['id'] . '/access', ['user_id' => $examiner['uuid'], 'permission' => 'download']));
        $this->actingAs($examiner);
        $this->assertSame(200, $this->getJson('/api/documents/' . $d['id'] . '/download')->status);
        // expired grants stop working
        Database::execute("UPDATE document_access_permissions SET expires_at = '2000-01-01 00:00:00' WHERE user_id = ?", [$examiner['id']]);
        $this->assertError($this->getJson('/api/documents/' . $d['id']), 404);
    }

    public function testDownloadIsAuditedAndHidesPath(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->upload($a, Fixtures::txt('mine.txt')));
        $r = $this->getJson('/api/documents/' . $d['id'] . '/download');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('attachment; filename="mine.txt"', $r->headers['Content-Disposition']);
        $this->assertNotNull($r->filePath);
        $this->assertNotNull(AuditLog::first(['action' => 'document.download']));
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
    }

    public function testDeleteIsSoftAndPurgedAfterRetention(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->upload($a, Fixtures::txt()));
        $row = Document::findByUuid($d['id']);
        $ver = DocumentVersion::first(['document_id' => $row['id']]);
        $path = FileStorage::path($ver['stored_filename']);
        $this->assertOk($this->deleteJson('/api/documents/' . $d['id']));
        $this->assertError($this->getJson('/api/documents/' . $d['id']), 404);
        $this->assertFileExists($path, 'file retained until retention period ends');
        $this->assertSame(0, DocumentService::purgeExpired());
        Database::execute("UPDATE documents SET retention_until = '2000-01-01' WHERE id = ?", [$row['id']]);
        $this->assertSame(1, DocumentService::purgeExpired());
        $this->assertFileDoesNotExist($path);
        $this->assertNotNull(DocumentVersion::find((int) $ver['id'])['file_deleted_at']);
        $this->assertSame(0, (int) Database::fetchValue('SELECT COUNT(*) FROM document_extractions WHERE version_id = ?', [$ver['id']]));
    }

    public function testSupervisorCannotDeleteStudentDocument(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->upload($a, Fixtures::txt()));
        $this->actingAs($a['sup']);
        $this->assertError($this->deleteJson('/api/documents/' . $d['id']), 403);
    }

    public function testCommentsAreLabelledByOrigin(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->upload($a, Fixtures::txt()));
        $this->actingAs($a['sup']);
        $c = $this->assertOk($this->postJson('/api/documents/' . $d['id'] . '/comments', ['body' => 'Tighten the problem statement', 'page' => 2]));
        $this->assertSame('supervisor', $c['origin']);
        $this->actingAs($a['student']);
        $list = $this->assertOk($this->getJson('/api/documents/' . $d['id'] . '/comments'));
        $this->assertSame('supervisor', $list[0]['origin']);
        $this->assertSame(2, $list[0]['page']);
        $this->assertSame(1, \App\Models\Notification::count(['user_id' => $a['student']['id'], 'type' => 'document_comment']));
        $this->assertOk($this->postJson('/api/documents/' . $d['id'] . '/comments/' . $c['id'] . '/resolve'));
    }

    public function testUploadRequiresCsrf(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $this->assertError($this->call('POST', '/api/documents/upload', ['project_id' => $a['project']['uuid']], [], Fixtures::txt(), false), 419);
    }

    public function testArchivedProjectRejectsUploads(): void
    {
        $a = $this->scenario();
        \App\Models\ResearchProject::updateById((int) $a['project']['id'], ['status' => 'archived']);
        $this->actingAs($a['student']);
        $this->assertError($this->upload($a, Fixtures::txt()), 403);
    }
}
