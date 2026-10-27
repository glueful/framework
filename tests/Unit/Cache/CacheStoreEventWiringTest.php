<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Cache;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Cache\EventDispatchingCacheStore;
use Glueful\Container\Container;
use Glueful\Container\Providers\CoreProvider;
use Glueful\Container\Providers\TagCollector;
use PHPUnit\Framework\TestCase;

/** The container's cache store is the single place hit/miss events are dispatched from. */
final class CacheStoreEventWiringTest extends TestCase
{
    public function testTheContainerCacheStoreDispatchesHitMissEvents(): void
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $container = new Container((new CoreProvider(new TagCollector(), $context))->defs());

        $store = $container->get(CacheStore::class);

        self::assertInstanceOf(EventDispatchingCacheStore::class, $store);
        // No EventService registered in this container: reads still work.
        $store->set('wiring_probe', 'v');
        self::assertSame('v', $store->get('wiring_probe'));
    }
}
