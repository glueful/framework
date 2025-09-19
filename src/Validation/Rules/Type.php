<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class Type implements Rule
{
    public function __construct(private string $type)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null) {
            return null;
        }
        return gettype($value) === $this->type ? null : "Expected type {$this->type}.";
    }
}
