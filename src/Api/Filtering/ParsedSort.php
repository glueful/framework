<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering;

/**
 * Value object representing a parsed sort directive
 *
 * Immutable object containing:
 * - field: The field name to sort by
 * - direction: The sort direction (ASC or DESC)
 */
final readonly class ParsedSort
{
    public const DIRECTION_ASC = 'ASC';
    public const DIRECTION_DESC = 'DESC';

    /**
     * Create a new parsed sort
     *
     * @param string $field The field name
     * @param string $direction The sort direction (ASC or DESC)
     */
    public function __construct(
        public string $field,
        public string $direction = self::DIRECTION_ASC,
    ) {
    }

    /**
     * Create a new ParsedSort instance
     *
     * @param string $field The field name
     * @param string $direction The sort direction
     * @return self
     */
    public static function create(string $field, string $direction = self::DIRECTION_ASC): self
    {
        return new self($field, strtoupper($direction));
    }

    /**
     * Create an ascending sort
     *
     * @param string $field The field name
     * @return self
     */
    public static function asc(string $field): self
    {
        return new self($field, self::DIRECTION_ASC);
    }

    /**
     * Create a descending sort
     *
     * @param string $field The field name
     * @return self
     */
    public static function desc(string $field): self
    {
        return new self($field, self::DIRECTION_DESC);
    }

    /**
     * Parse a sort string (e.g., '-created_at' for descending)
     *
     * @param string $sortString The sort string to parse
     * @return self
     */
    public static function fromString(string $sortString): self
    {
        $sortString = trim($sortString);

        if (str_starts_with($sortString, '-')) {
            return new self(substr($sortString, 1), self::DIRECTION_DESC);
        }

        if (str_starts_with($sortString, '+')) {
            return new self(substr($sortString, 1), self::DIRECTION_ASC);
        }

        return new self($sortString, self::DIRECTION_ASC);
    }

    /**
     * Check if sorting in ascending order
     *
     * @return bool
     */
    public function isAscending(): bool
    {
        return $this->direction === self::DIRECTION_ASC;
    }

    /**
     * Check if sorting in descending order
     *
     * @return bool
     */
    public function isDescending(): bool
    {
        return $this->direction === self::DIRECTION_DESC;
    }

    /**
     * Convert to sort string format
     *
     * @return string
     */
    public function toString(): string
    {
        return $this->isDescending() ? "-{$this->field}" : $this->field;
    }
}
