<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Starts With operator (LIKE value%)
 *
 * Filters for values starting with: filter[email][starts]=admin@
 */
class StartsWithOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'starts';
    }

    public function aliases(): array
    {
        return ['prefix', 'starts_with'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $query->where($field, 'LIKE', "{$value}%");
    }
}
