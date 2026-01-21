<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Client;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 400 Bad Request Exception
 *
 * Thrown when the server cannot process the request due to malformed
 * syntax, invalid request message framing, or deceptive request routing.
 *
 * Common use cases:
 * - Malformed JSON in request body
 * - Missing required headers
 * - Invalid query parameters
 * - Request body too large
 *
 * @example
 * // Invalid JSON body
 * throw new BadRequestException('Invalid JSON in request body');
 *
 * @example
 * // Missing required header
 * throw new BadRequestException('Content-Type header is required');
 */
class BadRequestException extends HttpException
{
    /**
     * Create a new Bad Request exception
     *
     * @param string $message Error message describing the bad request
     * @param array<string, string> $headers Additional HTTP headers
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Bad Request',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(400, $message, $headers, 0, $previous);
    }
}
