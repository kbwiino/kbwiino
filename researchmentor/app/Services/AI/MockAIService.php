<?php
declare(strict_types=1);

namespace App\Services\AI;

/**
 * DEVELOPMENT SUBSTITUTE. Produces deterministic, schema-valid placeholder output so the application
 * can run without an API key. Every output is clearly labelled as mock. It contains no real analysis
 * and must never be used as a basis for academic decisions. It is refused in production (see AIFactory).
 */
final class MockAIService implements AIServiceInterface
{
    public const LABEL = '[MOCK AI - development substitute, not a real review]';

    public function provider(): string { return 'mock'; }
    public function model(): string { return 'mock-1'; }
    public function isMock(): bool { return true; }

    public function checkAvailability(): array
    {
        return ['available' => true, 'provider' => 'mock', 'model' => 'mock-1', 'mock' => true, 'reason' => 'Using the development mock AI. Configure ANTHROPIC_API_KEY for real analysis.'];
    }

    public function countTokensIfSupported(string $system, string $user): ?int
    {
        return null;
    }

    public function generateText(string $system, string $user, array $options = []): AIResult
    {
        return $this->done(self::LABEL . ' Placeholder response.', null, $user, $options);
    }

    public function createReview(string $reviewType, string $system, string $user, array $options = []): AIResult
    {
        $json = $this->review($reviewType, $user);
        return $this->done(json_encode($json), $json, $user, $options + ['purpose' => 'review:' . $reviewType]);
    }

    public function generateStructuredResponse(string $system, string $user, array $schema, array $options = []): AIResult
    {
        $purpose = (string) ($options['purpose'] ?? '');
        $json = match (true) {
            str_starts_with($purpose, 'review:') => $this->review(substr($purpose, 7), $user),
            $purpose === 'supervision_brief' => [
                'summary' => self::LABEL . ' Placeholder meeting brief generated from project data.',
                'agenda_items' => ['Review progress since the last meeting', 'Discuss open review findings', 'Agree next actions and deadlines'],
                'follow_up_questions' => ['What was the main obstacle since the last meeting?', 'Which open findings do you want to address first?'],
                'unresolved_issues' => ['(mock) Check the open findings list for unresolved items.'],
                'suggested_actions' => [['title' => '(mock) Address highest-severity open finding', 'description' => 'Placeholder suggestion.', 'owner' => 'student']],
            ],
            $purpose === 'kb_answer' => $this->kb($user),
            $purpose === 'viva_question' => ['question' => self::LABEL . ' In your own words, how does your research design answer your main research question?', 'topic' => 'Research design'],
            $purpose === 'viva_feedback' => [
                'feedback' => self::LABEL . ' Thank you for your answer. A real AI would give specific feedback here.',
                'strengths' => ['You attempted a direct answer.'], 'improvements' => ['Link your answer explicitly to your objectives.'],
                'follow_up_question' => 'What are the main limitations of your design, and how did you mitigate them?', 'follow_up_topic' => 'Limitations',
            ],
            default => ['summary' => self::LABEL . ' No mock handler for this purpose.'],
        };
        return $this->done(json_encode($json), $json, $user, $options);
    }

    private function done(string $text, ?array $json, string $user, array $options): AIResult
    {
        $in = (int) ceil(strlen($user) / 4);
        $r = new AIResult($text, $json, $in, (int) ceil(strlen($text) / 4), 'mock-1', 'mock-' . bin2hex(random_bytes(4)), 'end_turn', 1, 1, true);
        AiUsageLogger::record($options, $this, $in, $r->outputTokens, 1, 1, 'success', null, $r->requestId);
        return $r;
    }

    // ----------------------------------------------------------------

    private function kb(string $user): array
    {
        if (preg_match('/<source id="([^"]+)"[^>]*>(.*?)<\/source>/s', $user, $m)) {
            $snippet = trim(mb_substr(preg_replace('/\s+/', ' ', $m[2]), 0, 240));
            return ['answered' => true, 'answer' => self::LABEL . ' Relevant guideline text: "' . $snippet . '"', 'source_ids' => [$m[1]]];
        }
        return ['answered' => false, 'answer' => 'No guideline excerpts were supplied.', 'source_ids' => []];
    }

