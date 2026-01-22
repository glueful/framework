<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Greater Than operator (>)
 *
 * Filters for values greater than: filter[age][gt]=18
 */
class GreaterThanOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'gt';
    }

    public function aliases(): array
    {
        return ['>', 'greater_than'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $query->where($field, '>', $value);
    }
}
