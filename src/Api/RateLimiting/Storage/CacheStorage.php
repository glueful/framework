<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Storage;

use Glueful\Api\RateLimiting\Contracts\StorageInterface;
use Glueful\Cache\CacheStore;

/**
 * Storage adapter wrapping the framework's CacheStore
 *
 * Provides rate limiting storage operations using the configured
 * cache backend (Redis, Memcached, etc.).
 */
final class CacheStorage implements StorageInterface
{
    private const PREFIX = 'rate_limit:';

    /**
     * @param CacheStore<mixed> $cache The cache store instance
     */
    public function __construct(
        private readonly CacheStore $cache
    ) {
    }

    public function increment(string $key, int $amount = 1): int
    {
        return $this->cache->increment(self::PREFIX . $key, $amount);
    }

    public function decrement(string $key, int $amount = 1): int
    {
        return $this->cache->decrement(self::PREFIX . $key, $amount);
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get(self::PREFIX . $key);

        if ($value === null || $value === false) {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        return $this->cache->set(self::PREFIX . $key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete(self::PREFIX . $key);
    }

    public function expire(string $key, int $seconds): bool
    {
        return $this->cache->expire(self::PREFIX . $key, $seconds);
    }

    public function ttl(string $key): int
    {
        return $this->cache->ttl(self::PREFIX . $key);
    }

    public function zadd(string $key, array $scoreValues): bool
    {
        return $this->cache->zadd(self::PREFIX . $key, $scoreValues);
    }

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        return $this->cache->zremrangebyscore(self::PREFIX . $key, $min, $max);
    }

    public function zcard(string $key): int
    {
        return $this->cache->zcard(self::PREFIX . $key);
    }

    public function zrange(string $key, int $start, int $stop): array
    {
        return $this->cache->zrange(self::PREFIX . $key, $start, $stop);
    }

    public function exists(string $key): bool
    {
        return $this->cache->has(self::PREFIX . $key);
    }
}
