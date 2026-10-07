<?php
declare(strict_types=1);

namespace App\Support;

/** File logger. Redacts anything that looks like an API key or password. */
final class Logger
{
    public static function error(string $message, array $context = []): void { self::write('ERROR', $message, $context); }
    public static function warning(string $message, array $context = []): void { self::write('WARNING', $message, $context); }
    public static function info(string $message, array $context = []): void { self::write('INFO', $message, $context); }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = Config::get('storage.log_path');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            $level,
            self::redact($message),
            $context ? self::redact(json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) : ''
        );
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function redact(string $text): string
    {
        $text = preg_replace('/sk-ant-[A-Za-z0-9_\-]{6,}/', 'sk-ant-[REDACTED]', $text) ?? $text;
        $text = preg_replace('/("?(?:password|api[_-]?key|x-api-key|authorization)"?\s*[:=]\s*)"?[^"\s,}]+/i', '$1[REDACTED]', $text) ?? $text;
        return $text;
    }
}
