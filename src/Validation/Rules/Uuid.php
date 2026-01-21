<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * Uuid rule - validates UUID format
 *
 * Supports UUID v1-v5 formats.
 *
 * @example
 * new Uuid()  // Any valid UUID
 */
final class Uuid implements Rule
{
    /**
     * Standard UUID pattern (v1-v5)
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Nil UUID pattern
     */
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private bool $allowNil = false
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $field = $context['field'] ?? 'field';
        $stringValue = (string) $value;

        // Check for nil UUID
        if ($stringValue === self::NIL_UUID) {
            if ($this->allowNil) {
                return null;
            }
            return "The {$field} cannot be a nil UUID.";
        }

        if (!preg_match(self::UUID_PATTERN, $stringValue)) {
            return "The {$field} must be a valid UUID.";
        }

        return null;
    }
}
