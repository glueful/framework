<?php

declare(strict_types=1);

namespace Tests\Unit\Api\RateLimiting;

use Glueful\Api\RateLimiting\TierManager;
use PHPUnit\Framework\TestCase;

class TierManagerTest extends TestCase
{
    private TierManager $manager;

    protected function setUp(): void
    {
        $this->manager = new TierManager([
            'default_tier' => 'anonymous',
            'tiers' => [
                'anonymous' => [
                    'requests_per_minute' => 30,
                    'requests_per_hour' => 500,
                    'requests_per_day' => 5000,
                ],
                'free' => [
                    'requests_per_minute' => 60,
                    'requests_per_hour' => 1000,
                    'requests_per_day' => 10000,
                ],
                'pro' => [
                    'requests_per_minute' => 300,
                    'requests_per_hour' => 10000,
                    'requests_per_day' => 100000,
                ],
                'enterprise' => [
                    'requests_per_minute' => null,
                    'requests_per_hour' => null,
                    'requests_per_day' => null,
                ],
            ],
        ]);
    }

    public function testGetLimitsReturnsCorrectValues(): void
    {
        $limits = $this->manager->getLimits('free');

        $this->assertEquals(60, $limits['requests_per_minute']);
        $this->assertEquals(1000, $limits['requests_per_hour']);
        $this->assertEquals(10000, $limits['requests_per_day']);
    }

    public function testGetLimitsReturnsDefaultForUnknownTier(): void
    {
        $limits = $this->manager->getLimits('unknown');

        $this->assertEquals(30, $limits['requests_per_minute']);
    }

    public function testGetLimitReturnsSpecificWindow(): void
    {
        $limit = $this->manager->getLimit('pro', 'hour');

        $this->assertEquals(10000, $limit);
    }

    public function testIsUnlimitedReturnsTrueForNullLimits(): void
    {
        $this->assertTrue($this->manager->isUnlimited('enterprise', 'minute'));
        $this->assertTrue($this->manager->isUnlimited('enterprise', 'hour'));
        $this->assertTrue($this->manager->isUnlimited('enterprise', 'day'));
    }

    public function testIsUnlimitedReturnsFalseForDefinedLimits(): void
    {
        $this->assertFalse($this->manager->isUnlimited('free', 'minute'));
        $this->assertFalse($this->manager->isUnlimited('pro', 'hour'));
    }

    public function testIsCompletelyUnlimitedReturnsTrue(): void
    {
        $this->assertTrue($this->manager->isCompletelyUnlimited('enterprise'));
    }

    public function testIsCompletelyUnlimitedReturnsFalse(): void
    {
        $this->assertFalse($this->manager->isCompletelyUnlimited('free'));
        $this->assertFalse($this->manager->isCompletelyUnlimited('pro'));
    }

    public function testHasTierReturnsTrue(): void
    {
        $this->assertTrue($this->manager->hasTier('free'));
        $this->assertTrue($this->manager->hasTier('pro'));
        $this->assertTrue($this->manager->hasTier('enterprise'));
    }

    public function testHasTierReturnsFalse(): void
    {
        $this->assertFalse($this->manager->hasTier('unknown'));
    }

    public function testGetTierNamesReturnsAllTiers(): void
    {
        $names = $this->manager->getTierNames();

        $this->assertContains('anonymous', $names);
        $this->assertContains('free', $names);
        $this->assertContains('pro', $names);
        $this->assertContains('enterprise', $names);
    }

    public function testGetDefaultTier(): void
    {
        $this->assertEquals('anonymous', $this->manager->getDefaultTier());
    }

    public function testCreateDefaultLimitsReturnsCorrectStructure(): void
    {
        $limits = $this->manager->createDefaultLimits('free');

        $this->assertCount(3, $limits);

        $this->assertEquals(60, $limits[0]['attempts']);
        $this->assertEquals(60, $limits[0]['decaySeconds']);

        $this->assertEquals(1000, $limits[1]['attempts']);
        $this->assertEquals(3600, $limits[1]['decaySeconds']);

        $this->assertEquals(10000, $limits[2]['attempts']);
        $this->assertEquals(86400, $limits[2]['decaySeconds']);
    }

    public function testCreateDefaultLimitsReturnsEmptyForUnlimited(): void
    {
        $limits = $this->manager->createDefaultLimits('enterprise');

        $this->assertCount(0, $limits);
    }
}
