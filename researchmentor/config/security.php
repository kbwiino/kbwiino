<?php
use App\Support\Env;

return [
    'session_name' => Env::get('SESSION_NAME', 'rm_session'),
    'session_lifetime' => Env::int('SESSION_LIFETIME_MINUTES', 120),
    'secure_cookie' => Env::bool('SESSION_SECURE_COOKIE', true),
    'login_max_attempts' => Env::int('LOGIN_MAX_ATTEMPTS', 5),
    'login_lockout_minutes' => Env::int('LOGIN_LOCKOUT_MINUTES', 15),
    'password_min_length' => 10,
];
