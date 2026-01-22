<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Equal operator (=)
 *
 * Filters for exact equality: filter[status]=active
 */
class EqualOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'eq';
    }

    public function aliases(): array
    {
        return ['=', 'equal', 'equals'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $query->where($field, '=', $value);
    }
}
