<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * Confirmed rule - validates that a field matches its _confirmation counterpart
 *
 * @example
 * Rules: ['password' => 'required|confirmed']
 * Input: ['password' => 'secret', 'password_confirmation' => 'secret']
 */
final class Confirmed implements Rule
{
    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $field = $context['field'] ?? '';
        $confirmationField = $field . '_confirmation';
        $data = $context['data'] ?? [];
        $confirmationValue = $data[$confirmationField] ?? null;

        if ($value !== $confirmationValue) {
            return "The {$field} confirmation does not match.";
        }

        return null;
    }
}
