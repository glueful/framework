<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * The `min:N` / `max:N` rule strings: a string's length, a number's value, or an array's
 * item count, by the value's type (the OpenAPI generator maps them the same way).
 */
final class Size implements Rule
{
    public function __construct(
        private int|float|null $min = null,
        private int|float|null $max = null
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            if ($this->min !== null && $value < $this->min) {
                return "Must be at least {$this->min}.";
            }
            if ($this->max !== null && $value > $this->max) {
                return "Must be at most {$this->max}.";
            }
            return null;
        }

        if (is_array($value)) {
            $count = count($value);
            if ($this->min !== null && $count < $this->min) {
                return "Must have at least {$this->min} items.";
            }
            if ($this->max !== null && $count > $this->max) {
                return "Must have at most {$this->max} items.";
            }
            return null;
        }

        if (!is_string($value)) {
            return 'Expected string, number or array.';
        }

        $len = mb_strlen($value);
        if ($this->min !== null && $len < $this->min) {
            return "Must be at least {$this->min} characters.";
        }
        if ($this->max !== null && $len > $this->max) {
            return "Must be at most {$this->max} characters.";
        }
        return null;
    }
}
