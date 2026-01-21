<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * Json rule - validates that a value is valid JSON
 *
 * @example
 * new Json()  // Any valid JSON
 */
final class Json implements Rule
{
    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $field = $context['field'] ?? 'field';

        if (!is_string($value)) {
            return "The {$field} must be a valid JSON string.";
        }

        json_decode($value);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return "The {$field} must be a valid JSON string.";
        }

        return null;
    }
}
