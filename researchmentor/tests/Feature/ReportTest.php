<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GeneratedReport;
use App\Services\Extraction\TextExtractor;
use App\Services\Reports\ReportBuilder;
use App\Support\FileStorage;
use Smalot\PdfParser\Parser;
use Tests\Support\{Factory, Fixtures};
use Tests\TestCase;

final class ReportTest extends TestCase
{
    protected function tearDown(): void { Fixtures::cleanup(); parent::tearDown(); }

    private function reviewFor(array $a, string $type = 'proposal'): array
    {
        $this->actingAs($a['student']);
        $d = $this->assertOk($this->call('POST', '/api/documents/upload', ['project_id' => $a['project']['uuid'], 'category' => 'proposal'], [], Fixtures::txt()));
        return $this->assertOk($this->postJson("/api/ai/{$type}-review", ['project_id' => $a['project']['uuid'], 'document_id' => $d['id']]));
    }

    private function pdfText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rmpdf');
        file_put_contents($path, $bytes);
        $text = (new Parser())->parseFile($path)->getText();
        unlink($path);
        return preg_replace('/\s+/', ' ', $text);
    }

    private function docxText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rmdx');
        file_put_contents($path, $bytes);
        $out = TextExtractor::extract($path, 'docx');
        unlink($path);
        return implode("\n", array_map(fn($s) => $s->content, $out['segments']));
    }

    private function body(\App\Support\Response $r): string
    {
        $this->assertNotNull($r->filePath, 'file download expected, got: ' . $r->body);
        return (string) file_get_contents($r->filePath);
    }

    public function testReviewReportContainsRequiredSectionsInPdfAndDocx(): void
    {
        $a = $this->scenario();
        $job = $this->reviewFor($a);
        $pdfResp = $this->getJson('/api/reports/review/' . $job['review_id'] . '?format=pdf');
        $this->assertSame(200, $pdfResp->status);
        $this->assertSame('application/pdf', $pdfResp->headers['Content-Type']);
        $bytes = $this->body($pdfResp);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $text = $this->pdfText($bytes);
        foreach (['Proposal Review Report', 'Project information', $a['project']['title'], 'Report date', 'Review type', 'Strengths', 'Findings', 'Recommendations and action items', 'Evidence', 'AI-assistance disclaimer', 'not a grade', 'Human review status', 'NOT YET REVIEWED', 'DEVELOPMENT MOCK'] as $needle) {
            $this->assertStringContainsString($needle, $text, "PDF must contain: {$needle}");
        }
        $docx = $this->getJson('/api/reports/review/' . $job['review_id'] . '?format=docx');
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx->headers['Content-Type']);
        $dtext = $this->docxText($this->body($docx));
        foreach (['Proposal Review Report', 'Project information', 'AI-assistance disclaimer', 'Human review status', 'Evidence'] as $needle) {
            $this->assertStringContainsString($needle, $dtext);
        }
        $this->assertSame(2, GeneratedReport::count(['project_id' => $a['project']['id']]));
        $this->assertNotNull(\App\Models\AuditLog::first(['action' => 'report.generate']));
    }

    public function testHumanReviewStatusAppearsAfterSupervisorDecision(): void
    {
        $a = $this->scenario();
        $job = $this->reviewFor($a);
        $this->actingAs($a['sup']);
        $this->assertOk($this->postJson('/api/reviews/' . $job['review_id'] . '/decision', ['status' => 'modified', 'notes' => 'Partly agreed']));
        $text = $this->pdfText($this->body($this->getJson('/api/reports/review/' . $job['review_id'])));
        $this->assertStringContainsString('Modified by', $text);
        $this->assertStringContainsString('Partly agreed', $text);
        $this->assertStringNotContainsString('NOT YET REVIEWED', $text);
    }

    public function testReportTitlesPerReviewType(): void
    {
        $this->assertSame('Methodology Audit', ReportBuilder::titleFor('methodology'));
        $this->assertSame('Research Alignment Report', ReportBuilder::titleFor('alignment'));
        $this->assertSame('Chapter Review Report', ReportBuilder::titleFor('writing'));
        $a = $this->scenario();
        $job = $this->reviewFor($a, 'methodology');
        $this->assertStringContainsString('Methodology Audit', $this->pdfText($this->body($this->getJson('/api/reports/review/' . $job['review_id']))));
    }

    public function testAlignmentReportIncludesMatrix(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['student']);
        $this->assertOk($this->postJson('/api/projects/' . $a['project']['uuid'] . '/objectives', ['body' => 'Assess mobile money usage patterns']));
        $job = $this->assertOk($this->postJson('/api/ai/alignment-review', ['project_id' => $a['project']['uuid']]));
        $text = $this->docxText($this->body($this->getJson('/api/reports/review/' . $job['review_id'] . '?format=docx')));
        $this->assertStringContainsString('Alignment matrix', $text);
        $this->assertStringContainsString('Assess mobile money usage patterns', $text);
    }

    public function testMeetingProgressAndVivaReports(): void
    {
        $a = $this->scenario();
        $this->actingAs($a['sup']);
        $m = $this->assertOk($this->postJson('/api/supervision/meetings', ['project_id' => $a['project']['uuid'], 'title' => 'Chapter 1 review', 'scheduled_at' => '2026-11-01T09:00:00Z']));
        $this->assertOk($this->postJson('/api/supervision/meetings/' . $m['id'] . '/brief'));
        $this->assertOk($this->postJson('/api/supervision/meetings/' . $m['id'] . '/outcome', ['outcome' => 'Proceed to ethics.']));
        $t = $this->pdfText($this->body($this->getJson('/api/reports/meeting/' . $m['id'])));
        $this->assertStringContainsString('Supervision Meeting Report', $t);
        $this->assertStringContainsString('Official supervision outcome', $t);
        $this->assertStringContainsString('Proceed to ethics.', $t);
        $this->assertStringContainsString('AI-generated brief (suggestion only)', $t);
        $p = $this->pdfText($this->body($this->getJson('/api/reports/progress/' . $a['project']['uuid'])));
        $this->assertStringContainsString('Research Progress Report', $p);
        $this->assertStringContainsString('without generative AI', $p);
        $this->actingAs($a['student']);
        $v = $this->assertOk($this->postJson('/api/ai/viva-session', ['project_id' => $a['project']['uuid'], 'mode' => 'general']));
        $vt = $this->pdfText($this->body($this->getJson('/api/reports/viva/' . $v['id'] . '?format=pdf')));
        $this->assertStringContainsString('Viva Preparation Report', $vt);
        $this->assertStringContainsString('AI practice exercise, not an official examination', $vt);
    }

    public function testReportAuthorization(): void
    {
        $a = $this->scenario();
        $job = $this->reviewFor($a);
        $v = $this->assertOk($this->postJson('/api/ai/viva-session', ['project_id' => $a['project']['uuid'], 'mode' => 'general']));
        $other = Factory::user($a['org'], 'student');
        Factory::project($a['org'], $other, $a['sup']);
        foreach ([$other, Factory::user($a['org'], 'coordinator'), Factory::user($a['org'], 'sysadmin')] as $u) {
            $this->actingAs($u);
            $this->assertError($this->getJson('/api/reports/review/' . $job['review_id']), 404);
        }
        $this->actingAs($a['sup']);
        // viva practice reports are private to the student
        $this->assertError($this->getJson('/api/reports/viva/' . $v['id']), 404, 'not_found');
        $this->actingAs(Factory::user($a['org'], 'coordinator'));
        $this->assertSame(200, $this->getJson('/api/reports/progress/' . $a['project']['uuid'])->status, 'coordinators may export progress reports');
        $this->actingAs($a['student']);
        $this->assertError($this->getJson('/api/reports/review/' . $job['review_id'] . '?format=exe'), 422);
    }

    public function testStoredReportFilesArePrivateAndRandomlyNamed(): void
    {
        $a = $this->scenario();
        $job = $this->reviewFor($a);
        $this->getJson('/api/reports/review/' . $job['review_id']);
        $row = GeneratedReport::first(['project_id' => $a['project']['id']]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['stored_filename']);
        $this->assertFileExists(FileStorage::path($row['stored_filename'], 'exports'));
    }

    public function testSpecialCharactersDoNotBreakDocuments(): void
    {
        $r = new \App\Services\Reports\Report('Test <&> "quotes"', "sub\x00title \u{2019}smart\u{2019} \u{00E9}\u{00E8} \u{4E2D}");
        $r->section('A & B <c>');
        $r->p("Line with ctrl \x01 chars, accents \u{00E9}, and (parentheses) \\ backslash");
        $this->assertStringStartsWith('%PDF-', $r->toPdf());
        $this->assertStringContainsString('&lt;c&gt;', $this->unzip($r->toDocx(), 'word/document.xml'));
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($this->unzip($r->toDocx(), 'word/document.xml')), 'document.xml must be well-formed');
    }

    private function unzip(string $bytes, string $name): string
    {
        $p = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($p, $bytes);
        $z = new \ZipArchive();
        $z->open($p);
        $c = (string) $z->getFromName($name);
        $z->close();
        unlink($p);
        return $c;
    }
}
