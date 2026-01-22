<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Null operator (IS NULL)
 *
 * Filters for null values: filter[deleted_at][null]
 */
class NullOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function aliases(): array
    {
        return ['is_null'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        // Value is ignored for null check
        $query->whereNull($field);
    }
}
