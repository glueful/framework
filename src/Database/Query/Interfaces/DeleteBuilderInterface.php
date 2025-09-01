<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * DeleteBuilder Interface
 *
 * Defines the contract for DELETE query construction.
 * This interface ensures consistent DELETE query building
 * across different implementations.
 */
interface DeleteBuilderInterface
{
    /**
     * Delete records
     *
     * @param string $table
     * @param array<string, mixed> $conditions
     * @param bool $softDelete
     * @return int
     */
    public function delete(string $table, array $conditions, bool $softDelete = true): int;

    /**
     * Restore soft-deleted records
     *
     * @param string $table
     * @param array<string, mixed> $conditions
     * @return int
     */
    public function restore(string $table, array $conditions): int;

    /**
     * Hard delete records (bypass soft delete)
     *
     * @param string $table
     * @param array<string, mixed> $conditions
     * @return int
     */
    public function forceDelete(string $table, array $conditions): int;

    /**
     * Build DELETE SQL query
     *
     * @param string $table
     * @param array<string, mixed> $conditions
     * @param bool $softDelete
     * @return string
     */
    public function buildDeleteQuery(string $table, array $conditions, bool $softDelete): string;

    /**
     * Build RESTORE SQL query
     *
     * @param string $table
     * @param array<string, mixed> $conditions
     * @return string
     */
    public function buildRestoreQuery(string $table, array $conditions): string;

    /**
     * Build WHERE clause for DELETE
     *
     * @param array<string, mixed> $conditions
     * @return string
     */
    public function buildWhereClause(array $conditions): string;

    /**
     * Get parameter bindings for DELETE query
     *
     * @param array<string, mixed> $conditions
     * @return array<mixed>
     */
    public function getBindings(array $conditions): array;

    /**
     * Validate delete conditions
     *
     * @param array<string, mixed> $conditions
     * @return void
     */
    public function validateConditions(array $conditions): void;

    /**
     * Check if soft deletes are enabled
     */
    public function isSoftDeleteEnabled(): bool;

    /**
     * Enable or disable soft deletes
     */
    public function setSoftDeleteEnabled(bool $enabled): void;
}
