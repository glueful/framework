<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * Nullable rule - allows null values
 *
 * When applied, if the value is null or empty string, validation stops
 * and the field passes validation without running subsequent rules.
 *
 * @example
 * Rules: ['middle_name' => 'nullable|string|max:100']
 * Input: ['middle_name' => null]  // Passes
 */
final class Nullable implements Rule
{
    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        // This rule is handled specially by the Validator
        // If value is null or empty, validation chain stops
        // Always return null (no error) because this rule never fails
        return null;
    }

    /**
     * Check if this rule allows null values
     *
     * Used by the Validator to determine if null values should skip validation.
     */
    public function allowsNull(): bool
    {
        return true;
    }

    /**
     * Check if validation should stop for the given value
     */
    public function shouldStopValidation(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
