<?php

declare(strict_types=1);

namespace Glueful\Database\Query;

use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Query\Interfaces\UpdateBuilderInterface;
use Glueful\Database\Execution\Interfaces\QueryExecutorInterface;

/**
 * UpdateBuilder
 *
 * Handles UPDATE query construction and execution.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class UpdateBuilder implements UpdateBuilderInterface
{
    protected DatabaseDriver $driver;
    protected QueryExecutorInterface $executor;

    public function __construct(DatabaseDriver $driver, QueryExecutorInterface $executor)
    {
        $this->driver = $driver;
        $this->executor = $executor;
    }

    /**
     * Update records
     */
    public function update(string $table, array $data, array $conditions): int
    {
        $this->validateData($data);
        $this->validateConditions($conditions);

        $sql = $this->buildUpdateQuery($table, $data, $conditions);
        $bindings = $this->getBindings($data, $conditions);

        return $this->executor->executeModification($sql, $bindings);
    }

    /**
     * Build UPDATE SQL query
     */
    public function buildUpdateQuery(string $table, array $data, array $conditions): string
    {
        $sql = "UPDATE {$this->driver->wrapIdentifier($table)} SET ";
        $sql .= $this->buildSetClause($data);

        if (count($conditions) > 0) {
            $sql .= " WHERE " . $this->buildWhereClause($conditions);
        }

        return $sql;
    }

    /**
     * Build SET clause for UPDATE
     */
    public function buildSetClause(array $data): string
    {
        $setClauses = [];

        foreach (array_keys($data) as $column) {
            $setClauses[] = "{$this->driver->wrapIdentifier($column)} = ?";
        }

        return implode(', ', $setClauses);
    }

    /**
     * Build WHERE clause for UPDATE
     */
    public function buildWhereClause(array $conditions): string
    {
        $whereClauses = [];

        foreach ($conditions as $column => $condition) {
            $wrappedColumn = $this->driver->wrapIdentifier((string) $column);

            // Multiple predicates on the same column (e.g. a range id > 1 AND id < 3) are
            // folded into a __multi list by WhereClause; emit each AND-joined so the range
            // is preserved rather than collapsed to a single predicate.
            if (is_array($condition) && isset($condition['__multi'])) {
                foreach ($condition['__multi'] as $entry) {
                    $whereClauses[] = $this->predicateSql($wrappedColumn, (string) $column, $entry);
                }
                continue;
            }

            $whereClauses[] = $this->predicateSql($wrappedColumn, (string) $column, $condition);
        }

        return implode(' AND ', $whereClauses);
    }

    /**
     * Get parameter bindings for UPDATE query
     */
    public function getBindings(array $data, array $conditions): array
    {
        $bindings = array_values($data);

        foreach ($conditions as $condition) {
            if (is_array($condition) && isset($condition['__multi'])) {
                foreach ($condition['__multi'] as $entry) {
                    foreach ($this->predicateBindings($entry) as $binding) {
                        $bindings[] = $binding;
                    }
                }
                continue;
            }

            foreach ($this->predicateBindings($condition) as $binding) {
                $bindings[] = $binding;
            }
        }

        return $bindings;
    }

    /**
     * SQL fragment for a single WHERE predicate (column + operator + placeholders).
     */
    private function predicateSql(string $wrappedColumn, string $column, mixed $condition): string
    {
        if (is_array($condition) && isset($condition['__op'])) {
            $op = strtoupper(trim((string) $condition['__op']));

            if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                return "{$wrappedColumn} {$op}";
            }

            if ($op === 'IN' || $op === 'NOT IN') {
                $values = $condition['__value'] ?? null;
                if (!is_array($values) || count($values) === 0) {
                    throw new \InvalidArgumentException(
                        "Condition '{$column}' with operator '{$op}' must provide a non-empty array"
                    );
                }

                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                return "{$wrappedColumn} {$op} ({$placeholders})";
            }

            return "{$wrappedColumn} {$op} ?";
        }

        if ($condition === null) {
            return "{$wrappedColumn} IS NULL";
        }

        return "{$wrappedColumn} = ?";
    }

    /**
     * Bindings contributed by a single WHERE predicate, in placeholder order.
     *
     * @return array<int, mixed>
     */
    private function predicateBindings(mixed $condition): array
    {
        if (is_array($condition) && isset($condition['__op'])) {
            $op = strtoupper(trim((string) $condition['__op']));

            if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                return [];
            }

            if ($op === 'IN' || $op === 'NOT IN') {
                $values = $condition['__value'] ?? null;
                return is_array($values) ? array_values($values) : [];
            }

            return [$condition['__value'] ?? null];
        }

        if ($condition === null) {
            return [];
        }

        return [$condition];
    }

    /**
     * Validate update data
     */
    public function validateData(array $data): void
    {
        if (count($data) === 0) {
            throw new \InvalidArgumentException('Cannot update with empty data array');
        }

        if (!$this->isAssociativeArray($data)) {
            throw new \InvalidArgumentException('Update data must be an associative array');
        }
    }

    /**
     * Validate update conditions
     */
    public function validateConditions(array $conditions): void
    {
        if (count($conditions) === 0) {
            throw new \InvalidArgumentException('Update conditions cannot be empty. This would update all rows.');
        }

        if (!$this->isAssociativeArray($conditions)) {
            throw new \InvalidArgumentException('Update conditions must be an associative array');
        }
    }

    /**
     * Check if array is associative
     *
     * @param array<mixed> $array
     */
    protected function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
