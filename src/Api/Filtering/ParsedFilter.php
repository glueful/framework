<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering;

/**
 * Value object representing a parsed filter
 *
 * Immutable object containing the parsed filter components:
 * - field: The field name to filter on
 * - operator: The comparison operator (eq, gt, contains, etc.)
 * - value: The value to compare against
 */
final readonly class ParsedFilter
{
    /**
     * Create a new parsed filter
     *
     * @param string $field The field name
     * @param string $operator The operator name
     * @param mixed $value The filter value
     */
    public function __construct(
        public string $field,
        public string $operator,
        public mixed $value,
    ) {
    }

    /**
     * Create a new ParsedFilter instance
     *
     * @param string $field The field name
     * @param string $operator The operator name
     * @param mixed $value The filter value
     * @return self
     */
    public static function create(string $field, string $operator, mixed $value): self
    {
        return new self($field, $operator, $value);
    }

    /**
     * Create an equality filter
     *
     * @param string $field The field name
     * @param mixed $value The value to match
     * @return self
     */
    public static function equals(string $field, mixed $value): self
    {
        return new self($field, 'eq', $value);
    }

    /**
     * Check if this filter has a specific operator
     *
     * @param string $operator The operator to check
     * @return bool
     */
    public function hasOperator(string $operator): bool
    {
        return $this->operator === $operator;
    }

    /**
     * Check if this filter is for a specific field
     *
     * @param string $field The field to check
     * @return bool
     */
    public function isForField(string $field): bool
    {
        return $this->field === $field;
    }

    /**
     * Get the value as an array (for in/between operators)
     *
     * @return array<mixed>
     */
    public function getValueAsArray(): array
    {
        if (is_array($this->value)) {
            return $this->value;
        }

        if (is_string($this->value) && str_contains($this->value, ',')) {
            return array_map('trim', explode(',', $this->value));
        }

        return [$this->value];
    }
}
