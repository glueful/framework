<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Contains operator (case-insensitive, literal substring)
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
        $query->whereContains($field, (string) $value);
    }
}
