<?php

declare(strict_types=1);

namespace Glueful\Database\Execution;

use PDO;
use PDOStatement;
use PDOException;
use Glueful\Database\Execution\Interfaces\QueryExecutorInterface;
use Glueful\Database\Execution\Interfaces\ParameterBinderInterface;
use Glueful\Database\QueryLogger;
use Glueful\Database\QueryCacheService;

/**
 * QueryExecutor
 *
 * Handles query execution with caching, logging, and error handling.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class QueryExecutor implements QueryExecutorInterface
{
    protected PDO $pdo;
    protected ParameterBinderInterface $binder;
    protected QueryLogger $logger;
    protected ?QueryCacheService $cache = null;
    protected bool $cacheEnabled = false;
    protected ?int $cacheTtl = null;
    protected ?string $queryPurpose = null;
    protected bool $debugMode = false;

    public function __construct(
        PDO $pdo,
        ParameterBinderInterface $binder,
        QueryLogger $logger
    ) {
        $this->pdo = $pdo;
        $this->binder = $binder;
        $this->logger = $logger;
    }

    /**
     * Execute a SELECT query and return all results
     *
     * @param array<mixed> $bindings
     * @param int|null $cacheTtl   Per-query cache TTL (overrides the executor default for this call)
     * @param array<int, string> $cacheTags Per-query invalidation tags (from QueryBuilder::cache(ttl, tags))
     * @param bool $useCache        Force caching for this call even if the global cache flag is off
     * @return array<int, array<string, mixed>>
     */
    public function executeQuery(
        string $sql,
        array $bindings = [],
        ?int $cacheTtl = null,
        array $cacheTags = [],
        bool $useCache = false
    ): array {
        // Cache when the per-query flag is set or the executor has caching enabled globally.
        $cache = ($useCache || $this->cacheEnabled) ? $this->resolveCacheService() : null;
        if ($cache !== null) {
            return $cache->getOrExecute(
                $sql,
                $bindings,
                function () use ($sql, $bindings) {
                    $stmt = $this->executeStatement($sql, $bindings);
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                },
                $cacheTtl ?? $this->cacheTtl,
                $cacheTags
            );
        }

        // Execute without caching
        $stmt = $this->executeStatement($sql, $bindings);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lazily resolve a cache service when per-query caching is requested.
     * Returns null (and leaves caching off) if no cache backend can be created,
     * so a missing cache config degrades to uncached execution rather than erroring.
     */
    private function resolveCacheService(): ?QueryCacheService
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        try {
            $this->cache = new QueryCacheService();
        } catch (\Throwable $e) {
            $this->cache = null;
        }
        return $this->cache;
    }

    /**
     * Execute a SELECT query and return first result
     */
    public function executeFirst(string $sql, array $bindings = []): ?array
    {
        // Optimize by adding LIMIT 1 if not present
        if (stripos($sql, 'LIMIT') === false) {
            $sql .= ' LIMIT 1';
        }

        // Use cache if enabled
        if ($this->cacheEnabled && $this->cache !== null) {
            return $this->cache->getOrExecute(
                $sql,
                $bindings,
                function () use ($sql, $bindings) {
                    $stmt = $this->executeStatement($sql, $bindings);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result !== false ? $result : null;
                },
                $this->cacheTtl
            );
        }

        // Execute without caching
        $stmt = $this->executeStatement($sql, $bindings);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    /**
     * Execute a modification query (INSERT, UPDATE, DELETE)
     */
    public function executeModification(string $sql, array $bindings = []): int
    {
        $stmt = $this->executeStatement($sql, $bindings);
        return $stmt->rowCount();
    }

    /**
     * Execute a COUNT query
     */
    public function executeCount(string $sql, array $bindings = []): int
    {
        // Use cache if enabled
        if ($this->cacheEnabled && $this->cache !== null) {
            return $this->cache->getOrExecute(
                $sql,
                $bindings,
                function () use ($sql, $bindings) {
                    $stmt = $this->executeStatement($sql, $bindings);
                    return (int) $stmt->fetchColumn();
                },
                $this->cacheTtl
            );
        }

        // Execute without caching
        $stmt = $this->executeStatement($sql, $bindings);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Execute query and return PDO statement
     */
    public function executeStatement(string $sql, array $bindings = []): PDOStatement
    {
        // Start timing the query
        $timerId = $this->logger->startTiming($this->debugMode ? 'query_with_debug' : 'query');

        // Capture current purpose and reset it
        $purpose = $this->queryPurpose;
        $this->queryPurpose = null;

        // Flatten bindings to prevent array to string conversion warnings
        $flattenedParams = $this->binder->flattenBindings($bindings);

        try {
            $stmt = $this->pdo->prepare($sql);

            if (!$stmt) {
                throw new PDOException('Failed to prepare statement');
            }

            $stmt->execute($flattenedParams);

            // Log successful query with purpose
            $sanitizedBindings = $this->binder->sanitizeBindingsForLog($flattenedParams);
            $this->logger->logQuery($sql, $sanitizedBindings, $timerId, null, $purpose);

            return $stmt;
        } catch (PDOException $e) {
            // Log failed query with purpose
            $sanitizedBindings = $this->binder->sanitizeBindingsForLog($flattenedParams);
            $this->logger->logQuery($sql, $sanitizedBindings, $timerId, $e, $purpose);
            throw $e;
        }
    }

    /**
     * Check if caching is enabled for this executor
     */
    public function isCacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    /**
     * Enable query result caching
     */
    public function enableCache(?int $ttl = null): void
    {
        $this->cacheEnabled = true;
        $this->cacheTtl = $ttl;

        if ($this->cache === null) {
            $this->cache = new QueryCacheService();
        }

        if ($this->debugMode) {
            $this->logger->logEvent(
                'Query caching enabled',
                [
                'ttl' => $ttl ?? 'default',
                ],
                'debug'
            );
        }
    }

    /**
     * Disable query result caching
     */
    public function disableCache(): void
    {
        $this->cacheEnabled = false;

        if ($this->debugMode) {
            $this->logger->logEvent('Query caching disabled', [], 'debug');
        }
    }

    /**
     * Set business purpose for queries
     */
    public function withPurpose(string $purpose): void
    {
        $this->queryPurpose = $purpose;
    }

    /**
     * Set cache service instance
     */
    public function setCacheService(QueryCacheService $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * Enable or disable debug mode
     */
    public function setDebugMode(bool $debug): void
    {
        $this->debugMode = $debug;
    }

    /**
     * Get debug mode status
     */
    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    public function getDriverName(): string
    {
        $name = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return is_string($name) ? $name : '';
    }
}
