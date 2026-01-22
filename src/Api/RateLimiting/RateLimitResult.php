<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting;

/**
 * Immutable value object representing a rate limit check result
 *
 * Contains all information needed to make rate limiting decisions
 * and generate appropriate response headers.
 */
final readonly class RateLimitResult
{
    /**
     * Create a new rate limit result
     *
     * @param bool $allowed Whether the request is allowed
     * @param int $limit Maximum requests allowed in the window
     * @param int $remaining Number of requests remaining in the window
     * @param int $resetAt Unix timestamp when the window resets
     * @param int|null $retryAfter Seconds until retry is allowed (when limited)
     * @param int $cost Cost of this request
     * @param string|null $tier User's rate limit tier
     * @param string|null $policy Rate limit policy description
     */
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $resetAt,
        public ?int $retryAfter = null,
        public int $cost = 1,
        public ?string $tier = null,
        public ?string $policy = null
    ) {
    }

    /**
     * Check if the request is allowed
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /**
     * Check if the request is rate limited
     */
    public function isLimited(): bool
    {
        return !$this->allowed;
    }

    /**
     * Get the window duration in seconds from current time
     */
    public function getWindowSeconds(): int
    {
        return max(0, $this->resetAt - time());
    }

    /**
     * Get the percentage of quota used
     *
     * @return float Percentage between 0 and 100
     */
    public function getUsagePercentage(): float
    {
        if ($this->limit === 0) {
            return 0.0;
        }

        $used = $this->limit - $this->remaining;
        return min(100.0, ($used / $this->limit) * 100);
    }

    /**
     * Check if quota is nearly exhausted (above threshold)
     *
     * @param float $threshold Percentage threshold (default 80%)
     */
    public function isNearLimit(float $threshold = 80.0): bool
    {
        return $this->getUsagePercentage() >= $threshold;
    }

    /**
     * Create a new result with a different tier
     */
    public function withTier(string $tier): self
    {
        return new self(
            allowed: $this->allowed,
            limit: $this->limit,
            remaining: $this->remaining,
            resetAt: $this->resetAt,
            retryAfter: $this->retryAfter,
            cost: $this->cost,
            tier: $tier,
            policy: $this->policy
        );
    }

    /**
     * Create a new result with a different policy
     */
    public function withPolicy(string $policy): self
    {
        return new self(
            allowed: $this->allowed,
            limit: $this->limit,
            remaining: $this->remaining,
            resetAt: $this->resetAt,
            retryAfter: $this->retryAfter,
            cost: $this->cost,
            tier: $this->tier,
            policy: $policy
        );
    }

    /**
     * Create an unlimited result (always allowed)
     */
    public static function unlimited(?string $tier = null): self
    {
        return new self(
            allowed: true,
            limit: PHP_INT_MAX,
            remaining: PHP_INT_MAX,
            resetAt: time() + 60,
            retryAfter: null,
            cost: 0,
            tier: $tier,
            policy: 'unlimited'
        );
    }

    /**
     * Convert to array for serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'limit' => $this->limit,
            'remaining' => $this->remaining,
            'reset_at' => $this->resetAt,
            'retry_after' => $this->retryAfter,
            'cost' => $this->cost,
            'tier' => $this->tier,
            'policy' => $this->policy,
        ];
    }
}
