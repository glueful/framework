<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Server;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 504 Gateway Timeout Exception
 *
 * Thrown when the server, acting as a gateway or proxy, did not receive
 * a timely response from an upstream server it needed to access to
 * complete the request.
 *
 * Common use cases:
 * - External API timeout
 * - Upstream service timeout
 * - Database query timeout
 * - Microservice communication failure
 *
 * @example
 * // External API timeout
 * throw new GatewayTimeoutException('Payment gateway did not respond in time');
 *
 * @example
 * // Microservice timeout
 * throw new GatewayTimeoutException(
 *     'User service timed out',
 *     previous: $timeoutException
 * );
 */
class GatewayTimeoutException extends HttpException
{
    /**
     * Create a new Gateway Timeout exception
     *
     * @param string $message Error message
     * @param array<string, string> $headers Additional HTTP headers
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Gateway Timeout',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(504, $message, $headers, 0, $previous);
    }
}
