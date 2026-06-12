<?php

declare(strict_types=1);

namespace Glueful\Cache\Drivers;

use Memcached;
use Psr\SimpleCache\InvalidArgumentException;
use Glueful\Exceptions\CacheException;
use Glueful\Cache\CacheStore;
use Glueful\Security\SecureSerializer;

/**
 * Memcached Cache Driver
 *
 * Implements cache operations using Memcached backend.
 * Provides sorted set emulation using stored arrays.
 *
 * Values are stored as SecureSerializer-encoded strings so the Memcached
 * extension never runs its internal (ungated) unserialize on payloads.
 *
 * @template TValue
 * @implements CacheStore<TValue>
 */
class MemcachedCacheDriver implements CacheStore
{
    /** @var Memcached Memcached connection instance */
    private Memcached $memcached;

    /** @var SecureSerializer Secure serialization service */
    private SecureSerializer $serializer;

    /**
     * Constructor
     *
     * @param Memcached $memcached Configured Memcached connection
     */
    public function __construct(Memcached $memcached)
    {
        $this->memcached = $memcached;
        $this->serializer = SecureSerializer::forCache();
    }

    /**
     * Add to sorted set
     *
     * Emulates Redis ZADD using array storage.
     *
     * @param string $key Set key
     * @param array<string, int|float> $scoreValues Score-value pairs
     * @return bool True if added successfully
     */
    public function zadd(string $key, array $scoreValues): bool
    {
        $timestamps = $this->getScoreSet($key);
        foreach ($scoreValues as $member => $score) {
            $timestamps[$member] = $score;
        }
        return $this->memcached->set($key, $this->serializer->serialize($timestamps));
    }

