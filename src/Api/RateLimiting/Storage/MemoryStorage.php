<?php

declare(strict_types=1);

namespace Glueful\Api\RateLimiting\Storage;

use Glueful\Api\RateLimiting\Contracts\StorageInterface;

/**
 * In-memory storage for testing
 *
 * Provides a simple array-backed storage implementation
 * suitable for unit testing rate limiting components.
 */
final class MemoryStorage implements StorageInterface
{
    /** @var array<string, mixed> Storage data */
    private array $data = [];

    /** @var array<string, int> Expiration timestamps */
    private array $expirations = [];

    /** @var array<string, array<string, float>> Sorted sets */
    private array $sortedSets = [];

    public function increment(string $key, int $amount = 1): int
    {
        $this->removeIfExpired($key);

        $current = isset($this->data[$key]) ? (int) $this->data[$key] : 0;
        $newValue = $current + $amount;
        $this->data[$key] = $newValue;

        return $newValue;
    }

    public function decrement(string $key, int $amount = 1): int
    {
        $this->removeIfExpired($key);

        $current = isset($this->data[$key]) ? (int) $this->data[$key] : 0;
        $newValue = $current - $amount;
        $this->data[$key] = $newValue;

        return $newValue;
    }

    public function get(string $key): ?string
    {
        $this->removeIfExpired($key);

        if (!isset($this->data[$key])) {
            return null;
        }

        $value = $this->data[$key];

        return is_string($value) ? $value : (string) $value;
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        $this->data[$key] = is_string($value) ? $value : json_encode($value);
        $this->expirations[$key] = time() + $ttl;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key], $this->expirations[$key], $this->sortedSets[$key]);

        return true;
    }

    public function expire(string $key, int $seconds): bool
    {
        if (!$this->exists($key) && !isset($this->sortedSets[$key])) {
            return false;
        }

        $this->expirations[$key] = time() + $seconds;

        return true;
    }

    public function ttl(string $key): int
    {
        if (!isset($this->expirations[$key])) {
            if ($this->exists($key)) {
                return -1; // No expiration
            }
            return -2; // Key doesn't exist
        }

        $ttl = $this->expirations[$key] - time();

        if ($ttl <= 0) {
            $this->delete($key);
            return -2;
        }

        return $ttl;
    }

    public function zadd(string $key, array $scoreValues): bool
    {
        $this->removeIfExpired($key);

        if (!isset($this->sortedSets[$key])) {
            $this->sortedSets[$key] = [];
        }

        foreach ($scoreValues as $member => $score) {
            $this->sortedSets[$key][(string) $member] = (float) $score;
        }

        // Sort by score
        asort($this->sortedSets[$key]);

        return true;
    }

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        $this->removeIfExpired($key);

        if (!isset($this->sortedSets[$key])) {
            return 0;
        }

        $minScore = $min === '-inf' ? PHP_FLOAT_MIN : (float) $min;
        $maxScore = $max === '+inf' ? PHP_FLOAT_MAX : (float) $max;

        $removed = 0;

        foreach ($this->sortedSets[$key] as $member => $score) {
            if ($score >= $minScore && $score <= $maxScore) {
                unset($this->sortedSets[$key][$member]);
                $removed++;
            }
        }

        return $removed;
    }

    public function zcard(string $key): int
    {
        $this->removeIfExpired($key);

        return isset($this->sortedSets[$key]) ? count($this->sortedSets[$key]) : 0;
    }

    public function zrange(string $key, int $start, int $stop): array
    {
        $this->removeIfExpired($key);

        if (!isset($this->sortedSets[$key])) {
            return [];
        }

        $members = array_keys($this->sortedSets[$key]);

        // Handle negative indices
        $count = count($members);
        if ($start < 0) {
            $start = max(0, $count + $start);
        }
        if ($stop < 0) {
            $stop = $count + $stop;
        }

        // Get the slice
        $length = $stop - $start + 1;
        if ($length <= 0) {
            return [];
        }

        return array_slice($members, $start, $length);
    }

    public function exists(string $key): bool
    {
        $this->removeIfExpired($key);

        return isset($this->data[$key]) || isset($this->sortedSets[$key]);
    }

    /**
     * Remove key if expired
     */
    private function removeIfExpired(string $key): void
    {
        if (isset($this->expirations[$key]) && $this->expirations[$key] <= time()) {
            unset($this->data[$key], $this->expirations[$key], $this->sortedSets[$key]);
        }
    }

    /**
     * Clear all data (useful for testing)
     */
    public function clear(): void
    {
        $this->data = [];
        $this->expirations = [];
        $this->sortedSets = [];
    }
}
