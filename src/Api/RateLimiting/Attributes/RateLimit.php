<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Attributes;

use Attribute;

/**
 * Define rate limits for a controller or method
 *
 * Multiple attributes can be stacked for multi-window limiting.
 *
 * @example
 * // Basic per-minute limit
 * #[RateLimit(attempts: 60, perMinutes: 1)]
 * public function index(): Response { }
 *
 * @example
 * // Multi-window limits
 * #[RateLimit(attempts: 60, perMinutes: 1)]
 * #[RateLimit(attempts: 1000, perHours: 1)]
 * #[RateLimit(attempts: 10000, perDays: 1)]
 * public function search(): Response { }
 *
 * @example
 * // Tier-specific limits
 * #[RateLimit(tier: 'free', attempts: 100, perDays: 1)]
 * #[RateLimit(tier: 'pro', attempts: 10000, perDays: 1)]
 * #[RateLimit(tier: 'enterprise', attempts: 0)] // 0 = unlimited
 * public function query(): Response { }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RateLimit
{
    /**
     * Calculated decay time in seconds
     */
    public readonly int $decaySeconds;

    /**
     * Create a new rate limit attribute
     *
     * @param int $attempts Maximum attempts allowed in the window (0 = unlimited)
     * @param int $perMinutes Time window in minutes (default 1)
     * @param int|null $perHours Time window in hours (overrides perMinutes)
     * @param int|null $perDays Time window in days (overrides perHours)
     * @param string|null $tier Apply only to specific user tier (null = all tiers)
     * @param string|null $key Custom rate limit key pattern
     * @param string $algorithm Limiter algorithm: 'fixed', 'sliding', 'bucket'
     * @param string $by What to limit by: 'ip', 'user', 'endpoint', 'custom'
     */
    public function __construct(
        public readonly int $attempts,
        public readonly int $perMinutes = 1,
        public readonly ?int $perHours = null,
        public readonly ?int $perDays = null,
        public readonly ?string $tier = null,
        public readonly ?string $key = null,
        public readonly string $algorithm = 'sliding',
        public readonly string $by = 'ip'
    ) {
        // Calculate decay seconds from the most specific time unit
        if ($this->perDays !== null) {
            $this->decaySeconds = $this->perDays * 86400;
        } elseif ($this->perHours !== null) {
            $this->decaySeconds = $this->perHours * 3600;
        } else {
            $this->decaySeconds = $this->perMinutes * 60;
        }
    }

    /**
     * Check if this limit is unlimited
     */
    public function isUnlimited(): bool
    {
        return $this->attempts === 0;
    }

    /**
     * Get a unique identifier for this limit configuration
     */
    public function getLimitId(): string
    {
        $parts = [
            (string) $this->attempts,
            (string) $this->decaySeconds,
            $this->tier ?? 'all',
            $this->by,
            $this->algorithm,
        ];

        return implode(':', $parts);
    }

    /**
     * Get a human-readable description of this limit
     */
    public function getDescription(): string
    {
        if ($this->isUnlimited()) {
            return 'unlimited';
        }

        $window = match (true) {
            $this->perDays !== null => "{$this->perDays} day(s)",
            $this->perHours !== null => "{$this->perHours} hour(s)",
            default => "{$this->perMinutes} minute(s)"
        };

        $desc = "{$this->attempts} requests per {$window}";

        if ($this->tier !== null) {
            $desc .= " (tier: {$this->tier})";
        }

        return $desc;
    }

    /**
     * Convert to array for storage in route configuration
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attempts' => $this->attempts,
            'decaySeconds' => $this->decaySeconds,
            'tier' => $this->tier,
            'key' => $this->key,
            'algorithm' => $this->algorithm,
            'by' => $this->by,
        ];
    }
}
