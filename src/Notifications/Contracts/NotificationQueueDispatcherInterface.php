<?php

declare(strict_types=1);

namespace Glueful\Notifications\Contracts;

/**
 * Seam for enqueuing notification jobs for asynchronous dispatch.
 *
 * Lets {@see \Glueful\Notifications\Services\NotificationService} push async work without
 * constructing a `QueueManager` itself — keeping queueing optional and unit-testable. The
 * default implementation wraps `QueueManager`; tests (or queue-less deployments) can supply
 * their own.
 *
 * @package Glueful\Notifications\Contracts
 */
interface NotificationQueueDispatcherInterface
{
    /**
     * Enqueue a job for asynchronous processing.
     *
     * Best-effort: returns the queued job id, or `null` if the job could not be queued (the
     * caller must not fail the originating request because async dispatch could not be set up).
     *
     * @param string $job Fully-qualified job class to run
     * @param array<string, mixed> $payload Job payload
     * @param string|null $queue Target queue name (null = default queue)
     * @return string|null The queued job id, or null if it could not be queued
     */
    public function dispatch(string $job, array $payload, ?string $queue = null): ?string;
}
