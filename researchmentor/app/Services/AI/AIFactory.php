<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Support\{Config, Container};

final class AIFactory
{
    public static function make(): AIServiceInterface
    {
        if (Container::has('ai.service')) {
            return Container::get('ai.service');
        }
        $provider = (string) Config::get('ai.provider');
        $hasKey = trim((string) Config::get('ai.api_key')) !== '';
        $env = Config::get('app.env');
        // The mock is a development substitute and is never used in production.
        if ($provider === 'mock' && $env !== 'production') {
            return new MockAIService();
        }
        if ($provider === 'auto' && !$hasKey && $env !== 'production') {
            return new MockAIService();
        }
        return new ClaudeAIService(); // availability check reports a missing key
    }
}
