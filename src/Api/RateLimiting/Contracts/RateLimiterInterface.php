<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Contracts;

use Glueful\Api\RateLimiting\RateLimitResult;

/**
 * Interface for rate limiter implementations
 *
 * Defines the contract for various rate limiting algorithms
 * (fixed window, sliding window, token bucket).
 */
interface RateLimiterInterface
{
    /**
     * Attempt to consume from the rate limit bucket
     *
     * @param string $key Unique identifier for this limit (e.g., "user:123:api:posts")
     * @param int $maxAttempts Maximum allowed attempts in window
     * @param int $decaySeconds Time window in seconds
     * @param int $cost Number of units to consume (default 1)
     * @return RateLimitResult Result containing allowed status and metadata
     */
    public function attempt(
        string $key,
        int $maxAttempts,
        int $decaySeconds,
        int $cost = 1
    ): RateLimitResult;

    /**
     * Check limit without consuming an attempt
     *
     * @param string $key Unique identifier for this limit
     * @param int $maxAttempts Maximum allowed attempts in window
     * @param int $decaySeconds Time window in seconds
     * @return RateLimitResult Result containing current status
     */
    public function check(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult;

    /**
     * Reset limit for a key
     *
     * Clears all tracking data for the specified key.
     *
     * @param string $key Unique identifier for this limit
     */
    public function reset(string $key): void;

    /**
     * Get remaining attempts for a key
     *
     * @param string $key Unique identifier for this limit
     * @param int $maxAttempts Maximum allowed attempts in window
     * @param int $decaySeconds Time window in seconds
     * @return int Number of remaining attempts
     */
    public function remaining(string $key, int $maxAttempts, int $decaySeconds): int;

    /**
     * Get the name of this limiter algorithm
     *
     * @return string Algorithm name (e.g., 'fixed', 'sliding', 'bucket')
     */
    public function getName(): string;
}
