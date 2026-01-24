<?php

declare(strict_types=1);

namespace Glueful\Database\Features;

use Glueful\Database\Features\Interfaces\PaginationBuilderInterface;
use Glueful\Database\Execution\Interfaces\QueryExecutorInterface;
use Glueful\Database\QueryLogger;

/**
 * PaginationBuilder
 *
 * Handles query pagination with optimized count queries and metadata.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class PaginationBuilder implements PaginationBuilderInterface
{
    protected QueryExecutorInterface $executor;
    protected QueryLogger $logger;

    public function __construct(QueryExecutorInterface $executor, QueryLogger $logger)
    {
        $this->executor = $executor;
        $this->logger = $logger;
    }

    /**
     * Execute paginated query
     *
     * @return array{
     *   data: list<array<string,mixed>>,
     *   current_page: int,
     *   per_page: int,
     *   total: int,
     *   last_page: int,
     *   has_more: bool,
     *   from: int,
     *   to: int,
     *   execution_time_ms: int
     * }
     */
    public function paginate(int $page = 1, int $perPage = 10): array
    {
        throw new \LogicException('This method requires the full SQL query and bindings. Use paginateQuery() instead.');
    }

    /**
     * Execute paginated query with SQL and bindings
     *
     * @param array<string, scalar|array|null> $bindings
     * @return array{
     *   data: list<array<string,mixed>>,
     *   current_page: int,
     *   per_page: int,
     *   total: int,
     *   last_page: int,
     *   has_more: bool,
     *   from: int,
     *   to: int,
     *   execution_time_ms: int
     * }
     */
    /**
     * @param array<string, mixed> $bindings
     */
    public function paginateQuery(string $sql, array $bindings, int $page = 1, int $perPage = 10): array
    {
        $timerId = $this->logger->startTiming();

        $this->logger->logEvent(
            "Executing paginated query",
            [
            'page' => $page,
            'per_page' => $perPage,
            'query' => substr($sql, 0, 100) . '...'
            ],
            'debug'
        );

        $offset = ($page - 1) * $perPage;

        // Apply pagination to the query
        $paginatedSql = $this->applyPagination($sql, $page, $perPage);

        // Execute paginated query
        $data = $this->executor->executeQuery($paginatedSql, array_merge($bindings, [$perPage, $offset]));

        // Get total count
        $total = $this->getTotalCount($sql, $bindings);

        $executionTime = $this->logger->endTiming($timerId);

        $meta = $this->getPaginationMeta($total, $page, $perPage);

        $this->logger->logEvent(
            "Pagination complete",
            [
            'total_records' => $total,
            'total_pages' => $meta['last_page'],
            'page' => $page,
            'record_count' => count($data),
            'execution_time_ms' => $executionTime
            ],
            'debug'
        );

        /** @var array{data: array<int, array<string, mixed>>, current_page: int, per_page: int, total: int, last_page: int, has_more: bool, from: int, to: int, execution_time_ms: int} */
        return array_merge(
            [
                'data' => $data,
            ],
            $meta,
            [
                'execution_time_ms' => $executionTime
            ]
        );
    }

    /**
     * Get total count for pagination
     *
     * @param array<string, mixed> $bindings
     */
    public function getTotalCount(string $sql, array $bindings): int
    {
        $countQuery = $this->buildCountQuery($sql);
        return $this->executor->executeCount($countQuery, $bindings);
    }

    /**
     * Build optimized count query
     *
     * For complex queries with subqueries, functions, or GROUP BY,
     * wraps the query as a subquery to ensure accurate counting.
     */
    public function buildCountQuery(string $originalQuery): string
    {
        // Remove ORDER BY and LIMIT as they don't affect count
        // Stops at LIMIT, OFFSET, FOR UPDATE, or end of query
        $orderByPattern = '/\sORDER\s+BY\s+.+?(?=\s*(?:LIMIT|OFFSET|FOR\s+UPDATE|$))/is';
        $cleanedQuery = preg_replace($orderByPattern, '', $originalQuery);

        // Handles: LIMIT 10, LIMIT ?, LIMIT 10 OFFSET 5, LIMIT 10, 20 (MySQL syntax), LIMIT ? OFFSET ?
        $limitPattern = '/\sLIMIT\s+(?:\d+|\?)\s*(?:,\s*(?:\d+|\?))?(?:\s+OFFSET\s+(?:\d+|\?))?$/is';
        $cleanedQuery = preg_replace($limitPattern, '', $cleanedQuery);

        // Check if this is a complex query that needs wrapping
        // Complex = has GROUP BY, subqueries in SELECT, or aggregate functions
        $isComplex = $this->isComplexQuery($cleanedQuery);

        if ($isComplex) {
            // Wrap the entire query and count its results
            return "SELECT COUNT(*) as total FROM ({$cleanedQuery}) as count_table";
        }

        // Simple query - find the main FROM and replace SELECT columns with COUNT(*)
        $mainFromPos = $this->findMainFromPosition($cleanedQuery);

        if ($mainFromPos !== false) {
            return "SELECT COUNT(*) as total" . substr($cleanedQuery, $mainFromPos);
        }

        // Fallback: wrap as subquery
        return "SELECT COUNT(*) as total FROM ({$cleanedQuery}) as count_table";
    }

    /**
     * Check if query is complex (has subqueries, GROUP BY, UNION, window functions, CTEs, etc.)
     */
    private function isComplexQuery(string $query): bool
    {
        // Has GROUP BY
        if (stripos($query, 'GROUP BY') !== false) {
            return true;
        }

        // Has DISTINCT
        if (preg_match('/SELECT\s+DISTINCT/i', $query)) {
            return true;
        }

        // Has UNION (UNION, UNION ALL, INTERSECT, EXCEPT)
        if (preg_match('/\b(UNION|INTERSECT|EXCEPT)\b/i', $query)) {
            return true;
        }

        // Has CTE (WITH ... AS)
        if (preg_match('/^\s*WITH\s+\w+\s+AS\s*\(/i', $query)) {
            return true;
        }

        // Has window functions (OVER clause)
        if (preg_match('/\bOVER\s*\(/i', $query)) {
            return true;
        }

        // Check for subqueries or function calls in SELECT clause by finding parentheses
        // before the main FROM
        $mainFromPos = $this->findMainFromPosition($query);
        if ($mainFromPos !== false) {
            $selectClause = substr($query, 0, $mainFromPos);
            // If there are parentheses in SELECT clause, it's complex
            if (strpos($selectClause, '(') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the position of the main FROM clause (not inside parentheses)
     *
     * @return int|false Position of " FROM" or false if not found
     */
    private function findMainFromPosition(string $query): int|false
    {
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $len = strlen($query);

        for ($i = 0; $i < $len - 4; $i++) {
            $char = $query[$i];

            // Handle string literals
            if (($char === "'" || $char === '"') && ($i === 0 || $query[$i - 1] !== '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                }
                continue;
            }

            if ($inString) {
                continue;
            }

            // Track parentheses depth
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            // Look for FROM at depth 0 (main query level)
            if ($depth === 0) {
                $word = strtoupper(substr($query, $i, 5));
                if ($word === ' FROM') {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * Apply limit and offset to query
     */
    public function applyPagination(string $sql, int $page, int $perPage): string
    {
        // Remove existing LIMIT/OFFSET before adding a new one
        // Handles: LIMIT 10, LIMIT ?, LIMIT 10 OFFSET 5, LIMIT 10, 20 (MySQL syntax), LIMIT ? OFFSET ?
        $limitPattern = '/\sLIMIT\s+(?:\d+|\?)\s*(?:,\s*(?:\d+|\?))?(?:\s+OFFSET\s+(?:\d+|\?))?/i';
        $paginatedQuery = preg_replace($limitPattern, '', $sql);
        $paginatedQuery .= " LIMIT ? OFFSET ?";

        return $paginatedQuery;
    }

    /**
     * Get pagination metadata
     *
     * @return array{
     *   current_page: int,
     *   per_page: int,
     *   total: int,
     *   last_page: int,
     *   has_more: bool,
     *   from: int,
     *   to: int
     * }
     */
    public function getPaginationMeta(int $total, int $page, int $perPage): array
    {
        $lastPage = (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $from = $total > 0 ? $offset + 1 : 0;
        $to = min($offset + $perPage, $total);

        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Validate pagination parameters
     */
    public function validatePaginationParams(int $page, int $perPage): void
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('Page number must be greater than 0');
        }

        if ($perPage < 1) {
            throw new \InvalidArgumentException('Per page count must be greater than 0');
        }

        if ($perPage > 1000) {
            throw new \InvalidArgumentException('Per page count cannot exceed 1000');
        }
    }

    /**
     * Calculate pagination bounds with validation
     *
     * @return array{limit: int, offset: int}
     */
    public function calculateBounds(int $page, int $perPage): array
    {
        $this->validatePaginationParams($page, $perPage);

        $offset = ($page - 1) * $perPage;

        return [
            'limit' => $perPage,
            'offset' => $offset
        ];
    }
}
