<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Limiters;

use Glueful\Api\RateLimiting\Contracts\RateLimiterInterface;
use Glueful\Api\RateLimiting\Contracts\StorageInterface;
use Glueful\Api\RateLimiting\RateLimitResult;

/**
 * Sliding window rate limiter using sorted sets
 *
 * More accurate than fixed window, prevents bursts at window boundaries.
 * Each request is stored with its timestamp as the score.
 * Similar to the existing RateLimiter in src/Security/RateLimiter.php.
 */
final class SlidingWindowLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly StorageInterface $storage
    ) {
    }

    public function attempt(
        string $key,
        int $maxAttempts,
        int $decaySeconds,
        int $cost = 1
    ): RateLimitResult {
        $now = microtime(true);
        $windowStart = $now - $decaySeconds;
        $storageKey = $key . ':sliding';

        // Remove expired entries
        $this->storage->zremrangebyscore($storageKey, '-inf', (string) $windowStart);

        // Count current entries
        $currentCount = $this->storage->zcard($storageKey);

        // Check if we have room for this request (accounting for cost)
        $allowed = ($currentCount + $cost) <= $maxAttempts;

        if ($allowed) {
            // Add entries for the cost
            $entries = [];
            for ($i = 0; $i < $cost; $i++) {
                $memberId = $now . ':' . uniqid('', true) . ':' . $i;
                $entries[$memberId] = $now;
            }
            $this->storage->zadd($storageKey, $entries);
            $this->storage->expire($storageKey, $decaySeconds);
            $currentCount += $cost;
        }

        $remaining = max(0, $maxAttempts - $currentCount);

        // Calculate reset time from oldest entry
        $oldest = $this->storage->zrange($storageKey, 0, 0);
        $resetAt = count($oldest) > 0
            ? (int) ceil((float) $oldest[0] + $decaySeconds)
            : (int) ($now + $decaySeconds);

        return new RateLimitResult(
            allowed: $allowed,
            limit: $maxAttempts,
            remaining: $remaining,
            resetAt: $resetAt,
            retryAfter: $allowed ? null : max(1, $resetAt - (int) $now),
            cost: $cost
        );
    }

    public function check(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult
    {
        $now = microtime(true);
        $windowStart = $now - $decaySeconds;
        $storageKey = $key . ':sliding';

        // Remove expired entries
        $this->storage->zremrangebyscore($storageKey, '-inf', (string) $windowStart);

        // Count current entries
        $currentCount = $this->storage->zcard($storageKey);

        $remaining = max(0, $maxAttempts - $currentCount);

        // Calculate reset time from oldest entry
        $oldest = $this->storage->zrange($storageKey, 0, 0);
        $resetAt = count($oldest) > 0
            ? (int) ceil((float) $oldest[0] + $decaySeconds)
            : (int) ($now + $decaySeconds);

        return new RateLimitResult(
            allowed: $currentCount < $maxAttempts,
            limit: $maxAttempts,
            remaining: $remaining,
            resetAt: $resetAt
        );
    }

    public function reset(string $key): void
    {
        $this->storage->delete($key . ':sliding');
    }

    public function remaining(string $key, int $maxAttempts, int $decaySeconds): int
    {
        return $this->check($key, $maxAttempts, $decaySeconds)->remaining;
    }

    public function getName(): string
    {
        return 'sliding';
    }
}
