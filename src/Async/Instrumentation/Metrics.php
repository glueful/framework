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

    /**
     * Records that a fiber suspended execution on an operation.
     *
     * Called when a fiber yields control while waiting for an operation to complete.
     * Use this to track fiber context switches, identify blocking operations, and
     * measure scheduler efficiency.
     *
     * Operation types:
     * - sleep: Fiber suspended on sleep/delay operation
     * - read: Fiber waiting for stream/socket to become readable
     * - write: Fiber waiting for stream/socket to become writable
     * - other: Custom or unknown suspension operation
     *
     * Example usage:
     * ```php
     * // Track how often tasks suspend and on what operations
     * $metrics->fiberSuspended('http:GET /api/users', 'sleep');
     * ```
     *
     * @param string $taskName Name of the task/fiber being suspended
     * @param string $operation Type of operation: sleep|read|write|other
     * @return void
     */
    public function fiberSuspended(string $taskName, string $operation): void;

    /**
     * Records that a fiber resumed execution after suspension.
     *
     * Called when a fiber resumes after being suspended on an operation. Use this
     * with fiberSuspended() to calculate suspension duration and track resume patterns.
     *
     * The wait time indicates how long the fiber was suspended before resuming.
     * This can help identify:
     * - Long waits indicating slow operations
     * - Short waits indicating efficient polling
     * - Patterns in suspension/resume cycles
     *
     * Example usage:
     * ```php
     * // Track resume with wait duration
     * $metrics->fiberResumed('http:GET /api/users', 10.5); // Waited 10.5ms
     * ```
     *
     * @param string $taskName Name of the task/fiber being resumed
     * @param float $waitMs Estimated wait duration in milliseconds (0.0 if unknown)
     * @return void
     */
    public function fiberResumed(string $taskName, float $waitMs = 0.0): void;

    /**
     * Records a snapshot of the scheduler's queue depth.
     *
     * Called periodically by the scheduler to report queue sizes. Use this to:
     * - Monitor scheduler load and task concurrency
     * - Identify queue saturation and bottlenecks
     * - Detect resource starvation patterns
     * - Measure scheduler efficiency
     *
     * Queue categories:
     * - ready: Tasks ready to execute immediately (in run queue)
     * - waiting: Tasks blocked on I/O operations (streams, sockets)
     * - sleeping: Tasks suspended on timers (sleep, delay, timeout)
     *
     * High ready count may indicate CPU saturation. High waiting/sleeping counts
     * may indicate I/O-bound or timer-heavy workloads.
     *
     * Example usage:
     * ```php
     * // Scheduler reports queue state every N iterations
     * $metrics->queueDepth(ready: 5, waiting: 10, sleeping: 3);
     * ```
     *
     * @param int $ready Number of ready tasks in the run queue
     * @param int $waiting Number of tasks waiting on I/O operations
     * @param int $sleeping Number of tasks suspended on timers
     * @return void
     */
    public function queueDepth(int $ready, int $waiting, int $sleeping): void;

    /**
     * Records that a task cancellation was requested or executed.
     *
     * Called when a task is cancelled either manually or automatically (timeout,
     * resource limit, parent cancellation). Use this to track cancellation patterns
     * and identify tasks that are frequently cancelled.
     *
     * Cancellation reasons help categorize why tasks are being cancelled:
     * - "manual": Explicitly cancelled by user code
     * - "timeout": Cancelled due to timeout/deadline
     * - "parent": Cancelled because parent task was cancelled
     * - "resource_limit": Cancelled due to resource constraints
     * - Custom reasons from application code
     *
     * Example usage:
     * ```php
     * // Track manual cancellation
     * $metrics->taskCancelled('http:GET /api/slow', 'manual');
     *
     * // Track timeout cancellation
     * $metrics->taskCancelled('db:query', 'timeout');
     * ```
     *
     * @param string $taskName Name of the task being cancelled
     * @param string $reason Free-text reason for cancellation (empty if unknown)
     * @return void
     */
    public function taskCancelled(string $taskName, string $reason = ''): void;

    /**
     * Records that a resource limit has been reached or approached.
     *
     * Called when the scheduler or async system approaches or exceeds a configured
     * resource limit. Use this to detect resource exhaustion, trigger alerts, and
     * prevent system degradation.
     *
     * Common limit types:
     * - "tasks": Maximum concurrent tasks limit
     * - "memory": Memory usage limit in bytes
     * - "fds": File descriptor limit
     * - "connections": Connection pool limit
     * - Custom limits from application code
     *
     * When current >= max, the limit has been reached or exceeded.
     * Implementations may want to alert at threshold percentages (e.g., 80%, 90%).
     *
     * Example usage:
     * ```php
     * // Task limit approaching
     * $metrics->resourceLimit('tasks', current: 95, max: 100);
     *
     * // Memory limit exceeded
     * $metrics->resourceLimit('memory', current: 1100, max: 1024);
     * ```
     *
     * @param string $limitType Type of resource being limited
     * @param int $current Current resource usage
     * @param int $max Maximum allowed resource usage
     * @return void
     */
    public function resourceLimit(string $limitType, int $current, int $max): void;
}
