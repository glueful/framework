<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * Before rule - validates date is before another date
 *
 * @example
 * new Before('today')        // Before today
 * new Before('now')          // Before now
 * new Before('2030-01-01')   // Before specific date
 */
final class Before implements Rule
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
        $beforeTimestamp = match (strtolower($this->date)) {
            'today' => strtotime('today'),
            'now' => time(),
            'tomorrow' => strtotime('tomorrow'),
            'yesterday' => strtotime('yesterday'),
            default => strtotime($this->date),
        };

        if ($timestamp === false) {
            return "The {$field} must be a valid date.";
        }

        if ($beforeTimestamp === false) {
            return "The comparison date '{$this->date}' is not a valid date.";
        }

        if ($timestamp >= $beforeTimestamp) {
            return "The {$field} must be a date before {$this->date}.";
        }

        return null;
    }
}
