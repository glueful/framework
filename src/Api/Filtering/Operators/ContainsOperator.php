<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Contains operator (LIKE %value%)
 *
 * Filters for values containing substring: filter[name][contains]=john
 */
class ContainsOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'contains';
    }

    public function aliases(): array
    {
        return ['like', 'includes'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $query->where($field, 'LIKE', "%{$value}%");
    }
}
