<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Queue\Job;
use Glueful\Tasks\CacheMaintenanceTask;
use Glueful\Logging\LogManager;
use Throwable;

/**
 * Cache Maintenance Queue Job
 *
 * Queue wrapper for cache maintenance tasks. Provides reliable execution
 * with retry mechanisms, monitoring, and error handling for cache cleanup operations.
 *
 * Supported Operations:
 * - clearExpiredKeys: Remove expired cache entries
 * - optimizeCache: Defragment and optimize cache storage
 * - fullCleanup: Complete maintenance cycle (clear + optimize)
 *
 * Usage:
 * ```php
 * // Queue specific operation
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     CacheMaintenanceJob::class,
 *     ['operation' => 'clearExpiredKeys'],
 *     'maintenance'
 * );
 *
 * // Queue with options
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     CacheMaintenanceJob::class,
 *     ['operation' => 'optimizeCache', 'options' => ['verbose' => true]],
 *     'maintenance'
 * );
 *
 * // Full maintenance cycle
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     CacheMaintenanceJob::class,
 *     ['operation' => 'fullCleanup'],
 *     'maintenance'
 * );
 * ```
 */
class CacheMaintenanceJob extends Job
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);

        // Set job configuration
        $this->queue = 'maintenance';
    }

    /**
     * Execute the cache maintenance job
     *
     * @throws \InvalidArgumentException If operation is not supported
     */
    public function handle(): void
    {
        $data = $this->getData();
        $operation = $data['operation'] ?? 'clearExpiredKeys';
        $options = $data['options'] ?? [];

        $task = new CacheMaintenanceTask();

        $result = match ($operation) {
            'clearExpiredKeys' => (function () use ($task) {
                $task->clearExpiredKeys();
                return $task->handle();
            })(),
            'optimizeCache' => (function () use ($task) {
                $task->optimizeCache();
                return $task->handle();
            })(),
            'fullCleanup' => $task->handle($options),
            default => throw new \InvalidArgumentException("Unknown operation: {$operation}")
        };

        $logger = $this->context !== null
            ? container($this->context)->get(LogManager::class)
            : LogManager::getInstance();
        $logger->info('Cache maintenance job completed', [
            'operation' => $operation,
            'result' => $result
        ]);
    }


    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        $data = $this->getData();
        $operation = $data['operation'] ?? 'clearExpiredKeys';
        $options = $data['options'] ?? [];

        $logger = $this->context !== null
            ? container($this->context)->get(LogManager::class)
            : LogManager::getInstance();
        $logger->error('Cache maintenance job failed', [
            'operation' => $operation,
            'options' => $options,
            'error' => $exception->getMessage()
        ]);
    }
}
