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
 * - database: Clean database log records
 * - audit: Clean audit log entries
 * - all: Complete log cleanup (filesystem + database + audit)
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
 * // Queue database log cleanup
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     LogCleanupJob::class,
 *     ['cleanupType' => 'database', 'options' => ['retention_days' => 7]],
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
                return $task->handle();
            })(),
            'all' => (function () use ($task, $retentionDays) {
                $task->cleanFileSystemLogs($retentionDays);
                $task->cleanDatabaseLogs($retentionDays);
                $task->cleanAuditLogs($retentionDays);
                return $task->handle();
            })(),
            'audit' => (function () use ($task, $retentionDays) {
                $task->cleanAuditLogs($retentionDays);
                return $task->handle();
            })(),
            'database' => (function () use ($task, $retentionDays) {
                $task->cleanDatabaseLogs($retentionDays);
                return $task->handle();
            })(),
            default => throw new \InvalidArgumentException("Unknown cleanup type: {$cleanupType}")
        };

        app(LogManager::class)->info('Log cleanup completed', [
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

        app(LogManager::class)->error('Log cleanup job failed', [
            'cleanup_type' => $cleanupType,
            'options' => $options,
            'error' => $exception->getMessage()
        ]);
    }
}
