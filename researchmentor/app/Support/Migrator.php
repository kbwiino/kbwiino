<?php
declare(strict_types=1);

namespace App\Support;

final class Migrator
{
    public function __construct(private ?string $dir = null)
    {
        $this->dir ??= Config::basePath('database/migrations');
    }

    private function ensureTable(): void
    {
        Database::execute('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return array<string,bool> filename => applied? */
    public function status(): array
    {
        $this->ensureTable();
        $applied = array_column(Database::fetchAll('SELECT version FROM schema_migrations'), 'version');
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $f) {
            $out[basename($f)] = in_array(basename($f), $applied, true);
        }
        return $out;
    }

    /** @return string[] names of newly applied migrations */
    public function migrate(): array
    {
        $ran = [];
        foreach ($this->status() as $name => $applied) {
            if ($applied) {
                continue;
            }
            $sql = file_get_contents($this->dir . '/' . $name);
            // DDL auto-commits in MySQL, so no transaction wrapper; each file is idempotent-by-record.
            foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '') {
                    Database::execute($stmt);
                }
            }
            Database::execute('INSERT INTO schema_migrations (version) VALUES (?)', [$name]);
            $ran[] = $name;
        }
        return $ran;
    }

    /** Drops every table. Refused in production. */
    public function fresh(): void
    {
        if (Config::get('app.env') === 'production') {
            throw new \RuntimeException('Refusing to drop tables in production.');
        }
        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (Database::fetchAll('SHOW TABLES') as $row) {
            $pdo->exec('DROP TABLE IF EXISTS `' . array_values($row)[0] . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
