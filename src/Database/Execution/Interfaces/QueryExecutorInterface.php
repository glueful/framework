<?php

declare(strict_types=1);

namespace Glueful\Database\Execution\Interfaces;

use PDOStatement;

/**
 * QueryExecutor Interface
 *
 * Defines the contract for query execution functionality.
 * This interface ensures consistent query execution across
 * different implementations.
 */
interface QueryExecutorInterface
{
    /**
     * Execute a SELECT query and return all results
     *
     * @param array<int|string, mixed> $bindings
     * @param int|null $cacheTtl Per-query cache TTL override
     * @param array<int, string> $cacheTags Per-query invalidation tags
     * @param bool $useCache Force caching for this call even if the global flag is off
     * @return array<int, array<string, mixed>>
     */
    public function executeQuery(
        string $sql,
        array $bindings = [],
        ?int $cacheTtl = null,
        array $cacheTags = [],
        bool $useCache = false
    ): array;

    /**
     * Execute a SELECT query and return first result
     *
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function executeFirst(string $sql, array $bindings = []): ?array;

    /**
     * Execute a modification query (INSERT, UPDATE, DELETE)
     *
     * @param array<int|string, mixed> $bindings
     */
    public function executeModification(string $sql, array $bindings = []): int;

    /**
     * Execute a COUNT query
     *
     * @param array<int|string, mixed> $bindings
     */
    public function executeCount(string $sql, array $bindings = []): int;

    /**
     * Execute query and return PDO statement
     *
     * @param array<int|string, mixed> $bindings
     */
    public function executeStatement(string $sql, array $bindings = []): PDOStatement;

    /**
     * Check if caching is enabled for this executor
     */
    public function isCacheEnabled(): bool;

    /**
     * Enable query result caching
     */
    public function enableCache(?int $ttl = null): void;

    /**
     * Disable query result caching
     */
    public function disableCache(): void;

    /**
     * Set business purpose for queries
     */
    public function withPurpose(string $purpose): void;

    /**
     * Get the underlying PDO driver name (e.g. 'mysql', 'pgsql', 'sqlite').
     *
     * Used by callers that need to vary SQL by driver — for example,
     * `EXPLAIN` vs `EXPLAIN QUERY PLAN` on SQLite.
     */
    public function getDriverName(): string;
}
