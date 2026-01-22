<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Limiters;

use Glueful\Api\RateLimiting\Contracts\RateLimiterInterface;
use Glueful\Api\RateLimiting\Contracts\StorageInterface;
use Glueful\Api\RateLimiting\RateLimitResult;

/**
 * Fixed window rate limiter
 *
 * Counts requests in fixed time windows (e.g., per minute).
 * Simple and memory-efficient, but can allow bursts at window boundaries.
 */
final class FixedWindowLimiter implements RateLimiterInterface
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
        $windowKey = $this->getWindowKey($key, $decaySeconds);
        $now = time();

        // Increment the counter by the cost
        $current = $this->storage->increment($windowKey, $cost);

        // Set expiration on first request in window
        if ($current === $cost) {
            $this->storage->expire($windowKey, $decaySeconds);
        }

        $remaining = max(0, $maxAttempts - $current);
        $resetAt = $this->calculateResetAt($decaySeconds);
        $allowed = $current <= $maxAttempts;

        return new RateLimitResult(
            allowed: $allowed,
            limit: $maxAttempts,
            remaining: $remaining,
            resetAt: $resetAt,
            retryAfter: $allowed ? null : max(1, $resetAt - $now),
            cost: $cost
        );
    }

    public function check(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult
    {
        $windowKey = $this->getWindowKey($key, $decaySeconds);

        $currentValue = $this->storage->get($windowKey);
        $current = $currentValue !== null ? (int) $currentValue : 0;

        $remaining = max(0, $maxAttempts - $current);
        $resetAt = $this->calculateResetAt($decaySeconds);

        return new RateLimitResult(
            allowed: $current < $maxAttempts,
            limit: $maxAttempts,
            remaining: $remaining,
            resetAt: $resetAt
        );
    }

    public function reset(string $key): void
    {
        // Delete current and previous windows
        $now = time();
        $windows = [60, 3600, 86400]; // Common window sizes

        foreach ($windows as $window) {
            $currentWindow = (int) floor($now / $window);
            // Delete a few windows back
            for ($i = 0; $i < 5; $i++) {
                $windowKey = $key . ':fixed:' . ($currentWindow - $i);
                $this->storage->delete($windowKey);
            }
        }

        // Also try direct key pattern
        $this->storage->delete($key);
    }

    public function remaining(string $key, int $maxAttempts, int $decaySeconds): int
    {
        return $this->check($key, $maxAttempts, $decaySeconds)->remaining;
    }

    public function getName(): string
    {
        return 'fixed';
    }

    /**
     * Get the key for the current window
     */
    private function getWindowKey(string $key, int $decaySeconds): string
    {
        $window = (int) floor(time() / $decaySeconds);

        return $key . ':fixed:' . $window;
    }

    /**
     * Calculate when the current window resets
     */
    private function calculateResetAt(int $decaySeconds): int
    {
        return ((int) floor(time() / $decaySeconds) + 1) * $decaySeconds;
    }
}