    /**
     * Remove set members by score
     *
     * Emulates Redis ZREMRANGEBYSCORE.
     *
     * @param string $key Set key
     * @param string $min Minimum score
     * @param string $max Maximum score
     * @return int Number of removed members
     */
    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        $timestamps = $this->getScoreSet($key);
        $filtered = array_filter($timestamps, fn($score) => $score > (int) $max);
        $this->memcached->set($key, $this->serializer->serialize($filtered));
        return count($timestamps) - count($filtered);
    }

    /**
     * Get set cardinality
     *
     * @param string $key Set key
     * @return int Number of members
     */
    public function zcard(string $key): int
    {
        return count($this->getScoreSet($key));
    }

    /**
     * Get set range
     *
     * Emulates Redis ZRANGE with sorting.
     *
     * @param string $key Set key
     * @param int $start Start index
     * @param int $stop End index
     * @return list<string> Range of members
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        $timestamps = $this->getScoreSet($key);
        asort($timestamps); // sort by score ascending
        return array_map('strval', array_slice(array_keys($timestamps), $start, $stop - $start + 1));
    }

    /**
     * Set key expiration
     *
     * @param string $key Cache key
     * @param int $seconds Time until expiration
     * @return bool True if expiration set
     */
    public function expire(string $key, int $seconds): bool
    {
        return $this->memcached->touch($key, time() + $seconds);
    }

    /**
     * Delete key
     *
     * @param string $key Cache key
     * @return bool True if deleted
     */
    public function del(string $key): bool
    {
        return $this->memcached->delete($key);
    }

    /**
     * Get cached value (PSR-16 compatible)
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key not found
     * @return TValue|null Value or default if not found
     * @throws InvalidArgumentException If key is invalid
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $value = $this->memcached->get($key);
        if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
            return $default;
        }
        return $this->decode($value);
    }

    /**
     * Store value in cache (PSR-16 compatible)
     *
     * @param string $key Cache key
     * @param TValue $value Value to store
     * @param null|int|\DateInterval $ttl Time to live
     * @return bool True if stored successfully
     * @throws InvalidArgumentException If key is invalid
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $seconds = $this->normalizeTtl($ttl);
        return $this->memcached->set($key, $this->serializer->serialize($value), $seconds ?? 3600);
    }

    /**
     * Set value only if key does not exist (atomic operation)
     *
     * Uses Memcached's add() method which only sets if key doesn't exist.
     *
     * @param string $key Cache key
     * @param TValue $value Value to store
     * @param int $ttl Time to live in seconds
     * @return bool True if key was set (didn't exist), false if key already exists
     */
    public function setNx(string $key, mixed $value, int $ttl = 3600): bool
    {
        // Memcached's add() method only sets if key doesn't exist
        return $this->memcached->add($key, $this->serializer->serialize($value), $ttl);
    }

    /**
     * Get multiple cached values
     *
     * @param list<string> $keys Array of cache keys
     * @return array<string, TValue|null> Indexed array by key (driver returns list in same order)
     */
    public function mget(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $values = $this->memcached->getMulti($keys);
        $result = [];

        // Return values indexed by key; include missing keys with null
        foreach ($keys as $key) {
            $result[$key] = isset($values[$key]) ? $this->decode($values[$key]) : null;
        }

        return $result;
    }

    /**
     * Store multiple values in cache
     *
     * @param array<string, TValue> $values Associative array of key => value pairs
     * @param int $ttl Time to live in seconds
     * @return bool True if all values stored successfully
     */
    public function mset(array $values, int $ttl = 3600): bool
    {
        if ($values === []) {
            return true;
        }

        // Memcached setMulti returns true if all keys were stored successfully
        $encoded = array_map(fn($value) => $this->serializer->serialize($value), $values);
        return $this->memcached->setMulti($encoded, $ttl);
    }

    /**
     * Delete cached value (PSR-16 compatible)
     *
     * @param string $key Cache key
     * @return bool True if deleted
     * @throws InvalidArgumentException If key is invalid
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);
        return $this->memcached->delete($key);
    }

    /**
     * Increment numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to increment by (default: 1)
     * @return int New value after increment
     */
    public function increment(string $key, int $value = 1): int
    {
        $result = $this->memcached->increment($key, $value, 0);
        return $result !== false ? (int) $result : 0;
    }

    /**
     * Decrement numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to decrement by (default: 1)
     * @return int New value after decrement
     */
    public function decrement(string $key, int $value = 1): int
    {
        $result = $this->memcached->decrement($key, $value, 0);
        return $result !== false ? (int) $result : 0;
    }

    /**
     * Get remaining TTL
     *
     * Note: Memcached doesn't support direct TTL lookup
     *
     * @param string $key Cache key
     * @return int Approximate TTL or 0 if expired
     */
    public function ttl(string $key): int
    {
        // Memcached doesn't provide direct TTL lookup
        return $this->get($key) !== null ? 3600 : 0;
    }

    /**
     * Clear all cached values (PSR-16 compatible)
     *
     * @return bool True if cache cleared
     */
    public function clear(): bool
    {
        return $this->memcached->flush();
    }

    /**
     * Clear all cached values (alias for PSR-16 clear() for backward compatibility)
     *
     * @return bool True if cache cleared
     */
    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * Delete keys matching a pattern
     *
     * Note: Memcached doesn't support pattern-based deletion natively.
     * This is a limited implementation that cannot efficiently handle patterns.
     *
     * @param string $pattern Pattern to match (supports wildcards *)
     * @return bool True if deletion successful
     */
    public function deletePattern(string $pattern): bool
    {
        // Memcached doesn't support pattern-based operations
        // This operation is not feasible without key enumeration
        return false;
    }

    /**
     * Get all cache keys
     *
     * Note: Memcached doesn't support key enumeration natively.
     * This method returns an empty array as keys cannot be retrieved.
     *
     * @param string $pattern Optional pattern to filter keys
     * @return list<string> List of cache keys (always empty for Memcached)
     */
    public function getKeys(string $pattern = '*'): array
    {
        // Memcached doesn't support key enumeration
        return [];
    }

    /**
     * Get cache statistics and information
     *
     * Returns Memcached server statistics.
     *
     * @return array<string, mixed> Cache statistics
     */
    public function getStats(): array
    {
        try {
            $stats = $this->memcached->getStats();

            if ($stats === []) {
                return [
                    'driver' => 'memcached',
                    'error' => 'No server statistics available'
                ];
            }

            // Get stats from first server
            $serverStats = reset($stats);

            return [
                'driver' => 'memcached',
                'version' => $serverStats['version'] ?? 'unknown',
                'uptime' => $serverStats['uptime'] ?? 0,
                'memory' => [
                    'limit' => $serverStats['limit_maxbytes'] ?? 0,
                    'used' => $serverStats['bytes'] ?? 0,
                    'available' => ($serverStats['limit_maxbytes'] ?? 0) - ($serverStats['bytes'] ?? 0),
                ],
                'performance' => [
                    'total_connections' => $serverStats['total_connections'] ?? 0,
                    'current_connections' => $serverStats['curr_connections'] ?? 0,
                    'get_hits' => $serverStats['get_hits'] ?? 0,
                    'get_misses' => $serverStats['get_misses'] ?? 0,
                    'hit_rate' => $this->calculateHitRate($serverStats),
                ],
                'operations' => [
                    'cmd_get' => $serverStats['cmd_get'] ?? 0,
                    'cmd_set' => $serverStats['cmd_set'] ?? 0,
                    'get_hits' => $serverStats['get_hits'] ?? 0,
                    'get_misses' => $serverStats['get_misses'] ?? 0,
                ],
                'items' => [
                    'current_items' => $serverStats['curr_items'] ?? 0,
                    'total_items' => $serverStats['total_items'] ?? 0,
                ],
                'limitations' => [
                    'pattern_deletion' => false,
                    'key_enumeration' => false,
                    'note' => 'Memcached does not support pattern operations or key enumeration'
                ]
            ];
        } catch (\Exception $e) {
            return [
                'driver' => 'memcached',
                'error' => 'Failed to get stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all cache keys
     *
     * Note: Memcached doesn't support key enumeration natively.
     * This method returns an empty array as keys cannot be retrieved.
     *
     * @return list<string> List of all cache keys (always empty for Memcached)
     */
    public function getAllKeys(): array
    {
        return [];
    }

    /**
     * Check if a cache key exists (PSR-16)
     *
     * @param string $key Cache key
     * @return bool True if key exists
     * @throws InvalidArgumentException If key is invalid
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);
        $this->memcached->get($key);
        return $this->memcached->getResultCode() !== Memcached::RES_NOTFOUND;
    }

    /**
     * Get multiple cached values (PSR-16)
     *
     * @param iterable<mixed> $keys Cache keys
     * @param mixed $default Default value for missing keys
     * @return iterable<string, mixed|null> Values in same order as keys
     * @throws InvalidArgumentException If any key is invalid
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keyArray = is_array($keys) ? $keys : iterator_to_array($keys);

        foreach ($keyArray as $key) {
            $this->validateKey($key);
        }

        if ($keyArray === []) {
            return [];
        }

        $values = $this->memcached->getMulti($keyArray);
        $result = [];

        foreach ($keyArray as $key) {
            $result[$key] = isset($values[$key]) ? $this->decode($values[$key]) : $default;
        }

        return $result;
    }

    /**
     * Store multiple values in cache (PSR-16)
     *
     * @param iterable<mixed, mixed> $values Key-value pairs
     * @param null|int|\DateInterval $ttl Time to live
     * @return bool True if all values stored successfully
     * @throws InvalidArgumentException If any key is invalid
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $valueArray = is_array($values) ? $values : iterator_to_array($values);

        foreach (array_keys($valueArray) as $key) {
            $this->validateKey($key);
        }

        return $this->mset($valueArray, $this->normalizeTtl($ttl) ?? 3600);
    }

    /**
     * Delete multiple cache keys (PSR-16)
     *
     * @param iterable<mixed> $keys Cache keys
     * @return bool True if all keys deleted successfully
     * @throws InvalidArgumentException If any key is invalid
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keyArray = is_array($keys) ? $keys : iterator_to_array($keys);

        foreach ($keyArray as $key) {
            $this->validateKey($key);
        }

        if ($keyArray === []) {
            return true;
        }

        $result = $this->memcached->deleteMulti($keyArray);
        // deleteMulti returns array of results, we need boolean
        return is_array($result) && !in_array(false, $result, true);
    }

    /**
     * Get count of keys matching pattern
     *
     * Note: Memcached doesn't support pattern operations
     *
     * @param string $pattern Pattern to match (supports wildcards *)
     * @return int Number of matching keys (always 0 for Memcached)
     */
    public function getKeyCount(string $pattern = '*'): int
    {
        return 0; // Memcached doesn't support key enumeration
    }

    /**
     * Get cache driver capabilities
     *
     * @return array<string, mixed> Driver capabilities and features
     */
    public function getCapabilities(): array
    {
        return [
            'driver' => 'memcached',
            'features' => [
                'persistent' => true,
                'distributed' => true,
                'atomic_operations' => true,
                'pattern_deletion' => false, // Memcached limitation
                'sorted_sets' => true,       // Emulated via arrays
                'counters' => true,
                'expiration' => true,
                'bulk_operations' => true,
                'tags' => false,             // Not supported; Memcached lacks set primitives
                'key_enumeration' => false,  // Memcached limitation
            ],
            'data_types' => ['string', 'integer', 'float', 'boolean', 'array', 'object'],
            'max_key_length' => 250,        // Memcached limit
            'max_value_size' => 1024 * 1024, // 1MB default
            'limitations' => [
                'No pattern deletion support',
                'No key enumeration support',
                'TTL lookup not supported natively',
            ],
        ];
    }

    /**
     * Tag-based invalidation is not supported by this driver.
     *
     * Memcached lacks SET primitives, so tagging would require a separate
     * indexing layer (e.g. namespace versioning) that is intentionally out of
     * scope. Callers should branch on `getCapabilities()['features']['tags']`
     * and fall back to direct key deletion, or switch to the Redis driver
     * when tag-based invalidation is required.
     *
     * @param string $key Cache key
     * @param list<string> $tags Array of tags
     * @return bool Always false
     */
    public function addTags(string $key, array $tags): bool
    {
        return false;
    }

    /**
     * Tag-based invalidation is not supported by this driver.
     *
     * See {@see addTags()} for the rationale and recommended alternatives.
     *
     * @param list<string> $tags Array of tags
     * @return bool Always false
     */
    public function invalidateTags(array $tags): bool
    {
        return false;
    }

    /**
     * Remember pattern - get from cache or execute callback and store result
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss
     * @param int|null $ttl Time to live in seconds (null = default)
     * @return mixed Cached or computed value
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl ?? 3600);

        return $value;
    }

    /**
     * Decode a stored value through the gadget-gated serializer.
     *
     * Values written by this driver are SecureSerializer-encoded strings.
     * Non-string values (legacy entries the Memcached extension already
     * materialized) are passed through unchanged.
     *
     * @param mixed $value Raw value from Memcached
     * @return mixed Decoded value
     */
    private function decode(mixed $value): mixed
    {
        return is_string($value) ? $this->serializer->unserialize($value) : $value;
    }

    /**
     * Read an emulated sorted set, treating unreadable payloads as empty.
     *
     * @param string $key Set key
     * @return array<string, int|float> Member => score pairs
     */
    private function getScoreSet(string $key): array
    {
        $value = $this->memcached->get($key);

        if (!is_string($value)) {
            return is_array($value) ? $value : [];
        }

        try {
            $decoded = $this->serializer->unserialize($value);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Calculate cache hit rate from Memcached stats
     *
     * @param array<string, mixed> $stats Server statistics
     * @return float Hit rate as percentage
     */
    private function calculateHitRate(array $stats): float
    {
        $hits = $stats['get_hits'] ?? 0;
        $misses = $stats['get_misses'] ?? 0;
        $total = $hits + $misses;

        if ($total === 0) {
            return 0.0;
        }

        return round(($hits / $total) * 100, 2);
    }

    /**
     * Validate cache key according to PSR-16 requirements
     *
     * @param string $key Cache key to validate
     * @throws InvalidArgumentException If key is invalid
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw CacheException::emptyKey();
        }

        if (strpbrk($key, '{}()/\\@:') !== false) {
            throw CacheException::invalidCharacters($key);
        }

        // Memcached has a 250 character limit for keys
        if (strlen($key) > 250) {
            throw CacheException::invalidKey($key . ' (exceeds 250 character limit)');
        }
    }

    /**
     * Normalize TTL value to seconds
     *
     * @param null|int|\DateInterval $ttl TTL value
     * @return int|null TTL in seconds or null for no expiration
     */
    private function normalizeTtl(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            $future = $now->add($ttl);
            return $future->getTimestamp() - $now->getTimestamp();
        }

        return max(1, $ttl);
    }
}
