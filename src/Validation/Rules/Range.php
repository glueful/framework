<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class Range implements Rule
{
    public function __construct(
        private ?int $min = null,
        private ?int $max = null
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

        if (!is_int($value)) {
            if (is_numeric($value) && (string)(int)$value === (string)$value) {
                $value = (int)$value;
            } else {
                return 'Expected integer.';
            }
        }

        if ($this->min !== null && $value < $this->min) {
            return "Must be >= {$this->min}.";
        }
        if ($this->max !== null && $value > $this->max) {
            return "Must be <= {$this->max}.";
        }
        return null;
    }
}
