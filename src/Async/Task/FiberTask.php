<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\CancellationToken;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;
use Glueful\Async\Internal\SleepOp;

/**
 * Fiber-based task implementation.
 *
 * FiberTask wraps a callable in a PHP fiber, allowing it to cooperatively suspend
 * and resume execution. Tasks can be run standalone via getResult() or managed by
 * a scheduler for concurrent execution.
 *
 * Usage patterns:
 * - Standalone: $result = $task->getResult() - drives task to completion
 * - Scheduled: $scheduler->all([$task1, $task2]) - concurrent execution
 * - Step-by-step: $scheduler calls step() repeatedly for cooperative multitasking
 *
 * Suspension operations:
 * - SleepOp: Cooperative sleep with optional cancellation
 * - ReadOp/WriteOp: I/O operations (require scheduler)
 */
final class FiberTask implements Task
{
    private \Closure $closure;

    /** @var \Fiber<mixed, mixed, mixed, mixed>|null */
    private ?\Fiber $fiber = null;
    private bool $executed = false;
    private mixed $result = null;
    private ?\Throwable $error = null;
    private Metrics $metrics;
    private ?string $name;
    private ?CancellationToken $token = null;

    public function __construct(
        callable $fn,
        ?Metrics $metrics = null,
        ?string $name = null,
        ?CancellationToken $token = null
    ) {
        $this->closure = \Closure::fromCallable($fn);
        $this->metrics = $metrics ?? new NullMetrics();
        $this->name = $name;
        $this->token = $token;
    }

    public function isRunning(): bool
    {
        if ($this->executed) {
            return false;
        }
        return $this->fiber !== null && !$this->fiber->isTerminated();
    }

    public function isCompleted(): bool
    {
        return $this->executed;
    }

    /**
     * Gets the result of the task, driving it to completion if necessary.
     *
     * This method can be used for simple await($task) use cases when a task
     * is not being managed by an external scheduler. It runs a standalone loop
     * that drives the fiber to completion, handling suspension operations.
     *
     * Supported operations:
     * - SleepOp: Blocks using usleep() for the specified duration
     * - Cancellation: Checked early and consistently for all operations
     *
     * Unsupported operations (require scheduler):
     * - ReadOp: Throws RuntimeException - requires I/O multiplexing
     * - WriteOp: Throws RuntimeException - requires I/O multiplexing
     *
     * For concurrent tasks or I/O operations, use FiberScheduler->all() or race()
     * instead of calling getResult() directly.
     *
     * @return mixed The task result
     * @throws \Throwable Any exception thrown by the task or cancellation
     * @throws \RuntimeException If I/O operations are used without a scheduler
     */
    public function getResult(): mixed
    {
        // Return cached result if already executed
        if ($this->executed) {
            if ($this->error !== null) {
                throw $this->error;
            }
            return $this->result;
        }

        // Drive task to completion using standalone loop
        $taskName = $this->name ?? $this->inferName();
        $started = false;

        try {
            /** @phpstan-ignore-next-line */
            while (!$this->executed) {
                // Respect cooperative cancellation before each step
                $this->token?->throwIfCancelled();
                // Start fiber on first iteration
                if ($this->fiber === null) {
                    $this->metrics->taskStarted($taskName);
                    $started = true;
                    $fn = $this->closure;
                    $this->fiber = new \Fiber(static function () use ($fn) {
                        return $fn();
                    });
                    $suspend = $this->fiber->start();
                } else {
                    // Resume fiber from last suspension point
                    $suspend = $this->fiber->resume(null);
                }

                // Check if fiber completed
                if ($this->fiber->isTerminated()) {
                    $this->result = $this->fiber->getReturn();
                    $this->executed = true;
                    break;
                }

                // Handle suspension (cancellation checked first, then sleep/I/O)
                $this->handleSuspension($suspend);
            }

            // Record successful completion
            if ($started) {
                $this->metrics->taskCompleted($taskName);
            }
        } catch (\Throwable $e) {
            // Record error and mark as executed
            $this->error = $e;
            if ($started) {
                $this->metrics->taskFailed($taskName, $e);
            }
            $this->executed = true;
        }

        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->result;
    }

