<?php
declare(strict_types=1);

namespace App\Agents;

use App\Services\AI\{AIException, AIInvalidResponse, PromptManager, ReviewSchema};
use App\Support\{Config, Logger};

/**
 * Runs a review agent over a document. Short documents use a single request. Long documents use
 * staged analysis: (1) each window is reviewed separately, (2) the partial findings are consolidated.
 * A thesis is never sent blindly in one request.
 */
final class StagedAnalyzer
{
    public const MAX_WINDOWS = 10;
    private const RESERVED = 14000; // prompt overhead: project context, guidelines, instructions

    /** @return array{review:array,meta:array} */
    public static function run(ReviewAgent $agent, AiRunContext $ctx, array $segments): array
    {
        $maxChars = (int) Config::get('ai.max_input_chars');
        $budget = max(8000, $maxChars - self::RESERVED);
        $plainAll = DocumentRenderer::plain($segments);
        $baseCtx = ['project_id' => $ctx->project['uuid'], 'document_id' => $ctx->document['uuid'] ?? null, 'source_text' => $plainAll];
        $totalChars = mb_strlen($plainAll);

        $meta = ['staged' => false, 'windows' => 1, 'truncated' => false, 'document_chars' => $totalChars, 'input_tokens' => 0, 'output_tokens' => 0];

        if ($totalChars <= $budget) {
            $res = self::review($agent, $ctx, DocumentRenderer::render($segments), '', 'single');
            $meta['input_tokens'] += $res->inputTokens;
            $meta['output_tokens'] += $res->outputTokens;
            return ['review' => ReviewSchema::validate($res->json ?? [], $agent->type(), $baseCtx), 'meta' => $meta];
        }

        // ---- staged analysis
        $windows = DocumentRenderer::windows($segments, $budget);
        $meta['staged'] = true;
        $meta['windows'] = count($windows);
        $skippedNote = null;
        if (count($windows) > self::MAX_WINDOWS) {
            $kept = array_slice($windows, 0, self::MAX_WINDOWS);
            $skippedNote = 'The document is very long: only ' . self::MAX_WINDOWS . ' of ' . count($windows) . ' parts were reviewed (' . DocumentRenderer::pageRange(array_merge(...$kept)) . '). Review the remaining parts separately.';
            $windows = $kept;
            $meta['truncated'] = true;
            $meta['windows'] = count($windows);
        }

        $partials = [];
        $failures = 0;
        $lastError = null;
        $skipped = [];
        foreach ($windows as $i => $win) {
            $note = sprintf('This is part %d of %d of a longer document (%s). Review only this part; other parts are reviewed separately.', $i + 1, count($windows), DocumentRenderer::pageRange($win));
            try {
                $res = self::review($agent, $ctx, DocumentRenderer::render($win), $note, 'part' . ($i + 1));
                $meta['input_tokens'] += $res->inputTokens;
                $meta['output_tokens'] += $res->outputTokens;
                $partials[] = ReviewSchema::validate($res->json ?? [], $agent->type(), ['project_id' => $ctx->project['uuid'], 'document_id' => $ctx->document['uuid'] ?? null, 'source_text' => DocumentRenderer::plain($win)]);
            } catch (AIException $e) {
                $failures++;
                $lastError = $e;
                $skipped[] = DocumentRenderer::pageRange($win);
                Logger::warning('Staged review part failed: ' . $e->errorCode);
                if (in_array($e->errorCode, ['auth_error', 'unavailable', 'model_not_found', 'rate_limited'], true)) {
                    throw $e; // no point continuing
                }
            }
        }
        if (!$partials) {
            throw $lastError ?? new AIInvalidResponse(['no part could be reviewed']);
        }

        // ---- consolidation
        try {
            $payload = json_encode(array_map(fn($p, $n) => ['part' => $n + 1] + self::compact($p), $partials, array_keys($partials)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (mb_strlen($payload) > $maxChars - 4000) {
                $payload = mb_substr($payload, 0, $maxChars - 4000);   // bounded; falls back to local merge on parse errors
            }
            $prompt = PromptManager::render('review.reduce', [
                'review_type' => $agent->type(), 'project_context' => ResearchProjectAgent::promptContext($ctx->project, 3000), 'partials' => $payload,
            ]);
            $res = $ctx->ai->createReview($agent->type(), $prompt['system'], $prompt['user'], $ctx->callOptions('review:' . $agent->type()));
            $meta['input_tokens'] += $res->inputTokens;
            $meta['output_tokens'] += $res->outputTokens;
            $final = ReviewSchema::validate($res->json ?? [], $agent->type(), $baseCtx);
        } catch (AIException $e) {
            Logger::warning('Consolidation failed; merging locally: ' . $e->errorCode);
            $final = self::mergeLocally($agent->type(), $partials, $baseCtx);
        }
        if ($skippedNote) {
            $final['uncertainties'][] = $skippedNote;
        }
        if ($skipped) {
            $final['uncertainties'][] = 'Some parts could not be analysed (' . implode('; ', $skipped) . ').';
        }
        $final['uncertainties'][] = 'This long document was analysed in ' . count($windows) . ' stages; cross-part consistency may not be fully assessed.';
        return ['review' => $final, 'meta' => $meta];
    }

    private static function review(ReviewAgent $agent, AiRunContext $ctx, string $docText, string $partNote, string $stage): \App\Services\AI\AIResult
    {
        $vars = [
            'project_context' => ResearchProjectAgent::promptContext($ctx->project),
            'constitution' => ResearchProjectAgent::constitutionBlock($ctx->project),
            'document_text' => $docText,
            'part_note' => $partNote,
            'guidelines_raw' => self::guidelines($ctx, $agent),
        ] + $agent->variables($ctx, $docText);
        $prompt = PromptManager::render('review.' . $agent->type(), $vars);
        return $ctx->ai->createReview($agent->type(), $prompt['system'], $prompt['user'], $ctx->callOptions('review:' . $agent->type()) + ['stage' => $stage]);
    }

    private static function guidelines(AiRunContext $ctx, ReviewAgent $agent): string
    {
        if (!in_array($agent->type(), ['proposal', 'methodology', 'literature'], true)) {
            return '';
        }
        try {
            return \App\Services\KnowledgeService::guidelineBlock($ctx->user, $ctx->project, $agent->label());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function compact(array $review): array
    {
        $strip = fn(array $i) => ['code' => $i['issue_code'], 'severity' => $i['severity'], 'section' => $i['section'], 'page' => $i['page'], 'location' => $i['location'], 'issue' => $i['issue'], 'evidence' => mb_substr($i['evidence'], 0, 400), 'implication' => $i['implication'], 'recommendation' => $i['recommendation'], 'supervisor_question' => $i['supervisor_question']];
        return ['summary' => $review['summary'], 'major' => array_map($strip, $review['major_issues']), 'minor' => array_map($strip, $review['minor_issues']), 'strengths' => $review['strengths']];
    }

    private static function mergeLocally(string $type, array $partials, array $baseCtx): array
    {
        $raw = ['review_type' => $type, 'summary' => '', 'strengths' => [], 'major_issues' => [], 'minor_issues' => [], 'action_items' => [], 'uncertainties' => ['The partial results were merged without AI consolidation.'], 'source_references' => []];
        $summaries = [];
        foreach ($partials as $p) {
            $summaries[] = $p['summary'];
            foreach (['strengths', 'action_items', 'source_references', 'uncertainties'] as $k) {
                $raw[$k] = array_merge($raw[$k], $p[$k]);
            }
            foreach (['major_issues', 'minor_issues'] as $k) {
                foreach ($p[$k] as $i) {
                    unset($i['category'], $i['evidence_verified']);
                    $raw[$k][] = $i;
                }
            }
        }
        $raw['summary'] = mb_substr(implode(' ', $summaries), 0, 3900);
        foreach (['gaps', 'alignment_rows', 'analysis_modes', 'explanations'] as $ext) {
            foreach ($partials as $p) {
                if (isset($p[$ext])) {
                    $raw[$ext] = array_merge($raw[$ext] ?? [], $p[$ext]);
                }
            }
        }
        $raw['needs_statistician'] = true;
        // Issue codes are reassigned (duplicates across parts); evidence is verified against the whole text.
        foreach (['major_issues', 'minor_issues'] as $k) {
            foreach ($raw[$k] as &$i) {
                $i['issue_code'] = '';
            }
            unset($i);
        }
        return ReviewSchema::validate($raw, $type, $baseCtx);
    }
}
