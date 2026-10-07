<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Uuid;

/**
 * Lightweight table gateway. Rows are plain associative arrays.
 * Only columns that really exist on the table can be written (mass-assignment whitelist).
 */
abstract class Model
{
    protected static string $table;
    private static array $columnCache = [];

    public static function table(): string
    {
        return static::$table;
    }

    public static function columns(): array
    {
        $t = static::$table;
        if (!isset(self::$columnCache[$t])) {
            self::$columnCache[$t] = array_column(Database::fetchAll("SHOW COLUMNS FROM `{$t}`"), 'Field');
        }
        return self::$columnCache[$t];
    }

    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM `' . static::$table . '` WHERE id = ?', [$id]);
    }

    public static function findByUuid(string $uuid): ?array
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }
        return Database::fetchOne('SELECT * FROM `' . static::$table . '` WHERE uuid = ?', [$uuid]);
    }

    public static function findOrFail(int $id): array
    {
        return static::find($id) ?? throw \App\Support\HttpException::notFound(static::class);
    }

    public static function where(array $conditions, string $orderBy = 'id ASC', ?int $limit = null): array
    {
        [$sql, $params] = static::buildWhere($conditions);
        // $orderBy is always a developer-written constant, never request input. Defence in depth: reject anything that
        // could start a new statement or comment.
        $order = (preg_match('/^[A-Za-z0-9_.,() \'\-]+$/', $orderBy) && !str_contains($orderBy, '--')) ? $orderBy : 'id ASC';
        $q = 'SELECT * FROM `' . static::$table . '`' . $sql . ' ORDER BY ' . $order . ($limit ? ' LIMIT ' . (int) $limit : '');
        return Database::fetchAll($q, $params);
    }

    public static function first(array $conditions): ?array
    {
        $rows = static::where($conditions, 'id ASC', 1);
        return $rows[0] ?? null;
    }

    public static function count(array $conditions = []): int
    {
        [$sql, $params] = static::buildWhere($conditions);
        return (int) Database::fetchValue('SELECT COUNT(*) FROM `' . static::$table . '`' . $sql, $params);
    }

    public static function create(array $data): int
    {
        $cols = static::columns();
        if (in_array('uuid', $cols, true) && empty($data['uuid'])) {
            $data['uuid'] = Uuid::v4();
        }
        $data = array_intersect_key($data, array_flip($cols));
        unset($data['id']);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return Database::insert(static::$table, $data);
    }

    public static function updateById(int $id, array $data): int
    {
        $data = array_intersect_key($data, array_flip(static::columns()));
        unset($data['id'], $data['uuid']);
        if (!$data) {
            return 0;
        }
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return Database::update(static::$table, $data, ['id' => $id]);
    }

    public static function deleteById(int $id): int
    {
        return Database::delete(static::$table, ['id' => $id]);
    }

    /** @return array{0:string,1:array} */
    protected static function buildWhere(array $conditions): array
    {
        if (!$conditions) {
            return ['', []];
        }
        $parts = [];
        $params = [];
        foreach ($conditions as $col => $val) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $col)) {
                throw new \InvalidArgumentException('Bad column name');
            }
            if ($val === null) {
                $parts[] = "`$col` IS NULL";
            } elseif (is_array($val)) {
                if (!$val) {
                    $parts[] = '1 = 0';
                    continue;
                }
                $parts[] = "`$col` IN (" . Database::placeholders($val) . ')';
                array_push($params, ...array_values($val));
            } else {
                $parts[] = "`$col` = ?";
                $params[] = $val;
            }
        }
        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    /** Decode JSON columns in a row. */
    public static function decodeJson(array $row, array $columns): array
    {
        foreach ($columns as $c) {
            if (array_key_exists($c, $row) && is_string($row[$c])) {
                $row[$c] = json_decode($row[$c], true);
            }
        }
        return $row;
    }
}
