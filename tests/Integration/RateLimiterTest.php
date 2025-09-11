<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear any existing environment state
        unset($_ENV['CACHE_DRIVER'], $_ENV['ENABLE_ADAPTIVE_RATE_LIMITING']);
    }

    public function testRateLimitBlocksAfterThreshold(): void
    {
        // Create a direct RateLimiter test using ArrayCache to avoid framework configuration issues
        $cache = new \Glueful\Cache\Drivers\ArrayCacheDriver();

        // Use the same IP for all requests to test IP-based rate limiting
        $clientIp = '127.0.0.1';
        $limiter = new \Glueful\Security\RateLimiter("ip:$clientIp", 2, 60, $cache);

        // First attempt should succeed
        $this->assertTrue($limiter->attempt(), 'First attempt should succeed');
        $this->assertFalse($limiter->isExceeded(), 'Should not be exceeded after first attempt');
        $this->assertSame(1, $limiter->remaining(), 'Should have 1 remaining after first attempt');

        // Second attempt should succeed
        $this->assertTrue($limiter->attempt(), 'Second attempt should succeed');
        $this->assertTrue($limiter->isExceeded(), 'Should be exceeded after second attempt (at limit)');
        $this->assertSame(0, $limiter->remaining(), 'Should have 0 remaining after second attempt');

        // Third attempt should fail (exceeded)
        $this->assertFalse($limiter->attempt(), 'Third attempt should fail (rate limited)');
        $this->assertTrue($limiter->isExceeded(), 'Should still be exceeded');
        $this->assertSame(0, $limiter->remaining(), 'Should still have 0 remaining');
    }

    public function testRateLimiterWithDifferentConfigurations(): void
    {
        $cache = new \Glueful\Cache\Drivers\ArrayCacheDriver();

        // Test with different limits
        $limiter1 = new \Glueful\Security\RateLimiter('test:limit-1', 1, 60, $cache);
        $this->assertTrue($limiter1->attempt());
        $this->assertTrue($limiter1->isExceeded());
        $this->assertFalse($limiter1->attempt());

        // Test with higher limits
        $limiter3 = new \Glueful\Security\RateLimiter('test:limit-3', 3, 60, $cache);
        $this->assertTrue($limiter3->attempt());
        $this->assertTrue($limiter3->attempt());
        $this->assertTrue($limiter3->attempt());
        $this->assertTrue($limiter3->isExceeded());
        $this->assertFalse($limiter3->attempt());

        // Test remaining count accuracy
        $limiterRemaining = new \Glueful\Security\RateLimiter('test:remaining', 5, 60, $cache);
        $this->assertSame(5, $limiterRemaining->remaining());
        $limiterRemaining->attempt();
        $this->assertSame(4, $limiterRemaining->remaining());
        $limiterRemaining->attempt();
        $this->assertSame(3, $limiterRemaining->remaining());
    }
}
