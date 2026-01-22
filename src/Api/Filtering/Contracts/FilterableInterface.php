<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Contracts;

/**
 * Interface for filterable models
 *
 * Models implementing this interface can be filtered using the filtering DSL.
 */
interface FilterableInterface
{
    /**
     * Get fields that can be filtered
     *
     * @return array<string> List of filterable field names
     */
    public function getFilterableFields(): array;

    /**
     * Get fields that can be sorted
     *
     * @return array<string> List of sortable field names
     */
    public function getSortableFields(): array;

    /**
     * Get the default sort field and direction
     *
     * @return string|null Default sort string (e.g., '-created_at' for descending)
     */
    public function getDefaultSort(): ?string;
}
