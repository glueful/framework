<?php

declare(strict_types=1);

namespace Glueful\Database\Query;

use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Query\Interfaces\DeleteBuilderInterface;
use Glueful\Database\Execution\Interfaces\QueryExecutorInterface;

/**
 * DeleteBuilder
 *
 * Handles DELETE query construction and execution.
 * Extracted from the monolithic QueryBuilder to follow
 * Single Responsibility Principle.
 */
class DeleteBuilder implements DeleteBuilderInterface
{
    protected DatabaseDriver $driver;
    protected QueryExecutorInterface $executor;
    protected bool $softDeleteEnabled = true;

    public function __construct(DatabaseDriver $driver, QueryExecutorInterface $executor)
    {
        $this->driver = $driver;
        $this->executor = $executor;
    }

    /**
     * Delete records
     * @param string $table
     * @param array<string, mixed> $conditions
     * @param bool $softDelete
     */
    public function delete(string $table, array $conditions, bool $softDelete = true): int
    {
        $this->validateConditions($conditions);

        $useSoftDelete = $softDelete && $this->softDeleteEnabled;
        $sql = $this->buildDeleteQuery($table, $conditions, $useSoftDelete);
        $bindings = $this->getBindings($conditions);

        return $this->executor->executeModification($sql, $bindings);
    }

    /**
     * Restore soft-deleted records
     * @param string $table
     * @param array<string, mixed> $conditions
     */
    public function restore(string $table, array $conditions): int
    {
        $this->validateConditions($conditions);

        $sql = $this->buildRestoreQuery($table, $conditions);
        $bindings = $this->getBindings($conditions);

        return $this->executor->executeModification($sql, $bindings);
    }

    /**
     * Hard delete records (bypass soft delete)
     * @param string $table
     * @param array<string, mixed> $conditions
     */
    public function forceDelete(string $table, array $conditions): int
    {
        return $this->delete($table, $conditions, false);
    }

    /**
     * Build DELETE SQL query
     * @param string $table
     * @param array<string, mixed> $conditions
     * @param bool $softDelete
     */
    public function buildDeleteQuery(string $table, array $conditions, bool $softDelete): string
    {
        $tableName = $this->driver->wrapIdentifier($table);

        if ($softDelete) {
            // Soft delete: UPDATE table SET deleted_at = CURRENT_TIMESTAMP
            $sql = "UPDATE {$tableName} SET deleted_at = CURRENT_TIMESTAMP";
        } else {
            // Hard delete: DELETE FROM table
            $sql = "DELETE FROM {$tableName}";
        }

        if (count($conditions) > 0) {
            $sql .= " WHERE " . $this->buildWhereClause($conditions);
        }

        return $sql;
    }

    /**
     * Build RESTORE SQL query
     * @param string $table
     * @param array<string, mixed> $conditions
     */
    public function buildRestoreQuery(string $table, array $conditions): string
    {
        $tableName = $this->driver->wrapIdentifier($table);
        $sql = "UPDATE {$tableName} SET deleted_at = NULL";

        if (count($conditions) > 0) {
            $sql .= " WHERE " . $this->buildWhereClause($conditions);
        }

        return $sql;
    }

    /**
     * Build WHERE clause for DELETE
     * @param array<string, mixed> $conditions
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
     * Get parameter bindings for DELETE query
     * @param array<string, mixed> $conditions
     * @return array<int, mixed>
     */
    public function getBindings(array $conditions): array
    {
        $bindings = [];

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
     * Validate delete conditions
     * @param array<string, mixed> $conditions
     */
    public function validateConditions(array $conditions): void
    {
        if (count($conditions) === 0) {
            throw new \InvalidArgumentException('Delete conditions cannot be empty. This would delete all rows.');
        }

        if (!$this->isAssociativeArray($conditions)) {
            throw new \InvalidArgumentException('Delete conditions must be an associative array');
        }
    }

    /**
     * Check if soft deletes are enabled
     */
    public function isSoftDeleteEnabled(): bool
    {
        return $this->softDeleteEnabled;
    }

    /**
     * Enable or disable soft deletes
     */
    public function setSoftDeleteEnabled(bool $enabled): void
    {
        $this->softDeleteEnabled = $enabled;
    }

    /**
     * Check if array is associative
     * @param array<mixed> $array
     */
    protected function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
