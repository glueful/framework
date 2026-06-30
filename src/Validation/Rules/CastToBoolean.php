<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\MutatingRule;

/**
 * Coerces a boolean-like value to a bool: `true`/`"true"`/`"1"`/`"yes"`/`"on"`/`1` → true,
 * `false`/`"false"`/`"0"`/`"no"`/`"off"`/`0` → false.
 *
 * A {@see MutatingRule} coerces, it does not validate: anything else (including `""` and `null`)
 * is returned UNCHANGED so a paired validating rule can reject it.
 */
final class CastToBoolean implements MutatingRule
{
    /**
     * @param array<string, mixed> $context
     */
    public function mutate(mixed $value, array $context = []): mixed
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
                return false;
            }
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        return $value;
    }
}
