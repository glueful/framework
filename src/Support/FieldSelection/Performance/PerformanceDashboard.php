<?php

declare(strict_types=1);

namespace Glueful\Support\FieldSelection\Performance;

/**
 * Performance dashboard for field selection metrics
 * Provides formatted reports, analysis, and monitoring capabilities
 */
final class PerformanceDashboard
{
    public function __construct(
        private readonly FieldSelectionMetrics $metrics
    ) {
    }

    /**
     * Generate a comprehensive performance report
     */
    public function generateReport(): string
    {
        $summary = $this->metrics->getSummary();

        if (($summary['enabled'] ?? false) === false) {
            return "Field Selection Performance Monitoring: DISABLED\n";
        }

        $report = $this->generateHeader($summary);
        $report .= $this->generateOperationsReport($summary);
        $report .= $this->generateCacheReport($summary);
        $report .= $this->generateN1QueryReport();
        $report .= $this->generateRecommendations();
        $report .= $this->generateFooter();

        return $report;
    }

    /**
     * Generate JSON report for API consumption
     *
     * @return array<string, mixed>
     */
    public function generateJsonReport(): array
    {
        $summary = $this->metrics->getSummary();

        return [
            'enabled' => $summary['enabled'] ?? false,
            'period' => $summary['collection_period'] ?? [],
            'operations' => $summary['operations'] ?? [],
            'cache' => $summary['cache'] ?? [],
            'counters' => $summary['counters'] ?? [],
            'n1_queries' => $this->getN1QueryStats(),
            'recommendations' => $this->metrics->getRecommendations(),
            'slow_patterns' => $this->getSlowPatterns(),
            'top_operations' => $this->getTopOperations(),
            'memory_stats' => $this->getMemoryStats()
        ];
    }

    /**
     * Generate a simple status check
     */
    /**
     * @return array<string,mixed>
     */
    public function getHealthStatus(): array
    {
        $summary = $this->metrics->getSummary();
        $recommendations = $this->metrics->getRecommendations();

        $status = 'healthy';
        $issues = [];

        // Check for performance issues
        if (
            isset($summary['operations']['field_parsing']['avg_time_ms']) &&
            $summary['operations']['field_parsing']['avg_time_ms'] > 50
        ) {
            $status = 'warning';
            $issues[] = 'Slow field parsing detected';
        }

        if (
            isset($summary['operations']['projection']['avg_time_ms']) &&
            $summary['operations']['projection']['avg_time_ms'] > 100
        ) {
            $status = 'warning';
            $issues[] = 'Slow projection operations detected';
        }

        if (isset($summary['cache']['hit_rate']) && $summary['cache']['hit_rate'] < 50) {
            $status = 'warning';
            $issues[] = 'Low cache hit rate';
        }

        $n1Issues = ($summary['counters']['n1_queries_detected'] ?? 0) -
                   ($summary['counters']['n1_queries_prevented'] ?? 0);
        if ($n1Issues > 0) {
            $status = 'critical';
            $issues[] = "{$n1Issues} unhandled N+1 queries detected";
        }

        return [
            'status' => $status,
            'issues' => $issues,
            'recommendations_count' => count($recommendations),
            'monitoring_enabled' => $summary['enabled'] ?? false
        ];
    }

    /**
     * Generate report header
     */
    /**
     * @param array<string,mixed> $summary
     */
    private function generateHeader(array $summary): string
    {
        $duration = $summary['collection_period']['duration_seconds'] ?? 0;
        $startTime = date('Y-m-d H:i:s', (int)($summary['collection_period']['start'] ?? time()));

        return "
╔══════════════════════════════════════════════════════════════════════════════╗
║                     FIELD SELECTION PERFORMANCE REPORT                      ║
╠══════════════════════════════════════════════════════════════════════════════╣
║ Collection Period: {$startTime} - " . gmdate('H:i:s', (int)$duration) . " duration           ║
║ Monitoring Status: ENABLED                                                  ║
╚══════════════════════════════════════════════════════════════════════════════╝

";
    }

    /**
     * Generate operations performance report
     */
    /**
     * @param array<string,mixed> $summary
     */
    private function generateOperationsReport(array $summary): string
    {
        $report = "📊 OPERATIONS PERFORMANCE\n" . str_repeat("─", 50) . "\n";

        if (($summary['operations'] ?? []) === []) {
            $report .= "No operations recorded yet.\n\n";
            return $report;
        }

        foreach ($summary['operations'] as $operation => $stats) {
            $report .= sprintf("▸ %s\n", strtoupper(str_replace('_', ' ', $operation)));
            $report .= sprintf("  Count: %d operations\n", $stats['count']);

            if (isset($stats['avg_time_ms'])) {
                $report .= sprintf(
                    "  Average: %.2fms | Min: %.2fms | Max: %.2fms\n",
                    $stats['avg_time_ms'],
                    $stats['min_time_ms'],
                    $stats['max_time_ms']
                );
                $report .= sprintf(
                    "  P95: %.2fms | P99: %.2fms | Total: %.2fms\n",
                    $stats['p95_time_ms'],
                    $stats['p99_time_ms'],
                    $stats['total_time_ms']
                );
            }
            $report .= "\n";
        }

        return $report;
    }

