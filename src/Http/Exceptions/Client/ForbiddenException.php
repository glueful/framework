<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Client;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 403 Forbidden Exception
 *
 * Thrown when the server understands the request but refuses to authorize it.
 * Unlike 401 Unauthorized, the client's identity is known but they lack
 * sufficient permissions to access the resource.
 *
 * Common use cases:
 * - User lacks required role/permission
 * - IP address blocked
 * - Resource access restricted
 * - Account suspended
 *
 * @example
 * // Insufficient permissions
 * throw new ForbiddenException('You do not have permission to delete this resource');
 *
 * @example
 * // Specific ability denial
 * throw new ForbiddenException('Admin access required');
 */
class ForbiddenException extends HttpException
{
    /**
     * Create a new Forbidden exception
     *
     * @param string $message Error message describing why access is forbidden
     * @param array<string, string> $headers Additional HTTP headers
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Forbidden',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(403, $message, $headers, 0, $previous);
    }
}
