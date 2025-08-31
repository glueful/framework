<?php

declare(strict_types=1);

namespace Glueful\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Cache Store Interface
 *
 * Extends PSR-16 CacheInterface with advanced cache operations.
 * Provides unified interface across all cache drivers (Redis, Memcached, File, etc.)
 * with consistent implementation of advanced features like pattern deletion,
 * counters, sorted sets, and introspection methods.
 */
/**
 * @template TValue
 */
interface CacheStore extends CacheInterface
{
    // PSR-16 methods re-declared with generics:
    /**
     * @return TValue|null
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * @param TValue $value
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    /**
     * @param iterable<string> $keys
     * @return iterable<string, TValue|null>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable;

    /**
     * @param iterable<string, TValue> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool;

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool;

    public function has(string $key): bool;

    /**
     * Set value only if key does not exist (atomic operation)
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds
     * @return bool True if key was set (didn't exist), false if key already exists
     */
    /**
     * @param TValue $value
     */
    public function setNx(string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * Get multiple cached values (alias for PSR-16 getMultiple for backward compatibility)
     *
     * @param list<string> $keys
     * @return array<string, TValue|null>
     */
    public function mget(array $keys): array;

    /**
     * Store multiple values in cache (alias for PSR-16 setMultiple for backward compatibility)
     *
     * @param array<string, TValue> $values
     * @param int $ttl Time to live in seconds
     * @return bool True if all values stored successfully
     */
    public function mset(array $values, int $ttl = 3600): bool;

    /**
     * Increment numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to increment by (default: 1)
     * @return int New value after increment
     */
    public function increment(string $key, int $value = 1): int;

    /**
     * Decrement numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to decrement by (default: 1)
     * @return int New value after decrement
     */
    public function decrement(string $key, int $value = 1): int;

    /**
     * Get remaining TTL
     *
     * @param string $key Cache key
     * @return int Remaining time in seconds
     */
    public function ttl(string $key): int;

    /**
     * Clear all cached values (alias for PSR-16 clear() for backward compatibility)
     *
     * @return bool True if cache cleared successfully
     */
    public function flush(): bool;

    /**
     * Add to sorted set
     *
     * @param string $key Set key
     * @param array $scoreValues Score-value pairs
     * @return bool True if added successfully
     */
    public function zadd(string $key, array $scoreValues): bool;

    /**
     * Remove set members by score
     *
     * @param string $key Set key
     * @param string $min Minimum score
     * @param string $max Maximum score
     * @return int Number of removed members
     */
    public function zremrangebyscore(string $key, string $min, string $max): int;

    /**
     * Get set cardinality
     *
     * @param string $key Set key
     * @return int Number of members
     */
    public function zcard(string $key): int;

    /**
     * Get set range
     *
     * @param string $key Set key
     * @param int $start Start index
     * @param int $stop End index
     * @return list<string> Range of members
     */
    public function zrange(string $key, int $start, int $stop): array;

    /**
     * Set key expiration
     *
     * @param string $key Cache key
     * @param int $seconds Time until expiration
     * @return bool True if expiration set
     */
    public function expire(string $key, int $seconds): bool;

    /**
     * Delete key (alias for PSR-16 delete() for backward compatibility)
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public function del(string $key): bool;

    /**
     * Delete keys matching a pattern
     *
     * @param string $pattern Pattern to match (supports wildcards *)
     * @return bool True if deletion successful
     */
    public function deletePattern(string $pattern): bool;

    /**
     * Get all cache keys
     *
     * @param string $pattern Optional pattern to filter keys
     * @return list<string> List of cache keys
     */
    public function getKeys(string $pattern = '*'): array;

    /**
     * Get cache statistics and information
     *
     * @return array<string, mixed> Cache statistics
     */
    public function getStats(): array;

    /**
     * Get all cache keys
     *
     * @return list<string> List of all cache keys
     */
    public function getAllKeys(): array;

    /**
     * Get count of keys matching pattern
     *
     * @param string $pattern Pattern to match (supports wildcards *)
     * @return int Number of matching keys
     */
    public function getKeyCount(string $pattern = '*'): int;

    /**
     * Get cache driver capabilities
     *
     * @return array<string, mixed> Driver capabilities and features
     */
    public function getCapabilities(): array;

    /**
     * Add tags to a cache key for grouped invalidation
     *
     * @param string $key Cache key
     * @param list<string> $tags Array of tags to associate with the key
     * @return bool True if tags added successfully
     */
    public function addTags(string $key, array $tags): bool;

    /**
     * Invalidate all cache entries with specified tags
     *
     * @param list<string> $tags Array of tags to invalidate
     * @return bool True if invalidation successful
     */
    public function invalidateTags(array $tags): bool;

    /**
     * Remember pattern - get from cache or execute callback and store result
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss
     * @param int|null $ttl Time to live in seconds (null = default)
     * @return mixed Cached or computed value
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed;
}
