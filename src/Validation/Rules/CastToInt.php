<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\MutatingRule;

/**
 * Coerces an integer-like value (`42`, `"42"`, `42.0`) to an int.
 *
 * A {@see MutatingRule} coerces, it does not validate: values that are not cleanly integer
 * (`"abc"`, `"42.5"`, `null`) are returned UNCHANGED so a paired validating rule (e.g.
 * {@see Numeric} with `integerOnly` or {@see Type}) can reject them.
 */
final class CastToInt implements MutatingRule
{
    /**
     * @param array<string, mixed> $context
     */
    public function mutate(mixed $value, array $context = []): mixed
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        if (is_float($value) && floor($value) === $value && is_finite($value)) {
            return (int) $value;
        }
        return $value;
    }
}
