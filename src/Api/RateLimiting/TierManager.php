<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting;

/**
 * Manages tier configurations and limit lookups
 *
 * Provides access to tier-based rate limit configurations
 * loaded from the application configuration.
 */
final class TierManager
{
    /**
     * @var array<string, array<string, int|null>> Tier configurations
     */
    private array $tiers = [];

    /**
     * @var string Default tier for unmatched requests
     */
    private string $defaultTier = 'anonymous';

    /**
     * Create a new tier manager
     *
     * @param array<string, mixed> $config Configuration from api.rate_limiting
     */
    public function __construct(array $config = [])
    {
        $this->tiers = $config['tiers'] ?? [];
        $this->defaultTier = $config['default_tier'] ?? 'anonymous';
    }

    /**
     * Get all limits for a tier
     *
     * @param string $tier Tier name
     * @return array<string, int|null> Limits array
     */
    public function getLimits(string $tier): array
    {
        return $this->tiers[$tier] ?? $this->tiers[$this->defaultTier] ?? [];
    }

    /**
     * Get specific limit for tier and window
     *
     * @param string $tier Tier name
     * @param string $window Window name: 'minute', 'hour', 'day'
     * @return int|null Limit or null if unlimited
     */
    public function getLimit(string $tier, string $window): ?int
    {
        $limits = $this->getLimits($tier);
        $key = "requests_per_{$window}";

        return $limits[$key] ?? null;
    }

    /**
     * Check if tier has unlimited requests for a window
     *
     * @param string $tier Tier name
     * @param string $window Window name: 'minute', 'hour', 'day'
     */
    public function isUnlimited(string $tier, string $window = 'minute'): bool
    {
        $limit = $this->getLimit($tier, $window);

        return $limit === null || $limit === 0;
    }

    /**
     * Check if tier is completely unlimited (all windows)
     *
     * @param string $tier Tier name
     */
    public function isCompletelyUnlimited(string $tier): bool
    {
        return $this->isUnlimited($tier, 'minute')
            && $this->isUnlimited($tier, 'hour')
            && $this->isUnlimited($tier, 'day');
    }

    /**
     * Check if a tier exists
     *
     * @param string $tier Tier name
     */
    public function hasTier(string $tier): bool
    {
        return isset($this->tiers[$tier]);
    }

    /**
     * Get all tier names
     *
     * @return array<string>
     */
    public function getTierNames(): array
    {
        return array_keys($this->tiers);
    }

    /**
     * Get the default tier name
     */
    public function getDefaultTier(): string
    {
        return $this->defaultTier;
    }

    /**
     * Get all tier configurations
     *
     * @return array<string, array<string, int|null>>
     */
    public function getAllTiers(): array
    {
        return $this->tiers;
    }

    /**
     * Create default limits array from tier configuration
     *
     * @param string $tier Tier name
     * @return array<array<string, mixed>> Array of limit configurations
     */
    public function createDefaultLimits(string $tier): array
    {
        $tierLimits = $this->getLimits($tier);
        $limits = [];

        $minuteLimit = $tierLimits['requests_per_minute'] ?? null;
        if (is_int($minuteLimit) && $minuteLimit > 0) {
            $limits[] = [
                'attempts' => $minuteLimit,
                'decaySeconds' => 60,
                'algorithm' => 'sliding',
                'by' => 'ip',
            ];
        }

        $hourLimit = $tierLimits['requests_per_hour'] ?? null;
        if (is_int($hourLimit) && $hourLimit > 0) {
            $limits[] = [
                'attempts' => $hourLimit,
                'decaySeconds' => 3600,
                'algorithm' => 'sliding',
                'by' => 'ip',
            ];
        }

        $dayLimit = $tierLimits['requests_per_day'] ?? null;
        if (is_int($dayLimit) && $dayLimit > 0) {
            $limits[] = [
                'attempts' => $dayLimit,
                'decaySeconds' => 86400,
                'algorithm' => 'sliding',
                'by' => 'ip',
            ];
        }

        return $limits;
    }
}
