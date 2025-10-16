<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;

/**
 * Simple synchronous task that executes a closure without fibers.
 *
 * ClosureTask provides a non-fiber task implementation for simple synchronous
 * operations. Unlike FiberTask, it executes the callable immediately and completely
 * when getResult() is called, without any cooperative suspension.
 *
 * Use cases:
 * - Simple synchronous operations that don't need cooperative multitasking
 * - CPU-bound tasks without I/O operations
 * - Wrapping existing blocking code in the Task interface
 * - Testing or simple async patterns
 *
 * Key differences from FiberTask:
 * - No cooperative suspension (no sleep, I/O operations)
 * - Executes synchronously and blocks until complete
 * - Lower overhead (no fiber creation)
 * - Cannot be scheduled step-by-step
 *
 * Example:
 * ```php
 * $task = new ClosureTask(fn() => heavyComputation());
 * $result = $task->getResult(); // Blocks until computation completes
 * ```
 */
final class ClosureTask implements Task
{
    /** @var \Closure The callable to execute */
    private \Closure $closure;

    /** @var bool Whether the task has been executed */
    private bool $executed = false;

    /** @var mixed The cached result after execution */
    private mixed $result = null;

    /** @var \Throwable|null Any error that occurred during execution */
    private ?\Throwable $error = null;

    /** @var Metrics Metrics collector for performance monitoring */
    private Metrics $metrics;

    /** @var string|null Optional task name for metrics */
    private ?string $name;

    /**
     * Creates a new synchronous closure task.
     *
     * @param callable $fn The function to execute
     * @param Metrics|null $metrics Optional metrics collector
     * @param string|null $name Optional task name for metrics
     */
    public function __construct(callable $fn, ?Metrics $metrics = null, ?string $name = null)
    {
        $this->closure = \Closure::fromCallable($fn);
        $this->metrics = $metrics ?? new NullMetrics();
        $this->name = $name;
    }

    /**
     * Checks if the task is currently running.
     *
     * For ClosureTask, this returns true only during execution since
     * the task runs synchronously without yielding.
     *
     * @return bool True if not yet executed, false otherwise
     */
    public function isRunning(): bool
    {
        return !$this->executed;
    }

    /**
     * Checks if the task has completed execution.
     *
     * @return bool True if executed (successfully or with error), false otherwise
     */
    public function isCompleted(): bool
    {
        return $this->executed;
    }

    /**
     * Executes the closure and returns the result.
     *
     * On first call, this method:
     * 1. Records task start metrics
     * 2. Executes the closure synchronously (blocking)
     * 3. Caches the result or error
     * 4. Records completion or failure metrics
     *
     * Subsequent calls return the cached result immediately.
     *
     * Note: This method blocks until the closure completes. For long-running
     * or I/O-bound operations, consider using FiberTask with a scheduler instead.
     *
     * @return mixed The closure's return value
     * @throws \Throwable Any exception thrown by the closure
     */
    public function getResult(): mixed
    {
        // Execute closure on first call
        if (!$this->executed) {
            $taskName = $this->name ?? $this->inferName();
            $this->metrics->taskStarted($taskName);
            try {
                // Execute synchronously - this blocks until complete
                $this->result = ($this->closure)();
                $this->metrics->taskCompleted($taskName);
            } catch (\Throwable $e) {
                // Cache error for re-throwing on subsequent calls
                $this->error = $e;
                $this->metrics->taskFailed($taskName, $e);
            } finally {
                $this->executed = true;
            }
        }

        // Re-throw cached error if execution failed
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->result;
    }

    /**
     * Cancellation is not supported for synchronous tasks.
     *
     * ClosureTask executes synchronously and cannot be cancelled once started.
     * This method is a no-op to satisfy the Task interface.
     *
     * @return void
     */
    public function cancel(): void
    {
        // No-op: Synchronous execution cannot be cancelled
    }

    /**
     * Infers a task name from the closure's source location.
     *
     * Uses reflection to determine the file and line number where the
     * closure was defined, providing useful identification in metrics.
     *
     * @return string The inferred task name (e.g., "closure@MyFile.php:42")
     */
    private function inferName(): string
    {
        $ref = new \ReflectionFunction($this->closure);
        if ($ref->isClosure()) {
            $fileName = $ref->getFileName();
            $file = basename($fileName !== false ? $fileName : 'closure');
            $line = $ref->getStartLine();
            return 'closure@' . $file . ':' . $line;
        }
        return 'task';
    }
}
