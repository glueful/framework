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
    private ?string $dumpPath = null;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Where to write the human-readable phase breakdown after boot. Null (the default) writes
     * nothing; the dump is an opt-in diagnostic, never a boot obligation.
     */
    public function setDumpPath(?string $path): void
    {
        $this->dumpPath = ($path === null || $path === '') ? null : $path;
    }

    /**
     * Resolve the BOOT_PROFILE_LOG setting: unset/false/empty/"0" disables the dump, boolean
     * true (or "1") selects a per-user file under the system temp directory, and any other
     * string is taken as an explicit path. Per-user, because one shared path on a multi-user
     * host is unwritable for every user but the first.
     */
    public static function dumpPathFromEnv(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === '' || $value === '0' || $value === 0) {
            return null;
        }

        if ($value === true || $value === '1' || $value === 1) {
            return sys_get_temp_dir() . '/glueful-boot-profile-' . getmyuid() . '.log';
        }

        return is_string($value) ? $value : null;
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

        // Opt-in phase breakdown dump; a failed write is never allowed to abort boot.
        if ($this->dumpPath !== null) {
            try {
                @file_put_contents($this->dumpPath, $this->formatSummary($summary));
            } catch (\Throwable) {
                // best-effort diagnostic only
            }
        }

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
