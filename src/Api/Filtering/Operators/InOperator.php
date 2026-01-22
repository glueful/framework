<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * In operator (IN array)
 *
 * Filters for values in array: filter[status][in]=active,pending,review
 */
class InOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'in';
    }

    public function aliases(): array
    {
        return [];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $values = $this->parseValues($value);
        $query->whereIn($field, $values);
    }

    /**
     * Parse value into array
     *
     * @param mixed $value The value (array or comma-separated string)
     * @return array<mixed>
     */
    private function parseValues(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            return array_map('trim', explode(',', $value));
        }

        return [$value];
    }
}
