<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * QueryBuilder Interface
 *
 * Defines the contract for the main QueryBuilder class.
 * This interface ensures consistent query building functionality
 * across different implementations.
 */
interface QueryBuilderInterface
{
    /**
     * Set the primary table for the query
     *
     * Specifies the primary table that will be used for the SELECT, UPDATE, or DELETE operation.
     * This method validates the table name and sets up the query state for subsequent operations.
     *
     * @param  string $table The name of the table to query
     * @return static Returns the QueryBuilder instance for method chaining
     * @throws \InvalidArgumentException If table name is invalid or contains unsafe characters
     */
    public function from(string $table);

    /**
     * Set columns to select
     *
     * @param array<string|\Glueful\Database\RawExpression> $columns
     */
    public function select(array $columns = ['*']): static;

    /**
     * Add WHERE condition
     *
     * @param string|array<string,mixed>|callable $column
     * @param mixed $operator
     * @param mixed $value
     */
    public function where($column, $operator = null, $value = null): static;

    /**
     * Add OR WHERE condition
     *
     * @param string|array<string,mixed>|callable $column
     * @param mixed $operator
     * @param mixed $value
     */
    public function orWhere($column, $operator = null, $value = null): static;

    /**
     * Add WHERE IN condition
     *
     * @param array<mixed> $values
     */
    public function whereIn(string $column, array $values): static;

    /**
     * Add WHERE NOT IN condition
     *
     * @param array<mixed> $values
     */
    public function whereNotIn(string $column, array $values): static;

    /**
     * Add WHERE NULL condition
     */
    public function whereNull(string $column): static;

    /**
     * Add WHERE NOT NULL condition
     */
    public function whereNotNull(string $column): static;

    /**
     * Add OR WHERE NULL condition
     */
    public function orWhereNull(string $column): static;

    /**
     * Add OR WHERE NOT NULL condition
     */
    public function orWhereNotNull(string $column): static;

    /**
     * Add WHERE BETWEEN condition
     *
     * @param mixed $min
     * @param mixed $max
     */
    public function whereBetween(string $column, $min, $max): static;

    /**
     * Add WHERE LIKE condition
     */
    public function whereLike(string $column, string $pattern): static;

    /**
     * Add raw WHERE condition
     *
     * @param array<mixed> $bindings
     */
    public function whereRaw(string $condition, array $bindings = []): static;

    /**
     * Add JSON contains WHERE condition (database-agnostic)
     */
    public function whereJsonContains(string $column, string $searchValue, ?string $path = null): static;

    /**
     * Add JOIN clause
     */
    public function join(
        string $table,
        string $first,
        string $operator,
        string $second,
        string $type = 'INNER'
    ): static;

    /**
     * Add LEFT JOIN clause
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static;

    /**
     * Add RIGHT JOIN clause
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): static;

    /**
     * Add GROUP BY clause
     *
     * @param string|array<string> $columns
     */
    public function groupBy($columns): static;

    /**
     * Add HAVING clause
     *
     * @param array<string,mixed> $conditions
     */
    public function having(array $conditions): static;

    /**
     * Add ORDER BY clause
     *
     * @param string|array<string,string> $column
     */
    public function orderBy($column, string $direction = 'ASC'): static;

    /**
     * Add LIMIT clause
     */
    public function limit(int $count): static;

    /**
     * Add OFFSET clause
     */
    public function offset(int $count): static;

    /**
     * Execute query and return all results
     *
     * @return list<array<string,mixed>>
     */
    public function get(): array;

    /**
     * Execute query and return first result
     *
     * @return array<string,mixed>|null
     */
    public function first(): ?array;

    /**
     * Execute count query
     */
    public function count(): int;

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
     * Insert data
     *
     * @param array<string,mixed> $data
     */
    public function insert(array $data): int;

    /**
     * Insert multiple rows
     *
     * @param array<array<string,mixed>> $rows
     */
    public function insertBatch(array $rows): int;

    /**
     * Update data
     *
     * @param array<string,mixed> $data
     */
    public function update(array $data): int;

    /**
     * Delete records
     */
    public function delete(): int;

    /**
     * Execute in transaction
     *
     * @return mixed
     */
    public function transaction(callable $callback);

    /**
     * Enable query caching
     */
    public function cache(?int $ttl = null): static;

    /**
     * Enable query optimization
     */
    public function optimize(): static;

    /**
     * Set business purpose for the query
     */
    public function withPurpose(string $purpose): static;

    /**
     * Get SQL string
     */
    public function toSql(): string;

    /**
     * Get parameter bindings
     *
     * @return array<mixed>
     */
    public function getBindings(): array;

    /**
     * Create raw expression
     */
    public function raw(string $expression): \Glueful\Database\RawExpression;

    /**
     * Execute a raw SQL query and return results
     *
     * @param array<mixed> $bindings
     * @return array<mixed>
     */
    public function executeRaw(string $sql, array $bindings = []): array;

    /**
     * Execute a raw SQL query and return first result
     *
     * @param array<mixed> $bindings
     * @return array<string,mixed>|null
     */
    public function executeRawFirst(string $sql, array $bindings = []): ?array;
}
