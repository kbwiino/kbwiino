<?php
declare(strict_types=1);

namespace App\Support;

final class Config
{
    private static array $cache = [];
    private static array $overrides = [];

    public static function basePath(string $suffix = ''): string
    {
        return dirname(__DIR__, 2) . ($suffix !== '' ? '/' . ltrim($suffix, '/') : '');
    }

    /** Dot-notation access: Config::get('ai.model'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }
        $parts = explode('.', $key);
        $file = array_shift($parts);
        if (!isset(self::$cache[$file])) {
            $path = self::basePath("config/{$file}.php");
            self::$cache[$file] = is_file($path) ? require $path : [];
        }
        $value = self::$cache[$file];
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$overrides[$key] = $value;
    }

    public static function reset(): void
    {
        self::$cache = [];
        self::$overrides = [];
    }
}
