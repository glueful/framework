<?php

declare(strict_types=1);

namespace Glueful\Bootstrap;

use Psr\Log\LoggerInterface;

class BootProfiler
{
    /** @var array<string, array<string, mixed>> */
    private array $timings = [];
    /** @var array<string, array<string, float>> */
    private array $phases = [];
    private float $startTime;
    private ?LoggerInterface $logger = null;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Time a specific phase with a callback
     */
    public function time(string $phase, callable $callback): mixed
    {
        $start = microtime(true);

        try {
            $result = $callback();
            $this->recordPhase($phase, $start, microtime(true));
            return $result;
        } catch (\Throwable $e) {
            $this->recordPhase($phase, $start, microtime(true), $e);
            throw $e;
        }
    }

    /**
     * Start timing a phase manually
     */
    public function start(string $phase): void
    {
        $this->phases[$phase] = ['start' => microtime(true)];
    }

    /**
     * End timing a phase manually
     */
    public function end(string $phase): float
    {
        if (!isset($this->phases[$phase])) {
            throw new \InvalidArgumentException("Phase '{$phase}' was not started");
        }

        $endTime = microtime(true);
        $duration = $endTime - $this->phases[$phase]['start'];

        $this->timings[$phase] = [
            'duration' => $duration,
            'start' => $this->phases[$phase]['start'] - $this->startTime,
            'end' => $endTime - $this->startTime,
            'memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];

        unset($this->phases[$phase]);

        return $duration;
    }

    /**
     * Get all timing data
     * @return array<string, array<string, mixed>>
     */
    public function getTimings(): array
    {
        return $this->timings;
    }

    /**
     * Get total boot time
     */
    public function getTotalTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * Get timing for a specific phase
     */
    public function getPhaseTime(string $phase): ?float
    {
        return $this->timings[$phase]['duration'] ?? null;
    }

    /**
     * Log timing summary
     */
    public function logSummary(): void
    {
        $total = $this->getTotalTime();
        $summary = [
            'total_boot_time_ms' => round($total * 1000, 2),
            'phases' => []
        ];

        foreach ($this->timings as $phase => $data) {
            $summary['phases'][$phase] = [
                'duration_ms' => round($data['duration'] * 1000, 2),
                'percentage' => round(($data['duration'] / $total) * 100, 1),
                'memory_mb' => round($data['memory'] / 1024 / 1024, 2)
            ];
        }

        // Write phase breakdown to temp file for analysis
        file_put_contents('/tmp/boot_profile.log', $this->formatSummary($summary));

        // Log with different levels based on performance
        if ($total < 0.015) { // Under 15ms
            $level = 'debug';
        } elseif ($total < 0.1) { // Under 100ms
            $level = 'info';
        } else { // Over 100ms
            $level = 'warning';
        }

        // Try to log through the framework logger if available
        try {
            if ($this->logger instanceof LoggerInterface) {
                match ($level) {
                    'debug' => $this->logger->debug('Framework boot completed', $summary),
                    'info' => $this->logger->info('Framework boot completed', $summary),
                    'warning' => $this->logger->warning('Framework boot completed', $summary),
                };
                return;
            }
        } catch (\Throwable) {
            // ignore and fall back
        }

        // Fallback to error_log if framework logging fails or not available
        error_log("Framework boot: " . json_encode($summary));
    }

    private function recordPhase(string $phase, float $start, float $end, ?\Throwable $exception = null): void
    {
        $duration = $end - $start;

        $this->timings[$phase] = [
            'duration' => $duration,
            'start' => $start - $this->startTime,
            'end' => $end - $this->startTime,
            'memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'error' => $exception !== null ? $exception->getMessage() : null
        ];
    }

    /**
     * @param array{
     *     total_boot_time_ms: float,
     *     phases: array<string, array{duration_ms: float, percentage: float, memory_mb: float}>
     * } $summary
     */
    private function formatSummary(array $summary): string
    {
        $output = sprintf("Total Boot Time: %.2f ms\n\n", $summary['total_boot_time_ms']);
        $output .= "Phase Breakdown:\n";

        foreach ($summary['phases'] as $phase => $data) {
            $output .= sprintf(
                "  %-20s: %6.2f ms (%4.1f%%) - Memory: %.2f MB\n",
                $phase,
                $data['duration_ms'],
                $data['percentage'],
                $data['memory_mb']
            );
        }

        return $output;
    }
}
