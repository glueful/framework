<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * Sometimes rule - only validate if field is present
 *
 * When applied, if the field is not present in the input data at all,
 * the entire validation chain is skipped for that field.
 *
 * @example
 * Rules: ['avatar' => 'sometimes|image|max:2048']
 * Input: []  // Field not present, validation skipped entirely
 * Input: ['avatar' => null]  // Field present (null), runs remaining rules
 */
final class Sometimes implements Rule
{
    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        // This rule is handled specially by the Validator
        // Always return null (no error) because this rule never fails by itself
        return null;
    }

    /**
     * Check if this rule makes the field optional
     *
     * Used by the Validator to determine if absent fields should be skipped.
     */
    public function isOptional(): bool
    {
        return true;
    }

    /**
     * Check if validation should be skipped based on field presence
     *
     * @param array<string, mixed> $data Full input data
     */
    public function shouldSkipValidation(string $field, array $data): bool
    {
        return !array_key_exists($field, $data);
    }
}
