<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

final class Email implements Rule
{
    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Invalid email address.';
    }
}
