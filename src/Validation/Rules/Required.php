<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class Required implements Rule
{
    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        return ($value === null || $value === '' || (is_array($value) && $value === []))
            ? 'This field is required.'
            : null;
    }
}
