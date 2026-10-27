<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Services\AI\{AIException, HttpTransport};

/** Scripted HTTP transport: queue of responses/exceptions, or a responder callable. Records every request. */
final class FakeTransport implements HttpTransport
{
    /** @var array<int,array{url:string,headers:array,body:array}> */
    public array $requests = [];
    private array $queue = [];
    /** @var callable|null */
    private $responder;

    public function __construct(?callable $responder = null)
    {
        $this->responder = $responder;
    }

    public function push(int|\Throwable $statusOrError, mixed $body = null, array $headers = []): self
    {
        $this->queue[] = $statusOrError instanceof \Throwable ? $statusOrError : ['status' => $statusOrError, 'headers' => $headers, 'body' => is_string($body) ? $body : json_encode($body)];
        return $this;
    }

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $payload = json_decode($body, true);
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $payload, 'timeout' => $timeoutSeconds];
        if ($this->queue) {
            $next = array_shift($this->queue);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return $next;
        }
        if ($this->responder) {
            return ($this->responder)($payload);
        }
        throw new \LogicException('FakeTransport queue exhausted');
    }

    public static function message(array|string $content, string $stop = 'end_turn', int $in = 120, int $out = 60): array
    {
        $text = is_array($content) ? json_encode($content) : $content;
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => $stop, 'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
        ];
    }

    public static function ok(array|string $content, string $stop = 'end_turn'): array
    {
        return ['status' => 200, 'headers' => ['request-id' => 'req_test'], 'body' => json_encode(self::message($content, $stop))];
    }

    public static function review(string $type = 'proposal', array $over = []): array
    {
        return $over + [
            'review_type' => $type, 'summary' => 'Real-looking summary.', 'strengths' => ['Clear topic'],
            'major_issues' => [[
                'issue_code' => 'PROP-001', 'severity' => 'high', 'section' => 'Problem', 'page' => 1, 'location' => 'Section 1.2',
                'issue' => 'Problem statement lacks evidence.', 'evidence' => 'Rural households save little.', 'implication' => 'Weak justification.',
                'recommendation' => 'Add data.', 'supervisor_question' => 'What data supports this?', 'requires_human_verification' => true,
            ]],
            'minor_issues' => [], 'action_items' => [['title' => 'Add statistics', 'description' => 'Cite national survey', 'owner' => 'student', 'priority' => 'medium']],
            'uncertainties' => [], 'source_references' => [],
        ];
    }
}
