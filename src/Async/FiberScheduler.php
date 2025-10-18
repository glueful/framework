<?php

declare(strict_types=1);

namespace Glueful\Async;

use Glueful\Async\Contracts\Scheduler;
use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\CancellationToken;
use Glueful\Async\Task\FiberTask;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;
use Glueful\Async\Exceptions\ResourceLimitException;
use Glueful\Async\Exceptions\TimeoutException;
use Glueful\Helpers\ConfigManager;

/**
 * Fiber-based cooperative task scheduler.
 *
 * This scheduler manages concurrent execution of tasks using PHP fibers, providing
 * cooperative multitasking with support for I/O operations, timers, and cancellation.
 *
 * Features:
 * - Non-blocking I/O operations (read/write)
 * - Timer-based task suspension
 * - Task cancellation support
 * - Metrics collection for performance monitoring
 * - Concurrent task execution with all() and race() semantics
 *
 * The scheduler uses an event loop pattern to efficiently multiplex tasks, waiting
 * on I/O readiness and timer expiration using stream_select().
 */
final class FiberScheduler implements Scheduler
{
    private int $maxConcurrentTasks = 0;
    private float $maxTaskExecutionSeconds = 0.0;
    private int $currentSpawned = 0;
    private int $maxMemoryBytes = 0;
    private int $maxOpenFileDescriptors = 0;
    /** @var \SplObjectStorage<\Glueful\Async\Task\FiberTask, float> */
    private \SplObjectStorage $startTimes;
    /** @var \SplObjectStorage<\Glueful\Async\Task\FiberTask, bool> */
    private \SplObjectStorage $ownedTasks;
    /**
     * Creates a new fiber scheduler with optional resource limits and metrics.
     *
     * The scheduler manages cooperative multitasking using PHP fibers, allowing
     * concurrent execution of tasks with I/O operations, timers, and cancellation.
     * Resource limits help prevent runaway tasks from consuming excessive resources.
     *
     * Configuration sources (in order of precedence):
     * 1. Constructor parameters (maxConcurrentTasks, maxTaskExecutionSeconds)
     * 2. Configuration file settings (async.limits.max_memory_mb, async.limits.max_open_file_descriptors)
     * 3. Defaults (no limits)
     *
     * Resource limits enforced:
     * - **Max concurrent tasks**: Limits number of spawned tasks (prevents task explosion)
     * - **Max task execution time**: Per-task timeout to prevent infinite loops
     * - **Max memory usage**: Total memory limit for async operations (from config)
     * - **Max file descriptors**: Limit on open streams/sockets (from config)
     *
     * Metrics collection:
     * - Task lifecycle events (started, completed, failed, cancelled)
     * - Fiber suspension/resumption with timing data
     * - Queue depths for ready, waiting, and timer queues
     * - Resource limit violations
     *
     * @param Metrics|null $metrics Optional metrics collector for performance monitoring.
     *                              Defaults to NullMetrics (no-op) if not provided.
     *                              Use LoggerMetrics for PSR-3 logging integration.
     * @param int $maxConcurrentTasks Maximum number of concurrent spawned tasks (0 = unlimited).
     *                                When limit is reached, spawn() throws ResourceLimitException.
     *                                Recommended: 100-1000 for typical applications.
     * @param float $maxTaskExecutionSeconds Maximum execution time per task in seconds (0 = unlimited).
     *                                        Prevents infinite loops and runaway tasks.
     *                                        Recommended: 30-300 seconds for typical tasks.
     *
     * Examples:
     * ```php
     * // Example 1: Basic scheduler with no limits
     * $scheduler = new FiberScheduler();
     * $task = $scheduler->spawn(fn() => performWork());
     * $result = $scheduler->all([$task]);
     *
     * // Example 2: Scheduler with metrics logging
     * $metrics = new LoggerMetrics($logger);
     * $scheduler = new FiberScheduler($metrics);
     * // Logs task.started, task.completed, fiber.suspended, fiber.resumed events
     *
     * // Example 3: Production scheduler with resource limits
     * $scheduler = new FiberScheduler(
     *     metrics: new LoggerMetrics($logger),
     *     maxConcurrentTasks: 500,           // Max 500 concurrent tasks
     *     maxTaskExecutionSeconds: 60.0      // Each task max 60 seconds
     * );
     *
     * // Example 4: Memory and FD limits from config
     * // In config/async.php:
     * // 'limits' => [
     * //     'max_memory_mb' => 512,              // 512 MB total memory limit
     * //     'max_open_file_descriptors' => 1000, // Max 1000 open streams
     * // ]
     * $scheduler = new FiberScheduler($metrics, 100, 30.0);
     * // Enforces constructor limits + config limits
     *
     * // Example 5: Handling resource limit exceptions
     * $scheduler = new FiberScheduler(maxConcurrentTasks: 10);
     * try {
     *     for ($i = 0; $i < 100; $i++) {
     *         $tasks[] = $scheduler->spawn(fn() => doWork($i));
     *     }
     * } catch (ResourceLimitException $e) {
     *     // Only 10 tasks can be spawned at once
     *     logger()->warning('Task limit reached', ['limit' => 10]);
     * }
     * ```
     *
     * @throws \Throwable If config loading fails (error is caught and defaults are used)
     */
    public function __construct(
        private ?Metrics $metrics = null,
        int $maxConcurrentTasks = 0,
        float $maxTaskExecutionSeconds = 0.0
    ) {
        $this->metrics = $this->metrics ?? new NullMetrics();
        $this->maxConcurrentTasks = max(0, $maxConcurrentTasks);
        $this->maxTaskExecutionSeconds = max(0.0, $maxTaskExecutionSeconds);
        $this->startTimes = new \SplObjectStorage();
        $this->ownedTasks = new \SplObjectStorage();

        // Optional resource limits from configuration
        try {
            $memMb = (int) (ConfigManager::get('async.limits.max_memory_mb', 0));
            $this->maxMemoryBytes = $memMb > 0 ? $memMb * 1024 * 1024 : 0;
            $this->maxOpenFileDescriptors = (int) (ConfigManager::get('async.limits.max_open_file_descriptors', 0));
        } catch (\Throwable) {
            // Config not available; keep defaults (no limit)
            $this->maxMemoryBytes = 0;
            $this->maxOpenFileDescriptors = 0;
        }
    }

