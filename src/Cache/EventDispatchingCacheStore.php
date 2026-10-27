<?php

declare(strict_types=1);

namespace Glueful\Cache;

use Glueful\Events\Cache\CacheHitEvent;
use Glueful\Events\Cache\CacheMissEvent;
use Glueful\Events\EventService;

/**
 * Cache store decorator that dispatches CacheHitEvent / CacheMissEvent for get() and remember().
 *
 * Wraps the container's `cache.store` so every driver reports hits and misses from one place.
 * Events are only built and dispatched when a listener is registered for them, so an unobserved
 * cache pays a single listener lookup per read. The event service is resolved lazily on first
 * read (absent → no events), and neither a resolution failure nor a failing listener can break a
 * cache read. Every other operation delegates to the wrapped driver unchanged; driver-specific
 * methods outside the CacheStore contract are forwarded through __call().
 *
 * @template TValue
 * @implements CacheStore<TValue>
 */
final class EventDispatchingCacheStore implements CacheStore
{
    private ?EventService $events = null;
    private bool $resolved = false;

    /**
     * @param CacheStore<TValue> $inner
     * @param \Closure(): ?EventService $eventsResolver
     */
    public function __construct(
        private readonly CacheStore $inner,
        private readonly \Closure $eventsResolver
    ) {
    }

    /** @return CacheStore<TValue> */
    public function getInnerStore(): CacheStore
    {
        return $this->inner;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $events = $this->listeningEvents();
        if ($events === null) {
            return $this->inner->get($key, $default);
        }

        $missing = new \stdClass();
        $start = hrtime(true);
        $value = $this->inner->get($key, $missing);
        $elapsed = (hrtime(true) - $start) / 1e6;

        if ($value === $missing) {
            $this->dispatch($events, CacheMissEvent::class, static fn() => new CacheMissEvent($key));
            return $default;
        }

        $this->dispatch($events, CacheHitEvent::class, static fn() => new CacheHitEvent($key, $value, [], $elapsed));
        return $value;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $events = $this->listeningEvents();
        if ($events === null) {
            return $this->inner->remember($key, $callback, $ttl);
        }

        // Delegate to the driver's own remember() so its TTL/null semantics are unchanged; the
        // wrapped callback only runs on a miss, which is how a hit is told apart.
        $missed = false;
        $start = hrtime(true);
        $value = $this->inner->remember(
            $key,
            function () use ($events, $key, $callback, &$missed): mixed {
                $missed = true;
                $this->dispatch(
                    $events,
                    CacheMissEvent::class,
                    static fn() => new CacheMissEvent($key, [], $callback)
                );
                return $callback();
            },
            $ttl
        );

        if (!$missed) {
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->dispatch(
                $events,
                CacheHitEvent::class,
                static fn() => new CacheHitEvent($key, $value, [], $elapsed)
            );
        }

        return $value;
    }

    /**
     * The event service when anything listens for cache hits or misses; null otherwise.
     */
    private function listeningEvents(): ?EventService
    {
        if (!$this->resolved) {
            $this->resolved = true;
            try {
                $this->events = ($this->eventsResolver)();
            } catch (\Throwable) {
                $this->events = null;
            }
        }

        if ($this->events === null) {
            return null;
        }

        try {
            $listening = $this->events->hasListeners(CacheHitEvent::class)
                || $this->events->hasListeners(CacheMissEvent::class);
        } catch (\Throwable) {
            return null;
        }

        return $listening ? $this->events : null;
    }

    /**
     * @param class-string $eventClass
     * @param callable(): object $makeEvent
     */
    private function dispatch(EventService $events, string $eventClass, callable $makeEvent): void
    {
        try {
            if ($events->hasListeners($eventClass)) {
                $events->dispatch($makeEvent());
            }
        } catch (\Throwable) {
            // Observability must never break a cache read.
        }
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return $this->inner->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->inner->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        return $this->inner->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->inner->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function setNx(string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->inner->setNx($key, $value, $ttl);
    }

    public function mget(array $keys): array
    {
        return $this->inner->mget($keys);
    }

    public function mset(array $values, int $ttl = 3600): bool
    {
        return $this->inner->mset($values, $ttl);
    }

    public function increment(string $key, int $value = 1): int
    {
        return $this->inner->increment($key, $value);
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->inner->decrement($key, $value);
    }

    public function ttl(string $key): int
    {
        return $this->inner->ttl($key);
    }

    public function flush(): bool
    {
        return $this->inner->flush();
    }

    public function zadd(string $key, array $scoreValues): bool
    {
        return $this->inner->zadd($key, $scoreValues);
    }

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        return $this->inner->zremrangebyscore($key, $min, $max);
    }

    public function zcard(string $key): int
    {
        return $this->inner->zcard($key);
    }

    public function zrange(string $key, int $start, int $stop): array
    {
        return $this->inner->zrange($key, $start, $stop);
    }

    public function expire(string $key, int $seconds): bool
    {
        return $this->inner->expire($key, $seconds);
    }

    public function del(string $key): bool
    {
        return $this->inner->del($key);
    }

    public function deletePattern(string $pattern): bool
    {
        return $this->inner->deletePattern($pattern);
    }

    public function getKeys(string $pattern = '*'): array
    {
        return $this->inner->getKeys($pattern);
    }

    public function getStats(): array
    {
        return $this->inner->getStats();
    }

    public function getAllKeys(): array
    {
        return $this->inner->getAllKeys();
    }

    public function getKeyCount(string $pattern = '*'): int
    {
        return $this->inner->getKeyCount($pattern);
    }

    public function getCapabilities(): array
    {
        return $this->inner->getCapabilities();
    }

    public function addTags(string $key, array $tags): bool
    {
        return $this->inner->addTags($key, $tags);
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->inner->invalidateTags($tags);
    }

    /**
     * Forward driver-specific methods (pull, forever, rememberForever, …) to the wrapped driver.
     *
     * @param array<int|string, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $target = [$this->inner, $method];
        if (!is_callable($target)) {
            throw new \BadMethodCallException(sprintf(
                'Method %s::%s() does not exist.',
                $this->inner::class,
                $method
            ));
        }

        return $target(...$arguments);
    }
}
