<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;
use DateTime;

/**
 * Date rule - validates date format
 *
 * @example
 * new Date()           // Any valid date
 * new Date('Y-m-d')    // Specific format
 * new Date('d/m/Y')    // European format
 */
final class Date implements Rule
{
    public function __construct(
        private ?string $format = null
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

        if ($this->format !== null) {
            $date = DateTime::createFromFormat($this->format, (string) $value);
            if ($date === false || $date->format($this->format) !== (string) $value) {
                return "The {$field} must be a valid date in format {$this->format}.";
            }
        } else {
            // Try to parse as any valid date
            try {
                $timestamp = strtotime((string) $value);
                if ($timestamp === false) {
                    return "The {$field} must be a valid date.";
                }
            } catch (\Throwable) {
                return "The {$field} must be a valid date.";
            }
        }

        return null;
    }
}
