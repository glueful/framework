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
    /** @var \SplObjectStorage<\Glueful\Async\Task\FiberTask, float> */
    private \SplObjectStorage $startTimes;
    /** @var \SplObjectStorage<\Glueful\Async\Task\FiberTask, bool> */
    private \SplObjectStorage $ownedTasks;
    /**
     * Creates a new fiber scheduler.
     *
     * @param Metrics|null $metrics Optional metrics collector for performance monitoring.
     *                              Defaults to NullMetrics if not provided.
     * @param int $maxConcurrentTasks Maximum number of concurrent tasks (0 = unlimited)
     * @param float $maxTaskExecutionSeconds Maximum execution time per task (0 = unlimited)
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
    }

    /**
     * Spawns a new task from a callable.
     *
     * The callable will be executed as a fiber task, allowing it to cooperatively
     * yield control back to the scheduler when waiting for I/O or timers
     *
     * @param callable $fn The function to execute as a task
     * @param CancellationToken|null $token Optional cancellation token for the task
     * @return Task The created task instance
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
        $timers = [];                    // Tasks waiting on sleep/timers
        $readWaiters = [];              // Tasks waiting on read I/O
        $writeWaiters = [];             // Tasks waiting on write I/O

        // Main event loop: continue until all tasks complete
        while ($pending !== []) {
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
                    $timers[] = [$suspend->wakeAt, $k, $task, $suspend->token];
                } elseif ($suspend instanceof \Glueful\Async\Internal\ReadOp) {
                    // Task is waiting for stream to become readable
                    $readWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                } elseif ($suspend instanceof \Glueful\Async\Internal\WriteOp) {
                    // Task is waiting for stream to become writable
                    $writeWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                } else {
                    // Unknown suspension type - re-queue immediately
                    $ready->enqueue([$k, $task]);
                }
            } else {
                // No ready tasks - wait for I/O readiness or timer expiration
                $this->waitForIoOrTimers($ready, $timers, $readWaiters, $writeWaiters);

                // If no tasks were enqueued and no waiters remain, break the loop
                if ($ready->isEmpty() && $timers === [] && $readWaiters === [] && $writeWaiters === []) {
                    break;
                }
            }
        }

        return $results;
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
        $timers = [];                    // Tasks waiting on sleep/timers
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
                        $timers[] = [$suspend->wakeAt, $k, $task, $suspend->token];
                    } elseif ($suspend instanceof \Glueful\Async\Internal\ReadOp) {
                        // Task is waiting for stream to become readable
                        $readWaiters[] = [$suspend->stream, $k, $task, $suspend->deadline, $suspend->token];
                    } elseif ($suspend instanceof \Glueful\Async\Internal\WriteOp) {
                        // Task is waiting for stream to become writable
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
                if ($ready->isEmpty() && $timers === [] && $readWaiters === [] && $writeWaiters === []) {
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
     * @param array<int, array{0: float, 1: mixed, 2: FiberTask, 3: ?CancellationToken}> &$timers
     * @param array<int, array{0: resource, 1: mixed, 2: FiberTask, 3: ?float, 4: ?CancellationToken}> &$readWaiters
     * @param array<int, array{0: resource, 1: mixed, 2: FiberTask, 3: ?float, 4: ?CancellationToken}> &$writeWaiters
     * @return void
     */
    private function waitForIoOrTimers(
        \SplQueue $ready,
        array &$timers,
        array &$readWaiters,
        array &$writeWaiters
    ): void {
        // Step 1: Compute the earliest deadline from all pending operations
        // This determines how long we can block in stream_select()
        $nextAt = null;

        // Check timers for earliest wake time
        if ($timers !== []) {
            usort($timers, static fn($a, $b) => $a[0] <=> $b[0]);
            $nextAt = $timers[0][0];
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
        // or whose cancellation has been requested (stronger cancellation semantics)
        $newTimers = [];
        foreach ($timers as $tm) {
            [$wakeAt, $k3, $t3, $tok3] = $tm;

            // If cancelled, wake immediately to allow the task to handle cancellation
            if ($tok3 !== null && $tok3->isCancelled()) {
                $ready->enqueue([$k3, $t3]);
                continue;
            }

            if ($now >= $wakeAt) {
                // Timer has expired - enqueue task for execution
                $ready->enqueue([$k3, $t3]);
            } else {
                // Timer still pending - keep in the timer queue
                $newTimers[] = $tm;
            }
        }
        $timers = $newTimers;
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
                $ready->enqueue([$k, $task]);
            } else {
                // Still waiting - keep in the waiter queue
                $remaining[] = $entry;
            }
        }
        $waiters = $remaining;
    }
}
