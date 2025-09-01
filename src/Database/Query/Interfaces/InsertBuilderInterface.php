<?php

declare(strict_types=1);

namespace Glueful\Database\Query\Interfaces;

/**
 * InsertBuilder Interface
 *
 * Defines the contract for INSERT query construction.
 * This interface ensures consistent INSERT query building
 * across different implementations.
 */
interface InsertBuilderInterface
{
    /**
     * Insert single record
     *
     * @param string $table
     * @param array<string, mixed> $data
     * @return int
     */
    public function insert(string $table, array $data): int;

    /**
     * Insert multiple records in batch
     *
     * @param string $table
     * @param array<array<string, mixed>> $rows
     * @return int
     */
    public function insertBatch(string $table, array $rows): int;

    /**
     * Insert or update on duplicate key
     *
     * @param string $table
     * @param array<string, mixed> $data
     * @param array<string> $updateColumns
     * @return int
     */
    public function upsert(string $table, array $data, array $updateColumns): int;

    /**
     * Build INSERT SQL query
     *
     * @param string $table
     * @param array<string, mixed> $data
     * @return string
     */
    public function buildInsertQuery(string $table, array $data): string;

    /**
     * Build batch INSERT SQL query
     *
     * @param string $table
     * @param array<array<string, mixed>> $rows
     * @return string
     */
    public function buildBatchInsertQuery(string $table, array $rows): string;

    /**
     * Build UPSERT SQL query
     *
     * @param string $table
     * @param array<string, mixed> $data
     * @param array<string> $updateColumns
     * @return string
     */
    public function buildUpsertQuery(string $table, array $data, array $updateColumns): string;

    /**
     * Validate insert data
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function validateData(array $data): void;

    /**
     * Validate batch insert data
     *
     * @param array<array<string, mixed>> $rows
     * @return void
     */
    public function validateBatchData(array $rows): void;
}
