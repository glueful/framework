<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\CancellationToken;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;

/**
 * Task that enforces a cooperative timeout around a callable.
 *
 * TimeoutTask wraps a callable with automatic timeout enforcement. If the callable
 * doesn't complete within the specified duration, a timeout exception is thrown.
 * This is useful for preventing operations from hanging indefinitely and enforcing
 * SLA requirements.
 *
 * The timeout is **cooperative** - it only checks at suspension points (when the
 * callable yields control via Fiber::suspend). The callable must be cooperative
 * (using async I/O, sleeps, or explicit yields) for the timeout to be enforced
 * promptly.
 *
 * Key features:
 * - **Cooperative timeout**: Checks at every suspension point
 * - **Deadline-based**: Uses absolute deadline for accurate timing
 * - **Cancellable**: Supports additional cancellation beyond timeout
 * - **Interposition**: Wraps inner fiber to intercept all suspensions
 * - **Metrics support**: Optional instrumentation for monitoring
 * - **Exception on timeout**: Throws RuntimeException when deadline exceeded
 *
 * How it works:
 * TimeoutTask creates two fiber layers:
 * 1. **Outer FiberTask**: Manages the timeout logic and wrapping
 * 2. **Inner Fiber**: Executes the actual callable
 *
 * The outer fiber manually drives the inner fiber, checking the deadline at
 * each suspension point. This allows precise timeout enforcement without
 * polling or interrupting the inner fiber mid-operation.
 *
 * Use cases:
 * - **API call timeouts**: Prevent hanging on unresponsive external services
 * - **Database query timeouts**: Enforce maximum query execution time
 * - **SLA enforcement**: Guarantee operations complete within time limits
 * - **Resource protection**: Prevent operations from consuming resources indefinitely
 * - **Cascading timeouts**: Enforce timeouts at multiple levels of abstraction
 *
 * Important limitations:
 * - **CPU-bound operations**: Won't timeout if callable never yields
 * - **Blocking calls**: Native blocking functions (file I/O, sleep()) won't respect timeout
 * - **Accuracy**: Timeout precision depends on suspension frequency
 *
 * Usage examples:
 * ```php
 * $scheduler = new FiberScheduler();
 *
 * // Basic timeout (prevent hanging on slow API)
 * $task = new TimeoutTask(
 *     fn() => callExternalApi(),
 *     5.0  // 5 second timeout
 * );
 * try {
 *     $result = $scheduler->all([$task])[0];
 * } catch (\RuntimeException $e) {
 *     // Handle timeout: "Operation timed out"
 *     echo "API call timed out\n";
 * }
 *
 * // Timeout with fallback (try primary, fall back to cached)
 * $primaryTask = new TimeoutTask(fn() => fetchFromPrimary(), 2.0);
 * try {
 *     $data = $primaryTask->getResult();
 * } catch (\RuntimeException $e) {
 *     $data = fetchFromCache();  // Fallback on timeout
 * }
 *
 * // Racing timeout against operation (Promise.race pattern)
 * $scheduler = new FiberScheduler();
 * $dataTask = $scheduler->spawn(fn() => fetchData());
 * $timeoutTask = $scheduler->spawn(function() use ($scheduler) {
 *     $scheduler->sleep(5.0);
 *     throw new \RuntimeException('Operation timed out');
 * });
 * $result = $scheduler->race([$dataTask, $timeoutTask]);
 *
 * // Multiple operations with individual timeouts
 * $tasks = [
 *     new TimeoutTask(fn() => fetchUsers(), 3.0),
 *     new TimeoutTask(fn() => fetchPosts(), 2.0),
 *     new TimeoutTask(fn() => fetchComments(), 1.0),
 * ];
 * $results = $scheduler->all($tasks);
 * // Each operation has its own timeout
 *
 * // Nested timeouts (cascading timeouts at different levels)
 * $outerTask = new TimeoutTask(
 *     function() {
 *         $innerTask = new TimeoutTask(
 *             fn() => slowOperation(),
 *             2.0  // Inner timeout: 2 seconds
 *         );
 *         return $innerTask->getResult();
 *     },
 *     5.0  // Outer timeout: 5 seconds
 * );
 * ```
 *
 * Cooperative requirement:
 * ```php
 * // Good: Cooperative operation (yields during work)
 * $task = new TimeoutTask(function() use ($scheduler) {
 *     for ($i = 0; $i < 100; $i++) {
 *         processItem($i);
 *         $scheduler->sleep(0.01);  // Yields - timeout can be checked
 *     }
 * }, 5.0);
 *
 * // Bad: CPU-bound operation (never yields)
 * $task = new TimeoutTask(function() {
 *     while (true) {
 *         // This will NEVER timeout - no suspension points!
 *         $x = md5(random_bytes(1000));
 *     }
 * }, 5.0);
 * ```
 *
 * Exception handling:
 * - Throws `\RuntimeException` with message "Operation timed out"
 * - Preserves original exceptions if callable fails before timeout
 * - Cancellation exceptions take precedence over timeout
 *
 * Performance notes:
 * - Minimal overhead (one extra fiber layer)
 * - Deadline checked at each suspension (fast comparison)
 * - No polling or background threads required
 * - Efficient for I/O-bound cooperative operations
 */
