<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * Token Expired Exception
 *
 * Thrown when a JWT or other authentication token has expired.
 * This is a specific authentication failure that indicates the client
 * needs to refresh their token or re-authenticate.
 *
 * The WWW-Authenticate header includes error details per RFC 6750
 * (Bearer Token Usage).
 *
 * @example
 * // Basic usage
 * throw new TokenExpiredException();
 *
 * @example
 * // With custom message
 * throw new TokenExpiredException('Your session has expired. Please log in again.');
 */
class TokenExpiredException extends HttpException
{
    /**
     * Create a new Token Expired exception
     *
     * @param string $message Error message
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Token has expired',
        ?Throwable $previous = null
    ) {
        parent::__construct(401, $message, [
            'WWW-Authenticate' => 'Bearer error="invalid_token", error_description="The access token has expired"',
        ], 0, $previous);
    }
}
