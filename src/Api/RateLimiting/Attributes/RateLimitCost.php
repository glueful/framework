<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Attributes;

use Attribute;

/**
 * Define the cost (quota units) for an operation
 *
 * Expensive operations can consume more than 1 unit from the rate limit bucket.
 * This allows protecting against resource-intensive endpoints while maintaining
 * simple request counting for regular endpoints.
 *
 * @example
 * // Simple endpoint costs 1 unit (default)
 * public function list(): Response { }
 *
 * @example
 * // Complex query costs 10 units
 * #[RateLimitCost(cost: 10, reason: 'Complex aggregation query')]
 * public function analytics(): Response { }
 *
 * @example
 * // Export costs 100 units
 * #[RateLimitCost(cost: 100, reason: 'Full data export')]
 * public function export(): Response { }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RateLimitCost
{
    /**
     * Create a new rate limit cost attribute
     *
     * @param int $cost Number of quota units consumed (must be >= 1)
     * @param string|null $reason Optional description for documentation
     */
    public function __construct(
        public readonly int $cost,
        public readonly ?string $reason = null
    ) {
        if ($this->cost < 1) {
            throw new \InvalidArgumentException('Rate limit cost must be at least 1');
        }
    }

    /**
     * Get a human-readable description of this cost
     */
    public function getDescription(): string
    {
        $desc = "Costs {$this->cost} unit(s)";

        if ($this->reason !== null) {
            $desc .= ": {$this->reason}";
        }

        return $desc;
    }

    /**
     * Convert to array for storage
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cost' => $this->cost,
            'reason' => $this->reason,
        ];
    }
}
