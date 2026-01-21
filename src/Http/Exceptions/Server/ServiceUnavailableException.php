<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Server;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 503 Service Unavailable Exception
 *
 * Thrown when the server is temporarily unable to handle the request.
 * This typically occurs during maintenance windows, when dependencies
 * are down, or when the server is overloaded.
 *
 * Optionally includes the Retry-After header to indicate when the
 * service is expected to be available again.
 *
 * Common use cases:
 * - Planned maintenance
 * - Database connection failure
 * - External dependency unavailable
 * - Server overload
 * - Feature flag disabled
 *
 * @example
 * // Database unavailable
 * throw new ServiceUnavailableException('Database connection failed');
 *
 * @example
 * // Maintenance with retry time
 * throw new ServiceUnavailableException(
 *     message: 'Service is under maintenance',
 *     retryAfter: 3600
 * );
 */
class ServiceUnavailableException extends HttpException
{
    /**
     * Create a new Service Unavailable exception
     *
     * @param string $message Error message
     * @param int|null $retryAfter Seconds until service is expected to be available
     * @param array<string, string> $headers Additional HTTP headers
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Service Unavailable',
        ?int $retryAfter = null,
        array $headers = [],
        ?Throwable $previous = null
    ) {
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        parent::__construct(503, $message, $headers, 0, $previous);
    }

    /**
     * Get the retry-after value in seconds if set
     *
     * @return int|null Seconds until service should be available, or null if not set
     */
    public function getRetryAfter(): ?int
    {
        $retryAfter = $this->headers['Retry-After'] ?? null;

        return $retryAfter !== null ? (int) $retryAfter : null;
    }
}
