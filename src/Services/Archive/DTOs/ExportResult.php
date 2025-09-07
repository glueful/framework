<?php

namespace Glueful\Services\Archive\DTOs;

/**
 * Export Operation Result
 *
 * Contains the result of exporting data from a table
 * for archival purposes.
 *
 * @package Glueful\Services\Archive\DTOs
 */
class ExportResult
{
    /**
     * @param array<int, array<string, mixed>> $data
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly array $data,
        public readonly int $recordCount,
        public readonly array $metadata
    ) {
    }

    /**
     * Check if export contains data
     */
    public function hasData(): bool
    {
        return count($this->data) > 0;
    }

    /**
     * Get the first record
     *
     * @return array<string, mixed>|null
     */
    public function getFirstRecord(): ?array
    {
        return $this->data[0] ?? null;
    }

    /**
     * Get the last record
     *
     * @return array<string, mixed>|null
     */
    public function getLastRecord(): ?array
    {
        return count($this->data) === 0 ? null : $this->data[array_key_last($this->data)];
    }
}
