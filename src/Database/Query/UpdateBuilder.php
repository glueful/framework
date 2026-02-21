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

            if (is_array($condition) && isset($condition['__op'])) {
                $op = strtoupper(trim((string) $condition['__op']));

                if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                    $whereClauses[] = "{$wrappedColumn} {$op}";
                    continue;
                }

                if ($op === 'IN' || $op === 'NOT IN') {
                    $values = $condition['__value'] ?? null;
                    if (!is_array($values) || count($values) === 0) {
                        throw new \InvalidArgumentException(
                            "Condition '{$column}' with operator '{$op}' must provide a non-empty array"
                        );
                    }

                    $placeholders = implode(', ', array_fill(0, count($values), '?'));
                    $whereClauses[] = "{$wrappedColumn} {$op} ({$placeholders})";
                    continue;
                }

                $whereClauses[] = "{$wrappedColumn} {$op} ?";
                continue;
            }

            if ($condition === null) {
                $whereClauses[] = "{$wrappedColumn} IS NULL";
                continue;
            }

            $whereClauses[] = "{$wrappedColumn} = ?";
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
            if (is_array($condition) && isset($condition['__op'])) {
                $op = strtoupper(trim((string) $condition['__op']));

                if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                    continue;
                }

                if ($op === 'IN' || $op === 'NOT IN') {
                    $values = $condition['__value'] ?? null;
                    if (is_array($values)) {
                        foreach ($values as $value) {
                            $bindings[] = $value;
                        }
                    }
                    continue;
                }

                $bindings[] = $condition['__value'] ?? null;
                continue;
            }

            if ($condition === null) {
                continue;
            }

            $bindings[] = $condition;
        }

        return $bindings;
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
