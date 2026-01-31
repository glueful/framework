<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Queue\Job;
use Glueful\Tasks\LogCleanupTask;
use Glueful\Logging\LogManager;
use Throwable;

/**
 * Log Cleanup Queue Job
 *
 * Queue wrapper for log cleanup operations. Provides reliable execution
 * with retry mechanisms, monitoring, and error handling for log management.
 *
 * Supported Cleanup Types:
 * - filesystem: Clean file system log files
 * - database: Clean database log records (simple age-based)
 * - channel: Clean database logs using channel-specific retention
 * - all: Complete log cleanup (filesystem + channel-based database)
 *
 * Usage:
 * ```php
 * // Queue filesystem log cleanup
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     LogCleanupJob::class,
 *     ['cleanupType' => 'filesystem', 'options' => ['retention_days' => 30]],
 *     'maintenance'
 * );
 *
 * // Queue database log cleanup with channel retention
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     LogCleanupJob::class,
 *     ['cleanupType' => 'channel'],
 *     'maintenance'
 * );
 *
 * // Queue full log cleanup
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     LogCleanupJob::class,
 *     ['cleanupType' => 'all', 'options' => ['retention_days' => 30]],
 *     'maintenance'
 * );
 * ```
 */
class LogCleanupJob extends Job
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);

        // Set job configuration
        $this->queue = 'maintenance';
    }

    /**
     * Execute the log cleanup job
     *
     * @throws \InvalidArgumentException If cleanup type is not supported
     */
    public function handle(): void
    {
        $data = $this->getData();
        $cleanupType = $data['cleanupType'] ?? 'all';
        $options = $data['options'] ?? [];

        $task = new LogCleanupTask();

        // Get retention days from options or use default
        $retentionDays = (int) ($options['retention_days'] ?? 30);

        $result = match ($cleanupType) {
            'filesystem' => (function () use ($task, $retentionDays) {
                $task->cleanFileSystemLogs($retentionDays);
                $task->logResults();
                return ['deleted_files' => 0, 'deleted_db_logs' => 0, 'bytes_freed' => 0];
            })(),
            'database' => (function () use ($task, $retentionDays) {
                $task->cleanDatabaseLogs($retentionDays);
                $task->logResults();
                return ['deleted_files' => 0, 'deleted_db_logs' => 0, 'bytes_freed' => 0];
            })(),
            'channel' => (function () use ($task) {
                $task->cleanDatabaseLogsByChannel();
                $task->logResults();
                return ['deleted_files' => 0, 'deleted_db_logs' => 0, 'bytes_freed' => 0];
            })(),
            'all' => $task->handle([
                'retention_days' => $retentionDays,
                'use_channel_retention' => true,
            ]),
            default => throw new \InvalidArgumentException("Unknown cleanup type: {$cleanupType}")
        };

        $logger = $this->context !== null
            ? container($this->context)->get(LogManager::class)
            : LogManager::getInstance();
        $logger->info('Log cleanup completed', [
            'cleanup_type' => $cleanupType,
            'deleted_files' => $result['deleted_files'] ?? 0,
            'deleted_db_logs' => $result['deleted_db_logs'] ?? 0,
            'bytes_freed' => $result['bytes_freed'] ?? 0
        ]);
    }


    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        $data = $this->getData();
        $cleanupType = $data['cleanupType'] ?? 'all';
        $options = $data['options'] ?? [];

        $logger = $this->context !== null
            ? container($this->context)->get(LogManager::class)
            : LogManager::getInstance();
        $logger->error('Log cleanup job failed', [
            'cleanup_type' => $cleanupType,
            'options' => $options,
            'error' => $exception->getMessage()
        ]);
    }
}
