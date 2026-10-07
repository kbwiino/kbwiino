<?php
declare(strict_types=1);

if (!function_exists('e')) {
    /** HTML-escape output. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
