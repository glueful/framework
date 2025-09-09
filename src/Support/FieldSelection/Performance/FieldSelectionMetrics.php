<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection\Performance;

use Psr\Log\LoggerInterface;

/**
 * Performance metrics collector for field selection operations
 * Tracks parsing time, projection performance, memory usage, and cache effectiveness
 */
final class FieldSelectionMetrics
{
    private static ?self $instance = null;

    /** @var array<string, array<string, mixed>> */
    private array $metrics = [];

    /** @var array<string, float> */
    private array $timers = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, float> */
    private array $memorySnapshots = [];

    private bool $enabled;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        bool $enabled = true
    ) {
        $this->enabled = $enabled && (env('APP_ENV') === 'development' || (bool)env('FIELD_SELECTION_METRICS', false));
        $this->reset();
    }

    public static function getInstance(?LoggerInterface $logger = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($logger);
        }
        return self::$instance;
    }

    /**
     * Start timing an operation
     */
    public function startTimer(string $operation): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->timers[$operation] = microtime(true);
        $this->memorySnapshots["{$operation}_start"] = memory_get_usage(true);
    }

    /**
     * End timing an operation and record metrics
     */
    /**
     * @param array<string,mixed> $context
     */
    public function endTimer(string $operation, array $context = []): float
    {
        if (!$this->enabled) {
            return 0.0;
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        if (!isset($this->timers[$operation])) {
            return 0.0;
        }

        $duration = ($endTime - $this->timers[$operation]) * 1000; // Convert to milliseconds
        $memoryUsed = $endMemory - ($this->memorySnapshots["{$operation}_start"] ?? 0);
        $peakMemory = memory_get_peak_usage(true) / 1024 / 1024; // Convert to MB

        $this->metrics[$operation][] = [
            'duration_ms' => round($duration, 3),
            'memory_used_bytes' => $memoryUsed,
            'memory_peak_mb' => round($peakMemory, 2),
            'timestamp' => $endTime,
            'context' => $context
        ];

        unset($this->timers[$operation]);
        unset($this->memorySnapshots["{$operation}_start"]);

        return $duration;
    }

    /**
     * Record a counter increment
     */
    public function increment(string $counter, int $amount = 1): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $amount;
    }

    /**
     * Record a gauge value
     */
    /**
     * @param array<string,mixed> $context
     */
    public function gauge(string $metric, float $value, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->metrics[$metric][] = [
            'value' => $value,
            'timestamp' => microtime(true),
            'context' => $context
        ];
    }

    /**
     * Record field selection parsing metrics
     */
    public function recordParsingMetrics(string $input, int $fieldCount, float $duration, int $memoryUsed): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->metrics['field_parsing'][] = [
            'input_length' => strlen($input),
            'field_count' => $fieldCount,
            'duration_ms' => round($duration, 3),
            'memory_used_bytes' => $memoryUsed,
            'memory_per_field_bytes' => $fieldCount > 0 ? round($memoryUsed / $fieldCount, 2) : 0,
            'fields_per_second' => $duration > 0 ? round($fieldCount / ($duration / 1000), 0) : 0,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Record projection operation metrics
     */
    /**
     * @param array<string,mixed> $context
     */
    public function recordProjectionMetrics(
        int $itemCount,
        int $fieldCount,
        float $duration,
        int $memoryUsed,
        array $context = []
    ): void {
        if (!$this->enabled) {
            return;
        }

        $this->metrics['projection'][] = [
            'item_count' => $itemCount,
            'field_count' => $fieldCount,
            'duration_ms' => round($duration, 3),
            'memory_used_bytes' => $memoryUsed,
            'items_per_second' => $duration > 0 ? round($itemCount / ($duration / 1000), 0) : 0,
            'avg_time_per_item_ms' => $itemCount > 0 ? round($duration / $itemCount, 3) : 0,
            'timestamp' => microtime(true),
            'context' => $context
        ];
    }

    /**
     * Record cache operation
     */
    public function recordCacheOperation(string $operation, bool $hit, string $key, float $duration = 0.0): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->increment("cache_{$operation}_total");
        if ($hit) {
            $this->increment("cache_{$operation}_hits");
        } else {
            $this->increment("cache_{$operation}_misses");
        }

        if ($duration > 0) {
            $this->metrics["cache_{$operation}_time"][] = [
                'duration_ms' => round($duration * 1000, 3),
                'hit' => $hit,
                'key_hash' => md5($key),
                'timestamp' => microtime(true)
            ];
        }
    }

    /**
     * Record N+1 query detection
     */
    public function recordN1Detection(string $relation, int $queryCount, bool $prevented = false): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->metrics['n1_detection'][] = [
            'relation' => $relation,
            'query_count' => $queryCount,
            'prevented' => $prevented,
            'severity' => $queryCount > 10 ? 'high' : ($queryCount > 5 ? 'medium' : 'low'),
            'timestamp' => microtime(true)
        ];

        $this->increment($prevented ? 'n1_queries_prevented' : 'n1_queries_detected');
    }

    /**
     * Record slow field selection pattern
     */
    /**
     * @param array<string,mixed> $context
     */
    public function recordSlowPattern(string $pattern, float $duration, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->metrics['slow_patterns'][] = [
            'pattern' => $pattern,
            'duration_ms' => round($duration, 3),
            'context' => $context,
            'timestamp' => microtime(true)
        ];

        if ($this->logger !== null) {
            $this->logger->warning('Slow field selection pattern detected', [
                'pattern' => $pattern,
                'duration_ms' => round($duration, 3),
                'context' => $context
            ]);
        }
    }

    /**
     * Get aggregated metrics summary
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        if (!$this->enabled) {
            return ['enabled' => false];
        }

        $summary = [
            'enabled' => true,
            'collection_period' => [
                'start' => $this->getEarliestTimestamp(),
                'end' => microtime(true),
                'duration_seconds' => microtime(true) - $this->getEarliestTimestamp()
            ],
            'counters' => $this->counters,
            'operations' => []
        ];

        foreach ($this->metrics as $operation => $measurements) {
            if ($measurements === []) {
                continue;
            }

            $durations = array_column($measurements, 'duration_ms');
            $durations = array_filter($durations, fn($d) => $d !== null);

            if ($durations !== []) {
                $summary['operations'][$operation] = [
                    'count' => count($measurements),
                    'total_time_ms' => round(array_sum($durations), 3),
                    'avg_time_ms' => round(array_sum($durations) / count($durations), 3),
                    'min_time_ms' => round(min($durations), 3),
                    'max_time_ms' => round(max($durations), 3),
                    'p95_time_ms' => round($this->percentile($durations, 0.95), 3),
                    'p99_time_ms' => round($this->percentile($durations, 0.99), 3)
                ];
            } else {
                $summary['operations'][$operation] = [
                    'count' => count($measurements)
                ];
            }
        }

        // Cache statistics
        if (isset($this->counters['cache_get_total'])) {
            $cacheHits = $this->counters['cache_get_hits'] ?? 0;
            $cacheTotal = $this->counters['cache_get_total'] ?? 0;
            $summary['cache'] = [
                'hit_rate' => $cacheTotal > 0 ? round(($cacheHits / $cacheTotal) * 100, 1) : 0.0,
                'total_requests' => $cacheTotal,
                'hits' => $cacheHits,
                'misses' => ($this->counters['cache_get_misses'] ?? 0)
            ];
        }

        return $summary;
    }

    /**
     * Get detailed metrics for specific operation
     *
     * @return array<array<string, mixed>>
     */
    public function getOperationMetrics(string $operation): array
    {
        return $this->metrics[$operation] ?? [];
    }

    /**
     * Get performance recommendations based on collected metrics
     *
     * @return array<string>
     */
    public function getRecommendations(): array
    {
        if (!$this->enabled) {
            return [];
        }

        $recommendations = [];

        // Check parsing performance
        if (isset($this->metrics['field_parsing'])) {
            $avgParsingTime = $this->getAverageMetric('field_parsing', 'duration_ms');
            if ($avgParsingTime > 50) {
                $recommendations[] = "Field parsing is slow (avg {$avgParsingTime}ms). " .
                    "Consider caching parsed field trees.";
            }
        }

        // Check projection performance
        if (isset($this->metrics['projection'])) {
            $avgProjectionTime = $this->getAverageMetric('projection', 'duration_ms');
            if ($avgProjectionTime > 100) {
                $recommendations[] = "Projection is slow (avg {$avgProjectionTime}ms). " .
                    "Consider optimizing field selection patterns.";
            }
        }

        // Check cache hit rate
        if (isset($this->counters['cache_get_total']) && $this->counters['cache_get_total'] > 10) {
            $hitRate = ($this->counters['cache_get_hits'] ?? 0) / $this->counters['cache_get_total'] * 100;
            if ($hitRate < 50) {
                $recommendations[] = "Low cache hit rate ({$hitRate}%). " .
                    "Consider adjusting cache TTL or key strategies.";
            }
        }

        // Check N+1 queries
        $n1Detected = $this->counters['n1_queries_detected'] ?? 0;
        $n1Prevented = $this->counters['n1_queries_prevented'] ?? 0;
        if ($n1Detected > $n1Prevented) {
            $recommendations[] = "N+1 queries detected ({$n1Detected} detected vs {$n1Prevented} prevented). " .
                "Consider adding more expanders.";
        }

        return $recommendations;
    }

    /**
     * Log metrics to configured logger
     */
    public function logMetrics(string $level = 'info'): void
    {
        if (!$this->enabled || $this->logger === null) {
            return;
        }

        $summary = $this->getSummary();
        $recommendations = $this->getRecommendations();

        $this->logger->log($level, 'Field Selection Performance Report', [
            'summary' => $summary,
            'recommendations' => $recommendations
        ]);
    }

    /**
     * Reset all metrics
     */
    public function reset(): void
    {
        $this->metrics = [];
        $this->timers = [];
        $this->counters = [];
        $this->memorySnapshots = [];
    }

    /**
     * Calculate percentile from array of values
     */
    /**
     * @param array<float> $values
     */
    private function percentile(array $values, float $percentile): float
    {
        sort($values);
        $index = ceil(count($values) * $percentile) - 1;
        return $values[max(0, (int)$index)] ?? 0.0;
    }

    /**
     * Get earliest timestamp from all metrics
     */
    private function getEarliestTimestamp(): float
    {
        $earliest = microtime(true);

        foreach ($this->metrics as $measurements) {
            foreach ($measurements as $measurement) {
                if (isset($measurement['timestamp']) && $measurement['timestamp'] < $earliest) {
                    $earliest = $measurement['timestamp'];
                }
            }
        }

        return $earliest;
    }

    /**
     * Get average value for specific metric
     */
    private function getAverageMetric(string $operation, string $field): float
    {
        if (!isset($this->metrics[$operation])) {
            return 0.0;
        }

        $values = array_column($this->metrics[$operation], $field);
        $values = array_filter($values, fn($v) => $v !== null);

        return count($values) > 0 ? array_sum($values) / count($values) : 0.0;
    }
}
