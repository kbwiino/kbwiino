<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Institution;
use App\Services\{AuditLogger, RateLimiter};
use App\Support\{Config, HttpException};

/** Preconditions for sending project content to an AI provider: availability, authorisation, consent, rate limit. */
final class AiGuard
{
    public const DISCLOSURE = 'To produce AI suggestions, excerpts of your project text are sent to an external AI provider (Anthropic) over an encrypted connection. Requests are logged without document content. AI output is advisory and is not an official assessment.';

    public static function assertAllowed(array $user, array $project, string $purpose, bool $consumeRate = true): AIServiceInterface
    {
        $svc = AIFactory::make();
        $av = $svc->checkAvailability();
        if (!Config::get('ai.enabled')) {
            throw new HttpException(503, 'ai_disabled', 'AI features are disabled by the administrator.');
        }
        if (!$av['available']) {
            throw new HttpException(503, 'ai_unavailable', 'AI features are currently unavailable: ' . ($av['reason'] ?? 'unknown reason'));
        }
        $inst = Institution::find((int) $project['institution_id']);
        if (!$inst || !(int) $inst['ai_enabled']) {
            throw new HttpException(403, 'ai_disabled_for_institution', 'AI features are disabled for your institution.');
        }
        if (!$svc->isMock() && empty($project['ai_consent_at'])) {
            throw new HttpException(403, 'ai_consent_required', 'The project owner must consent to AI processing before project content is sent to the external AI provider. ' . self::DISCLOSURE);
        }
        if ($consumeRate) {
            $limit = (int) Config::get('ai.rate_limit_per_hour');
            if (!RateLimiter::hit('ai:' . $user['id'], $limit, 3600)) {
                throw HttpException::tooMany("AI request limit reached ({$limit} per hour). Please try again later.");
            }
        }
        return $svc;
    }
}