final class TimeoutTask implements Task
{
    /**
     * The wrapped FiberTask that handles the timeout logic.
     *
     * @var Task
     */
    private Task $inner;

    /**
     * Creates a timeout task that enforces a deadline on a callable.
     *
     * The callable executes within a manually-driven inner fiber. At each
     * suspension point, the outer fiber checks if the deadline has been
     * exceeded. If so, it throws a timeout exception instead of resuming.
     *
     * Execution flow:
     * 1. Calculate absolute deadline from timeout duration
     * 2. Start inner fiber with the callable
     * 3. At each suspension: check cancellation and deadline
     * 4. If deadline exceeded: throw timeout exception
     * 5. Otherwise: propagate suspension to scheduler
     * 6. Resume inner fiber when scheduler resumes us
     * 7. Repeat until callable completes or times out
     *
     * @param callable():mixed $fn Callable to execute with timeout.
     *                             Must be cooperative (yield periodically).
     *                             Can return any value or throw exceptions.
     * @param float $seconds Timeout duration in seconds.
     *                       Supports sub-second precision (e.g., 0.5 for 500ms).
     *                       Negative values are clamped to 0.
     * @param CancellationToken|null $token Optional cooperative cancellation token.
     *                                      Checked at each suspension point.
     *                                      Cancellation takes precedence over timeout.
     * @param Metrics|null $metrics Optional metrics collector for observability.
     *                              Defaults to NullMetrics (no-op).
     */
    public function __construct(
        callable $fn,
        float $seconds,
        ?CancellationToken $token = null,
        ?Metrics $metrics = null
    ) {
        $m = $metrics ?? new NullMetrics();

        // Wrap in a FiberTask that enforces the timeout
        $this->inner = new FiberTask(function () use ($fn, $seconds, $token) {
            // Calculate absolute deadline (more accurate than counting down)
            $deadline = microtime(true) + max(0.0, $seconds);

            // Run the callable in an inner fiber we manually drive.
            // This lets us interpose timeout and cancellation checks at each suspension.
            $inner = new \Fiber(static function () use ($fn) {
                return $fn();
            });

            // Start the inner fiber and get its first suspension (if any)
            $suspend = $inner->start();

            // Drive the inner fiber until completion or timeout
            while (true) {
                // Check if inner fiber completed
                if ($inner->isTerminated()) {
                    return $inner->getReturn();
                }

                // Check cancellation (takes precedence over timeout)
                $token?->throwIfCancelled();

                // Check timeout deadline
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Operation timed out');
                }

                // Propagate suspension to the scheduler
                // This allows I/O operations and sleeps to work correctly
                \Fiber::suspend($suspend);

                // Scheduler resumed us - resume the inner fiber and get next suspension
                $suspend = $inner->resume(null);
            }
        }, $m, 'timeout-task', $token);
    }

    /**
     * Checks if the timeout task is currently running.
     *
     * Returns true if the task has started (executing the callable or
     * waiting at a suspension point) but hasn't completed or timed out yet.
     *
     * @return bool True if task is running, false otherwise
     */
    public function isRunning(): bool
    {
        return $this->inner->isRunning();
    }

    /**
     * Checks if the timeout task has completed.
     *
     * Returns true if the callable finished successfully, timed out, or
     * was cancelled.
     *
     * @return bool True if task completed, false otherwise
     */
    public function isCompleted(): bool
    {
        return $this->inner->isCompleted();
    }

    /**
     * Retrieves the result of the timeout task.
     *
     * Blocks until the callable completes or the timeout is exceeded.
     * Returns the callable's return value on success.
     *
     * Behavior:
     * - If callable completes in time: returns its result
     * - If timeout exceeded: throws RuntimeException("Operation timed out")
     * - If callable throws: propagates that exception
     * - If cancelled: throws cancellation exception
     *
     * Example:
     * ```php
     * $task = new TimeoutTask(fn() => fetchData(), 5.0);
     * try {
     *     $data = $task->getResult();
     *     echo "Got data: $data\n";
     * } catch (\RuntimeException $e) {
     *     echo "Timed out: " . $e->getMessage() . "\n";
     * }
     * ```
     *
     * @return mixed The return value of the wrapped callable
     * @throws \RuntimeException If the timeout is exceeded ("Operation timed out")
     * @throws \Throwable If the callable threw an exception or task was cancelled
     */
    public function getResult(): mixed
    {
        return $this->inner->getResult();
    }

    /**
     * Requests cooperative cancellation of the timeout task.
     *
     * Sets the cancellation token to cancelled state. The timeout logic will
     * check this token at each suspension point (before checking the deadline).
     * Cancellation takes precedence over timeout.
     *
     * Cancellation points:
     * - At each suspension point (checked before timeout)
     * - Cancellation exception thrown if token is cancelled
     *
     * Note: The wrapped callable continues executing until it reaches a
     * suspension point, at which time the cancellation is detected.
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->inner->cancel();
    }
}
