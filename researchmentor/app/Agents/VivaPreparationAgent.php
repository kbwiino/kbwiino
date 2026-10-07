<?php
declare(strict_types=1);

namespace App\Agents;

use App\Services\AI\{AIServiceInterface, PromptManager, Schemas};
use App\Services\ConstitutionService;

/**
 * Viva Preparation Agent: practice questions and formative feedback only.
 * It never produces marks, grades, pass/fail or any official examination decision.
 */
final class VivaPreparationAgent
{
    public const NOTICE = 'This is an AI practice exercise, not an official examination.';
    public const MODES = [
        'general' => ['General Viva', 'broad questions on the whole study: problem, purpose, design, findings and implications'],
        'theory' => ['Theory', 'theoretical framework, concepts, and how theory underpins the study'],
        'methodology' => ['Methodology', 'research design, sampling, instruments, validity/reliability or trustworthiness, ethics, analysis choices'],
        'statistics' => ['Statistics', 'statistical methods, assumptions, interpretation of results and limitations of the analysis'],
        'contribution' => ['Research Contribution', 'originality, contribution to knowledge, practice and policy, and limitations'],
        'external_examiner' => ['External Examiner Practice', 'probing, sceptical examiner-style questions that test defence of key decisions'],
    ];

    /** Approved information first; working data only when no Constitution exists (and says so). */
    private static function context(array $project): array
    {
        $approved = ConstitutionService::current($project);
        return [
            'project_context' => $approved ? 'Title: ' . $approved['approved_title'] . "\nProblem: " . $approved['approved_problem'] . "\nPurpose: " . $approved['approved_purpose'] : ResearchProjectAgent::promptContext($project, 3000),
            'constitution' => ResearchProjectAgent::constitutionBlock($project),
        ];
    }

    public static function question(AIServiceInterface $ai, array $user, array $project, string $mode, array $history): array
    {
        [$label, $desc] = self::MODES[$mode];
        $hist = implode("\n\n", array_map(fn($h, $i) => 'Q' . ($i + 1) . ': ' . $h['question'] . ($h['answer'] !== null ? "\nStudent answer: " . $h['answer'] : '') . (!empty($h['follow_up']) ? "\n(Planned follow-up: " . $h['follow_up'] . ')' : ''), $history, array_keys($history)));
        $prompt = PromptManager::render('viva.question', self::context($project) + ['mode' => $label, 'mode_description' => $desc, 'history' => $hist !== '' ? $hist : 'No questions asked yet.']);
        $res = $ai->generateStructuredResponse($prompt['system'], $prompt['user'], Schemas::vivaQuestion(), ['purpose' => 'viva_question', 'user_id' => $user['id'], 'project_id' => $project['id']]);
        return Schemas::vivaQuestion_($res->json ?? []);
    }

    public static function feedback(AIServiceInterface $ai, array $user, array $project, string $mode, string $question, string $answer): array
    {
        $prompt = PromptManager::render('viva.feedback', self::context($project) + ['mode' => self::MODES[$mode][0], 'question' => $question, 'answer' => $answer]);
        $res = $ai->generateStructuredResponse($prompt['system'], $prompt['user'], Schemas::vivaFeedback(), ['purpose' => 'viva_feedback', 'user_id' => $user['id'], 'project_id' => $project['id']]);
        return Schemas::vivaFeedback_($res->json ?? []);
    }
}
