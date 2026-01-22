<?php

declare(strict_types=1);

namespace Tests\Unit\Api\RateLimiting\Attributes;

use Glueful\Api\RateLimiting\Attributes\RateLimit;
use Glueful\Api\RateLimiting\Attributes\RateLimitCost;
use PHPUnit\Framework\TestCase;

class RateLimitTest extends TestCase
{
    public function testDecaySecondsCalculatedFromMinutes(): void
    {
        $limit = new RateLimit(attempts: 60, perMinutes: 5);

        $this->assertEquals(300, $limit->decaySeconds);
    }

    public function testDecaySecondsCalculatedFromHours(): void
    {
        $limit = new RateLimit(attempts: 1000, perHours: 2);

        $this->assertEquals(7200, $limit->decaySeconds);
    }

    public function testDecaySecondsCalculatedFromDays(): void
    {
        $limit = new RateLimit(attempts: 10000, perDays: 1);

        $this->assertEquals(86400, $limit->decaySeconds);
    }

    public function testDaysOverridesHours(): void
    {
        $limit = new RateLimit(attempts: 100, perHours: 1, perDays: 1);

        $this->assertEquals(86400, $limit->decaySeconds);
    }

    public function testHoursOverridesMinutes(): void
    {
        $limit = new RateLimit(attempts: 100, perMinutes: 5, perHours: 1);

        $this->assertEquals(3600, $limit->decaySeconds);
    }

    public function testIsUnlimitedReturnsTrue(): void
    {
        $limit = new RateLimit(attempts: 0);

        $this->assertTrue($limit->isUnlimited());
    }

    public function testIsUnlimitedReturnsFalse(): void
    {
        $limit = new RateLimit(attempts: 100);

        $this->assertFalse($limit->isUnlimited());
    }

    public function testGetLimitIdIsUnique(): void
    {
        $limit1 = new RateLimit(attempts: 60, perMinutes: 1);
        $limit2 = new RateLimit(attempts: 60, perHours: 1);
        $limit3 = new RateLimit(attempts: 60, perMinutes: 1, tier: 'pro');

        $this->assertNotEquals($limit1->getLimitId(), $limit2->getLimitId());
        $this->assertNotEquals($limit1->getLimitId(), $limit3->getLimitId());
    }

    public function testGetDescriptionForLimited(): void
    {
        $limit = new RateLimit(attempts: 60, perMinutes: 1);

        $this->assertEquals('60 requests per 1 minute(s)', $limit->getDescription());
    }

    public function testGetDescriptionForUnlimited(): void
    {
        $limit = new RateLimit(attempts: 0);

        $this->assertEquals('unlimited', $limit->getDescription());
    }

    public function testGetDescriptionWithTier(): void
    {
        $limit = new RateLimit(attempts: 100, perHours: 1, tier: 'pro');

        $this->assertStringContainsString('tier: pro', $limit->getDescription());
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $limit = new RateLimit(
            attempts: 100,
            perMinutes: 5,
            tier: 'free',
            key: 'custom:{ip}',
            algorithm: 'fixed',
            by: 'user'
        );

        $array = $limit->toArray();

        $this->assertEquals(100, $array['attempts']);
        $this->assertEquals(300, $array['decaySeconds']);
        $this->assertEquals('free', $array['tier']);
        $this->assertEquals('custom:{ip}', $array['key']);
        $this->assertEquals('fixed', $array['algorithm']);
        $this->assertEquals('user', $array['by']);
    }

    public function testDefaultValues(): void
    {
        $limit = new RateLimit(attempts: 60);

        $this->assertEquals(1, $limit->perMinutes);
        $this->assertNull($limit->perHours);
        $this->assertNull($limit->perDays);
        $this->assertNull($limit->tier);
        $this->assertNull($limit->key);
        $this->assertEquals('sliding', $limit->algorithm);
        $this->assertEquals('ip', $limit->by);
    }
}

class RateLimitCostTest extends TestCase
{
    public function testCostIsStored(): void
    {
        $cost = new RateLimitCost(cost: 10);

        $this->assertEquals(10, $cost->cost);
    }

    public function testReasonIsStored(): void
    {
        $cost = new RateLimitCost(cost: 10, reason: 'Complex query');

        $this->assertEquals('Complex query', $cost->reason);
    }

    public function testReasonIsNullByDefault(): void
    {
        $cost = new RateLimitCost(cost: 5);

        $this->assertNull($cost->reason);
    }

    public function testCostMustBeAtLeastOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitCost(cost: 0);
    }

    public function testGetDescriptionWithoutReason(): void
    {
        $cost = new RateLimitCost(cost: 10);

        $this->assertEquals('Costs 10 unit(s)', $cost->getDescription());
    }

    public function testGetDescriptionWithReason(): void
    {
        $cost = new RateLimitCost(cost: 10, reason: 'Export operation');

        $this->assertEquals('Costs 10 unit(s): Export operation', $cost->getDescription());
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $cost = new RateLimitCost(cost: 50, reason: 'Bulk operation');

        $array = $cost->toArray();

        $this->assertEquals(50, $array['cost']);
        $this->assertEquals('Bulk operation', $array['reason']);
    }
}
