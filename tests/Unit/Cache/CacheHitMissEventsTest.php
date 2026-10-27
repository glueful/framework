<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Cache;

use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Cache\EventDispatchingCacheStore;
use Glueful\Events\Cache\CacheHitEvent;
use Glueful\Events\Cache\CacheMissEvent;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/** Cache reads through the store dispatch CacheHitEvent / CacheMissEvent to listeners. */
final class CacheHitMissEventsTest extends TestCase
{
    private EventService $events;
    /** @var list<string> */
    private array $seen = [];

    protected function setUp(): void
    {
        $provider = new ListenerProvider();
        $this->events = new EventService(new EventDispatcher($provider), $provider);
    }

    private function listen(): void
    {
        $this->events->addListener(CacheHitEvent::class, function (CacheHitEvent $e): void {
            $this->seen[] = 'hit:' . $e->getKey() . '=' . var_export($e->getValue(), true);
        });
        $this->events->addListener(CacheMissEvent::class, function (CacheMissEvent $e): void {
            $this->seen[] = 'miss:' . $e->getKey();
        });
    }

    /** @return EventDispatchingCacheStore<mixed> */
    private function store(?EventService $events = null): EventDispatchingCacheStore
    {
        $events ??= $this->events;
        return new EventDispatchingCacheStore(new ArrayCacheDriver(), static fn() => $events);
    }

    public function testGetDispatchesHitAndMiss(): void
    {
        $this->listen();
        $cache = $this->store();
        $cache->set('present', null);

        self::assertSame('fallback', $cache->get('absent', 'fallback'));
        self::assertNull($cache->get('present', 'fallback'));
        self::assertSame(['miss:absent', 'hit:present=NULL'], $this->seen);
    }

    public function testRememberDispatchesMissThenHit(): void
    {
        $this->listen();
        $cache = $this->store();

        self::assertSame(1, $cache->remember('k', static fn() => 1));
        self::assertSame(1, $cache->remember('k', static fn() => 2));
        self::assertSame(['miss:k', 'hit:k=1'], $this->seen);
    }

    public function testNothingIsDispatchedWithoutListeners(): void
    {
        $dispatched = false;
        $provider = new ListenerProvider();
        $dispatcher = new class ($dispatched) implements EventDispatcherInterface {
            public function __construct(private bool &$flag)
            {
            }

            public function dispatch(object $event): object
            {
                $this->flag = true;
                return $event;
            }
        };
        $cache = $this->store(new EventService($dispatcher, $provider));
        $cache->set('a', 1);
        $cache->get('a');
        $cache->get('b');
        $cache->remember('c', static fn() => 3);

        self::assertFalse($dispatched);
    }

    public function testAFailingListenerDoesNotBreakTheRead(): void
    {
        $this->events->addListener(CacheHitEvent::class, static function (): void {
            throw new \RuntimeException('listener boom');
        });
        $cache = $this->store();
        $cache->set('a', 'v');

        $previous = ini_set('error_log', '/dev/null');
        try {
            self::assertSame('v', $cache->get('a'));
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }

    public function testReadsWorkWhenNoEventServiceResolves(): void
    {
        $cache = new EventDispatchingCacheStore(new ArrayCacheDriver(), static fn() => null);
        $cache->set('a', 'v');

        self::assertSame('v', $cache->get('a'));
        self::assertSame('d', $cache->get('b', 'd'));
        self::assertSame(5, $cache->remember('c', static fn() => 5));
    }

    public function testDriverSpecificMethodsStillReachTheDriver(): void
    {
        $cache = $this->store();
        $cache->set('a', 'v');

        /** @phpstan-ignore method.notFound */
        self::assertSame('v', $cache->pull('a'));
        self::assertFalse($cache->has('a'));
    }
}
