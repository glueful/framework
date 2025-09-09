<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection\Performance;

use Glueful\Support\FieldSelection\FieldTree;

/**
 * Caching layer for parsed field trees to improve performance
 * Tracks cache hit/miss rates and provides performance metrics
 */
final class FieldTreeCache
{
    /** @var array<string, array{tree: FieldTree, timestamp: float, hits: int}> */
    private array $cache = [];

    private int $maxSize;
    private float $ttl;
    private FieldSelectionMetrics $metrics;

    public function __construct(
        int $maxSize = 1000,
        float $ttl = 3600, // 1 hour
        ?FieldSelectionMetrics $metrics = null
    ) {
        $this->maxSize = $maxSize;
        $this->ttl = $ttl;
        $this->metrics = $metrics ?? FieldSelectionMetrics::getInstance();
    }

    /**
     * Get cached field tree or null if not found/expired
     */
    public function get(string $key): ?FieldTree
    {
        $startTime = microtime(true);

        // Check if key exists and is not expired
        if (!isset($this->cache[$key])) {
            $this->metrics->recordCacheOperation('get', false, $key, microtime(true) - $startTime);
            return null;
        }

        $entry = $this->cache[$key];

        // Check if expired
        if (microtime(true) - $entry['timestamp'] > $this->ttl) {
            unset($this->cache[$key]);
            $this->metrics->recordCacheOperation('get', false, $key, microtime(true) - $startTime);
            return null;
        }

        // Update hit count and return
        $this->cache[$key]['hits']++;
        $this->metrics->recordCacheOperation('get', true, $key, microtime(true) - $startTime);

        return $entry['tree'];
    }

    /**
     * Store field tree in cache
     */
    public function put(string $key, FieldTree $tree): void
    {
        $startTime = microtime(true);

        // Evict old entries if cache is full
        if (count($this->cache) >= $this->maxSize) {
            $this->evictLeastUsed();
        }

        $this->cache[$key] = [
            'tree' => $tree,
            'timestamp' => microtime(true),
            'hits' => 0
        ];

        $this->metrics->recordCacheOperation('put', true, $key, microtime(true) - $startTime);
    }

    /**
     * Remove entry from cache
     */
    public function delete(string $key): bool
    {
        if (isset($this->cache[$key])) {
            unset($this->cache[$key]);
            return true;
        }
        return false;
    }

    /**
     * Clear all cache entries
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->metrics->increment('cache_clears');
    }

    /**
     * Get cache statistics
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $now = microtime(true);
        $expired = 0;
        $totalHits = 0;
        $oldestEntry = $now;
        $newestEntry = 0;

        foreach ($this->cache as $entry) {
            if ($now - $entry['timestamp'] > $this->ttl) {
                $expired++;
            }
            $totalHits += $entry['hits'];
            $oldestEntry = min($oldestEntry, $entry['timestamp']);
            $newestEntry = max($newestEntry, $entry['timestamp']);
        }

        return [
            'size' => count($this->cache),
            'max_size' => $this->maxSize,
            'utilization' => count($this->cache) / $this->maxSize * 100,
            'expired_entries' => $expired,
            'total_hits' => $totalHits,
            'ttl_seconds' => $this->ttl,
            'oldest_entry_age' => $now - $oldestEntry,
            'newest_entry_age' => $now - $newestEntry,
            'memory_usage_bytes' => $this->estimateMemoryUsage()
        ];
    }

    /**
     * Generate cache key from field selection parameters
     */
    /**
     * @param array<string> $whitelist
     * @param array<string,mixed> $context
     */
    public static function generateKey(
        string $fields,
        string $expand,
        array $whitelist = [],
        array $context = []
    ): string {
        return md5(serialize([
            'fields' => $fields,
            'expand' => $expand,
            'whitelist' => $whitelist,
            'context_signature' => md5(serialize($context)) // Avoid storing sensitive context data
        ]));
    }

    /**
     * Evict least used entries to make room
     */
    private function evictLeastUsed(): void
    {
        if ($this->cache === []) {
            return;
        }

        // Sort by hit count (ascending) and age (oldest first)
        $sortedKeys = array_keys($this->cache);
        usort($sortedKeys, function ($a, $b) {
            $entryA = $this->cache[$a];
            $entryB = $this->cache[$b];

            // First sort by hits (fewer hits = higher priority for eviction)
            if ($entryA['hits'] !== $entryB['hits']) {
                return $entryA['hits'] <=> $entryB['hits'];
            }

            // Then by age (older = higher priority for eviction)
            return $entryA['timestamp'] <=> $entryB['timestamp'];
        });

        // Remove the 10% least used entries
        $toRemove = max(1, (int)(count($sortedKeys) * 0.1));
        for ($i = 0; $i < $toRemove; $i++) {
            unset($this->cache[$sortedKeys[$i]]);
        }

        $this->metrics->increment('cache_evictions', $toRemove);
    }

    /**
     * Estimate memory usage of cache
     */
    private function estimateMemoryUsage(): int
    {
        // Rough estimate: each cache entry uses about 1KB on average
        // This includes the FieldTree object and metadata
        return count($this->cache) * 1024;
    }
}
