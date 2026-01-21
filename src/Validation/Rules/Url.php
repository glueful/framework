<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;

/**
 * Url rule - validates URL format
 *
 * @example
 * new Url()              // Any valid URL
 * new Url(['http', 'https'])  // Only http/https URLs
 */
final class Url implements Rule
{
    /**
     * @param array<string>|null $protocols Allowed protocols (null = any)
     */
    public function __construct(
        private ?array $protocols = null
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

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return "The {$field} must be a valid URL.";
        }

        // Check protocol if specified
        if ($this->protocols !== null) {
            $parsedUrl = parse_url((string) $value);
            $scheme = $parsedUrl['scheme'] ?? '';

            if (!in_array(strtolower($scheme), array_map('strtolower', $this->protocols), true)) {
                $allowed = implode(', ', $this->protocols);
                return "The {$field} must use one of these protocols: {$allowed}.";
            }
        }

        return null;
    }
}
