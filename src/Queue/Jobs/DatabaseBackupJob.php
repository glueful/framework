<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Bootstrap\ApplicationContext;
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
    use ResolvesJobLogger;

    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data, $context);

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

        $task = new DatabaseBackupTask($this->context);

        $result = match ($backupType) {
            'full' => $task->handle(['backup_type' => 'full'] + $options),
            'incremental' => $task->handle(['backup_type' => 'incremental'] + $options),
            'schema' => $task->handle(['backup_type' => 'schema'] + $options),
            default => throw new \InvalidArgumentException("Unknown backup type: {$backupType}")
        };

        // A backup that was not made is a failed job: the queue records it and failed() logs it.
        if (!$result['backup_created']) {
            throw new \RuntimeException(
                'Database backup was not created: ' . implode('; ', $result['errors'])
            );
        }

        $logger = $this->jobLogger();
        $logger->info('Database backup completed', [
            'backup_type' => $backupType,
            'result' => $result
        ]);
    }


    /**
     * Handle job failure
     */
    #[\Override]
    public function failed(Throwable $exception): void
    {
        $data = $this->getData();
        $backupType = $data['backupType'] ?? 'full';
        $options = $data['options'] ?? [];

        $logger = $this->jobLogger();
        $logger->critical('Database backup job failed', [
            'backup_type' => $backupType,
            'options' => $options,
            'error' => $exception->getMessage()
        ]);
    }
}
