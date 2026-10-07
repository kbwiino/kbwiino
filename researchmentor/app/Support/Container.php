<?php
declare(strict_types=1);

namespace App\Support;

/** Minimal service container: singletons via factories, overridable in tests. */
final class Container
{
    private static array $factories = [];
    private static array $instances = [];

    public static function bind(string $id, callable $factory): void
    {
        self::$factories[$id] = $factory;
        unset(self::$instances[$id]);
    }

    public static function instance(string $id, object $obj): void
    {
        self::$instances[$id] = $obj;
    }

    public static function has(string $id): bool
    {
        return isset(self::$instances[$id]) || isset(self::$factories[$id]);
    }

    public static function get(string $id): mixed
    {
        if (isset(self::$instances[$id])) {
            return self::$instances[$id];
        }
        if (isset(self::$factories[$id])) {
            return self::$instances[$id] = (self::$factories[$id])();
        }
        if (class_exists($id)) {
            return self::$instances[$id] = new $id();
        }
        throw new \RuntimeException("Service not bound: {$id}");
    }

    public static function reset(): void
    {
        self::$factories = [];
        self::$instances = [];
    }
}
