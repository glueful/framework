<?php

declare(strict_types=1);

namespace Glueful\Async\Instrumentation;

use Psr\Http\Message\RequestInterface;

/**
 * Metrics collection interface for async operations.
 *
 * This interface defines the contract for collecting performance metrics and
 * observability data from async tasks and HTTP requests. Implementations can
 * log to PSR-3 loggers, send to monitoring systems, or collect in-memory stats.
 *
 * The interface supports two primary use cases:
 * 1. **Task metrics** - Track lifecycle of async tasks (start, complete, fail)
 * 2. **HTTP metrics** - Monitor async HTTP requests with timing and status data
 *
 * Implementations:
 * - LoggerMetrics: Logs events to PSR-3 logger
 * - NullMetrics: No-op implementation for production when metrics are disabled
 * - Custom: StatsD, Prometheus, CloudWatch, etc.
 *
 * Usage with FiberScheduler:
 * ```php
 * $logger = app(LoggerInterface::class);
 * $metrics = new LoggerMetrics($logger);
 * $scheduler = new FiberScheduler($metrics);
 *
 * // Metrics are automatically collected
 * $task = $scheduler->spawn(fn() => expensiveOperation());
 * ```
 */
interface Metrics
{
    /**
     * Records that an async task has started execution.
     *
     * Called when a task begins execution (fiber starts). Use this to track
     * task concurrency, identify long-running tasks, or measure task throughput.
     *
     * @param string $name Task name (e.g., "fiber@UserController.php:42")
     * @param array<string, mixed> $context Additional contextual data
     * @return void
     */
    public function taskStarted(string $name, array $context = []): void;

    /**
     * Records that an async task completed successfully.
     *
     * Called when a task finishes without error. Use this with taskStarted()
     * to calculate task duration and success rates.
     *
     * @param string $name Task name (same as passed to taskStarted)
     * @param array<string, mixed> $context Additional contextual data
     * @return void
     */
    public function taskCompleted(string $name, array $context = []): void;

    /**
     * Records that an async task failed with an exception.
     *
     * Called when a task throws an exception during execution. Use this to
     * track error rates, identify failing tasks, and monitor exceptions.
     *
     * @param string $name Task name (same as passed to taskStarted)
     * @param \Throwable $e The exception that caused the failure
     * @param array<string, mixed> $context Additional contextual data
     * @return void
     */
    public function taskFailed(string $name, \Throwable $e, array $context = []): void;

    /**
     * Records that an async HTTP request has started.
     *
     * Called when an async HTTP client begins a request. Use this to track
     * concurrent HTTP requests and identify which endpoints are being called.
     *
     * @param RequestInterface $request The PSR-7 HTTP request
     * @param array<string, mixed> $context Additional contextual data
     * @return void
     */
    public function httpRequestStarted(RequestInterface $request, array $context = []): void;

    /**
     * Records successful completion of an async HTTP request.
     *
     * Called when an HTTP request completes with a response. Use this to track
     * response times, status codes, and HTTP performance metrics.
     *
     * @param RequestInterface $request The PSR-7 HTTP request
     * @param int $statusCode HTTP status code (e.g., 200, 404)
     * @param float $durationMs Request duration in milliseconds
     * @param array<string, mixed> $context Additional contextual data
     * @return void
     */
    public function httpRequestCompleted(
        RequestInterface $request,
        int $statusCode,
        float $durationMs,
        array $context = []
    ): void;

    /**
     * Records failure of an async HTTP request.
     *
     * Called when an HTTP request fails with an exception (network error,
     * timeout, etc.). Use this to track HTTP failures and error patterns.
     *
     * @param RequestInterface $request The PSR-7 HTTP request
     * @param \Throwable $e The exception that caused the failure
     * @param float $durationMs Time elapsed before failure in milliseconds
     * @param array<string, mixed> $context Additional contextual data
     * @return void
     */
    public function httpRequestFailed(
        RequestInterface $request,
        \Throwable $e,
        float $durationMs,
        array $context = []
    ): void;
}
