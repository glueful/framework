<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Contracts;

/**
 * Interface for searchable models
 *
 * Models implementing this interface can be searched using full-text search.
 */
interface SearchableInterface
{
    /**
     * Get fields that can be searched
     *
     * @return array<string> List of searchable field names
     */
    public function getSearchableFields(): array;

    /**
     * Get the search index name
     *
     * @return string The name of the search index (usually the table name)
     */
    public function searchableIndex(): string;

    /**
     * Convert model to searchable array
     *
     * @return array<string, mixed> Data to be indexed
     */
    public function toSearchableArray(): array;
}
