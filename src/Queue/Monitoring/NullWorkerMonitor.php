<?php

namespace Glueful\Queue\Monitoring;

use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\WorkerMonitorInterface;

/**
 * Null Worker Monitor
 *
 * No-op implementation of {@see WorkerMonitorInterface}. Used when worker
 * monitoring is disabled or unavailable (e.g. the queue-ops capability is not
 * installed). Performs no persistence and requires no database connection.
 *
 * @package Glueful\Queue\Monitoring
 */
final class NullWorkerMonitor implements WorkerMonitorInterface
{
    /**
     * @param string $workerUuid Worker UUID
     * @param array<string, mixed> $workerData Worker information
     */
    public function registerWorker(string $workerUuid, array $workerData): void
    {
        // No-op
    }

    /**
     * @param string $workerUuid Worker UUID
     * @param array<string, mixed> $data Heartbeat data
     */
    public function updateWorkerHeartbeat(string $workerUuid, array $data): void
    {
        // No-op
    }

    /**
     * @param string $workerUuid Worker UUID
     * @param array<string, mixed> $finalStats Final worker statistics
     */
    public function unregisterWorker(string $workerUuid, array $finalStats = []): void
    {
        // No-op
    }

    public function recordJobStart(JobInterface $job): void
    {
        // No-op
    }

    public function recordJobSuccess(JobInterface $job, float $processingTime): void
    {
        // No-op
    }

    public function recordJobFailure(JobInterface $job, \Exception $exception, float $processingTime): void
    {
        // No-op
    }

    /**
     * @return array<int, array<string, mixed>> Always empty
     */
    public function getActiveWorkers(): array
    {
        return [];
    }

    public function cleanupOldWorkers(int $daysOld = 7): bool
    {
        return false;
    }

    public function cleanupOldMetrics(int $daysOld = 30): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