    private function review(string $type, string $user): array
    {
        $doc = '';
        if (preg_match('/<document>\s*(.*?)\s*<\/document>/s', $user, $m)) {
            $doc = $m[1];
        }
        // Sentences that really occur in the document, so that evidence is genuine.
        $plain = preg_replace('/^\[(?:Page|Section)[^\n]*\]$/m', ' ', $doc);
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/', preg_replace('/\s+/', ' ', (string) $plain)) ?: []), fn($s) => mb_strlen($s) >= 25 && mb_strlen($s) <= 300));
        $page = null;
        if (preg_match('/^\[Page (\d+)/m', $doc, $pm)) {
            $page = (int) $pm[1];
        }
        $e1 = $sentences[0] ?? '';
        $e2 = $sentences[1] ?? ($sentences[0] ?? '');
        $labels = [
            'proposal' => ['Problem statement', 'Objectives'], 'literature' => ['Synthesis', 'Citation placement'], 'gap' => ['Gap argument', 'Evidence base'],
            'methodology' => ['Sampling', 'Validity'], 'alignment' => ['Objective-question link', 'Analysis plan'], 'writing' => ['Clarity', 'Transitions'],
            'integrity' => ['Citation support', 'Attribution'], 'statistics' => ['Interpretation', 'Analysis choice'], 'constitution_check' => ['Purpose', 'Design'],
        ][$type] ?? ['Section', 'Section'];
        $issue = fn(string $code, string $sev, string $sec, string $ev) => [
            'issue_code' => $code, 'severity' => $sev, 'section' => $sec, 'page' => $page, 'location' => $sec,
            'issue' => "(mock) Placeholder {$sev}-severity finding about {$sec}. A real AI would describe a concrete problem here.",
            'evidence' => $ev, 'implication' => '(mock) Explains why it matters.', 'recommendation' => '(mock) Suggested improvement.',
            'supervisor_question' => '(mock) Question for discussion.', 'requires_human_verification' => true,
        ];
        $p = ReviewSchema::PREFIX[$type] ?? 'MOCK';
        $out = [
            'review_type' => $type,
            'summary' => self::LABEL . ' This placeholder output exists only so the workflow can be exercised without an API key.',
            'strengths' => ['(mock) The document was provided and could be read.'],
            'major_issues' => [$issue("{$p}-001", 'high', $labels[0], $e1)],
            'minor_issues' => [$issue("{$p}-002", 'low', $labels[1], $e2)],
            'action_items' => [['title' => '(mock) Review the placeholder findings', 'description' => 'Replace with real analysis by configuring the AI provider.', 'owner' => 'student', 'priority' => 'low']],
            'uncertainties' => ['This is mock output; no real analysis was performed.'],
            'source_references' => $e1 !== '' ? [['label' => 'Excerpt 1', 'page' => $page, 'section' => $labels[0], 'excerpt' => $e1]] : [],
        ];
        if ($type === 'gap') {
            $out['gaps'] = [['gap_type' => 'contextual', 'statement' => '(mock) Possible contextual gap - requires verification.', 'supporting_evidence' => $e1, 'confidence' => 'low', 'limitations' => 'Mock output; no literature search performed.', 'verification_questions' => ['Which recent studies cover this context?']]];
        } elseif ($type === 'alignment') {
            $rows = [];
            if (preg_match('/<alignment_skeleton>\s*(.*?)\s*<\/alignment_skeleton>/s', $user, $m) && ($skel = json_decode($m[1], true)) && is_array($skel)) {
                foreach ($skel as $r) {
                    if (is_array($r)) {
                        $blank = array_keys(array_filter($r, fn($v) => $v === '' || $v === null));
                        $r['mismatch_note'] = $blank ? '(mock) Missing links: ' . implode(', ', $blank) : '';
                        $rows[] = $r;
                    }
                }
            }
            $out['alignment_rows'] = $rows;
        } elseif ($type === 'literature') {
            $out['analysis_modes'] = [['mode' => 'description', 'assessment' => '(mock) Placeholder assessment.', 'example_excerpt' => $e1]];
        } elseif ($type === 'statistics') {
            $out['explanations'] = [['subject' => 'Supplied summary', 'explanation' => '(mock) Placeholder explanation.', 'caveats' => 'Consult a qualified statistician.']];
            $out['needs_statistician'] = true;
        }
        return $out;
    }
}
