<?php

declare(strict_types=1);

namespace BCashPay;

use PDO;
use PDOStatement;
use PDOException;
use RuntimeException;

/**
 * Lightweight PDO wrapper for B-Pay.
 *
 * Provides a singleton connection and convenience helpers that map directly
 * to the SQL patterns used throughout the application.
 *
 * Usage:
 *   $db = Database::getInstance();
 *   $row = $db->fetchOne('SELECT * FROM api_clients WHERE api_key = ?', [$key]);
 */
class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $driver = env('DB_DRIVER', 'sqlite');

        if ($driver === 'sqlite') {
            $sqlitePath = env('DB_SQLITE_PATH', dirname(__DIR__) . '/database/bcashpay.sqlite');
            // Resolve relative path from the api directory
            if (!str_starts_with($sqlitePath, '/')) {
                $sqlitePath = dirname(__DIR__) . '/' . ltrim($sqlitePath, './');
            }
            $dsn     = 'sqlite:' . $sqlitePath;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            try {
                $this->pdo = new PDO($dsn, null, null, $options);
                $this->pdo->exec('PRAGMA journal_mode = WAL');
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            } catch (PDOException $e) {
                throw new RuntimeException('SQLite connection failed: ' . $e->getMessage());
            }
        } else {
            // MySQL / MariaDB
            $host     = config('database.host');
            $port     = config('database.port');
            $dbname   = config('database.database');
            $username = config('database.username');
            $password = config('database.password');
            $options  = config('database.options');

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            try {
                $this->pdo = new PDO($dsn, $username, $password, $options);
            } catch (PDOException $e) {
                // Do not expose DB credentials in error messages
                throw new RuntimeException('Database connection failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get or create the singleton PDO connection.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Execute a query and return the PDOStatement.
     *
     * @param list<mixed> $params
     * @throws RuntimeException on query failure
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new RuntimeException('Query failed: ' . $e->getMessage());
        }
    }

    /**
     * Insert a row and return the last insert ID.
     *
     * @param array<string, mixed> $data Column => value map
     */
    public function insert(string $table, array $data): string|false
    {
        $columns      = implode(', ', array_map(fn($c) => "\"{$c}\"", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql          = "INSERT INTO \"{$table}\" ({$columns}) VALUES ({$placeholders})";

        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    /**
     * Update rows matching $where and return affected row count.
     *
     * @param array<string, mixed> $data  Column => value to set
     * @param array<string, mixed> $where Column => value conditions (AND-joined)
     */
    public function update(string $table, array $data, array $where): int
    {
        $setClause   = implode(', ', array_map(fn($c) => "\"{$c}\" = ?", array_keys($data)));
        $whereClause = implode(' AND ', array_map(fn($c) => "\"{$c}\" = ?", array_keys($where)));
        $sql         = "UPDATE \"{$table}\" SET {$setClause} WHERE {$whereClause}";

        $stmt = $this->query($sql, [...array_values($data), ...array_values($where)]);
        return $stmt->rowCount();
    }

    /**
     * Fetch a single row or null if not found.
     *
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $row  = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Fetch all matching rows.
     *
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Execute a callable inside a MySQL transaction with automatic rollback.
     *
     * @throws \Throwable on any exception (rolls back and re-throws)
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Return the underlying PDO for raw access (e.g. lastInsertId after INSERT).
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
