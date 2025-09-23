<?php

declare(strict_types=1);

namespace Glueful\Queue\Jobs;

use Glueful\Queue\Job;
use Glueful\Tasks\SessionCleanupTask;
use Glueful\Logging\LogManager;
use Throwable;

/**
 * Session Cleanup Queue Job
 *
 * Queue wrapper for session cleanup operations. Provides reliable execution
 * with retry mechanisms, monitoring, and error handling for session management.
 *
 * Supported Cleanup Types:
 * - expired: Clean expired access and refresh tokens
 * - revoked: Clean old revoked sessions
 * - all: Complete session cleanup (expired + revoked)
 * - inactive: Clean inactive sessions beyond threshold
 *
 * Usage:
 * ```php
 * // Queue expired session cleanup
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     SessionCleanupJob::class,
 *     ['cleanupType' => 'expired'],
 *     'maintenance'
 * );
 *
 * // Queue full cleanup
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     SessionCleanupJob::class,
 *     ['cleanupType' => 'all'],
 *     'maintenance'
 * );
 *
 * // Queue with custom retention
 * app(\Glueful\Queue\QueueManager::class)->push(
 *     SessionCleanupJob::class,
 *     ['cleanupType' => 'revoked', 'options' => ['retention_days' => 14]],
 *     'maintenance'
 * );
 * ```
 */
class SessionCleanupJob extends Job
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);

        // Set job configuration
        $this->queue = 'maintenance';
    }

    /**
     * Execute the session cleanup job
     *
     * @throws \InvalidArgumentException If cleanup type is not supported
     */
    public function handle(): void
    {
        $data = $this->getData();
        $cleanupType = $data['cleanupType'] ?? 'expired';
        $options = $data['options'] ?? [];

        $task = new SessionCleanupTask();

        $result = match ($cleanupType) {
            'expired' => (function () use ($task) {
                $task->cleanExpiredAccessTokens();
                $task->cleanExpiredRefreshTokens();
                return $task->handle();
            })(),
            'all' => (function () use ($task) {
                $task->cleanExpiredAccessTokens();
                $task->cleanExpiredRefreshTokens();
                $task->cleanOldRevokedSessions();
                return $task->handle();
            })(),
            'inactive' => (function () use ($task) {
                return $task->handle();
            })(),
            'revoked' => (function () use ($task) {
                $task->cleanOldRevokedSessions();
                return $task->handle();
            })(),
            default => throw new \InvalidArgumentException("Unknown cleanup type: {$cleanupType}")
        };

        app(LogManager::class)->info('Session cleanup completed', [
            'cleanup_type' => $cleanupType,
            'sessions_cleaned' => $result['cleaned_count'] ?? 0,
            'errors' => $result['errors'] ?? []
        ]);
    }


    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        $data = $this->getData();
        $cleanupType = $data['cleanupType'] ?? 'expired';
        $options = $data['options'] ?? [];

        app(LogManager::class)->error('Session cleanup job failed', [
            'cleanup_type' => $cleanupType,
            'options' => $options,
            'error' => $exception->getMessage()
        ]);
    }
}
