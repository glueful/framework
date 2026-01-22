<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Greater Than or Equal operator (>=)
 *
 * Filters for values greater than or equal: filter[age][gte]=21
 */
class GreaterThanOrEqualOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'gte';
    }

    public function aliases(): array
    {
        return ['>=', 'greater_than_or_equal'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $query->where($field, '>=', $value);
    }
}
