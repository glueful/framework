<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Cache;

use Glueful\Cache\Drivers\MemcachedCacheDriver;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the Memcached driver consistently reports tagging as unsupported
 * and returns false from both addTags()/invalidateTags(), so callers can rely
 * on the capability flag to branch. (The file driver supports tags — see
 * FileCacheTagsTest.)
 */
final class UnsupportedTagsContractTest extends TestCase
{
    public function testMemcachedDriverReportsTagsUnsupported(): void
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('memcached extension not available');
        }

        $driver = new MemcachedCacheDriver(new \Memcached());
        $capabilities = $driver->getCapabilities();

        self::assertFalse($capabilities['features']['tags']);
        self::assertFalse($driver->addTags('any-key', ['tag']));
        self::assertFalse($driver->invalidateTags(['tag']));
    }
}
