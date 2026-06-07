<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Cache;

use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Cache\NullEdgeCache;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the no-op core default returns the disabled-state values, so
 * response caching keeps working with no CDN integration installed.
 */
final class NullEdgeCacheTest extends TestCase
{
    public function testImplementsEdgeCacheInterface(): void
    {
        self::assertInstanceOf(EdgeCacheInterface::class, new NullEdgeCache());
    }

    public function testIsEnabledReturnsFalse(): void
    {
        self::assertFalse((new NullEdgeCache())->isEnabled());
    }

    public function testGetProviderReturnsNull(): void
    {
        self::assertNull((new NullEdgeCache())->getProvider());
    }

    public function testGenerateCacheHeadersReturnsEmptyArray(): void
    {
        self::assertSame([], (new NullEdgeCache())->generateCacheHeaders('any', 'application/json'));
    }

    public function testPurgeUrlReturnsFalse(): void
    {
        self::assertFalse((new NullEdgeCache())->purgeUrl('https://example.com/foo'));
    }

    public function testPurgeByTagReturnsFalse(): void
    {
        self::assertFalse((new NullEdgeCache())->purgeByTag('posts'));
    }

    public function testPurgeAllReturnsFalse(): void
    {
        self::assertFalse((new NullEdgeCache())->purgeAll());
    }
}
