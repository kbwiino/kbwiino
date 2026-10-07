<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOStatement;

/** Thin PDO wrapper: prepared statements only, UTC session, exceptions on error. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('database');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset']);
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ]);
        }
        return self::$pdo;
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        foreach (array_values($params) === $params ? array_values($params) : $params as $k => $v) {
            $name = is_int($k) ? $k + 1 : (str_starts_with((string) $k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($v) ? (int) $v : $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $row = self::query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        self::assertIdentifier($table);
        $cols = array_keys($data);
        array_map([self::class, 'assertIdentifier'], $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::connection()->lastInsertId();
    }

    public static function update(string $table, array $data, array $where): int
    {
        self::assertIdentifier($table);
        $sets = [];
        $params = [];
        foreach ($data as $c => $v) {
            self::assertIdentifier($c);
            $sets[] = "`$c` = ?";
            $params[] = $v;
        }
        $conds = [];
        foreach ($where as $c => $v) {
            self::assertIdentifier($c);
            $conds[] = "`$c` = ?";
            $params[] = $v;
        }
        return self::execute(sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), implode(' AND ', $conds)), $params);
    }

    public static function delete(string $table, array $where): int
    {
        self::assertIdentifier($table);
        $conds = [];
        $params = [];
        foreach ($where as $c => $v) {
            self::assertIdentifier($c);
            $conds[] = "`$c` = ?";
            $params[] = $v;
        }
        return self::execute(sprintf('DELETE FROM `%s` WHERE %s', $table, implode(' AND ', $conds)), $params);
    }

    /** Run $fn inside a transaction (nested calls join the outer transaction). */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Placeholders for IN (...) lists: "?,?,?" */
    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Illegal SQL identifier: {$name}");
        }
    }
}
