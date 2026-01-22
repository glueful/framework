<?php

declare(strict_types=1);

namespace Tests\Unit\Api\RateLimiting\Limiters;

use Glueful\Api\RateLimiting\Limiters\FixedWindowLimiter;
use Glueful\Api\RateLimiting\Storage\MemoryStorage;
use PHPUnit\Framework\TestCase;

class FixedWindowLimiterTest extends TestCase
{
    private FixedWindowLimiter $limiter;
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
        $this->limiter = new FixedWindowLimiter($this->storage);
    }

    public function testAttemptAllowsWithinLimit(): void
    {
        $result = $this->limiter->attempt('test-key', 10, 60);

        $this->assertTrue($result->allowed);
        $this->assertEquals(10, $result->limit);
        $this->assertEquals(9, $result->remaining);
    }

    public function testAttemptDeniesWhenLimitExceeded(): void
    {
        // Exhaust the limit
        for ($i = 0; $i < 10; $i++) {
            $this->limiter->attempt('test-key', 10, 60);
        }

        // 11th attempt should be denied
        $result = $this->limiter->attempt('test-key', 10, 60);

        $this->assertFalse($result->allowed);
        $this->assertEquals(0, $result->remaining);
        $this->assertGreaterThan(0, $result->retryAfter);
    }

    public function testCheckDoesNotConsumeAttempts(): void
    {
        // Make one attempt
        $this->limiter->attempt('test-key', 10, 60);

        // Check should not consume attempts
        $before = $this->limiter->remaining('test-key', 10, 60);
        $this->limiter->check('test-key', 10, 60);
        $after = $this->limiter->remaining('test-key', 10, 60);

        $this->assertEquals($before, $after);
    }

    public function testResetClearsLimit(): void
    {
        // Make some attempts
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt('test-key', 10, 60);
        }

        // Reset
        $this->limiter->reset('test-key');

        // Should have full capacity again
        $remaining = $this->limiter->remaining('test-key', 10, 60);
        $this->assertEquals(10, $remaining);
    }

    public function testCostMultiplier(): void
    {
        // Attempt with cost of 5
        $result = $this->limiter->attempt('test-key', 10, 60, 5);

        $this->assertTrue($result->allowed);
        $this->assertEquals(5, $result->remaining);

        // Another attempt with cost of 6 should be denied
        $result = $this->limiter->attempt('test-key', 10, 60, 6);

        $this->assertFalse($result->allowed);
    }

    public function testGetName(): void
    {
        $this->assertEquals('fixed', $this->limiter->getName());
    }
}
