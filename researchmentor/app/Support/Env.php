<?php
declare(strict_types=1);

namespace App\Support;

final class Env
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        self::$loaded = true;
        if (!is_readable($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strrpos($value, $quote);
                $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
            } else {
                // strip inline comment
                $value = trim(preg_replace('/\s+#.*$/', '', $value));
            }
            // real environment (e.g. phpunit.xml, Apache SetEnv) wins over .env
            if (getenv($key) === false && !isset($_ENV[$key])) {
                self::$values[$key] = $value;
            }
        }
    }

    public static function set(string $key, ?string $value): void
    {
        if ($value === null) {
            unset(self::$values[$key]);
            return;
        }
        self::$values[$key] = $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        $env = $_ENV[$key] ?? getenv($key);
        if ($env !== false && $env !== null) {
            return (string) $env;
        }
        return $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return ($v === null || $v === '' || !is_numeric($v)) ? $default : (int) $v;
    }
}
