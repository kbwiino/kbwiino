<?php
use App\Support\Env;

return [
    'enabled' => Env::bool('AI_ENABLED', true),
    'provider' => Env::get('AI_PROVIDER', 'claude'),
    'api_key' => Env::get('ANTHROPIC_API_KEY', ''),
    'model' => Env::get('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
    'api_version' => Env::get('ANTHROPIC_API_VERSION', '2023-06-01'),
    'base_url' => rtrim(Env::get('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),
    'max_tokens' => Env::int('AI_MAX_TOKENS', 8000),
    'timeout' => Env::int('AI_TIMEOUT', 120),
    'max_retries' => Env::int('AI_MAX_RETRIES', 3),
    'max_input_chars' => Env::int('AI_MAX_INPUT_CHARS', 120000),
    'rate_limit_per_hour' => Env::int('AI_RATE_LIMIT_PER_HOUR', 30),
];
