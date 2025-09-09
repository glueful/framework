<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Fields;

use Glueful\Console\BaseCommand;
use Glueful\Support\FieldSelection\Performance\{FieldSelectionMetrics, PerformanceDashboard};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Field Performance Command
 * Provides detailed performance analysis of field selection operations
 */
#[AsCommand(
    name: 'fields:performance',
    description: 'Performance analysis of field selections'
)]
class PerformanceCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Analyze field selection performance metrics and identify optimization opportunities')
            ->setHelp('This command provides detailed performance analysis, metrics, and recommendations ' .
                'for field selection.')
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (dashboard, json, metrics)',
                'dashboard'
            )
            ->addOption(
                'reset',
                null,
                InputOption::VALUE_NONE,
                'Reset performance metrics before analysis'
            )
            ->addOption(
                'watch',
                'w',
                InputOption::VALUE_NONE,
                'Watch mode: continuously display metrics (press Ctrl+C to stop)'
            )
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_REQUIRED,
                'Watch mode update interval in seconds',
                '5'
            )
            ->addOption(
                'threshold',
                't',
                InputOption::VALUE_REQUIRED,
                'Performance threshold in milliseconds for highlighting slow operations',
                '100'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        $reset = (bool) $input->getOption('reset');
        $watch = (bool) $input->getOption('watch');
        $interval = max(1, (int) $input->getOption('interval'));
        $threshold = max(1, (int) $input->getOption('threshold'));

        try {
            $metrics = $this->getService(FieldSelectionMetrics::class);
            $dashboard = new PerformanceDashboard($metrics);

            // Reset metrics if requested
            if ($reset) {
                $metrics->reset();
                $this->success('📊 Performance metrics reset successfully.');

                if (!$watch) {
                    $this->info('Run field selection operations and then run this command again to see metrics.');
                    return self::SUCCESS;
                }
            }

            // Watch mode
            if ($watch) {
                return $this->runWatchMode($dashboard, $interval, $threshold);
            }

            // Single analysis
            return $this->runSingleAnalysis($dashboard, $format, $threshold);
        } catch (\Exception $e) {
            $this->error('Performance analysis failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function runWatchMode(PerformanceDashboard $dashboard, int $interval, int $threshold): int
    {
        $this->info("🔄 Starting performance monitoring (updating every {$interval}s)...");
        $this->line('Press Ctrl+C to stop.');
        $this->line('');

        $iteration = 0;
        $shouldContinue = true;

        // Set up signal handler for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$shouldContinue) {
                $shouldContinue = false;
            });
        }

        while ($shouldContinue) {
            // Check for signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Clear screen (works on most terminals)
            $this->output->write("\033[2J\033[H");

            $iteration++;
            $this->line("📊 Field Selection Performance Monitor - Iteration #{$iteration}");
            $this->line('Last updated: ' . date('Y-m-d H:i:s'));
            $this->line(str_repeat('─', 80));

            try {
                $report = $dashboard->generateReport();
                $this->line($report);

                $this->displayRealTimeAlerts($dashboard, $threshold);
            } catch (\Exception $e) {
                $this->error('Failed to generate report: ' . $e->getMessage());
                // In case of repeated errors, exit
                break;
            }

            sleep($interval);

            // Safety check: if we've been running for too long without interruption
            // and pcntl functions aren't available, exit after a reasonable time
            if (!function_exists('pcntl_signal') && $iteration > 1000) {
                $this->warning('Watch mode stopped after 1000 iterations (no signal handler available).');
                break;
            }
        }

        $this->line('');
        $this->info('Performance monitoring stopped.');

        return self::SUCCESS;
    }

    private function runSingleAnalysis(PerformanceDashboard $dashboard, string $format, int $threshold): int
    {
        $this->info('🔍 Analyzing field selection performance...');

        switch ($format) {
            case 'json':
                $report = $dashboard->generateJsonReport();
                $this->line(json_encode($report, JSON_PRETTY_PRINT));
                break;

            case 'metrics':
                $this->displayDetailedMetrics($dashboard, $threshold);
                break;

            case 'dashboard':
            default:
                $report = $dashboard->generateReport();
                $this->line($report);
                break;
        }

        // Show health status
        $healthStatus = $dashboard->getHealthStatus();
        $this->displayHealthStatus($healthStatus);

        return self::SUCCESS;
    }

    private function displayDetailedMetrics(PerformanceDashboard $dashboard, int $threshold): void
    {
        $jsonReport = $dashboard->generateJsonReport();

        $this->line('');
        $this->info('📈 Detailed Performance Metrics');
        $this->line('');

        // Operations breakdown
        if (($jsonReport['operations'] ?? []) !== []) {
            $this->line('🚀 Operations Performance:');
            $this->line('');

            $headers = ['Operation', 'Count', 'Avg (ms)', 'Min (ms)', 'Max (ms)', 'P95 (ms)', 'P99 (ms)', 'Total (ms)'];
            $rows = [];

            foreach ($jsonReport['operations'] as $operation => $stats) {
                $avgTime = $stats['avg_time_ms'] ?? 0;
                $isSlowOperation = $avgTime > $threshold;

                $row = [
                    $operation,
                    $stats['count'] ?? 0,
                    $isSlowOperation ? "⚠️  {$avgTime}" : (string) $avgTime,
                    $stats['min_time_ms'] ?? 0,
                    $stats['max_time_ms'] ?? 0,
                    $stats['p95_time_ms'] ?? 0,
                    $stats['p99_time_ms'] ?? 0,
                    $stats['total_time_ms'] ?? 0,
                ];

                $rows[] = $row;
            }

            $this->table($headers, $rows);
        }

        // Memory statistics
        if (($jsonReport['memory_stats'] ?? []) !== []) {
            $this->line('');
            $this->line('🧠 Memory Usage:');
            $this->line('');

            $memory = $jsonReport['memory_stats'];
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Memory Used', $this->formatBytes($memory['total_memory_used_bytes'] ?? 0)],
                    ['Avg Memory per Operation', $this->formatBytes($memory['avg_memory_per_operation_bytes'] ?? 0)],
                    ['Max Memory Used', $this->formatBytes($memory['max_memory_used_bytes'] ?? 0)],
                    ['Peak Memory', ($memory['peak_memory_mb'] ?? 0) . ' MB'],
                ]
            );
        }

        // Cache performance
        if (($jsonReport['cache'] ?? []) !== []) {
            $this->line('');
            $this->line('🗂️  Cache Performance:');
            $this->line('');

            $cache = $jsonReport['cache'];
            $hitRate = $cache['hit_rate'] ?? 0;
            $hitRateDisplay = $hitRate < 50 ? "⚠️  {$hitRate}%" : "{$hitRate}%";

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Hit Rate', $hitRateDisplay],
                    ['Total Requests', $cache['total_requests'] ?? 0],
                    ['Hits', $cache['hits'] ?? 0],
                    ['Misses', $cache['misses'] ?? 0],
                ]
            );
        }

        // Top slow operations
        $slowPatterns = $jsonReport['slow_patterns'] ?? [];
        if ($slowPatterns !== []) {
            $this->line('');
            $this->warning('🐌 Slowest Field Selection Patterns:');
            $this->line('');

            $slowRows = [];
            foreach (array_slice($slowPatterns, 0, 10) as $pattern) {
                $slowRows[] = [
                    substr($pattern['pattern'], 0, 50) . (strlen($pattern['pattern']) > 50 ? '...' : ''),
                    $pattern['duration_ms'] . 'ms',
                    date('H:i:s', (int) $pattern['timestamp'])
                ];
            }

            $this->table(['Pattern', 'Duration', 'Time'], $slowRows);
        }
    }

    private function displayRealTimeAlerts(PerformanceDashboard $dashboard, int $threshold): void
    {
        $jsonReport = $dashboard->generateJsonReport();
        $alerts = [];

        // Check for performance issues
        foreach ($jsonReport['operations'] ?? [] as $operation => $stats) {
            $avgTime = $stats['avg_time_ms'] ?? 0;
            if ($avgTime > $threshold) {
                $alerts[] = "🔥 {$operation}: {$avgTime}ms (threshold: {$threshold}ms)";
            }
        }

        // Check cache performance
        $hitRate = $jsonReport['cache']['hit_rate'] ?? 100;
        if ($hitRate < 50) {
            $alerts[] = "❄️  Cache hit rate is low: {$hitRate}%";
        }

        // Check N+1 queries
        $n1Issues = ($jsonReport['counters']['n1_queries_detected'] ?? 0) -
                   ($jsonReport['counters']['n1_queries_prevented'] ?? 0);
        if ($n1Issues > 0) {
            $alerts[] = "⚡ {$n1Issues} unhandled N+1 queries detected";
        }

        // Display alerts
        if ($alerts !== []) {
            $this->line('');
            $this->line('🚨 PERFORMANCE ALERTS:');
            foreach ($alerts as $alert) {
                $this->line("  {$alert}");
            }
            $this->line('');
        }
    }

    /**
     * @param array<string,mixed> $healthStatus
     */
    private function displayHealthStatus(array $healthStatus): void
    {
        $this->line('');

        $statusIcon = match ($healthStatus['status']) {
            'healthy' => '💚',
            'warning' => '💛',
            'critical' => '💔',
            default => '❓'
        };

        $this->line("{$statusIcon} Overall Health: " . strtoupper($healthStatus['status']));

        if (($healthStatus['issues'] ?? []) !== []) {
            $this->line('');
            $this->line('Issues detected:');
            foreach ($healthStatus['issues'] as $issue) {
                $this->line("  • {$issue}");
            }
        }

        if ($healthStatus['recommendations_count'] > 0) {
            $this->line('');
            $this->info("💡 {$healthStatus['recommendations_count']} optimization recommendations available.");
            $this->line('Run with --format=dashboard to see detailed recommendations.');
        }

        $this->line('');
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes !== 0 ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * (int) $pow));

        return round($bytes, 2) . ' ' . $units[(int) $pow];
    }
}
