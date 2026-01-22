<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Not Equal operator (!=)
 *
 * Filters for inequality: filter[status][ne]=deleted
 */
class NotEqualOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'ne';
    }

    public function aliases(): array
    {
        return ['!=', 'neq', 'not_equal'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $query->where($field, '!=', $value);
    }
}
