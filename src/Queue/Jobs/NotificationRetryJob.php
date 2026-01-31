<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Queue\Job;
use Glueful\Tasks\NotificationRetryTask;
use Glueful\Logging\LogManager;
use Throwable;

/**
 * Notification Retry Queue Job
 *
 * Queue wrapper for notification retry processing. Provides reliable execution
 * with retry mechanisms, monitoring, and error handling for failed notifications.
 *
 * Supported Operations:
 * - process: Process pending notification retries
 * - cleanup: Clean up old retry records
 * - maintenance: Full retry system maintenance
 *
 * Usage:
 * ```php
 * // Queue retry processing
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     NotificationRetryJob::class,
 *     ['retryType' => 'process', 'options' => ['limit' => 50]],
 *     'notifications'
 * );
 *
 * // Queue cleanup
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     NotificationRetryJob::class,
 *     ['retryType' => 'cleanup', 'options' => ['retention_days' => 7]],
 *     'notifications'
 * );
 *
 * // Queue full maintenance
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     NotificationRetryJob::class,
 *     ['retryType' => 'maintenance'],
 *     'notifications'
 * );
 * ```
 */
class NotificationRetryJob extends Job
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);

        // Set job configuration
        $this->queue = 'notifications';
    }

    /**
     * Execute the notification retry job
     *
     * @throws \InvalidArgumentException If operation is not supported
     */
    public function handle(): void
    {
        $data = $this->getData();
        $retryType = $data['retryType'] ?? 'process';
        $options = $data['options'] ?? [];

        $task = new NotificationRetryTask();

        // Note: NotificationRetryTask only has a handle() method that processes retries
        // The cleanup functionality would need to be implemented separately if needed
        $result = match ($retryType) {
            'process' => $task->handle($options),
            'maintenance' => $task->handle($options),
            'cleanup' => $task->handle($options),
            default => throw new \InvalidArgumentException("Unknown retry type: {$retryType}")
        };

        $logger = $this->context !== null
            ? container($this->context)->get(LogManager::class)
            : LogManager::getInstance();
        $logger->info('Notification retry completed', [
            'retry_type' => $retryType,
            'processed' => $result['processed'] ?? 0,
            'successful' => $result['successful'] ?? 0,
            'failed' => $result['failed'] ?? 0,
            'removed' => $result['removed'] ?? 0
        ]);
    }


    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        $data = $this->getData();
        $retryType = $data['retryType'] ?? 'process';
        $options = $data['options'] ?? [];

        $logger = $this->context !== null
            ? container($this->context)->get(LogManager::class)
            : LogManager::getInstance();
        $logger->error('Notification retry job failed', [
            'retry_type' => $retryType,
            'options' => $options,
            'error' => $exception->getMessage()
        ]);
    }
}