    /**
     * Spawns a new fiber task from a callable for concurrent execution.
     *
     * The `spawn()` method creates a new FiberTask that wraps the callable and registers
     * it with the scheduler for concurrent execution. The task can cooperatively yield
     * control during I/O operations, sleeps, or when explicitly suspending, allowing
     * other tasks to make progress while waiting.
     *
     * Task execution model:
     * - Tasks don't execute immediately when spawned
     * - Execution begins when you call all(), race(), or the task's getResult()
     * - Tasks can suspend via sleep(), stream reads/writes, or Fiber::suspend()
     * - Scheduler multiplexes tasks using an event loop with stream_select()
     *
     * Resource limit enforcement:
     * - If maxConcurrentTasks is set, spawn() throws when limit is exceeded
     * - Limit is checked against currently-active spawned tasks (not all tasks)
     * - Completed tasks don't count toward the limit
     * - Use try-catch to handle ResourceLimitException
     *
     * Cancellation:
     * - Pass a CancellationToken to enable cooperative cancellation
     * - Task must check token periodically (token->isCancelled())
     * - Or use token->throwIfCancelled() to interrupt immediately
     * - Cancellation is cooperative - tasks must check the token
     *
     * @param callable $fn The function to execute as a task. Receives no parameters.
     *                     Can return any value, throw exceptions, or suspend cooperatively.
     *                     Typical signature: `fn(): mixed`
     * @param CancellationToken|null $token Optional cancellation token for cooperative cancellation.
     *                                      Pass SimpleCancellationToken to enable cancellation.
     *                                      Task can check $token->isCancelled() or $token->throwIfCancelled().
     * @return Task The created FiberTask instance. Does not start execution until
     *              the task is awaited via all(), race(), or getResult().
     * @throws ResourceLimitException If maxConcurrentTasks limit is exceeded
     *
     * Examples:
     * ```php
     * // Example 1: Basic task spawning
     * $scheduler = new FiberScheduler();
     * $task1 = $scheduler->spawn(fn() => performWork());
     * $task2 = $scheduler->spawn(fn() => performMoreWork());
     * [$result1, $result2] = $scheduler->all([$task1, $task2]);
     *
     * // Example 2: Task with cooperative I/O
     * $scheduler = new FiberScheduler();
     * $task = $scheduler->spawn(function() use ($stream) {
     *     // These operations suspend the fiber, allowing other tasks to run
     *     $data = $stream->read(1024);  // Suspends on read
     *     $stream->write("Response");   // Suspends on write
     *     return processData($data);
     * });
     * $result = $task->getResult();  // Runs event loop until task completes
     *
     * // Example 3: Task with sleep (cooperative delay)
     * $scheduler = new FiberScheduler();
     * $task = $scheduler->spawn(function() use ($scheduler) {
     *     echo "Starting...\n";
     *     $scheduler->sleep(2.0);  // Yields for 2 seconds
     *     echo "Completed!\n";
     *     return "done";
     * });
     * $result = $scheduler->all([$task]);
     *
     * // Example 4: Cancellable task
     * $scheduler = new FiberScheduler();
     * $token = new SimpleCancellationToken();
     * $task = $scheduler->spawn(function() use ($token) {
     *     $count = 0;
     *     while (!$token->isCancelled()) {
     *         $count++;
     *         performWork();
     *     }
     *     return $count;
     * }, $token);
     *
     * // Later, cancel the task
     * $token->cancel();
     * $result = $task->getResult();  // Returns early with partial count
     *
     * // Example 5: Handling resource limits
     * $scheduler = new FiberScheduler(maxConcurrentTasks: 5);
     * $tasks = [];
     * try {
     *     for ($i = 0; $i < 10; $i++) {
     *         $tasks[] = $scheduler->spawn(fn() => doWork($i));
     *     }
     * } catch (ResourceLimitException $e) {
     *     // Limit reached at 5 tasks
     *     logger()->warning('Too many concurrent tasks');
     * }
     *
     * // Example 6: Multiple spawned tasks with different lifetimes
     * $scheduler = new FiberScheduler();
     * $quickTask = $scheduler->spawn(fn() => "quick");
     * $slowTask = $scheduler->spawn(function() use ($scheduler) {
     *     $scheduler->sleep(5.0);
     *     return "slow";
     * });
     *
     * // Race them - quickTask wins
     * $result = $scheduler->race([$quickTask, $slowTask]);
     * echo $result;  // "quick"
     *
     * // Example 7: Task with async HTTP
     * $scheduler = new FiberScheduler();
     * $httpClient = new CurlMultiHttpClient();
     * $task = $scheduler->spawn(function() use ($httpClient) {
     *     $response = $httpClient->sendAsync($request)->getResult();
     *     return json_decode($response->getBody(), true);
     * });
     * $data = $task->getResult();
     * ```
     *
     * Best practices:
     * - Use spawn() for I/O-bound operations that benefit from concurrency
     * - Don't spawn CPU-bound tasks without yielding - they block other tasks
     * - Pass CancellationToken for long-running or user-initiated operations
     * - Set maxConcurrentTasks to prevent resource exhaustion
     * - Handle ResourceLimitException when spawning dynamically
     */
    public function spawn(callable $fn, ?CancellationToken $token = null): Task
    {
        if ($this->maxConcurrentTasks > 0 && $this->currentSpawned >= $this->maxConcurrentTasks) {
            throw new ResourceLimitException('Max concurrent tasks exceeded');
        }
        $task = new FiberTask($fn, $this->metrics, null, $token);
        $this->currentSpawned++;
        $this->ownedTasks->attach($task, true);
        return $task;
    }

