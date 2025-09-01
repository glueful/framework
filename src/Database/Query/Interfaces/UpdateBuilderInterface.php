<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * UpdateBuilder Interface
 *
 * Defines the contract for UPDATE query construction.
 * This interface ensures consistent UPDATE query building
 * across different implementations.
 */
interface UpdateBuilderInterface
{
    /**
     * Update records
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $conditions
     */
    public function update(string $table, array $data, array $conditions): int;

    /**
     * Build UPDATE SQL query
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $conditions
     */
    public function buildUpdateQuery(string $table, array $data, array $conditions): string;

    /**
     * Build SET clause for UPDATE
     *
     * @param array<string, mixed> $data
     */
    public function buildSetClause(array $data): string;

    /**
     * Build WHERE clause for UPDATE
     *
     * @param array<string, mixed> $conditions
     */
    public function buildWhereClause(array $conditions): string;

    /**
     * Get parameter bindings for UPDATE query
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $conditions
     * @return array<mixed>
     */
    public function getBindings(array $data, array $conditions): array;

    /**
     * Validate update data
     *
     * @param array<string, mixed> $data
     */
    public function validateData(array $data): void;

    /**
     * Validate update conditions
     *
     * @param array<string, mixed> $conditions
     */
    public function validateConditions(array $conditions): void;
}
