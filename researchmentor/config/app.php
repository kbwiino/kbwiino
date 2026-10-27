<?php
use App\Support\Env;

return [
    'name' => 'ResearchMentor AI',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => Env::get('APP_URL', 'http://localhost:8080'),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
    'queue_driver' => Env::get('QUEUE_DRIVER', 'sync'),
    'default_retention_days' => Env::int('DEFAULT_RETENTION_DAYS', 1825),
];
