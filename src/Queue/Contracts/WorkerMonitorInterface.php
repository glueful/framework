<?php

namespace Glueful\Queue\Contracts;

/**
 * Worker Monitor Interface
 *
 * Defines the operational surface used by the queue system to track worker
 * lifecycle and job execution metrics. Implementations may persist to a
 * datastore (see {@see \Glueful\Queue\Monitoring\WorkerMonitor}) or no-op
 * (see {@see \Glueful\Queue\Monitoring\NullWorkerMonitor}).
 *
 * This is the trimmed write/lifecycle subset of WorkerMonitor. Reporting
 * methods (getWorkerStats/getJobMetrics/getPerformanceStats) are intentionally
 * excluded from this contract.
 *
 * @package Glueful\Queue\Contracts
 */
interface WorkerMonitorInterface
{
    /**
     * Register a new worker
     *
     * @param string $workerUuid Worker UUID
     * @param array<string, mixed> $workerData Worker information
     * @return void
     */
    public function registerWorker(string $workerUuid, array $workerData): void;

    /**
     * Update worker heartbeat
     *
     * @param string $workerUuid Worker UUID
     * @param array<string, mixed> $data Heartbeat data
     * @return void
     */
    public function updateWorkerHeartbeat(string $workerUuid, array $data): void;

    /**
     * Unregister worker
     *
     * @param string $workerUuid Worker UUID
     * @param array<string, mixed> $finalStats Final worker statistics
     * @return void
     */
    public function unregisterWorker(string $workerUuid, array $finalStats = []): void;

    /**
     * Record job start
     *
     * @param JobInterface $job Job instance
     * @return void
     */
    public function recordJobStart(JobInterface $job): void;

    /**
     * Record job success
     *
     * @param JobInterface $job Job instance
     * @param float $processingTime Processing time in seconds
     * @return void
     */
    public function recordJobSuccess(JobInterface $job, float $processingTime): void;

    /**
     * Record job failure
     *
     * @param JobInterface $job Job instance
     * @param \Exception $exception Exception that occurred
     * @param float $processingTime Processing time in seconds
     * @return void
     */
    public function recordJobFailure(JobInterface $job, \Exception $exception, float $processingTime): void;

    /**
     * Get active workers
     *
     * @return array<int, array<string, mixed>> Active worker list
     */
    public function getActiveWorkers(): array;

    /**
     * Cleanup old worker records
     *
     * @param int $daysOld Number of days old to cleanup
     * @return bool True if records were cleaned up
     */
    public function cleanupOldWorkers(int $daysOld = 7): bool;

    /**
     * Cleanup old job metrics
     *
     * @param int $daysOld Number of days old to cleanup
     * @return bool True if records were cleaned up
     */
    public function cleanupOldMetrics(int $daysOld = 30): bool;

    /**
     * Check if monitoring is enabled
     *
     * @return bool True if enabled
     */
    public function isEnabled(): bool;
}