    /**
     * Generate cache performance report
     */
    /**
     * @param array<string,mixed> $summary
     */
    private function generateCacheReport(array $summary): string
    {
        $report = "🗂️  CACHE PERFORMANCE\n" . str_repeat("─", 50) . "\n";

        if (!isset($summary['cache'])) {
            $report .= "No cache operations recorded yet.\n\n";
            return $report;
        }

        $cache = $summary['cache'];
        $report .= sprintf(
            "Hit Rate: %.1f%% (%d hits / %d total)\n",
            $cache['hit_rate'],
            $cache['hits'],
            $cache['total_requests']
        );
        $report .= sprintf("Misses: %d\n", $cache['misses']);

        // Add cache efficiency analysis
        if ($cache['hit_rate'] >= 80) {
            $report .= "Status: ✅ EXCELLENT - Cache is highly effective\n";
        } elseif ($cache['hit_rate'] >= 60) {
            $report .= "Status: ⚠️  GOOD - Cache is working well\n";
        } elseif ($cache['hit_rate'] >= 40) {
            $report .= "Status: ⚠️  FAIR - Consider optimizing cache strategy\n";
        } else {
            $report .= "Status: ❌ POOR - Cache needs optimization\n";
        }

        $report .= "\n";
        return $report;
    }

    /**
     * Generate N+1 query detection report
     */
    private function generateN1QueryReport(): string
    {
        $n1Stats = $this->getN1QueryStats();
        $report = "🔍 N+1 QUERY DETECTION\n" . str_repeat("─", 50) . "\n";

        $report .= sprintf(
            "Detected: %d | Prevented: %d | Unhandled: %d\n",
            $n1Stats['detected'],
            $n1Stats['prevented'],
            $n1Stats['unhandled']
        );

        if ($n1Stats['unhandled'] === 0) {
            $report .= "Status: ✅ ALL N+1 QUERIES PREVENTED\n";
        } elseif ($n1Stats['unhandled'] <= 2) {
            $report .= "Status: ⚠️  FEW UNHANDLED N+1 QUERIES\n";
        } else {
            $report .= "Status: ❌ MULTIPLE UNHANDLED N+1 QUERIES\n";
        }

        // Show recent N+1 detections
        $n1Details = $this->metrics->getOperationMetrics('n1_detection');
        if ($n1Details !== []) {
            $report .= "\nRecent Detections:\n";
            $recent = array_slice($n1Details, -5); // Last 5
            foreach ($recent as $detection) {
                $status = (($detection['prevented'] ?? false) === true) ? '✅' : '❌';
                $report .= sprintf(
                    "  %s %s - %d queries (%s severity)\n",
                    $status,
                    $detection['relation'],
                    $detection['query_count'],
                    $detection['severity']
                );
            }
        }

        $report .= "\n";
        return $report;
    }

    /**
     * Generate recommendations section
     */
    private function generateRecommendations(): string
    {
        $recommendations = $this->metrics->getRecommendations();
        $report = "💡 RECOMMENDATIONS\n" . str_repeat("─", 50) . "\n";

        if ($recommendations === []) {
            $report .= "✅ No performance issues detected. System is performing optimally!\n\n";
            return $report;
        }

        foreach ($recommendations as $i => $recommendation) {
            $report .= sprintf("%d. %s\n", $i + 1, $recommendation);
        }

        $report .= "\n";
        return $report;
    }

    /**
     * Generate report footer
     */
    private function generateFooter(): string
    {
        return "For detailed metrics, use: FieldSelectionMetrics::getInstance()->getSummary()\n" .
               "To reset metrics: FieldSelectionMetrics::getInstance()->reset()\n\n";
    }

    /**
     * Get N+1 query statistics
     */
    /**
     * @return array<string,int>
     */
    private function getN1QueryStats(): array
    {
        $detected = $this->metrics->getSummary()['counters']['n1_queries_detected'] ?? 0;
        $prevented = $this->metrics->getSummary()['counters']['n1_queries_prevented'] ?? 0;

        return [
            'detected' => $detected,
            'prevented' => $prevented,
            'unhandled' => max(0, $detected - $prevented)
        ];
    }

    /**
     * Get slow pattern statistics
     */
    /**
     * @return array<array<string,mixed>>
     */
    private function getSlowPatterns(): array
    {
        $slowPatterns = $this->metrics->getOperationMetrics('slow_patterns');

        return array_map(function ($pattern) {
            return [
                'pattern' => $pattern['pattern'],
                'duration_ms' => $pattern['duration_ms'],
                'timestamp' => $pattern['timestamp'],
                'context' => $pattern['context']
            ];
        }, $slowPatterns);
    }

    /**
     * Get top operations by time
     */
    /**
     * @return array<string,array<string,mixed>>
     */
    private function getTopOperations(): array
    {
        $operations = $this->metrics->getSummary()['operations'] ?? [];

        // Sort by total time
        uasort($operations, function ($a, $b) {
            return ($b['total_time_ms'] ?? 0) <=> ($a['total_time_ms'] ?? 0);
        });

        return array_slice($operations, 0, 5, true);
    }

    /**
     * Get memory usage statistics
     */
    /**
     * @return array<string,float|int>
     */
    private function getMemoryStats(): array
    {
        $allMetrics = [];
        foreach (['field_parsing', 'projection'] as $operation) {
            $metrics = $this->metrics->getOperationMetrics($operation);
            $allMetrics = array_merge($allMetrics, $metrics);
        }

        if ($allMetrics === []) {
            return [];
        }

        $memoryUsages = array_column($allMetrics, 'memory_used_bytes');
        $peakMemories = array_column($allMetrics, 'memory_peak_mb');

        return [
            'total_memory_used_bytes' => array_sum($memoryUsages),
            'avg_memory_per_operation_bytes' => array_sum($memoryUsages) / count($memoryUsages),
            'max_memory_used_bytes' => max($memoryUsages),
            'peak_memory_mb' => max(array_filter($peakMemories))
        ];
    }
}