    /**
     * Executes all tasks concurrently and waits for all to complete.
     *
     * This method runs all provided tasks concurrently, returning their results
     * in the same order as the input array. If any task throws an exception,
     * it will be propagated after all tasks complete.
     *
     * The scheduler uses an event loop to multiplex tasks:
     * 1. Ready tasks are executed step-by-step
     * 2. Tasks waiting on I/O or timers are tracked
     * 3. The scheduler blocks until at least one task becomes ready
     * 4. Process continues until all tasks complete
     *
     * @param array<int, Task> $tasks Array of tasks to execute concurrently
     * @return array<int, mixed> Results from all tasks, indexed by original keys
     */
    public function all(array $tasks): array
    {
        // Task execution queues and state tracking
        $ready = new \SplQueue();        // Tasks ready to execute next step
        $results = [];                   // Completed task results
        $pending = [];                   // Tasks still pending completion

        // Initialize: separate fiber tasks from already-completed tasks
        foreach ($tasks as $k => $t) {
            if ($t instanceof \Glueful\Async\Task\FiberTask) {
                $pending[$k] = $t;
                $ready->enqueue([$k, $t]);
            } else {
                // Non-fiber tasks are already completed
                $results[$k] = $t->getResult();
            }
        }

        // Waiting queues for suspended tasks
        $timers = new class extends \SplMinHeap
        {
            /**
             * @param mixed $a
             * @param mixed $b
             */
            protected function compare($a, $b): int
            {
                return $a[0] <=> $b[0];
            }
        };                    // Min-heap of [wakeAt, key, task, token]
        $readWaiters = [];              // Tasks waiting on read I/O
        $writeWaiters = [];             // Tasks waiting on write I/O

        // Main event loop: continue until all tasks complete
        while ($pending !== []) {
            // Check resource limits each loop iteration
            $this->enforceResourceLimits($readWaiters, $writeWaiters);

            if (!$ready->isEmpty()) {
                // Execute next ready task
                [$k, $task] = $ready->dequeue();
                if ($task instanceof \Glueful\Async\Task\FiberTask && !isset($this->startTimes[$task])) {
                    $this->startTimes[$task] = microtime(true);
                }
                $suspend = $task->step();

                // Check if task completed during this step
                if ($task->isCompleted()) {
                    $results[$k] = $task->getResult();
                    if ($task instanceof \Glueful\Async\Task\FiberTask) {
                        unset($this->startTimes[$task]);
                        if (isset($this->ownedTasks[$task])) {
                            $this->currentSpawned = max(0, $this->currentSpawned - 1);
                            $this->ownedTasks->detach($task);
                        }
                    }
                    unset($pending[$k]);
                    continue;
                }

                // Enforce per-task execution time limit
                if ($this->maxTaskExecutionSeconds > 0 && $task instanceof \Glueful\Async\Task\FiberTask) {
                    $startedAt = $this->startTimes[$task] ?? microtime(true);
                    if ((microtime(true) - $startedAt) > $this->maxTaskExecutionSeconds) {
                        throw new TimeoutException('Task execution time limit exceeded');
                    }
                }

                // Task suspended - classify the suspension reason
                if ($suspend instanceof \Glueful\Async\Internal\SleepOp) {
                    // Task is sleeping until a specific time
                    $this->metrics->fiberSuspended(get_class($task), 'sleep');
                    $timers->insert([$suspend->wakeAt, $k, $task, $suspend->token]);
                } elseif ($suspend instanceof \Glueful\Async\Internal\ReadOp) {
                    // Task is waiting for stream to become readable
                    $this->metrics->fiberSuspended(get_class($task), 'read');
                    $readWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                } elseif ($suspend instanceof \Glueful\Async\Internal\WriteOp) {
                    // Task is waiting for stream to become writable
                    $this->metrics->fiberSuspended(get_class($task), 'write');
                    $writeWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                } else {
                    // Unknown suspension type - re-queue immediately
                    $ready->enqueue([$k, $task]);
                }
            } else {
                // No ready tasks - wait for I/O readiness or timer expiration
                $this->metrics->queueDepth(
                    $ready->count(),
                    count($readWaiters) + count($writeWaiters),
                    $timers->count()
                );
                // Also enforce resource limits before blocking
                $this->enforceResourceLimits($readWaiters, $writeWaiters);
                $this->waitForIoOrTimers($ready, $timers, $readWaiters, $writeWaiters);

                // If no tasks were enqueued and no waiters remain, break the loop
                if ($ready->isEmpty() && $timers->count() === 0 && $readWaiters === [] && $writeWaiters === []) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Enforce configured memory and file descriptor limits.
     * Emits metrics when limits are exceeded and throws ResourceLimitException.
     *
     * @param array<int, array{0: resource, 1: mixed, 2: FiberTask, 3: ?float, 4: ?CancellationToken}> $readWaiters
     * @param array<int, array{0: resource, 1: mixed, 2: FiberTask, 3: ?float, 4: ?CancellationToken}> $writeWaiters
     */
    private function enforceResourceLimits(array $readWaiters, array $writeWaiters): void
    {
        // Memory limit
        if ($this->maxMemoryBytes > 0) {
            $currentBytes = @memory_get_usage(true);
            if ($currentBytes >= $this->maxMemoryBytes) {
                $this->metrics->resourceLimit(
                    'memory',
                    (int) floor($currentBytes / (1024 * 1024)),
                    (int) floor($this->maxMemoryBytes / (1024 * 1024))
                );
                throw new ResourceLimitException('Async memory limit exceeded');
            }
        }

        // File descriptor limit (best-effort)
        if ($this->maxOpenFileDescriptors > 0) {
            $fdCount = null;
            if (function_exists('get_resources')) {
                try {
                    $fdCount = count(@get_resources('stream'));
                } catch (\Throwable) {
                    $fdCount = null;
                }
            }
            // Fallback: approximate using active I/O waiters
            if ($fdCount === null) {
                $fdCount = count($readWaiters) + count($writeWaiters);
            }
            if ($fdCount >= $this->maxOpenFileDescriptors) {
                $this->metrics->resourceLimit('fds', $fdCount, $this->maxOpenFileDescriptors);
                throw new ResourceLimitException('Async file descriptor limit exceeded');
            }
        }
    }

    /**
     * Executes tasks concurrently, returning the first successful result.
     *
     * This method runs all tasks concurrently and returns as soon as any task
     * completes successfully. If all tasks fail, the first encountered error
     * is thrown. This implements "race" semantics - the fastest task wins.
     *
     * Behavior:
     * - Returns immediately when the first task succeeds
     * - Continues running if tasks fail, tracking the first error
     * - Throws the first error if all tasks fail
     * - Returns null if all tasks complete without success or error
     *
     * @param array<int, Task> $tasks Array of tasks to race against each other
     * @return mixed The result from the first successfully completed task
     * @throws \Throwable If all tasks fail, throws the first encountered error
     */
    public function race(array $tasks): mixed
    {
        // Task execution queues and state tracking
        $ready = new \SplQueue();        // Tasks ready to execute next step
        $pending = [];                   // Tasks still pending completion
        $timers = new class extends \SplMinHeap
        {
            /**
             * @param mixed $a
             * @param mixed $b
             */
            protected function compare($a, $b): int
            {
                return $a[0] <=> $b[0];
            }
        };
        $readWaiters = [];              // Tasks waiting on read I/O
        $writeWaiters = [];             // Tasks waiting on write I/O
        $firstError = null;             // Track first error encountered

        // Initialize: check for already-completed tasks
        foreach ($tasks as $k => $t) {
            if ($t instanceof \Glueful\Async\Task\FiberTask) {
                $pending[$k] = $t;
                $ready->enqueue([$k, $t]);
            } else {
                // Non-fiber task - attempt to get result immediately
                try {
                    return $t->getResult();
                } catch (\Throwable $e) {
                    // Task failed - track error but continue racing
                    $firstError ??= $e;
                }
            }
        }

        // Main event loop: continue until first task succeeds or all fail
        while ($pending !== []) {
            if (!$ready->isEmpty()) {
                // Execute next ready task
                [$k, $task] = $ready->dequeue();
                try {
                    if ($task instanceof \Glueful\Async\Task\FiberTask && !isset($this->startTimes[$task])) {
                        $this->startTimes[$task] = microtime(true);
                    }
                    $suspend = $task->step();

                    // Check if task completed - if so, return immediately (race winner!)
                    if ($task->isCompleted()) {
                        if ($task instanceof \Glueful\Async\Task\FiberTask) {
                            unset($this->startTimes[$task]);
                            if (isset($this->ownedTasks[$task])) {
                                $this->currentSpawned = max(0, $this->currentSpawned - 1);
                                $this->ownedTasks->detach($task);
                            }
                        }
                        return $task->getResult();
                    }

                    // Task suspended - classify the suspension reason
                    if ($suspend instanceof \Glueful\Async\Internal\SleepOp) {
                        // Task is sleeping until a specific time
                        $this->metrics->fiberSuspended(get_class($task), 'sleep');
                        $timers->insert([$suspend->wakeAt, $k, $task, $suspend->token]);
                    } elseif ($suspend instanceof \Glueful\Async\Internal\ReadOp) {
                        // Task is waiting for stream to become readable
                        $this->metrics->fiberSuspended(get_class($task), 'read');
                        $readWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                    } elseif ($suspend instanceof \Glueful\Async\Internal\WriteOp) {
                        // Task is waiting for stream to become writable
                        $this->metrics->fiberSuspended(get_class($task), 'write');
                        $writeWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                    } else {
                        // Unknown suspension type - re-queue immediately
                        $ready->enqueue([$k, $task]);
                    }
                } catch (\Throwable $e) {
                    // Task failed - track error and remove from pending
                    $firstError ??= $e;
                    if ($task instanceof \Glueful\Async\Task\FiberTask) {
                        unset($this->startTimes[$task]);
                        if (isset($this->ownedTasks[$task])) {
                            $this->currentSpawned = max(0, $this->currentSpawned - 1);
                            $this->ownedTasks->detach($task);
                        }
                    }
                    unset($pending[$k]);
                }
            } else {
                // No ready tasks - wait for I/O readiness or timer expiration
                $this->waitForIoOrTimers($ready, $timers, $readWaiters, $writeWaiters);

                // If no tasks were enqueued and no waiters remain, break the loop
                if ($ready->isEmpty() && $timers->count() === 0 && $readWaiters === [] && $writeWaiters === []) {
                    break;
                }
            }
        }

        // All tasks completed without a winner - throw first error if any
        if ($firstError !== null) {
            throw $firstError;
        }
        return null;
    }

    /**
     * Suspends the current task for a specified duration.
     *
     * If called from within a fiber, this cooperatively yields control back to
     * the scheduler for the specified duration. If called outside a fiber, it
     * falls back to blocking sleep using usleep().
     *
     * @param float $seconds Number of seconds to sleep (can be fractional)
     * @param CancellationToken|null $token Optional cancellation token to interrupt sleep
     * @return void
     * @throws \Exception If the cancellation token is triggered
     */
    public function sleep(float $seconds, ?CancellationToken $token = null): void
    {
        // Check for cancellation before sleeping
        if ($token !== null && $token->isCancelled()) {
            $token->throwIfCancelled();
        }

        $current = \Fiber::getCurrent();
        if ($current !== null) {
            // Inside fiber - cooperatively suspend with timer
            $wakeAt = microtime(true) + max(0.0, $seconds);
            \Fiber::suspend(new \Glueful\Async\Internal\SleepOp($wakeAt, $token));
            return;
        }

        // Outside fiber - fallback to blocking sleep
        usleep((int) max(0, $seconds * 1_000_000));
    }

    /**
     * Waits for I/O readiness or timers to expire, then enqueues ready tasks.
     *
     * This is the core waiting logic shared by both all() and race() methods.
     * It uses stream_select() to efficiently wait for multiple I/O operations
     * and timers simultaneously, then updates the task queues accordingly.
     *
     * The method modifies the passed-by-reference arrays to remove tasks that
     * have been enqueued to the ready queue.
     *
     * @param \SplQueue<array{0: mixed, 1: FiberTask}> $ready
     * @param \SplMinHeap<array{0: float, 1: mixed, 2: FiberTask, 3: ?CancellationToken}> $timers
     *                     Min-heap entries: [wakeAt, key, task, token]
     * @param array<int, array{0: resource, 1: mixed, 2: FiberTask, 3: ?float, 4: ?CancellationToken}> &$readWaiters
     * @param array<int, array{0: resource, 1: mixed, 2: FiberTask, 3: ?float, 4: ?CancellationToken}> &$writeWaiters
     * @return void
     */
    private function waitForIoOrTimers(
        \SplQueue $ready,
        \SplMinHeap $timers,
        array &$readWaiters,
        array &$writeWaiters
    ): void {
        // Step 1: Compute the earliest deadline from all pending operations
        // This determines how long we can block in stream_select()
        $nextAt = null;

        // Check timers for earliest wake time
        if (!$timers->isEmpty()) {
            $top = $timers->top();
            $nextAt = $top[0];
        }

        // Check read operations for earliest deadline
        foreach ($readWaiters as $rw) {
            $d = $rw[3];  // Deadline is at index 3
            if ($d !== null) {
                $nextAt = $nextAt === null ? $d : min($nextAt, $d);
            }
        }

        // Check write operations for earliest deadline
        foreach ($writeWaiters as $ww) {
            $d = $ww[3];  // Deadline is at index 3
            if ($d !== null) {
                $nextAt = $nextAt === null ? $d : min($nextAt, $d);
            }
        }

        // Step 2: Convert the deadline to timeout values for stream_select()
        $timeoutSec = null;
        $timeoutUsec = null;
        if ($nextAt !== null) {
            $now = microtime(true);
            $delta = max(0.0, $nextAt - $now);
            $timeoutSec = (int) floor($delta);
            $timeoutUsec = (int) (($delta - $timeoutSec) * 1_000_000);
        }

        // Step 3: Extract stream resources for stream_select()
        $r = array_map(fn($e) => $e[0], $readWaiters);   // Streams waiting for read
        $w = array_map(fn($e) => $e[0], $writeWaiters);  // Streams waiting for write
        $e = null;                                        // Exception streams (unused)

        // Step 4: Wait for I/O readiness or timeout
        if ($r === [] && $w === []) {
            // No I/O operations - just sleep until next timer
            if ($nextAt === null) {
                // No timers or I/O - nothing to wait for
                return;
            }
            $sleepMicros = (($timeoutSec ?? 0) * 1_000_000) + ($timeoutUsec ?? 0);
            if ($sleepMicros > 0) {
                usleep($sleepMicros);
            }
        } else {
            // Wait for I/O readiness with timeout
            @stream_select($r, $w, $e, $timeoutSec, $timeoutUsec);
        }

        // Step 5: Process results and enqueue ready tasks
        $now = microtime(true);

        // Process read and write waiters using the helper method
        $this->processWaiters($readWaiters, $r, $now, $ready);
        $this->processWaiters($writeWaiters, $w, $now, $ready);

        // Process timers - enqueue tasks whose scheduled wake time has arrived
        while (!$timers->isEmpty()) {
            $top = $timers->top();
            if ($now < $top[0]) {
                break;
            }
            [$wakeAt, $k3, $t3, $tok3] = $timers->extract();
            $this->metrics->fiberResumed(get_class($t3), max(0.0, ($now - $wakeAt)) * 1000.0);
            $ready->enqueue([$k3, $t3]);
        }
    }

    /**
     * Processes I/O waiters and enqueues ready or timed-out tasks.
     *
     * This helper method eliminates duplication between read and write waiter processing.
     * It checks each waiting task to see if its stream is ready, has timed out, or has
     * been cancelled. Ready tasks are enqueued, while still-waiting tasks remain in the queue.
     *
     * @param array<int, array{0: resource, 1: mixed, 2: FiberTask, 3: ?float, 4: ?CancellationToken}> &$waiters
     * @param array<resource> $readyStreams Streams that are ready (from stream_select)
     * @param float $now Current timestamp
     * @param \SplQueue<array{0: mixed, 1: FiberTask}> $ready Queue to enqueue ready tasks
     * @return void
     */
    private function processWaiters(array &$waiters, array $readyStreams, float $now, \SplQueue $ready): void
    {
        $remaining = [];
        foreach ($waiters as $entry) {
            [$stream, $k, $task, $deadline, $token] = $entry;

            // Determine if this task should be enqueued
            $isReady = in_array($stream, $readyStreams, true);
            $isTimeout = ($deadline !== null && $now >= $deadline);
            $isCancelled = ($token !== null && $token->isCancelled());

            // Handle cancellation token if present
            if ($isCancelled) {
                try {
                    $token->throwIfCancelled();
                } catch (\Throwable $ex) {
                    // Ignore cancellation exceptions during polling
                }
            }

            if ($isReady || $isTimeout || $isCancelled) {
                // Stream is ready, timed out, or cancelled - enqueue for execution
                $this->metrics->fiberResumed(get_class($task), 0.0);
                if ($isCancelled) {
                    $this->metrics->taskCancelled(get_class($task), 'token');
                }
                $ready->enqueue([$k, $task]);
            } else {
                // Still waiting - keep in the waiter queue
                $remaining[] = $entry;
            }
        }
        $waiters = $remaining;
    }
}
