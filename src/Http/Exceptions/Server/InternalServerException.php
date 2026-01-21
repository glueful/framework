<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Server;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 500 Internal Server Error Exception
 *
 * Thrown when the server encounters an unexpected condition that prevents
 * it from fulfilling the request. This is a generic "catch-all" for
 * server-side errors.
 *
 * In production, the actual error message should be hidden from the client
 * to prevent information leakage. The exception handler automatically
 * sanitizes error details based on the application environment.
 *
 * Common use cases:
 * - Uncaught exceptions
 * - Configuration errors
 * - Resource exhaustion
 * - Unexpected server state
 *
 * @example
 * // Generic server error
 * throw new InternalServerException('An unexpected error occurred');
 *
 * @example
 * // Wrapping an underlying exception
 * try {
 *     $result = $this->service->process();
 * } catch (ServiceException $e) {
 *     throw new InternalServerException(
 *         'Processing failed',
 *         previous: $e
 *     );
 * }
 */
class InternalServerException extends HttpException
{
    /**
     * Create a new Internal Server Error exception
     *
     * @param string $message Error message (may be hidden in production)
     * @param array<string, string> $headers Additional HTTP headers
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Internal Server Error',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(500, $message, $headers, 0, $previous);
    }
}
