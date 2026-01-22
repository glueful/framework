<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Ends With operator (LIKE %value)
 *
 * Filters for values ending with: filter[email][ends]=.com
 */
class EndsWithOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'ends';
    }

    public function aliases(): array
    {
        return ['suffix', 'ends_with'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $query->where($field, 'LIKE', "%{$value}");
    }
}
