<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Not Null operator (IS NOT NULL)
 *
 * Filters for non-null values: filter[verified_at][not_null]
 */
class NotNullOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'not_null';
    }

    public function aliases(): array
    {
        return ['notnull', 'is_not_null'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        // Value is ignored for not null check
        $query->whereNotNull($field);
    }
}
