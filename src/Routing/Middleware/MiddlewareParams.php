<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

/**
 * Coerces route-definition middleware params.
 *
 * The router splits "name:a,b,c" into trimmed STRINGS, so a middleware that accepts only
 * real ints/bools silently ignores every value written in a route file. These helpers accept
 * both the native type (direct calls, tests) and its string spelling, and return null for
 * anything unrecognised so the caller keeps its configured default.
 */
final class MiddlewareParams
{
    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $int = filter_var(trim($value), FILTER_VALIDATE_INT);

        return $int === false ? null : $int;
    }
}
