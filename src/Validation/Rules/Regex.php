<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class Regex implements Rule
{
    public function __construct(
        private string $pattern,
        private ?string $message = null
    ) {
        if (@preg_match($this->pattern, '') === false) {
            throw new \InvalidArgumentException("Invalid regex pattern: {$this->pattern}");
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
        if (!is_string($value)) {
            return 'Expected string.';
        }

        return preg_match($this->pattern, $value) === 1
            ? null
            : ($this->message ?? 'Value does not match required format.');
    }
}
