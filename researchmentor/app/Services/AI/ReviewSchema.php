<?php
declare(strict_types=1);

namespace App\Services\AI;

/**
 * The standard review schema (Part 11) plus type-specific extensions.
 * jsonSchema() is sent to the provider; validate() is ALWAYS run on the response before anything is saved.
 */
final class ReviewSchema
{
    public const TYPES = ['proposal', 'literature', 'gap', 'methodology', 'alignment', 'writing', 'integrity', 'statistics', 'constitution_check'];
    public const PREFIX = ['proposal' => 'PROP', 'literature' => 'LIT', 'gap' => 'GAP', 'methodology' => 'METH', 'alignment' => 'ALN', 'writing' => 'WRI', 'integrity' => 'INT', 'statistics' => 'STAT', 'constitution_check' => 'CON'];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];
    public const MAX_ISSUES = 40;

    private static function str(): array { return ['type' => 'string']; }
    private static function strList(): array { return ['type' => 'array', 'items' => ['type' => 'string']]; }
    private static function obj(array $props): array
    {
        return ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
    }

    private static function issue(): array
    {
        return self::obj([
            'issue_code' => self::str(),
            'severity' => ['type' => 'string', 'enum' => self::SEVERITIES],
            'section' => self::str(),
            'page' => ['type' => ['integer', 'null']],
            'location' => self::str(),
            'issue' => self::str(),
            'evidence' => self::str(),
            'implication' => self::str(),
            'recommendation' => self::str(),
            'supervisor_question' => self::str(),
            'requires_human_verification' => ['type' => 'boolean'],
        ]);
    }

    public static function extensions(string $type): array
    {
        return match ($type) {
            'literature' => ['analysis_modes' => ['type' => 'array', 'items' => self::obj([
                'mode' => ['type' => 'string', 'enum' => ['description', 'comparison', 'critique', 'synthesis', 'claimed_gap']],
                'assessment' => self::str(), 'example_excerpt' => self::str(),
            ])]],
            'gap' => ['gaps' => ['type' => 'array', 'items' => self::obj([
                'gap_type' => ['type' => 'string', 'enum' => ['contextual', 'theoretical', 'empirical', 'methodological', 'conceptual', 'practical', 'knowledge']],
                'statement' => self::str(), 'supporting_evidence' => self::str(),
                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'limitations' => self::str(), 'verification_questions' => self::strList(),
            ])]],
            'alignment' => ['alignment_rows' => ['type' => 'array', 'items' => self::obj([
                'objective' => self::str(), 'research_question' => self::str(), 'hypothesis' => self::str(), 'variable' => self::str(),
                'indicator' => self::str(), 'instrument' => self::str(), 'data_source' => self::str(), 'analysis' => self::str(), 'mismatch_note' => self::str(),
            ])]],
            'statistics' => [
                'explanations' => ['type' => 'array', 'items' => self::obj(['subject' => self::str(), 'explanation' => self::str(), 'caveats' => self::str()])],
                'needs_statistician' => ['type' => 'boolean'],
            ],
            default => [],
        };
    }

    public static function jsonSchema(string $type): array
    {
        $props = [
            'review_type' => self::str(),
            'summary' => self::str(),
            'strengths' => self::strList(),
            'major_issues' => ['type' => 'array', 'items' => self::issue()],
            'minor_issues' => ['type' => 'array', 'items' => self::issue()],
            'action_items' => ['type' => 'array', 'items' => self::obj([
                'title' => self::str(), 'description' => self::str(),
                'owner' => ['type' => 'string', 'enum' => ['student', 'supervisor', 'both']],
                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
            ])],
            'uncertainties' => self::strList(),
            'source_references' => ['type' => 'array', 'items' => self::obj([
                'label' => self::str(), 'page' => ['type' => ['integer', 'null']], 'section' => self::str(), 'excerpt' => self::str(),
            ])],
        ] + self::extensions($type);
        return self::obj($props);
    }

    /**
     * Validate and normalise an AI review. Never trusts the model: enforces structure, enums, lengths,
     * overrides identifiers, and checks that quoted evidence actually appears in the supplied source text.
     *
     * @param array{project_id:string,document_id:?string,source_text?:string} $ctx
     * @throws AIInvalidResponse
     */
    public static function validate(array $raw, string $type, array $ctx): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown review type {$type}");
        }
        $problems = [];
        if (!isset($raw['summary']) || !is_string($raw['summary']) || trim($raw['summary']) === '') {
            $problems[] = 'summary missing';
        }
        foreach (['major_issues', 'minor_issues'] as $k) {
            if (!isset($raw[$k]) || !is_array($raw[$k]) || (!array_is_list($raw[$k]) && $raw[$k] !== [])) {
                $problems[] = "{$k} must be a list";
            }
        }
        if ($problems) {
            throw new AIInvalidResponse($problems);
        }

        $source = self::norm((string) ($ctx['source_text'] ?? ''));
        $usedCodes = [];
        $seq = 0;
        $seenText = [];
        $prefix = self::PREFIX[$type];
        $count = 0;
        $normIssues = function (array $list, string $category) use (&$problems, &$usedCodes, &$seq, &$seenText, &$count, $prefix, $source): array {
            $out = [];
            foreach ($list as $i) {
                if (!is_array($i) || !isset($i['issue']) || !is_string($i['issue']) || trim($i['issue']) === '') {
                    $problems[] = 'an issue without description was dropped';
                    continue;
                }
                if (++$count > self::MAX_ISSUES) {
                    $problems[] = 'issue list truncated';
                    break;
                }
                $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($i['issue'])));
                if (isset($seenText[$key])) {
                    continue; // duplicate finding
                }
                $seenText[$key] = true;
                $sev = strtolower((string) ($i['severity'] ?? ''));
                $verifyNeeded = !in_array($sev, self::SEVERITIES, true);
                if ($verifyNeeded) {
                    $sev = 'medium';
                }
                $code = strtoupper(trim((string) ($i['issue_code'] ?? '')));
                if (!preg_match('/^' . $prefix . '-\d{3}$/', $code) || isset($usedCodes[$code])) {
                    do {
                        $code = sprintf('%s-%03d', $prefix, ++$seq);
                    } while (isset($usedCodes[$code]));
                }
                $usedCodes[$code] = true;
                $evidence = self::clip($i['evidence'] ?? '', 1500);
                $verified = null;
                if ($evidence !== '' && $source !== '') {
                    $verified = self::evidenceInSource($evidence, $source);
                }
                $page = isset($i['page']) && is_numeric($i['page']) && (int) $i['page'] > 0 ? (int) $i['page'] : null;
                $out[] = [
                    'issue_code' => $code,
                    'category' => $category,
                    'severity' => $sev,
                    'section' => self::clip($i['section'] ?? '', 250),
                    'page' => $page,
                    'location' => self::clip($i['location'] ?? '', 250),
                    'issue' => self::clip($i['issue'], 2000),
                    'evidence' => $evidence,
                    'evidence_verified' => $verified,
                    'implication' => self::clip($i['implication'] ?? '', 2000),
                    'recommendation' => self::clip($i['recommendation'] ?? '', 2000),
                    'supervisor_question' => self::clip($i['supervisor_question'] ?? '', 1000),
                    // AI findings always require human verification unless the model explicitly says otherwise AND evidence is verified
                    'requires_human_verification' => $verifyNeeded || $verified === false || !array_key_exists('requires_human_verification', $i) || (bool) $i['requires_human_verification'],
                ];
            }
            return $out;
        };

        $review = [
            'review_type' => $type,
            'project_id' => $ctx['project_id'],
            'document_id' => $ctx['document_id'] ?? null,
            'summary' => self::clip($raw['summary'], 4000),
            'strengths' => self::strings($raw['strengths'] ?? [], 15, 600),
            'major_issues' => $normIssues($raw['major_issues'], 'major'),
            'minor_issues' => $normIssues($raw['minor_issues'], 'minor'),
            'action_items' => [],
            'uncertainties' => self::strings($raw['uncertainties'] ?? [], 15, 800),
            'source_references' => [],
        ];
        foreach (is_array($raw['action_items'] ?? null) ? $raw['action_items'] : [] as $a) {
            if (is_array($a) && !empty($a['title']) && is_string($a['title'])) {
                $review['action_items'][] = [
                    'title' => self::clip($a['title'], 300), 'description' => self::clip($a['description'] ?? '', 1500),
                    'owner' => in_array($a['owner'] ?? '', ['student', 'supervisor', 'both'], true) ? $a['owner'] : 'student',
                    'priority' => in_array($a['priority'] ?? '', ['low', 'medium', 'high'], true) ? $a['priority'] : 'medium',
                ];
            }
            if (count($review['action_items']) >= 25) break;
        }
        foreach (is_array($raw['source_references'] ?? null) ? $raw['source_references'] : [] as $s) {
            if (is_array($s) && !empty($s['label'])) {
                $review['source_references'][] = [
                    'label' => self::clip($s['label'], 250), 'page' => isset($s['page']) && is_numeric($s['page']) && (int) $s['page'] > 0 ? (int) $s['page'] : null,
                    'section' => self::clip($s['section'] ?? '', 250), 'excerpt' => self::clip($s['excerpt'] ?? '', 800),
                ];
            }
            if (count($review['source_references']) >= 30) break;
        }
        $review += self::validateExtensions($raw, $type);
        if (in_array('issue list truncated', $problems, true)) {
            $review['uncertainties'][] = 'The AI produced more findings than can be shown; only the first ' . self::MAX_ISSUES . ' were kept.';
        }
        return $review;
    }

    private static function validateExtensions(array $raw, string $type): array
    {
        $out = [];
        switch ($type) {
            case 'literature':
                $out['analysis_modes'] = [];
                foreach (is_array($raw['analysis_modes'] ?? null) ? $raw['analysis_modes'] : [] as $m) {
                    if (is_array($m) && in_array($m['mode'] ?? '', ['description', 'comparison', 'critique', 'synthesis', 'claimed_gap'], true)) {
                        $out['analysis_modes'][] = ['mode' => $m['mode'], 'assessment' => self::clip($m['assessment'] ?? '', 1500), 'example_excerpt' => self::clip($m['example_excerpt'] ?? '', 800)];
                    }
                }
                break;
            case 'gap':
                $out['gaps'] = [];
                foreach (is_array($raw['gaps'] ?? null) ? $raw['gaps'] : [] as $g) {
                    if (!is_array($g) || empty($g['statement']) || !is_string($g['statement'])) continue;
                    $types = ['contextual', 'theoretical', 'empirical', 'methodological', 'conceptual', 'practical', 'knowledge'];
                    $out['gaps'][] = [
                        'gap_type' => in_array($g['gap_type'] ?? '', $types, true) ? $g['gap_type'] : 'knowledge',
                        'statement' => self::clip($g['statement'], 1500), 'supporting_evidence' => self::clip($g['supporting_evidence'] ?? '', 1500),
                        // A gap is never "established" by the AI: confidence is capped at 'medium' unless a human verifies it.
                        'confidence' => in_array($g['confidence'] ?? '', ['low', 'medium'], true) ? $g['confidence'] : 'medium',
                        'limitations' => self::clip($g['limitations'] ?? '', 1500) ?: 'Not assessed by the AI; absence of retrieved literature is not evidence of a gap.',
                        'verification_questions' => self::strings($g['verification_questions'] ?? [], 8, 500),
                        'status' => 'unverified_suggestion',
                    ];
                    if (count($out['gaps']) >= 15) break;
                }
                break;
            case 'alignment':
                $out['alignment_rows'] = [];
                $keys = ['objective', 'research_question', 'hypothesis', 'variable', 'indicator', 'instrument', 'data_source', 'analysis', 'mismatch_note'];
                foreach (is_array($raw['alignment_rows'] ?? null) ? $raw['alignment_rows'] : [] as $row) {
                    if (!is_array($row)) continue;
                    $clean = [];
                    foreach ($keys as $k) {
                        $clean[$k] = self::clip($row[$k] ?? '', 1500);
                    }
                    if (implode('', $clean) !== '') {
                        $out['alignment_rows'][] = $clean;
                    }
                    if (count($out['alignment_rows']) >= 60) break;
                }
                break;
            case 'statistics':
                $out['explanations'] = [];
                foreach (is_array($raw['explanations'] ?? null) ? $raw['explanations'] : [] as $e) {
                    if (is_array($e) && !empty($e['explanation'])) {
                        $out['explanations'][] = ['subject' => self::clip($e['subject'] ?? '', 300), 'explanation' => self::clip($e['explanation'], 2500), 'caveats' => self::clip($e['caveats'] ?? '', 1500)];
                    }
                    if (count($out['explanations']) >= 30) break;
                }
                $out['needs_statistician'] = (bool) ($raw['needs_statistician'] ?? true);
                break;
        }
        return $out;
    }

    public static function clip(mixed $v, int $max): string
    {
        if (!is_string($v)) {
            return is_scalar($v) ? self::clip((string) $v, $max) : '';
        }
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        $v = trim($v);
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;
    }

    /** @return string[] */
    private static function strings(mixed $list, int $maxItems, int $maxLen): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $s) {
            if (is_string($s) && trim($s) !== '') {
                $out[] = self::clip($s, $maxLen);
            }
            if (count($out) >= $maxItems) break;
        }
        return $out;
    }

    public static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"], ["'", "'", '"', '"', '-', '-'], $s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** True when each quoted fragment of the evidence appears in the source text. */
    public static function evidenceInSource(string $evidence, string $normSource): bool
    {
        $fragments = preg_split('/\[?\.\.\.\]?|…/u', $evidence) ?: [];
        $checked = 0;
        foreach ($fragments as $f) {
            $n = self::norm($f);
            if (mb_strlen($n) < 12) {
                continue;
            }
            $checked++;
            if (!str_contains($normSource, $n)) {
                return false;
            }
        }
        return $checked > 0;
    }
}
