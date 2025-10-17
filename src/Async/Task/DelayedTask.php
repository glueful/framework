<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\CancellationToken;
use Glueful\Async\Internal\SleepOp;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;

/**
 * Task that delays execution of a callable by a given duration.
 *
 * DelayedTask wraps a callable and defers its execution until after a specified
 * delay period. This is useful for implementing debouncing, rate limiting, retry
 * delays, and scheduled task execution in async workflows.
 *
 * The delay is cooperative - it suspends the fiber using SleepOp, allowing other
 * tasks to execute during the delay period. This makes it efficient for concurrent
 * execution of multiple delayed tasks.
 *
 * Key features:
 * - **Cooperative delay**: Doesn't block other tasks during the delay
 * - **Cancellable**: Supports cancellation during or after the delay
 * - **Metrics support**: Optional instrumentation for monitoring
 * - **Type-safe**: Wraps FiberTask for proper async execution
 * - **Flexible timing**: Sub-second precision for delays
 *
 * Use cases:
 * - **Debouncing**: Delay action until after rapid events settle
 * - **Retry with backoff**: Wait before retrying failed operations
 * - **Rate limiting**: Enforce minimum time between operations
 * - **Scheduled execution**: Run task at a specific future time
 * - **Timeout fallback**: Execute fallback after primary task times out
 *
 * Implementation note:
 * DelayedTask wraps a FiberTask that first sleeps for the specified duration,
 * then executes the wrapped callable. The delay happens before the callable
 * starts, not before each resumption.
 *
 * Usage examples:
 * ```php
 * $scheduler = new FiberScheduler();
 *
 * // Basic delayed execution (wait 2 seconds then run)
 * $task = new DelayedTask(
 *     fn() => echo "Executed after 2 seconds\n",
 *     2.0
 * );
 * $scheduler->all([$task]);
 *
 * // Debouncing pattern (delay multiple rapid calls)
 * $debounced = new DelayedTask(
 *     fn() => saveUserInput($input),
 *     0.5  // Wait 500ms after last input
 * );
 *
 * // Retry with exponential backoff
 * $attempt = 1;
 * $task = new DelayedTask(
 *     fn() => retryFailedOperation(),
 *     pow(2, $attempt) // 2^attempt seconds delay
 * );
 *
 * // With cancellation (cancel if user navigates away)
 * $token = new SimpleCancellationToken();
 * $task = new DelayedTask(
 *     fn() => performAction(),
 *     3.0,
 *     $token
 * );
 * // Later: $token->cancel();
 *
 * // Multiple delayed tasks running concurrently
 * $tasks = [
 *     new DelayedTask(fn() => task1(), 1.0),
 *     new DelayedTask(fn() => task2(), 2.0),
 *     new DelayedTask(fn() => task3(), 3.0),
 * ];
 * $results = $scheduler->all($tasks);
 * // All tasks run concurrently, completing after 3 seconds total
 * ```
 *
 * Cancellation behavior:
 * - Checks cancellation before starting the delay
 * - Checks cancellation after the delay completes
 * - Cancellation during delay is handled by SleepOp
 * - Throws exception if cancelled at any checkpoint
 *
 * Comparison to alternatives:
 * ```php
 * // Using DelayedTask (recommended)
 * $task = new DelayedTask(fn() => action(), 2.0);
 *
 * // Manual approach (more verbose)
 * $task = $scheduler->spawn(function() use ($scheduler) {
 *     $scheduler->sleep(2.0);
 *     return action();
 * });
 * ```
 */
final class DelayedTask implements Task
{
    /**
     * The wrapped FiberTask that handles the delay and execution.
     *
     * @var Task
     */
    private Task $inner;

    /**
     * Creates a delayed task that executes after a specified duration.
     *
     * The callable will not start execution until after the delay period.
     * During the delay, the fiber suspends, allowing other tasks to run.
     *
     * Execution flow:
     * 1. Task starts and checks cancellation
     * 2. Fiber suspends for the specified duration (SleepOp)
     * 3. After delay, checks cancellation again
     * 4. Executes the callable and returns its result
     *
     * @param callable $fn Callable to execute after the delay.
     *                     Can return any value or throw exceptions.
     * @param float $seconds Delay in seconds before starting the callable.
     *                       Supports sub-second precision (e.g., 0.5 for 500ms).
     *                       Negative values are clamped to 0.
     * @param CancellationToken|null $token Optional cooperative cancellation token.
     *                                      Checked before and after the delay.
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

        // Wrap in a FiberTask that delays before executing
        $this->inner = new FiberTask(function () use ($fn, $seconds, $token) {
            // Check cancellation before starting delay
            $token?->throwIfCancelled();

            // Suspend for the delay period (cooperative - other tasks can run)
            \Fiber::suspend(new SleepOp(microtime(true) + max(0.0, $seconds), $token));

            // Check cancellation after delay completes
            $token?->throwIfCancelled();

            // Execute the wrapped callable
            return $fn();
        }, $m, 'delayed-task', $token);
    }

    /**
     * Checks if the delayed task is currently running.
     *
     * Returns true if the task has started (either during the delay period or
     * executing the callable) but hasn't completed yet.
     *
     * @return bool True if task is running, false otherwise
     */
    public function isRunning(): bool
    {
        return $this->inner->isRunning();
    }

    /**
     * Checks if the delayed task has completed.
     *
     * Returns true if the delay period has elapsed and the callable has
     * finished execution (successfully or with an exception).
     *
     * @return bool True if task completed, false otherwise
     */
    public function isCompleted(): bool
    {
        return $this->inner->isCompleted();
    }

    /**
     * Retrieves the result of the delayed task.
     *
     * Blocks until the task completes if necessary. This includes waiting for
     * both the delay period and the callable execution.
     *
     * Behavior:
     * - If called before delay: waits for delay + execution
     * - If called during delay: waits for remaining delay + execution
     * - If called after completion: returns cached result immediately
     *
     * @return mixed The return value of the wrapped callable
     * @throws \Throwable If the callable threw an exception or task was cancelled
     */
    public function getResult(): mixed
    {
        return $this->inner->getResult();
    }

    /**
     * Requests cooperative cancellation of the delayed task.
     *
     * Sets the cancellation token to cancelled state. The task will check this
     * token before starting the delay, after the delay completes, and during
     * the delay (via SleepOp).
     *
     * Cancellation points:
     * - Before the delay starts
     * - During the delay (SleepOp checks token)
     * - After the delay, before callable execution
     *
     * Note: If the callable is already executing when cancel() is called, it
     * will not be interrupted unless it explicitly checks the cancellation token.
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->inner->cancel();
    }
}
