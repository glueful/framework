<?php

declare(strict_types=1);

namespace Glueful\Cache\Drivers;

use Glueful\Cache\CacheStore;
use DateInterval;

/**
 * Array-based cache driver for testing
 *
 * Stores cache data in memory only, perfect for unit tests.
 * Data is not persisted between requests.
 *
 * @template TValue
 * @implements CacheStore<TValue>
 */
class ArrayCacheDriver implements CacheStore
{
    /** @var array<string, mixed> In-memory cache storage */
    private array $cache = [];

    /** @var array<string, int> Expiration timestamps */
    private array $expirations = [];

    /** @var array<string, array<string>> Tags associated with keys */
    private array $tags = [];

    /** @var array<string, array<int, mixed>> Sorted sets */
    private array $sortedSets = [];

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Check if key exists and hasn't expired
        if (!array_key_exists($key, $this->cache)) {
            return $default;
        }

        // Check expiration
        if (isset($this->expirations[$key]) && $this->expirations[$key] < time()) {
            unset($this->cache[$key], $this->expirations[$key]);
            return $default;
        }

        return $this->cache[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->cache[$key] = $value;

        if ($ttl instanceof DateInterval) {
            $ttl = (int) $ttl->format('%s');
        }

        if ($ttl !== null && $ttl > 0) {
            $this->expirations[$key] = time() + $ttl;
        } else {
            unset($this->expirations[$key]);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->cache)) {
            return false;
        }

        // Check expiration
        if (isset($this->expirations[$key]) && $this->expirations[$key] < time()) {
            unset($this->cache[$key], $this->expirations[$key]);
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        unset($this->cache[$key], $this->expirations[$key], $this->tags[$key]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $this->cache = [];
        $this->expirations = [];
        $this->tags = [];
        $this->sortedSets = [];
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function increment(string $key, int $value = 1): int
    {
        $current = $this->get($key, 0);
        if (!is_int($current)) {
            $current = 0;
        }

        $newValue = $current + $value;
        $this->set($key, $newValue);
        return $newValue;
    }

    /**
     * {@inheritdoc}
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, -$value);
    }

    /**
     * {@inheritdoc}
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, null);
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $key): bool
    {
        return $this->delete($key);
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, $callback, null);
    }

    /**
     * {@inheritdoc}
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->set($key, $value, $ttl > 0 ? $ttl : null);
    }

    /**
     * {@inheritdoc}
     */
    /**
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    /**
     * @param array<string, mixed> $values
     */
    public function putMany(array $values, int $ttl = 0): bool
    {
        return $this->setMultiple($values, $ttl > 0 ? $ttl : null);
    }

    /**
     * {@inheritdoc}
     */
    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        if ($this->has($key)) {
            return false;
        }
        return $this->set($key, $value, $ttl > 0 ? $ttl : null);
    }

    /**
     * {@inheritdoc}
     */
    public function setNx(string $key, mixed $value, int $ttl = 3600): bool
    {
        if ($this->has($key)) {
            return false;
        }
        return $this->set($key, $value, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function mget(array $keys): array
    {
        return $this->many($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function mset(array $values, int $ttl = 3600): bool
    {
        return $this->setMultiple($values, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function ttl(string $key): int
    {
        if (!isset($this->expirations[$key])) {
            return $this->has($key) ? -1 : -2;
        }

        $ttl = $this->expirations[$key] - time();
        return $ttl > 0 ? $ttl : -2;
    }

    /**
     * {@inheritdoc}
     */
    public function zadd(string $key, array $scoreValues): bool
    {
        if (!isset($this->sortedSets[$key])) {
            $this->sortedSets[$key] = [];
        }

        foreach ($scoreValues as $score => $value) {
            $this->sortedSets[$key][$score] = $value;
        }

        ksort($this->sortedSets[$key]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        if (!isset($this->sortedSets[$key])) {
            return 0;
        }

        $removed = 0;
        $minScore = (float) $min;
        $maxScore = (float) $max;

        foreach ($this->sortedSets[$key] as $score => $value) {
            if ($score >= $minScore && $score <= $maxScore) {
                unset($this->sortedSets[$key][$score]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function zcard(string $key): int
    {
        return isset($this->sortedSets[$key]) ? count($this->sortedSets[$key]) : 0;
    }

    /**
     * {@inheritdoc}
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        if (!isset($this->sortedSets[$key])) {
            return [];
        }

        $values = array_values($this->sortedSets[$key]);
        return array_slice($values, $start, $stop - $start + 1);
    }

    /**
     * {@inheritdoc}
     */
    public function expire(string $key, int $seconds): bool
    {
        if (!$this->has($key)) {
            return false;
        }

        $this->expirations[$key] = time() + $seconds;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function del(string $key): bool
    {
        return $this->delete($key);
    }

    /**
     * {@inheritdoc}
     */
    public function deletePattern(string $pattern): bool
    {
        $pattern = str_replace(['*', '?'], ['.*', '.'], $pattern);
        $pattern = '/^' . $pattern . '$/';

        foreach ($this->cache as $key => $value) {
            if (preg_match($pattern, $key)) {
                $this->delete($key);
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getKeys(string $pattern = '*'): array
    {
        if ($pattern === '*') {
            return array_keys($this->cache);
        }

        $pattern = str_replace(['*', '?'], ['.*', '.'], $pattern);
        $pattern = '/^' . $pattern . '$/';

        $keys = [];
        foreach ($this->cache as $key => $value) {
            if (preg_match($pattern, $key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        return [
            'keys' => count($this->cache),
            'memory' => memory_get_usage(),
            'driver' => 'array',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getAllKeys(): array
    {
        return array_keys($this->cache);
    }

    /**
     * {@inheritdoc}
     */
    public function getKeyCount(string $pattern = '*'): int
    {
        return count($this->getKeys($pattern));
    }

    /**
     * {@inheritdoc}
     */
    public function getCapabilities(): array
    {
        return [
            'sorted_sets' => true,
            'expiration' => true,
            'tagging' => true,
            'persistence' => false,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function addTags(string $key, array $tags): bool
    {
        if (!isset($this->tags[$key])) {
            $this->tags[$key] = [];
        }

        $this->tags[$key] = array_unique(array_merge($this->tags[$key], $tags));
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateTags(array $tags): bool
    {
        foreach ($this->tags as $key => $keyTags) {
            if (count(array_intersect($keyTags, $tags)) > 0) {
                $this->delete($key);
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getStore(): mixed
    {
        return $this->cache;
    }

    /**
     * {@inheritdoc}
     */
    public function getPrefix(): string
    {
        return '';
    }
}
