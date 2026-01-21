<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Client;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 409 Conflict Exception
 *
 * Thrown when the request conflicts with the current state of the server.
 * This typically occurs when trying to create/update a resource that would
 * violate business rules or data constraints.
 *
 * Common use cases:
 * - Duplicate unique field (email, username)
 * - Concurrent modification conflict
 * - Version mismatch (optimistic locking)
 * - State transition violation
 *
 * @example
 * // Duplicate email
 * throw new ConflictException('A user with this email already exists');
 *
 * @example
 * // Version conflict
 * throw new ConflictException(
 *     'Resource was modified by another request. Please refresh and try again.'
 * );
 */
class ConflictException extends HttpException
{
    /**
     * Create a new Conflict exception
     *
     * @param string $message Error message describing the conflict
     * @param array<string, string> $headers Additional HTTP headers
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Conflict',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(409, $message, $headers, 0, $previous);
    }
}
