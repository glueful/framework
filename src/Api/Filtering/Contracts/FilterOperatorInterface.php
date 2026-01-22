<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Contracts;

use Glueful\Database\QueryBuilder;

/**
 * Interface for filter operators
 *
 * Filter operators apply specific comparison logic to query builders.
 * Each operator implements a specific comparison (eq, gt, contains, etc.)
 */
interface FilterOperatorInterface
{
    /**
     * Get operator name
     *
     * @return string The primary operator name (e.g., 'eq', 'gt', 'contains')
     */
    public function name(): string;

    /**
     * Get operator aliases
     *
     * @return array<string> Alternative names for this operator
     */
    public function aliases(): array;

    /**
     * Apply operator to query builder
     *
     * @param QueryBuilder $query The query builder to modify
     * @param string $field The field name to filter on
     * @param mixed $value The value to compare against
     */
    public function apply(QueryBuilder $query, string $field, mixed $value): void;
}
