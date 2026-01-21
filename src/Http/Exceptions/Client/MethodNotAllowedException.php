<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Client;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 405 Method Not Allowed Exception
 *
 * Thrown when the HTTP method used is not supported for the requested resource.
 * The response automatically includes the Allow header listing valid methods.
 *
 * Common use cases:
 * - POST to a read-only endpoint
 * - DELETE on a resource that doesn't support deletion
 * - Wrong HTTP verb for an API endpoint
 *
 * @example
 * // Wrong method for endpoint
 * throw new MethodNotAllowedException(
 *     allowedMethods: ['GET', 'HEAD'],
 *     message: 'This endpoint only supports GET requests'
 * );
 *
 * @example
 * // Simple usage
 * throw new MethodNotAllowedException(['GET', 'POST', 'PUT']);
 */
class MethodNotAllowedException extends HttpException
{
    /**
     * Create a new Method Not Allowed exception
     *
     * @param array<string> $allowedMethods List of HTTP methods that are allowed
     * @param string $message Error message
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        array $allowedMethods = [],
        string $message = 'Method Not Allowed',
        ?Throwable $previous = null
    ) {
        $headers = [];

        // Add Allow header with valid methods per RFC 7231
        if ($allowedMethods !== []) {
            $headers['Allow'] = implode(', ', array_map('strtoupper', $allowedMethods));
        }

        parent::__construct(405, $message, $headers, 0, $previous);
    }
}
