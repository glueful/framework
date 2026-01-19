<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class Numeric implements Rule
{
    public function __construct(
        private ?float $min = null,
        private ?float $max = null,
        private bool $integerOnly = false
    ) {
        if ($this->min !== null && $this->max !== null && $this->min > $this->max) {
            throw new \InvalidArgumentException('Minimum cannot be greater than maximum.');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            return 'Expected numeric value.';
        }

        if ($this->integerOnly && !$this->isInteger($value)) {
            return 'Must be an integer.';
        }

        $numericValue = (float) $value;
        if ($this->min !== null && $numericValue < $this->min) {
            return "Must be >= {$this->min}.";
        }
        if ($this->max !== null && $numericValue > $this->max) {
            return "Must be <= {$this->max}.";
        }

        return null;
    }

    private function isInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return fmod($value, 1.0) === 0.0;
        }
        if (is_string($value)) {
            return preg_match('/^-?\d+$/', $value) === 1;
        }

        return false;
    }
}
