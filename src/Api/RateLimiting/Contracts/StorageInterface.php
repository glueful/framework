<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Contracts;

/**
 * Interface for rate limiter storage backends
 *
 * Abstracts the storage operations needed for rate limiting,
 * including counters, sorted sets, and key expiration.
 */
interface StorageInterface
{
    /**
     * Increment a counter
     *
     * @param string $key Storage key
     * @param int $amount Amount to increment by
     * @return int New value after increment
     */
    public function increment(string $key, int $amount = 1): int;

    /**
     * Decrement a counter
     *
     * @param string $key Storage key
     * @param int $amount Amount to decrement by
     * @return int New value after decrement
     */
    public function decrement(string $key, int $amount = 1): int;

    /**
     * Get a value
     *
     * @param string $key Storage key
     * @return string|null Value or null if not found
     */
    public function get(string $key): ?string;

    /**
     * Set a value with optional TTL
     *
     * @param string $key Storage key
     * @param mixed $value Value to store
     * @param int $ttl Time-to-live in seconds
     * @return bool Success status
     */
    public function set(string $key, mixed $value, int $ttl): bool;

    /**
     * Delete a key
     *
     * @param string $key Storage key
     * @return bool Success status
     */
    public function delete(string $key): bool;

    /**
     * Set expiration time on a key
     *
     * @param string $key Storage key
     * @param int $seconds Expiration time in seconds
     * @return bool Success status
     */
    public function expire(string $key, int $seconds): bool;

    /**
     * Get TTL for a key
     *
     * @param string $key Storage key
     * @return int TTL in seconds, -1 if no expiration, -2 if key doesn't exist
     */
    public function ttl(string $key): int;

    /**
     * Add members to a sorted set
     *
     * @param string $key Storage key
     * @param array<string, float|int> $scoreValues Map of member => score
     * @return bool Success status
     */
    public function zadd(string $key, array $scoreValues): bool;

    /**
     * Remove members from sorted set by score range
     *
     * @param string $key Storage key
     * @param string $min Minimum score (use '-inf' for negative infinity)
     * @param string $max Maximum score (use '+inf' for positive infinity)
     * @return int Number of removed members
     */
    public function zremrangebyscore(string $key, string $min, string $max): int;

    /**
     * Get the number of members in a sorted set
     *
     * @param string $key Storage key
     * @return int Number of members
     */
    public function zcard(string $key): int;

    /**
     * Get range of members from sorted set
     *
     * @param string $key Storage key
     * @param int $start Start index
     * @param int $stop Stop index
     * @return array<string> Members in the range
     */
    public function zrange(string $key, int $start, int $stop): array;

    /**
     * Check if a key exists
     *
     * @param string $key Storage key
     * @return bool True if key exists
     */
    public function exists(string $key): bool;
}
