<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Client;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 401 Unauthorized Exception
 *
 * Thrown when authentication is required and has failed or not been provided.
 * Automatically includes the WWW-Authenticate header for proper HTTP compliance.
 *
 * Note: Despite the name "Unauthorized", this status code actually means
 * "Unauthenticated" - the client's identity is unknown. For authorization
 * failures (known client, insufficient permissions), use ForbiddenException.
 *
 * Common use cases:
 * - Missing authentication token
 * - Invalid credentials
 * - Expired authentication
 * - Malformed authorization header
 *
 * @example
 * // Missing token
 * throw new UnauthorizedException('Authentication token required');
 *
 * @example
 * // Invalid credentials with custom auth scheme
 * throw new UnauthorizedException(
 *     'Invalid API key',
 *     ['WWW-Authenticate' => 'ApiKey realm="api"']
 * );
 */
class UnauthorizedException extends HttpException
{
    /**
     * Create a new Unauthorized exception
     *
     * @param string $message Error message
     * @param array<string, string> $headers Additional HTTP headers (WWW-Authenticate added if not present)
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Unauthorized',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        // Add WWW-Authenticate header if not already provided
        $headers['WWW-Authenticate'] ??= 'Bearer';

        parent::__construct(401, $message, $headers, 0, $previous);
    }
}
