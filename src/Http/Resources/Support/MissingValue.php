<?php

declare(strict_types=1);

namespace Glueful\Http\Resources\Support;

/**
 * MissingValue Sentinel
 *
 * Used to indicate that an attribute should be omitted from the resource response.
 * When a conditional method (like `when()` or `whenLoaded()`) returns this sentinel,
 * the key-value pair is filtered out during resolution.
 *
 * @package Glueful\Http\Resources\Support
 */
final class MissingValue
{
    /**
     * Check if a value is missing
     */
    public static function isMissing(mixed $value): bool
    {
        return $value instanceof self;
    }
}
