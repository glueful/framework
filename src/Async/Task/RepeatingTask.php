<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\CancellationToken;
use Glueful\Async\Internal\SleepOp;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;

/**
 * Task that executes a callable repeatedly at a fixed interval.
 *
 * RepeatingTask executes a callable a specified number of times with a fixed
 * interval between executions. This is useful for polling, periodic checks,
 * batch processing, and scheduled recurring operations.
 *
 * The intervals are cooperative - the task suspends between iterations using
 * SleepOp, allowing other tasks to execute during the wait periods. This makes
 * it efficient for running multiple repeating tasks concurrently.
 *
 * Key features:
 * - **Fixed interval**: Consistent delay between iterations
 * - **Cooperative delays**: Doesn't block other tasks between iterations
 * - **Iteration tracking**: Callable receives current iteration index (0-based)
 * - **Cancellable**: Checks cancellation before each iteration and after delays
 * - **Collects results**: Returns array of all iteration results
 * - **Metrics support**: Optional instrumentation for monitoring
 *
 * Use cases:
 * - **Polling**: Check external state repeatedly until condition is met
 * - **Periodic tasks**: Execute maintenance operations at regular intervals
 * - **Batch processing**: Process items in batches with delays between batches
 * - **Rate-limited operations**: Execute operations with enforced minimum intervals
 * - **Retry with attempts**: Try operation N times with delays between attempts
 * - **Heartbeat/keepalive**: Send periodic signals or health checks
 *
 * Implementation note:
 * The first iteration executes immediately (no initial delay). Subsequent
 * iterations wait for the specified interval. If interval is 0 or negative,
 * iterations execute back-to-back without delays.
 *
 * Result collection:
 * All iteration results are collected in an array, which is returned when
 * getResult() is called. The array is indexed from 0 to (times-1).
 *
 * Usage examples:
 * ```php
 * $scheduler = new FiberScheduler();
 *
 * // Basic polling (check status 5 times, every 2 seconds)
 * $task = new RepeatingTask(
 *     fn($i) => checkStatus(),
 *     5,      // times
 *     2.0     // interval seconds
 * );
 * $statuses = $scheduler->all([$task])[0];  // Array of 5 status results
 *
 * // Batch processing with progress
 * $task = new RepeatingTask(
 *     fn($i) => processBatch($i, 100),  // Process 100 items per batch
 *     10,    // 10 batches
 *     1.0    // 1 second between batches
 * );
 * $batchResults = $task->getResult();
 *
 * // Retry pattern (try 3 times with 1 second delays)
 * $task = new RepeatingTask(
 *     function($attempt) {
 *         echo "Attempt " . ($attempt + 1) . "\n";
 *         return callUnreliableService();
 *     },
 *     3,   // 3 attempts
 *     1.0  // 1 second between attempts
 * );
 *
 * // With cancellation (stop polling if condition met)
 * $token = new SimpleCancellationToken();
 * $task = new RepeatingTask(
 *     function($i) use ($token) {
 *         $result = checkStatus();
 *         if ($result === 'complete') {
 *             $token->cancel();  // Stop future iterations
 *         }
 *         return $result;
 *     },
 *     100,  // Up to 100 checks
 *     0.5,  // Every 500ms
 *     $token
 * );
 *
 * // Heartbeat pattern (send keepalive every 30 seconds)
 * $task = new RepeatingTask(
 *     fn($i) => sendHeartbeat(),
 *     60,   // 60 heartbeats = 30 minutes
 *     30.0  // 30 seconds between heartbeats
 * );
 *
 * // No delay between iterations (back-to-back execution)
 * $task = new RepeatingTask(
 *     fn($i) => processItem($i),
 *     100,  // 100 iterations
 *     0.0   // No delay
 * );
 * ```
 *
 * Cancellation behavior:
 * - Checks cancellation before each iteration
 * - Checks cancellation after each delay
 * - Can be cancelled externally or from within the callable
 * - Returns partial results if cancelled mid-execution
 *
 * Error handling:
 * If the callable throws an exception during any iteration, the entire task
 * fails and the exception propagates. Partial results are not returned.
 *
 * Performance considerations:
 * - Interval timing is approximate (depends on scheduler loop frequency)
 * - Large iteration counts with short intervals may impact responsiveness
 * - Consider breaking very long-running repeating tasks into multiple tasks
 */
final class RepeatingTask implements Task
{
    /**
     * The wrapped FiberTask that handles the repetition loop.
     *
     * @var Task
     */
    private Task $inner;

