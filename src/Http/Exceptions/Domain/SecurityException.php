<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * Security Exception
 *
 * Thrown when security-related validation fails, including content type
 * validation, suspicious activity detection, and rate limit abuse.
 *
 * @example
 * throw new SecurityException('Access denied due to security restrictions', 403);
 *
 * @example
 * throw SecurityException::suspiciousActivity('Repeated failed logins');
 */
class SecurityException extends HttpException
{
    /**
     * Create a new security exception
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (default 400)
     * @param array<string, mixed>|null $context Additional error context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        int $statusCode = 400,
        ?array $context = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($statusCode, $message, [], 0, $previous);
        $this->context = $context;
    }

    /**
     * Create exception for invalid content type
     *
     * @param string $received Received content type
     * @param array<string> $expected Array of expected content types
     * @return static
     */
    public static function invalidContentType(string $received, array $expected): static
    {
        return new static(
            'Invalid content type provided',
            415,
            [
                'content_type_error' => true,
                'received' => $received,
                'expected' => $expected,
            ]
        );
    }

    /**
     * Create exception for suspicious activity
     *
     * @param string $activity Description of suspicious activity
     * @param array<string, mixed> $context Additional context data
     * @return static
     */
    public static function suspiciousActivity(string $activity, array $context = []): static
    {
        return new static(
            'Suspicious activity detected',
            403,
            array_merge([
                'security_violation' => true,
                'activity' => $activity,
            ], $context)
        );
    }

    /**
     * Create exception for rate limit abuse
     *
     * @param string $endpoint Endpoint being abused
     * @param int $attempts Number of attempts
     * @return static
     */
    public static function rateLimitAbuse(string $endpoint, int $attempts): static
    {
        return new static(
            'Rate limit abuse detected',
            429,
            [
                'rate_limit_abuse' => true,
                'endpoint' => $endpoint,
                'attempts' => $attempts,
            ]
        );
    }
}
