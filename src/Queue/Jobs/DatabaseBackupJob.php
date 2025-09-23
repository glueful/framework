<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Queue\Job;
use Glueful\Tasks\DatabaseBackupTask;
use Glueful\Logging\LogManager;
use Throwable;

/**
 * Database Backup Queue Job
 *
 * Queue wrapper for database backup operations. Provides reliable execution
 * with retry mechanisms, monitoring, and error handling for database backups.
 *
 * Supported Backup Types:
 * - full: Complete database backup with data and schema
 * - schema: Schema-only backup (structure without data)
 * - incremental: Incremental backup (if supported by database)
 *
 * Usage:
 * ```php
 * // Queue full backup
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     DatabaseBackupJob::class,
 *     ['backupType' => 'full', 'options' => ['retention_days' => 7]],
 *     'critical'
 * );
 *
 * // Queue schema backup
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     DatabaseBackupJob::class,
 *     ['backupType' => 'schema'],
 *     'critical'
 * );
 *
 * // Queue with custom options
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     DatabaseBackupJob::class,
 *     ['backupType' => 'full', 'options' => ['retention_days' => 14, 'compress' => true]],
 *     'critical'
 * );
 * ```
 */
class DatabaseBackupJob extends Job
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);

        // Set job configuration - database backups are critical
        $this->queue = 'critical';
    }

    /**
     * Execute the database backup job
     *
     * @throws \InvalidArgumentException If backup type is not supported
     */
    public function handle(): void
    {
        $data = $this->getData();
        $backupType = $data['backupType'] ?? 'full';
        $options = $data['options'] ?? [];

        $task = new DatabaseBackupTask();

        $result = match ($backupType) {
            'full' => $task->handle(['backup_type' => 'full'] + $options),
            'incremental' => $task->handle(['backup_type' => 'incremental'] + $options),
            'schema' => $task->handle(['backup_type' => 'schema'] + $options),
            default => throw new \InvalidArgumentException("Unknown backup type: {$backupType}")
        };

        app(LogManager::class)->info('Database backup completed', [
            'backup_type' => $backupType,
            'result' => $result
        ]);
    }


    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        $data = $this->getData();
        $backupType = $data['backupType'] ?? 'full';
        $options = $data['options'] ?? [];

        app(LogManager::class)->critical('Database backup job failed', [
            'backup_type' => $backupType,
            'options' => $options,
            'error' => $exception->getMessage()
        ]);
    }
}
