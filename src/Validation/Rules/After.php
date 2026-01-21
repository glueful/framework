<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * After rule - validates date is after another date
 *
 * @example
 * new After('today')         // After today
 * new After('now')           // After now
 * new After('2020-01-01')    // After specific date
 */
final class After implements Rule
{
    public function __construct(
        private string $date
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
        $timestamp = strtotime((string) $value);

        // Handle special date keywords
        $afterTimestamp = match (strtolower($this->date)) {
            'today' => strtotime('today'),
            'now' => time(),
            'tomorrow' => strtotime('tomorrow'),
            'yesterday' => strtotime('yesterday'),
            default => strtotime($this->date),
        };

        if ($timestamp === false) {
            return "The {$field} must be a valid date.";
        }

        if ($afterTimestamp === false) {
            return "The comparison date '{$this->date}' is not a valid date.";
        }

        if ($timestamp <= $afterTimestamp) {
            return "The {$field} must be a date after {$this->date}.";
        }

        return null;
    }
}
