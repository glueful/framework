<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts;

/**
 * Contract for async task scheduling and concurrent execution.
 *
 * Scheduler is the core of the async framework - it's the event loop that
 * enables cooperative multitasking. The scheduler multiplexes multiple tasks,
 * switching between them when they yield control (via suspension points like
 * I/O waits, sleeps, or explicit yields).
 *
 * Think of the Scheduler as:
 * - **Event loop**: Continuously processes ready tasks and I/O events
 * - **Task orchestrator**: Manages concurrent task execution and coordination
 * - **I/O multiplexer**: Uses stream_select() to wait for multiple I/O operations
 * - **Timer manager**: Handles sleep operations and timeouts
 *
 * Key capabilities:
 * - **Spawn tasks**: Create and start async tasks from callables
 * - **Concurrent execution**: Run multiple tasks concurrently (all())
 * - **Racing**: Wait for first task to complete (race())
 * - **Cooperative sleeping**: Non-blocking delays that yield to other tasks
 * - **I/O multiplexing**: Efficient waiting on multiple streams (via ReadOp/WriteOp)
 *
 * Primary implementation: **FiberScheduler**
 * - Uses PHP 8.1+ fibers for cooperative multitasking
 * - stream_select() for efficient I/O multiplexing
 * - Supports timeouts, cancellation, and metrics
 *
 * Usage patterns:
 * ```php
 * $scheduler = new FiberScheduler();
 *
 * // Pattern 1: Spawn and await tasks concurrently
 * $task1 = $scheduler->spawn(fn() => fetchUser(1));
 * $task2 = $scheduler->spawn(fn() => fetchUser(2));
 * $task3 = $scheduler->spawn(fn() => fetchUser(3));
 * [$user1, $user2, $user3] = $scheduler->all([$task1, $task2, $task3]);
 *
 * // Pattern 2: Race multiple operations (timeout pattern)
 * $dataTask = $scheduler->spawn(fn() => fetchData());
 * $timeoutTask = $scheduler->spawn(function() use ($scheduler) {
 *     $scheduler->sleep(5.0);
 *     throw new \RuntimeException('Operation timed out');
 * });
 * $result = $scheduler->race([$dataTask, $timeoutTask]);
 *
 * // Pattern 3: Concurrent HTTP requests
 * $httpClient = new CurlMultiHttpClient();
 * $tasks = [
 *     $httpClient->sendAsync(new Request('GET', 'https://api.example.com/users')),
 *     $httpClient->sendAsync(new Request('GET', 'https://api.example.com/posts')),
 *     $httpClient->sendAsync(new Request('GET', 'https://api.example.com/comments')),
 * ];
 * $responses = $scheduler->all($tasks);
 *
 * // Pattern 4: With cancellation
 * $token = new SimpleCancellationToken();
 * $task = $scheduler->spawn(fn() => longRunningOperation($scheduler, $token), $token);
 * // Later: cancel if needed
 * $token->cancel();
 * ```
 *
 * Performance characteristics:
 * - O(n) per event loop iteration where n = number of active tasks
 * - stream_select() enables efficient I/O waiting without polling
 * - Minimal overhead per task switch (fiber suspension is fast)
 * - Scales well to hundreds of concurrent tasks
 *
 * Relationship to other contracts:
 * - **Task**: Scheduler creates and executes tasks
 * - **CancellationToken**: Enables cooperative task cancellation
 * - **HttpClient**: Benefits from scheduler for concurrent HTTP requests
 */
interface Scheduler
{
    /**
     * Spawns a new async task from a callable.
     *
     * Creates a FiberTask that wraps the callable and registers it with the
     * scheduler for concurrent execution. The task starts in a suspended state
     * and will be executed when passed to all() or race().
     *
     * The callable should be cooperative: it should periodically yield control
     * via suspension points (I/O operations, sleep, explicit yields) to allow
     * other tasks to run.
     *
     * Example:
     * ```php
     * $task = $scheduler->spawn(function() use ($scheduler) {
     *     $data = fetchFromApi();
     *     $scheduler->sleep(1.0); // Yield to other tasks
     *     return processData($data);
     * });
     * ```
     *
     * @param callable $fn The async operation to execute. Should be cooperative.
     * @param CancellationToken|null $token Optional cancellation token for the task
     * @return Task A FiberTask representing the async operation
     */
    public function spawn(callable $fn, ?CancellationToken $token = null): Task;

