<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Support\{Config, Logger};

/**
 * Anthropic Messages API client (server-side only; the API key never reaches the browser).
 *
 * - POST {base}/v1/messages with x-api-key + anthropic-version headers
 * - bounded retries with exponential backoff for 429 / 5xx / 529 / network errors (honours retry-after)
 * - optional request features (output_config.format / effort) are dropped once if the API rejects them
 * - stop_reason "refusal" and "max_tokens" are surfaced as errors instead of being trusted
 */
final class ClaudeAIService implements AIServiceInterface
{
    /** @var callable */
    private $sleeper;

    public function __construct(
        private ?HttpTransport $transport = null,
        ?callable $sleeper = null,
        private ?array $config = null,
    ) {
        $this->transport ??= new CurlTransport();
        $this->sleeper = $sleeper ?? fn(float $s) => usleep((int) ($s * 1_000_000));
        $this->config ??= Config::get('ai');
    }

    public function provider(): string { return 'claude'; }
    public function model(): string { return (string) $this->config['model']; }
    public function isMock(): bool { return false; }

    public function checkAvailability(): array
    {
        $reason = null;
        if (!$this->config['enabled']) {
            $reason = 'AI features are disabled by the administrator.';
        } elseif (trim((string) $this->config['api_key']) === '') {
            $reason = 'The AI provider has not been configured by the administrator.';
        }
        return ['available' => $reason === null, 'provider' => 'claude', 'model' => $this->model(), 'mock' => false, 'reason' => $reason];
    }

    public function generateText(string $system, string $user, array $options = []): AIResult
    {
        return $this->send($system, $user, null, $options);
    }

    public function generateStructuredResponse(string $system, string $user, array $schema, array $options = []): AIResult
    {
        return $this->send($system, $user, $schema, $options);
    }

    public function createReview(string $reviewType, string $system, string $user, array $options = []): AIResult
    {
        return $this->generateStructuredResponse($system, $user, ReviewSchema::jsonSchema($reviewType), $options + ['purpose' => 'review:' . $reviewType]);
    }

