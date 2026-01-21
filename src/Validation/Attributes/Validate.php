<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

use Attribute;

/**
 * Validate attribute for declarative request validation
 *
 * Apply to controller methods to automatically validate incoming requests.
 * Supports both string-based rules (Laravel-style) and Rule object arrays.
 *
 * @example
 * #[Validate([
 *     'email' => 'required|email|unique:users',
 *     'password' => 'required|min:8|confirmed',
 *     'name' => 'required|string|max:255',
 * ])]
 * public function store(ValidatedRequest $request): Response
 *
 * @example With custom messages
 * #[Validate(
 *     rules: [
 *         'email' => 'required|email',
 *         'age' => 'required|integer|min:18',
 *     ],
 *     messages: [
 *         'email.required' => 'We need your email address.',
 *         'age.min' => 'You must be at least 18 years old.',
 *     ]
 * )]
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Validate
{
    /**
     * @param array<string, string|array<\Glueful\Validation\Contracts\Rule>> $rules Validation rules
     * @param array<string, string> $messages Custom error messages
     * @param array<string, string> $attributes Custom attribute names for error messages
     * @param bool $stopOnFirstFailure Stop validation on first failure
     */
    public function __construct(
        public readonly array $rules,
        public readonly array $messages = [],
        public readonly array $attributes = [],
        public readonly bool $stopOnFirstFailure = false,
    ) {
    }
}