    /**
     * Executes one step of the task and returns the suspension operation.
     *
     * This method is used by schedulers to cooperatively execute tasks step-by-step.
     * Each call either starts the fiber (first call) or resumes it from its last
     * suspension point.
     *
     * The scheduler uses the returned suspension operation to determine how to
     * wait for the task:
     * - SleepOp: Add to timer queue
     * - ReadOp: Wait for stream to become readable
     * - WriteOp: Wait for stream to become writable
     * - null: Task completed
     *
     * Note: Unlike getResult(), this method does NOT handle suspension operations.
     * The caller (scheduler) is responsible for managing I/O, timers, and cancellation.
     *
     * @return mixed The suspension operation, or null if task is completed
     */
    public function step(): mixed
    {
        if ($this->executed) {
            return null;
        }
        try {
            // Respect cooperative cancellation before running/resuming
            $this->token?->throwIfCancelled();
            if ($this->fiber === null) {
                $this->metrics->taskStarted($this->name ?? $this->inferName());
                $fn = $this->closure;
                $this->fiber = new \Fiber(static function () use ($fn) {
                    return $fn();
                });
                $suspend = $this->fiber->start();
            } else {
                $suspend = $this->fiber->resume(null);
            }
            if ($this->fiber->isTerminated()) {
                $this->result = $this->fiber->getReturn();
                $this->metrics->taskCompleted($this->name ?? $this->inferName());
                $this->executed = true;
                return null;
            }
            return $suspend;
        } catch (\Throwable $e) {
            $this->error = $e;
            $this->metrics->taskFailed($this->name ?? $this->inferName(), $e);
            $this->executed = true;
            return null;
        }
    }

    public function cancel(): void
    {
        // Signal cooperative cancellation if a token is associated
        $this->metrics->taskCancelled($this->name ?? $this->inferName(), 'manual');
        $this->token?->cancel();
    }

    private function inferName(): string
    {
        $ref = new \ReflectionFunction($this->closure);
        if ($ref->isClosure()) {
            $fileName = $ref->getFileName();
            $file = basename($fileName !== false ? $fileName : 'closure');
            $line = $ref->getStartLine();
            return 'fiber@' . $file . ':' . $line;
        }
        return 'fiber-task';
    }

    /**
     * Checks if the suspension operation has been cancelled.
     *
     * This method provides a centralized, visible cancellation check for all
     * suspension operations that support cancellation tokens.
     *
     * @param mixed $suspend The suspension operation to check
     * @return void
     * @throws \Exception If the operation has been cancelled
     */
    private function checkCancellation(mixed $suspend): void
    {
        // Check all suspension types that support cancellation
        if (
            $suspend instanceof SleepOp ||
            $suspend instanceof \Glueful\Async\Internal\ReadOp ||
            $suspend instanceof \Glueful\Async\Internal\WriteOp
        ) {
            if ($suspend->token !== null && $suspend->token->isCancelled()) {
                $suspend->token->throwIfCancelled();
            }
        }
    }

    /**
     * Handles a sleep operation by blocking for the specified duration.
     *
     * @param SleepOp $op The sleep operation to handle
     * @return void
     */
    private function handleSleep(SleepOp $op): void
    {
        $now = microtime(true);
        $timeout = max(0.0, $op->wakeAt - $now);
        if ($timeout > 0) {
            usleep((int) ($timeout * 1_000_000));
        }
    }

    /**
     * Centralized handler for all suspension operations.
     *
     * This method provides clear, consistent handling of all suspension types:
     * 1. Checks for cancellation first (visible and early)
     * 2. Routes to appropriate handler based on operation type
     * 3. Provides helpful error messages for unsupported operations
     *
     * @param mixed $suspend The suspension operation to handle
     * @return void
     * @throws \RuntimeException If I/O operations are used without a scheduler
     * @throws \Exception If the operation has been cancelled
     */
    private function handleSuspension(mixed $suspend): void
    {
        // Early cancellation check - highly visible and consistent across all operation types
        $this->checkCancellation($suspend);

        // Route to appropriate handler based on suspension type
        if ($suspend instanceof SleepOp) {
            $this->handleSleep($suspend);
        } elseif ($suspend instanceof \Glueful\Async\Internal\ReadOp) {
            throw new \RuntimeException(
                'ReadOp requires a scheduler for I/O multiplexing. ' .
                'Use await() with FiberScheduler->all() or race() instead of calling getResult() directly.'
            );
        } elseif ($suspend instanceof \Glueful\Async\Internal\WriteOp) {
            throw new \RuntimeException(
                'WriteOp requires a scheduler for I/O multiplexing. ' .
                'Use await() with FiberScheduler->all() or race() instead of calling getResult() directly.'
            );
        }
        // Unknown suspend types fall through and are ignored (will resume immediately)
    }
}
