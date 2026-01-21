<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Client;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 404 Not Found Exception
 *
 * Thrown when the requested resource cannot be found on the server.
 * This is one of the most commonly used HTTP exceptions.
 *
 * Common use cases:
 * - Resource with given ID doesn't exist
 * - Route/endpoint not found
 * - File not found
 * - Deleted resource
 *
 * For ORM/model-specific not found scenarios, consider using
 * ModelNotFoundException instead, which provides additional
 * context about the model class and IDs.
 *
 * @example
 * // Generic resource not found
 * throw new NotFoundException('User not found');
 *
 * @example
 * // Route not found
 * throw new NotFoundException('The requested endpoint does not exist');
 *
 * @see \Glueful\Http\Exceptions\Domain\ModelNotFoundException
 */
class NotFoundException extends HttpException
{
    /**
     * Create a new Not Found exception
     *
     * @param string $message Error message describing what wasn't found
     * @param array<string, string> $headers Additional HTTP headers
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Not Found',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(404, $message, $headers, 0, $previous);
    }
}
