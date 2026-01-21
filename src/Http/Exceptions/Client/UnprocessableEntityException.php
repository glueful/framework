<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Client;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 422 Unprocessable Entity Exception
 *
 * Thrown when the server understands the content type and syntax of the
 * request, but cannot process the contained instructions.
 *
 * This is commonly used for validation errors where the request format
 * is correct but the data fails business validation rules.
 *
 * For more detailed validation errors with field-specific messages,
 * consider using ValidationException from the Validation namespace.
 *
 * Common use cases:
 * - Validation failures
 * - Semantic errors in request data
 * - Business rule violations
 *
 * @example
 * // Generic validation failure
 * throw new UnprocessableEntityException('Invalid data provided');
 *
 * @example
 * // Specific field error
 * throw new UnprocessableEntityException('Email format is invalid');
 *
 * @see \Glueful\Validation\ValidationException
 */
class UnprocessableEntityException extends HttpException
{
    /**
     * Create a new Unprocessable Entity exception
     *
     * @param string $message Error message describing the validation failure
     * @param array<string, string> $headers Additional HTTP headers
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Unprocessable Entity',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(422, $message, $headers, 0, $previous);
    }
}
