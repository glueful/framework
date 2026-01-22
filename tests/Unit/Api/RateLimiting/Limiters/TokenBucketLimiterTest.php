<?php

declare(strict_types=1);

namespace Tests\Unit\Api\RateLimiting\Limiters;

use Glueful\Api\RateLimiting\Limiters\TokenBucketLimiter;
use Glueful\Api\RateLimiting\Storage\MemoryStorage;
use PHPUnit\Framework\TestCase;

class TokenBucketLimiterTest extends TestCase
{
    private TokenBucketLimiter $limiter;
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
        $this->limiter = new TokenBucketLimiter($this->storage);
    }

    public function testAttemptAllowsWithinLimit(): void
    {
        $result = $this->limiter->attempt('test-key', 10, 60);

        $this->assertTrue($result->allowed);
        $this->assertEquals(10, $result->limit);
        $this->assertGreaterThanOrEqual(9, $result->remaining);
    }

    public function testAttemptDeniesWhenBucketEmpty(): void
    {
        // Exhaust the bucket
        for ($i = 0; $i < 10; $i++) {
            $this->limiter->attempt('test-key', 10, 60);
        }

        // Next attempt should be denied
        $result = $this->limiter->attempt('test-key', 10, 60);

        $this->assertFalse($result->allowed);
    }

    public function testCheckDoesNotConsumeTokens(): void
    {
        // Make one attempt
        $this->limiter->attempt('test-key', 10, 60);

        // Check should not consume tokens
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

        // Another attempt with cost of 6 should be denied
        $result = $this->limiter->attempt('test-key', 10, 60, 6);

        $this->assertFalse($result->allowed);
    }

    public function testGetName(): void
    {
        $this->assertEquals('bucket', $this->limiter->getName());
    }
}
