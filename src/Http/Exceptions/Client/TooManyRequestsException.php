<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Client;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * 429 Too Many Requests Exception
 *
 * Thrown when the user has sent too many requests in a given amount of time
 * (rate limiting). Automatically includes the Retry-After header indicating
 * when the client can retry the request.
 *
 * Common use cases:
 * - Rate limit exceeded
 * - API quota exhausted
 * - Too many login attempts
 * - Burst protection triggered
 *
 * @example
 * // Standard rate limit
 * throw new TooManyRequestsException(retryAfter: 60);
 *
 * @example
 * // Custom message with longer retry
 * throw new TooManyRequestsException(
 *     retryAfter: 3600,
 *     message: 'API quota exceeded. Please upgrade your plan.'
 * );
 */
class TooManyRequestsException extends HttpException
{
    /**
     * Create a new Too Many Requests exception
     *
     * @param int $retryAfter Seconds until the client can retry (added to Retry-After header)
     * @param string $message Error message
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        int $retryAfter = 60,
        string $message = 'Too Many Requests',
        ?Throwable $previous = null
    ) {
        parent::__construct(429, $message, [
            'Retry-After' => (string) $retryAfter,
        ], 0, $previous);
    }

    /**
     * Get the retry-after value in seconds
     *
     * @return int Seconds until the client can retry
     */
    public function getRetryAfter(): int
    {
        return (int) ($this->headers['Retry-After'] ?? 60);
    }
}
