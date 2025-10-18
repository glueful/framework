<?php

declare(strict_types=1);

namespace Glueful\Async\Instrumentation;

use Psr\Http\Message\RequestInterface;

/**
 * Null object pattern implementation of Metrics interface.
 *
 * NullMetrics provides a no-op implementation that discards all metric events.
 * This is useful as a default when metrics collection is disabled, avoiding
 * null checks throughout the codebase.
 *
 * Benefits:
 * - Zero overhead - all methods are empty no-ops
 * - Type safety - satisfies Metrics interface contract
 * - Clean code - eliminates conditional checks for null metrics
 * - Production-ready - safe default when metrics aren't needed
 *
 * This is the default metrics implementation used by FiberScheduler when no
 * metrics collector is provided.
 *
 * Usage:
 * ```php
 * // Explicitly disable metrics
 * $scheduler = new FiberScheduler(new NullMetrics());
 *
 * // Or rely on default (implicitly uses NullMetrics)
 * $scheduler = new FiberScheduler();
 * ```
 *
 * Use cases:
 * - Production when metrics aren't needed
 * - Testing when metrics would add noise
 * - Development when debugging without logs
 * - Performance-sensitive code paths
 *
 * To enable metrics, use LoggerMetrics or a custom implementation instead:
 * ```php
 * $scheduler = new FiberScheduler(new LoggerMetrics($logger));
 * ```
 */
final class NullMetrics implements Metrics
{
    /**
     * No-op: task start event is discarded.
     *
     * @param string $name Task name (ignored)
     * @param array<string, mixed> $context Context data (ignored)
     * @return void
     */
    public function taskStarted(string $name, array $context = []): void
    {
        // No-op: metrics disabled
    }

    /**
     * No-op: task completion event is discarded.
     *
     * @param string $name Task name (ignored)
     * @param array<string, mixed> $context Context data (ignored)
     * @return void
     */
    public function taskCompleted(string $name, array $context = []): void
    {
        // No-op: metrics disabled
    }

    /**
     * No-op: task failure event is discarded.
     *
     * @param string $name Task name (ignored)
     * @param \Throwable $e Exception (ignored)
     * @param array<string, mixed> $context Context data (ignored)
     * @return void
     */
    public function taskFailed(string $name, \Throwable $e, array $context = []): void
    {
        // No-op: metrics disabled
    }

    /**
     * No-op: HTTP request start event is discarded.
     *
     * @param RequestInterface $request HTTP request (ignored)
     * @param array<string, mixed> $context Context data (ignored)
     * @return void
     */
    public function httpRequestStarted(RequestInterface $request, array $context = []): void
    {
        // No-op: metrics disabled
    }

    /**
     * No-op: HTTP request completion event is discarded.
     *
     * @param RequestInterface $request HTTP request (ignored)
     * @param int $statusCode HTTP status code (ignored)
     * @param float $durationMs Request duration (ignored)
     * @param array<string, mixed> $context Context data (ignored)
     * @return void
     */
    public function httpRequestCompleted(
        RequestInterface $request,
        int $statusCode,
        float $durationMs,
        array $context = []
    ): void {
        // No-op: metrics disabled
    }

    /**
     * No-op: HTTP request failure event is discarded.
     *
     * @param RequestInterface $request HTTP request (ignored)
     * @param \Throwable $e Exception (ignored)
     * @param float $durationMs Request duration (ignored)
     * @param array<string, mixed> $context Context data (ignored)
     * @return void
     */
    public function httpRequestFailed(
        RequestInterface $request,
        \Throwable $e,
        float $durationMs,
        array $context = []
    ): void {
        // No-op: metrics disabled
    }

    /**
     * No-op: fiber suspension event is discarded.
     *
     * @param string $taskName Task name (ignored)
     * @param string $operation Operation type (ignored)
     * @return void
     */
    public function fiberSuspended(string $taskName, string $operation): void
    {
        // No-op: metrics disabled
    }

    /**
     * No-op: fiber resume event is discarded.
     *
     * @param string $taskName Task name (ignored)
     * @param float $waitMs Wait duration (ignored)
     * @return void
     */
    public function fiberResumed(string $taskName, float $waitMs = 0.0): void
    {
        // No-op: metrics disabled
    }

    /**
     * No-op: queue depth snapshot is discarded.
     *
     * @param int $ready Ready tasks count (ignored)
     * @param int $waiting Waiting tasks count (ignored)
     * @param int $sleeping Sleeping tasks count (ignored)
     * @return void
     */
    public function queueDepth(int $ready, int $waiting, int $sleeping): void
    {
        // No-op: metrics disabled
    }

    /**
     * No-op: task cancellation event is discarded.
     *
     * @param string $taskName Task name (ignored)
     * @param string $reason Cancellation reason (ignored)
     * @return void
     */
    public function taskCancelled(string $taskName, string $reason = ''): void
    {
        // No-op: metrics disabled
    }

    /**
     * No-op: resource limit event is discarded.
     *
     * @param string $limitType Resource type (ignored)
     * @param int $current Current usage (ignored)
     * @param int $max Maximum limit (ignored)
     * @return void
     */
    public function resourceLimit(string $limitType, int $current, int $max): void
    {
        // No-op: metrics disabled
    }
}
