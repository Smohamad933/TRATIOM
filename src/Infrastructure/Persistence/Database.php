<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper. Supports MySQL (production) and SQLite (local development / tests).
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($this->driver() === 'sqlite') {
            $path = (string) $this->config['database'];
            if ($path !== ':memory:' && !is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            $this->pdo = new PDO('sqlite:' . $path, null, null, $options);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'] ?? '127.0.0.1',
            (int) ($this->config['port'] ?? 3306),
            $this->config['database'] ?? '',
            $this->config['charset'] ?? 'utf8mb4'
        );
        $this->pdo = new PDO($dsn, (string) ($this->config['username'] ?? ''), (string) ($this->config['password'] ?? ''), $options);
        $this->pdo->exec("SET time_zone = '+00:00'");
        return $this->pdo;
    }

    /** @param array<string|int, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string, mixed>|null */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): void
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(fn ($c) => ':' . $c, $cols))
        );
        $this->query($sql, $data);
    }

    /**
     * Appends "FOR UPDATE" on MySQL (row lock inside a transaction). SQLite locks the whole DB on write anyway.
     */
    public function forUpdate(): string
    {
        return $this->driver() === 'mysql' ? ' FOR UPDATE' : '';
    }

    /**
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $callback($this);
        }
        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