    /**
     * Creates a repeating task that executes a callable multiple times.
     *
     * The callable executes immediately for the first iteration, then waits for
     * the specified interval before each subsequent iteration. The callable
     * receives the current iteration index (0-based) as its parameter.
     *
     * Execution flow:
     * 1. Check cancellation
     * 2. Execute callable with iteration index (0)
     * 3. If more iterations: suspend for interval
     * 4. Check cancellation after delay
     * 5. Repeat steps 2-4 for remaining iterations
     * 6. Return array of all results
     *
     * @param callable(int):mixed $fn Callable to execute each iteration.
     *                                Receives 0-based iteration index as parameter.
     *                                Can return any value.
     * @param int $times Number of times to execute the callable.
     *                   Must be >= 0. Values < 0 are clamped to 0.
     * @param float $intervalSeconds Interval between iterations in seconds.
     *                               Supports sub-second precision (e.g., 0.5 for 500ms).
     *                               First iteration has no delay.
     *                               Use 0 for back-to-back execution.
     * @param CancellationToken|null $token Optional cooperative cancellation token.
     *                                      Checked before each iteration and after delays.
     * @param Metrics|null $metrics Optional metrics collector for observability.
     *                              Defaults to NullMetrics (no-op).
     */
    public function __construct(
        callable $fn,
        int $times,
        float $intervalSeconds,
        ?CancellationToken $token = null,
        ?Metrics $metrics = null
    ) {
        // Clamp times to minimum of 0
        $times = max(0, $times);
        $m = $metrics ?? new NullMetrics();

        // Wrap in a FiberTask that executes the repetition loop
        $this->inner = new FiberTask(function () use ($fn, $times, $intervalSeconds, $token) {
            $results = [];

            // Execute the callable N times with intervals between iterations
            for ($i = 0; $i < $times; $i++) {
                // Check cancellation before each iteration
                $token?->throwIfCancelled();

                // Wait for interval before iterations 2+ (skip delay for first iteration)
                if ($i > 0 && $intervalSeconds > 0) {
                    \Fiber::suspend(new SleepOp(microtime(true) + $intervalSeconds, $token));
                    // Check cancellation after delay
                    $token?->throwIfCancelled();
                }

                // Execute callable with current iteration index
                $results[] = $fn($i);
            }

            // Return array of all iteration results
            return $results;
        }, $m, 'repeating-task', $token);
    }

    /**
     * Checks if the repeating task is currently running.
     *
     * Returns true if the task has started (executing iterations or waiting
     * between iterations) but hasn't completed all iterations yet.
     *
     * @return bool True if task is running, false otherwise
     */
    public function isRunning(): bool
    {
        return $this->inner->isRunning();
    }

    /**
     * Checks if the repeating task has completed.
     *
     * Returns true if all iterations have finished execution (successfully
     * or with an exception) or if the task was cancelled.
     *
     * @return bool True if task completed, false otherwise
     */
    public function isCompleted(): bool
    {
        return $this->inner->isCompleted();
    }

    /**
     * Retrieves the array of results from all iterations.
     *
     * Blocks until all iterations complete if necessary. Returns an array
     * indexed from 0 to (times-1), where each element is the return value
     * of the callable for that iteration.
     *
     * Behavior:
     * - If called during execution: waits for all remaining iterations
     * - If called after completion: returns cached results immediately
     * - If any iteration throws: propagates that exception (no partial results)
     * - If cancelled: throws cancellation exception (no partial results)
     *
     * Example:
     * ```php
     * $task = new RepeatingTask(fn($i) => $i * 2, 5, 0.1);
     * $results = $task->getResult();  // [0, 2, 4, 6, 8]
     * ```
     *
     * @return mixed Array of iteration results (array<int, mixed>)
     * @throws \Throwable If any iteration threw an exception or task was cancelled
     */
    public function getResult(): mixed
    {
        return $this->inner->getResult();
    }

    /**
     * Requests cooperative cancellation of the repeating task.
     *
     * Sets the cancellation token to cancelled state. The task will check this
     * token before each iteration and after each interval delay. If cancelled,
     * it throws a cancellation exception.
     *
     * Cancellation points:
     * - Before each iteration starts
     * - After each interval delay
     * - During delays (SleepOp checks token)
     *
     * Behavior:
     * - Prevents future iterations from executing
     * - Currently executing iteration runs to completion
     * - No partial results are returned (task fails with cancellation exception)
     *
     * Example:
     * ```php
     * $token = new SimpleCancellationToken();
     * $task = new RepeatingTask(fn($i) => work($i), 100, 1.0, $token);
     * // After some iterations: $token->cancel();
     * ```
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->inner->cancel();
    }
}