    public function countTokensIfSupported(string $system, string $user): ?int
    {
        $av = $this->checkAvailability();
        if (!$av['available']) {
            return null;
        }
        try {
            $r = $this->http('/v1/messages/count_tokens', ['model' => $this->model(), 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]], 30, 0);
            return isset($r['body']['input_tokens']) ? (int) $r['body']['input_tokens'] : null;
        } catch (AIException $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------

    private function send(string $system, string $user, ?array $schema, array $options): AIResult
    {
        $av = $this->checkAvailability();
        if (!$av['available']) {
            AiUsageLogger::record($options, $this, 0, 0, 0, 1, 'error', 'unavailable', null);
            throw new AIException('unavailable', (string) $av['reason']);
        }
        $maxTokens = (int) ($options['max_tokens'] ?? $this->config['max_tokens']);
        $payload = [
            'model' => $this->model(),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ];
        $outputConfig = [];
        if ($schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $schema];
        }
        if (!empty($this->config['effort'])) {
            $outputConfig['effort'] = $this->config['effort'];
        }
        if ($outputConfig) {
            $payload['output_config'] = $outputConfig;
        }

        $t0 = microtime(true);
        $attempts = 0;
        try {
            $resp = $this->http('/v1/messages', $payload, (int) $this->config['timeout'], (int) $this->config['max_retries'], $attempts);
        } catch (AIException $e) {
            AiUsageLogger::record($options, $this, 0, 0, (int) ((microtime(true) - $t0) * 1000), max(1, $e->attempts), $e->errorCode === 'rate_limited' ? 'rate_limited' : 'error', $e->errorCode, null);
            throw $e;
        }
        $body = $resp['body'];
        $latency = (int) ((microtime(true) - $t0) * 1000);
        $in = (int) ($body['usage']['input_tokens'] ?? 0);
        $out = (int) ($body['usage']['output_tokens'] ?? 0);
        $reqId = $resp['headers']['request-id'] ?? ($body['id'] ?? null);
        $stop = (string) ($body['stop_reason'] ?? '');

        if ($stop === 'refusal') {
            AiUsageLogger::record($options, $this, $in, $out, $latency, $attempts, 'refused', 'refusal', $reqId);
            throw new AIException('refused', 'The AI provider declined to process this content.', false, 200, $attempts);
        }
        $text = '';
        foreach (($body['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        $json = null;
        if ($schema !== null) {
            if ($stop === 'max_tokens') {
                AiUsageLogger::record($options, $this, $in, $out, $latency, $attempts, 'error', 'truncated', $reqId);
                throw new AIException('truncated', 'The AI response was cut off before it was complete. Try a smaller document section.', false, 200, $attempts);
            }
            $json = self::decodeJson($text);
            if ($json === null) {
                AiUsageLogger::record($options, $this, $in, $out, $latency, $attempts, 'error', 'invalid_json', $reqId);
                throw new AIException('invalid_response', 'The AI returned a response that could not be read as structured data.', false, 200, $attempts);
            }
        }
        AiUsageLogger::record($options, $this, $in, $out, $latency, $attempts, 'success', null, $reqId);
        return new AIResult($text, $json, $in, $out, (string) ($body['model'] ?? $this->model()), $reqId, $stop, $latency, $attempts);
    }

    /** Decode JSON from model text (tolerates a fenced block). */
    public static function decodeJson(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) {
            $text = $m[1];
        }
        $data = json_decode($text, true);
        return is_array($data) ? $data : null;
    }

    /**
     * POST with retry/backoff. Returns ['status','headers','body'(decoded array)].
     * @throws AIException
     */
    private function http(string $path, array $payload, int $timeout, int $maxRetries, int &$attemptsUsed = 0): array
    {
        $url = rtrim((string) $this->config['base_url'], '/') . $path;
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->config['api_key'],
            'anthropic-version: ' . $this->config['api_version'],
        ];
        $attempt = 0;
        $downgraded = [];
        while (true) {
            $attempt++;
            $attemptsUsed = $attempt;
            try {
                $r = $this->transport->post($url, $headers, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $timeout);
            } catch (AIException $e) {
                if ($e->retryable && $attempt <= $maxRetries) {
                    $this->backoff($attempt, null);
                    continue;
                }
                throw new AIException($e->errorCode, $e->getMessage(), $e->retryable, $e->httpStatus, $attempt);
            }
            $status = $r['status'];
            $decoded = json_decode($r['body'], true);
            if ($status >= 200 && $status < 300 && is_array($decoded)) {
                return ['status' => $status, 'headers' => $r['headers'], 'body' => $decoded];
            }
            $apiMsg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            if (in_array($status, [429, 500, 502, 503, 504, 529], true) || ($status >= 500 && $status < 600)) {
                if ($attempt <= $maxRetries) {
                    $this->backoff($attempt, isset($r['headers']['retry-after']) ? (float) $r['headers']['retry-after'] : null);
                    continue;
                }
                $code = $status === 429 ? 'rate_limited' : ($status === 529 ? 'overloaded' : 'provider_error');
                Logger::warning("AI provider error {$status} after {$attempt} attempts");
                throw new AIException($code, $status === 429 ? 'The AI provider is rate limiting requests. Please try again shortly.' : 'The AI provider is temporarily unavailable. Please try again later.', true, $status, $attempt);
            }
            // 400: optional feature unsupported by this model/account -> drop it once and retry.
            if ($status === 400 && isset($payload['output_config'])) {
                foreach (['effort', 'format'] as $feature) {
                    if (isset($payload['output_config'][$feature]) && !in_array($feature, $downgraded, true) && stripos($apiMsg, $feature) !== false) {
                        unset($payload['output_config'][$feature]);
                        if (!$payload['output_config']) {
                            unset($payload['output_config']);
                        }
                        $downgraded[] = $feature;
                        Logger::warning("AI provider rejected output_config.{$feature}; retrying without it");
                        continue 2;
                    }
                }
            }
            Logger::error("AI request failed with HTTP {$status}: " . mb_substr($apiMsg, 0, 300));
            $safe = match (true) {
                $status === 401 || $status === 403 => ['auth_error', 'The AI provider rejected the server credentials. Contact the administrator.'],
                $status === 404 => ['model_not_found', 'The configured AI model is not available. Contact the administrator.'],
                $status === 413 => ['too_large', 'The request is too large for the AI provider. Try a smaller section.'],
                default => ['bad_request', 'The AI provider rejected the request.'],
            };
            throw new AIException($safe[0], $safe[1], false, $status, $attempt);
        }
    }

    private function backoff(int $attempt, ?float $retryAfter): void
    {
        $delay = $retryAfter !== null ? min(60.0, max(0.5, $retryAfter)) : min(30.0, (2 ** ($attempt - 1)) + mt_rand(0, 500) / 1000);
        ($this->sleeper)($delay);
    }
}
