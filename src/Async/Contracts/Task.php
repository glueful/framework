<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts;

/**
 * Contract for async task execution and state management.
 *
 * Task represents a unit of async work - similar to JavaScript's Promise or
 * C#'s Task. It provides a unified interface for working with asynchronous
 * operations regardless of their implementation (fibers, closures, pre-computed
 * values, etc.).
 *
 * A Task can be in one of three states:
 * 1. **Running** - Task is currently executing (fiber running or not started)
 * 2. **Completed** - Task finished successfully with a result
 * 3. **Failed** - Task threw an exception (retrieved via getResult())
 *
 * Key concepts:
 * - **Non-blocking**: Tasks don't block the calling code; they execute cooperatively
 * - **Lazy execution**: Tasks may not start until scheduled or awaited
 * - **Single result**: Each task produces one result (or throws one exception)
 * - **Cooperative cancellation**: cancel() signals intent; task must check token
 *
 * Implementations:
 * - **FiberTask**: Executes callable in a PHP fiber for true async execution
 * - **ClosureTask**: Synchronous execution wrapper (no concurrency)
 * - **CompletedTask**: Wrapper for pre-computed successful values
 * - **FailedTask**: Wrapper for pre-existing errors
 *
 * Usage with scheduler:
 * ```php
 * $scheduler = new FiberScheduler();
 *
 * // Spawn multiple tasks
 * $task1 = $scheduler->spawn(fn() => fetchUser(1));
 * $task2 = $scheduler->spawn(fn() => fetchUser(2));
 *
 * // Wait for all to complete
 * [$user1, $user2] = $scheduler->all([$task1, $task2]);
 *
 * // Or race them (first to complete wins)
 * $firstUser = $scheduler->race([$task1, $task2]);
 * ```
 *
 * Direct usage (without scheduler):
 * ```php
 * $task = new FiberTask(fn() => expensiveOperation());
 *
 * // Check state
 * if ($task->isRunning()) {
 *     // Task hasn't completed yet
 * }
 *
 * // Get result (blocks until complete for FiberTask without scheduler)
 * try {
 *     $result = $task->getResult();
 * } catch (\Throwable $e) {
 *     // Task failed with exception
 * }
 * ```
 *
 * Relationship to other contracts:
 * - **Scheduler**: Creates and executes tasks concurrently
 * - **CancellationToken**: Enables cooperative task cancellation
 * - **HttpClient**: Returns tasks for async HTTP requests
 */
interface Task
{
    /**
     * Checks if the task is currently running.
     *
     * Returns true if the task has started but not yet completed (either
     * successfully or with an error). For FiberTask, this means the fiber
     * is suspended or executing. For completed/failed tasks, always false.
     *
     * @return bool True if task is in progress, false if completed or not started
     */
    public function isRunning(): bool;

    /**
     * Checks if the task has completed execution.
     *
     * Returns true if the task finished (successfully or with error). Once
     * completed, the result is available via getResult(). Note that a completed
     * task may have failed - use getResult() in a try-catch to handle errors.
     *
     * @return bool True if task finished, false if still running or not started
     */
    public function isCompleted(): bool;

    /**
     * Retrieves the task result, blocking if necessary until completion.
     *
     * This method:
     * - Blocks until the task completes (for FiberTask without scheduler)
     * - Returns the task's return value on success
     * - Throws the task's exception on failure
     * - Can be called multiple times (same result each time)
     *
     * For FiberTask: If called outside a scheduler, executes the fiber
     * synchronously. Inside a scheduler, the scheduler handles suspension.
     *
     * For CompletedTask: Returns pre-computed value immediately
     * For FailedTask: Throws pre-existing exception immediately
     *
     * @return mixed The task's return value
     * @throws \Throwable If the task failed with an exception
     */
    public function getResult(): mixed; // May throw \Throwable

    /**
     * Requests cooperative cancellation of the task.
     *
     * IMPORTANT: This is cooperative cancellation. Calling cancel() signals
     * intent to cancel, but the task must explicitly check its cancellation
     * token and honor the request. Cancellation is not forced or preemptive.
     *
     * Behavior:
     * - For tasks with cancellation tokens: Sets token to cancelled state
     * - Task must check token via isCancelled() or throwIfCancelled()
     * - No effect on tasks without cancellation support
     * - Safe to call multiple times
     *
     * @return void
     */
    public function cancel(): void;
}
