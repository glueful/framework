<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Less Than operator (<)
 *
 * Filters for values less than: filter[price][lt]=100
 */
class LessThanOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'lt';
    }

    public function aliases(): array
    {
        return ['<', 'less_than'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $query->where($field, '<', $value);
    }
}
