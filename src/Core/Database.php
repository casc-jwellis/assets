<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin lazy PDO wrapper. All queries go through prepared statements with
 * bound parameters; never concatenate user input into SQL.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = $this->config->get('db.dsn') ?: sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config->get('db.host', 'localhost'),
                (int) $this->config->get('db.port', 3306),
                $this->config->get('db.name', 'assets'),
                $this->config->get('db.charset', 'utf8mb4')
            );
            $this->pdo = new PDO(
                $dsn,
                $this->config->get('db.user'),
                $this->config->get('db.pass'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false, // real server-side prepares
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        }
        return $this->pdo;
    }

    /** @param list<mixed> $params positional parameters */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Returns the number of affected rows. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }
}
