<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Exceptions;

use InvalidArgumentException;

/**
 * Exception thrown when a filter is invalid
 */
class InvalidFilterException extends InvalidArgumentException
{
    /**
     * Create exception for exceeding max filters
     *
     * @param int $max Maximum allowed filters
     * @param int $actual Actual number of filters
     * @return self
     */
    public static function tooManyFilters(int $max, int $actual): self
    {
        return new self(
            "Maximum number of filters ({$max}) exceeded. Got {$actual} filters."
        );
    }

    /**
     * Create exception for exceeding max depth
     *
     * @param int $max Maximum allowed depth
     * @return self
     */
    public static function maxDepthExceeded(int $max): self
    {
        return new self(
            "Maximum filter depth ({$max}) exceeded."
        );
    }

    /**
     * Create exception for invalid field
     *
     * @param string $field The invalid field name
     * @param array<string> $allowed Allowed field names
     * @return self
     */
    public static function invalidField(string $field, array $allowed): self
    {
        $allowedList = implode(', ', $allowed);
        return new self(
            "Field '{$field}' is not filterable. Allowed fields: {$allowedList}"
        );
    }

    /**
     * Create exception for invalid filter value
     *
     * @param string $field The field name
     * @param string $reason The reason the value is invalid
     * @return self
     */
    public static function invalidValue(string $field, string $reason): self
    {
        return new self(
            "Invalid filter value for field '{$field}': {$reason}"
        );
    }
}
