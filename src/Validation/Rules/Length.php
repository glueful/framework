<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class Length implements Rule
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
        if (!is_string($value)) {
            return 'Expected string.';
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