    /**
     * Waits for all tasks to complete, returning their results.
     *
     * Executes an event loop that runs all tasks concurrently, switching between
     * them as they yield control. Returns only when all tasks have completed
     * (successfully or with errors).
     *
     * Behavior:
     * - Runs event loop until all tasks complete
     * - Returns results in same order as input tasks
     * - If any task throws, the exception is re-thrown (after all tasks finish)
     * - Handles I/O multiplexing via stream_select() for efficient waiting
     * - Processes timers and sleep operations
     *
     * Similar to: Promise.all() in JavaScript, Task.WhenAll() in C#
     *
     * Example:
     * ```php
     * $tasks = [
     *     $scheduler->spawn(fn() => fetchUser(1)),
     *     $scheduler->spawn(fn() => fetchUser(2)),
     *     $scheduler->spawn(fn() => fetchUser(3)),
     * ];
     * [$user1, $user2, $user3] = $scheduler->all($tasks);
     * ```
     *
     * @param array<int, Task> $tasks Array of tasks to execute concurrently
     * @return array<int, mixed> Results in same order as input tasks
     * @throws \Throwable If any task failed (after all tasks complete)
     */
    public function all(array $tasks): array;

    /**
     * Races multiple tasks, returning the first result.
     *
     * Executes an event loop that runs all tasks concurrently, but returns as
     * soon as the first task completes (successfully or with error). Other tasks
     * are left in their current state (not automatically cancelled).
     *
     * Behavior:
     * - Runs event loop until first task completes
     * - Returns the first completion's result immediately
     * - If first task throws, that exception is thrown
     * - Other tasks continue running but their results are discarded
     * - Useful for timeout patterns and fallback scenarios
     *
     * Similar to: Promise.race() in JavaScript, Task.WhenAny() in C#
     *
     * Example (timeout pattern):
     * ```php
     * $dataTask = $scheduler->spawn(fn() => fetchSlowData());
     * $timeoutTask = $scheduler->spawn(function() use ($scheduler) {
     *     $scheduler->sleep(5.0);
     *     throw new TimeoutException('Operation took too long');
     * });
     * $result = $scheduler->race([$dataTask, $timeoutTask]);
     * ```
     *
     * @param array<int, Task> $tasks Array of tasks to race
     * @return mixed Result of the first task to complete
     * @throws \Throwable If the first task to complete threw an exception
     */
    public function race(array $tasks): mixed;

    /**
     * Sleeps for the specified duration without blocking other tasks.
     *
     * This is a cooperative sleep that yields control to the scheduler, allowing
     * other tasks to execute while waiting. Unlike PHP's sleep() or usleep(),
     * this doesn't block the entire thread.
     *
     * Must be called from within a fiber (task). The scheduler suspends the
     * current fiber and resumes it after the specified duration.
     *
     * Behavior:
     * - Suspends current fiber with SleepOp
     * - Scheduler resumes fiber after duration elapses
     * - Can be cancelled via token (throws exception on cancellation)
     * - Precision depends on scheduler's event loop iteration time
     *
     * Example:
     * ```php
     * $task = $scheduler->spawn(function() use ($scheduler) {
     *     echo "Starting...\n";
     *     $scheduler->sleep(2.0); // Yields for 2 seconds
     *     echo "Done!\n";
     * });
     * $scheduler->all([$task]); // Other tasks can run during the sleep
     * ```
     *
     * @param float $seconds Duration to sleep in seconds (supports microsecond precision)
     * @param CancellationToken|null $token Optional cancellation token
     * @return void
     * @throws \Exception If cancelled via token
     */
    public function sleep(float $seconds, ?CancellationToken $token = null): void;
}
