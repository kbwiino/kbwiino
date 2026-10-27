<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AI\{AIInvalidResponse, ReviewSchema};
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTransport;

final class ReviewSchemaTest extends TestCase
{
    private const SRC = 'Rural households save little. A stratified random sample of 400 households was selected from three districts.';

    private function ctx(): array
    {
        return ['project_id' => 'proj-uuid', 'document_id' => 'doc-uuid', 'source_text' => self::SRC];
    }

    public function testValidReviewIsNormalisedAndIdentifiersOverridden(): void
    {
        $raw = FakeTransport::review('proposal', ['project_id' => 'attacker-controlled']);
        $v = ReviewSchema::validate($raw, 'proposal', $this->ctx());
        $this->assertSame('proj-uuid', $v['project_id']);
        $this->assertSame('doc-uuid', $v['document_id']);
        $this->assertSame('major', $v['major_issues'][0]['category']);
        $this->assertTrue($v['major_issues'][0]['evidence_verified']);
    }

    public function testFabricatedEvidenceIsFlaggedNotTrusted(): void
    {
        $raw = FakeTransport::review();
        $raw['major_issues'][0]['evidence'] = 'The study surveyed 12,000 farmers across Kenya and Tanzania.';
        $raw['major_issues'][0]['requires_human_verification'] = false;
        $i = ReviewSchema::validate($raw, 'proposal', $this->ctx())['major_issues'][0];
        $this->assertFalse($i['evidence_verified']);
        $this->assertTrue($i['requires_human_verification'], 'unverified evidence always requires human verification');
    }

    public function testEvidenceWithEllipsisFragmentsIsVerifiedPerFragment(): void
    {
        $this->assertTrue(ReviewSchema::evidenceInSource('Rural households save little ... a stratified random sample of 400 households', ReviewSchema::norm(self::SRC)));
        $this->assertFalse(ReviewSchema::evidenceInSource('Rural households save little ... invented second half of the quote here', ReviewSchema::norm(self::SRC)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("brokenReviews")]
    public function testStructuralProblemsAreRejected(array $raw): void
    {
        $this->expectException(AIInvalidResponse::class);
        ReviewSchema::validate($raw, 'proposal', $this->ctx());
    }

    public static function brokenReviews(): array
    {
        return [
            'no summary' => [['major_issues' => [], 'minor_issues' => []]],
            'empty summary' => [['summary' => '  ', 'major_issues' => [], 'minor_issues' => []]],
            'issues not list' => [['summary' => 'x', 'major_issues' => 'many', 'minor_issues' => []]],
            'missing minor' => [['summary' => 'x', 'major_issues' => []]],
            'assoc instead of list' => [['summary' => 'x', 'major_issues' => ['a' => 'b'], 'minor_issues' => []]],
        ];
    }

    public function testBadSeverityDefaultsAndRequiresVerification(): void
    {
        $raw = FakeTransport::review();
        $raw['major_issues'][0]['severity'] = 'catastrophic';
        $i = ReviewSchema::validate($raw, 'proposal', $this->ctx())['major_issues'][0];
        $this->assertSame('medium', $i['severity']);
        $this->assertTrue($i['requires_human_verification']);
    }

    public function testIssueCodesAreUniqueAndFollowTypePrefix(): void
    {
        $raw = FakeTransport::review();
        $dup = $raw['major_issues'][0];
        $dup['issue'] = 'Another problem';
        $bad = $dup;
        $bad['issue_code'] = "<script>alert(1)</script>";
        $bad['issue'] = 'Third problem';
        $raw['major_issues'] = [$raw['major_issues'][0], $dup, $bad];
        $codes = array_column(ReviewSchema::validate($raw, 'proposal', $this->ctx())['major_issues'], 'issue_code');
        $this->assertSame(3, count(array_unique($codes)));
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^PROP-\d{3}$/', $c);
        }
    }

    public function testDuplicateFindingsCollapsedAndListIsCapped(): void
    {
        $raw = FakeTransport::review();
        $one = $raw['major_issues'][0];
        $raw['major_issues'] = array_fill(0, 3, $one);
        $this->assertCount(1, ReviewSchema::validate($raw, 'proposal', $this->ctx())['major_issues']);
        $many = [];
        for ($i = 0; $i < 80; $i++) { $x = $one; $x['issue'] = "Distinct issue number $i"; $many[] = $x; }
        $raw['major_issues'] = $many;
        $v = ReviewSchema::validate($raw, 'proposal', $this->ctx());
        $this->assertCount(ReviewSchema::MAX_ISSUES, $v['major_issues']);
        $this->assertNotEmpty(array_filter($v['uncertainties'], fn($u) => str_contains($u, 'only the first')));
    }

    public function testStringsAreClippedAndControlCharsStripped(): void
    {
        $raw = FakeTransport::review();
        $raw['summary'] = str_repeat('a', 9000) . "\x00\x07";
        $v = ReviewSchema::validate($raw, 'proposal', $this->ctx());
        $this->assertLessThanOrEqual(4000, mb_strlen($v['summary']));
        $this->assertStringNotContainsString("\x00", $v['summary']);
    }

    public function testGapsCannotBePresentedAsEstablished(): void
    {
        $raw = FakeTransport::review('gap');
        $raw['gaps'] = [['gap_type' => 'empirical', 'statement' => 'No studies exist on X', 'supporting_evidence' => 'none found', 'confidence' => 'high', 'limitations' => '', 'verification_questions' => ['Search Scopus for X']]];
        $g = ReviewSchema::validate($raw, 'gap', $this->ctx())['gaps'][0];
        $this->assertNotSame('high', $g['confidence']);
        $this->assertSame('unverified_suggestion', $g['status']);
        $this->assertNotSame('', $g['limitations']);
    }

    public function testJsonSchemaIsStrictAndTypeSpecific(): void
    {
        $s = ReviewSchema::jsonSchema('alignment');
        $this->assertFalse($s['additionalProperties']);
        $this->assertContains('alignment_rows', $s['required']);
        $this->assertNotContains('gaps', $s['required']);
        $this->assertContains('major_issues', $s['required']);
        $issue = $s['properties']['major_issues']['items'];
        $this->assertSame(['low', 'medium', 'high', 'critical'], $issue['properties']['severity']['enum']);
        $this->assertFalse($issue['additionalProperties']);
    }
}
