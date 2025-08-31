<?php

declare(strict_types=1);

namespace Glueful\Database\Features\Interfaces;

/**
 * PaginationBuilder Interface
 *
 * Defines the contract for pagination functionality.
 * This interface ensures consistent pagination handling
 * across different implementations.
 */
interface PaginationBuilderInterface
{
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
    public function paginate(int $page = 1, int $perPage = 10): array;

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
    public function paginateQuery(string $sql, array $bindings, int $page = 1, int $perPage = 10): array;

    /**
     * Get total count for pagination
     */
    public function getTotalCount(string $sql, array $bindings): int;

    /**
     * Build optimized count query
     */
    public function buildCountQuery(string $originalQuery): string;

    /**
     * Apply limit and offset to query
     */
    public function applyPagination(string $sql, int $page, int $perPage): string;

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
    public function getPaginationMeta(int $total, int $page, int $perPage): array;
}
