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

        foreach (array_keys($conditions) as $column) {
            $whereClauses[] = "{$this->driver->wrapIdentifier($column)} = ?";
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
        return array_values($conditions);
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
