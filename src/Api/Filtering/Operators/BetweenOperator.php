<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Api\Filtering\Exceptions\InvalidOperatorException;
use Glueful\Database\QueryBuilder;

/**
 * Between operator (BETWEEN min AND max)
 *
 * Filters for values in range: filter[price][between]=10,100
 */
class BetweenOperator implements FilterOperatorInterface
{
    public function name(): string
    {
        return 'between';
    }

    public function aliases(): array
    {
        return ['range'];
    }

    public function apply(QueryBuilder $query, string $field, mixed $value): void
    {
        $values = $this->parseValues($value);

        if (count($values) !== 2) {
            throw InvalidOperatorException::invalidArgument(
                'between',
                'requires exactly 2 values (e.g., filter[price][between]=10,100)'
            );
        }

        $query->whereBetween($field, trim((string) $values[0]), trim((string) $values[1]));
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
            return array_values($value);
        }

        if (is_string($value)) {
            return array_map('trim', explode(',', $value));
        }

        return [$value];
    }
}
