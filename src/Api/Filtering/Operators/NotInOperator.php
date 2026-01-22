<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Database\QueryBuilder;

/**
 * Not In operator (NOT IN array)
 *
 * Filters for values not in array: filter[status][nin]=archived,deleted
 */
class NotInOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'nin';
    }

    public function aliases(): array
    {
        return ['not_in'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $values = $this->parseValues($value);
        $query->whereNotIn($field, $values);
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
