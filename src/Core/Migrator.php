<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Applies the numbered .sql files in migrations/ in filename order and
 * records each one in the schema_migrations table, so every file runs once.
 *
 * MySQL commits DDL implicitly, so a migration cannot be rolled back. Write
 * migrations to be safe to re-run (e.g. MODIFY COLUMN, CREATE TABLE IF NOT EXISTS):
 * if one fails part-way it is not recorded and will be retried.
 */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return list<array{name:string, applied_at:?string}> every migration file with its status */
    public function status(): array
    {
        $applied = $this->applied();
        $out = [];
        foreach ($this->files() as $name) {
            $out[] = ['name' => $name, 'applied_at' => $applied[$name] ?? null];
        }
        return $out;
    }

    /** @return list<string> names of migrations not yet applied */
    public function pending(): array
    {
        return array_column(array_filter($this->status(), static fn(array $m): bool => $m['applied_at'] === null), 'name');
    }

    /**
     * Apply all pending migrations in order, stopping at the first failure.
     *
     * @param list<string> $done filled with the names that succeeded (also on failure)
     * @throws \RuntimeException naming the migration that failed
     */
    public function run(array &$done = []): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration  VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME     NOT NULL
            )'
        );

        foreach ($this->pending() as $name) {
            $sql = file_get_contents($this->dir . '/' . $name);
            if ($sql === false) {
                throw new \RuntimeException("{$name}: file could not be read.");
            }
            try {
                foreach (self::splitStatements($sql) as $statement) {
                    $this->db->pdo()->exec($statement); // text protocol: DDL is fine here
                }
                $this->db->execute(
                    'INSERT INTO schema_migrations (migration, applied_at) VALUES (?, ?)',
                    [$name, date('Y-m-d H:i:s')]
                );
            } catch (\PDOException $e) {
                throw new \RuntimeException("{$name}: " . $e->getMessage(), 0, $e);
            }
            $done[] = $name;
        }
    }

    /** @return array<string,string> name => applied_at */
    private function applied(): array
    {
        try {
            $rows = $this->db->all('SELECT migration, applied_at FROM schema_migrations');
        } catch (\PDOException) {
            return []; // table not created yet: nothing has been applied
        }
        return array_column($rows, 'applied_at', 'migration');
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = array_map('basename', glob($this->dir . '/*.sql') ?: []);
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * Split a SQL script on ";" while ignoring semicolons inside quotes and
     * comments (-- , #, and block comments). Plain scripts only: no DELIMITER support.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($c === "'" || $c === '"' || $c === '`') {
                $end = $i + 1;
                while ($end < $len) {
                    if ($sql[$end] === '\\' && $c !== '`') {
                        $end += 2;
                        continue;
                    }
                    if ($sql[$end] === $c) {
                        if (($sql[$end + 1] ?? '') === $c) { // doubled quote = literal quote
                            $end += 2;
                            continue;
                        }
                        break;
                    }
                    $end++;
                }
                $current .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($c === '#' || ($c === '-' && $next === '-')) {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl; // drop the comment, keep the newline
                $current .= "\n";
            } elseif ($c === '/' && $next === '*') {
                $close = strpos($sql, '*/', $i + 2);
                $i = $close === false ? $len : $close + 1;
                $current .= ' ';
            } elseif ($c === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $c;
            }
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
