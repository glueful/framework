<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Less Than or Equal operator (<=)
 *
 * Filters for values less than or equal: filter[price][lte]=50
 */
class LessThanOrEqualOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'lte';
    }

    public function aliases(): array
    {
        return ['<=', 'less_than_or_equal'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $query->where($field, '<=', $value);
    }
}
