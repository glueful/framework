<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Limiters;

use Glueful\Api\RateLimiting\Contracts\RateLimiterInterface;
use Glueful\Api\RateLimiting\Contracts\StorageInterface;
use Glueful\Api\RateLimiting\RateLimitResult;

/**
 * Token bucket rate limiter
 *
 * Allows bursts while maintaining an average rate.
 * Tokens are added at a constant rate, requests consume tokens.
 * Good for APIs that want to allow occasional bursts.
 */
final class TokenBucketLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly StorageInterface $storage
    ) {
    }

    public function attempt(
        string $key,
        int $maxAttempts, // = bucket size
        int $decaySeconds, // = time to refill bucket
        int $cost = 1
    ): RateLimitResult {
        $now = microtime(true);
        $storageKey = $key . ':bucket';
        $refillRate = $maxAttempts / $decaySeconds; // tokens per second

        // Get current bucket state
        $bucketData = $this->storage->get($storageKey);

        if ($bucketData === null) {
            $tokens = (float) $maxAttempts;
            $lastRefill = $now;
        } else {
            $bucket = json_decode($bucketData, true);

            if (!is_array($bucket)) {
                $tokens = (float) $maxAttempts;
                $lastRefill = $now;
            } else {
                $tokens = (float) ($bucket['tokens'] ?? $maxAttempts);
                $lastRefill = (float) ($bucket['last_refill'] ?? $now);

                // Refill tokens based on elapsed time
                $elapsed = $now - $lastRefill;
                $tokens = min($maxAttempts, $tokens + ($elapsed * $refillRate));
                $lastRefill = $now;
            }
        }

        $allowed = $tokens >= $cost;

        if ($allowed) {
            $tokens -= $cost;
        }

        // Save bucket state
        $this->storage->set($storageKey, json_encode([
            'tokens' => $tokens,
            'last_refill' => $lastRefill,
        ]), $decaySeconds + 60);

        $remaining = (int) floor($tokens);
        $timeToRefill = $allowed ? 0 : ($cost - $tokens) / $refillRate;

        return new RateLimitResult(
            allowed: $allowed,
            limit: $maxAttempts,
            remaining: $remaining,
            resetAt: $allowed ? (int) ($now + $decaySeconds) : (int) ceil($now + $timeToRefill),
            retryAfter: $allowed ? null : (int) ceil($timeToRefill),
            cost: $cost
        );
    }

    public function check(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult
    {
        // Check without consuming tokens
        return $this->attempt($key, $maxAttempts, $decaySeconds, 0);
    }

    public function reset(string $key): void
    {
        $this->storage->delete($key . ':bucket');
    }

    public function remaining(string $key, int $maxAttempts, int $decaySeconds): int
    {
        return $this->check($key, $maxAttempts, $decaySeconds)->remaining;
    }

    public function getName(): string
    {
        return 'bucket';
    }
}
