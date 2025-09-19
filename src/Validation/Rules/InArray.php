<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class InArray implements Rule
{
    /** @var array<int|string, mixed> */
    private array $choices;

    /**
     * @param array<int|string, mixed> $choices
     */
    public function __construct(array $choices)
    {
        $this->choices = $choices;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null) {
            return null;
        }
        return in_array($value, $this->choices, true)
            ? null
            : 'Value must be one of: ' . implode(', ', array_map('strval', $this->choices)) . '.';
    }
}
