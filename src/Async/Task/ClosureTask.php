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
 * Key characteristics:
 * - **Synchronous execution**: Blocks until completion (no yielding)
 * - **Lower overhead**: No fiber allocation or context switching
 * - **One-time execution**: Callable runs once, result is cached
 * - **Metrics support**: Optional instrumentation for performance monitoring
 * - **Task interface**: Provides Task compatibility for polymorphic usage
 * - **Exception caching**: Errors are caught and re-thrown on subsequent calls
 *
 * Key differences from FiberTask:
 * - **No cooperative suspension**: Cannot sleep, do async I/O, or yield
 * - **Synchronous blocking**: Executes completely before returning
 * - **Lower overhead**: No fiber creation or scheduling overhead
 * - **No step-by-step scheduling**: Cannot be paused and resumed
 * - **Immediate execution**: Runs on first getResult() call
 *
 * When to use ClosureTask:
 * - **CPU-bound operations**: Pure computation without I/O
 * - **Quick synchronous work**: Operations that complete in microseconds
 * - **No async dependencies**: Code that doesn't call async functions
 * - **Existing sync code**: Wrapping legacy blocking code in Task interface
 * - **Lower overhead needed**: When fiber overhead is unacceptable
 *
 * When to use FiberTask instead:
 * - **I/O operations**: Database queries, HTTP requests, file I/O
 * - **Long-running work**: Operations that take seconds or more
 * - **Cooperative concurrency**: When you want to yield to other tasks
 * - **Async dependencies**: Calling other async functions or tasks
 *
 * Performance considerations:
 * - **Overhead**: ~100 bytes (vs ~2KB for FiberTask with fiber)
 * - **Execution**: Direct function call (vs fiber context switch)
 * - **Metrics**: Optional instrumentation adds minimal overhead
 * - **Caching**: Result cached after first execution
 *
 * Usage examples:
 * ```php
 * // Basic synchronous computation
 * $task = new ClosureTask(fn() => md5(file_get_contents('data.txt')));
 * $hash = $task->getResult();  // Blocks until complete
 *
 * // CPU-bound calculation
 * $task = new ClosureTask(function() {
 *     $result = 0;
 *     for ($i = 0; $i < 1000000; $i++) {
 *         $result += $i;
 *     }
 *     return $result;
 * });
 *
 * // With metrics (for profiling)
 * $metrics = new LoggerMetrics($logger);
 * $task = new ClosureTask(
 *     fn() => expensiveComputation(),
 *     $metrics,
 *     'expensive-computation'
 * );
 * $result = $task->getResult();
 * // Logs: "async.task.started" and "async.task.completed"
 *
 * // Result caching behavior
 * $task = new ClosureTask(fn() => rand());
 * $first = $task->getResult();   // Executes callable, e.g., returns 42
 * $second = $task->getResult();  // Returns cached value: 42
 * assert($first === $second);    // Always true
 *
 * // Error handling
 * $task = new ClosureTask(fn() => throw new \Exception('error'));
 * try {
 *     $task->getResult();  // Throws on first call
 * } catch (\Exception $e) {
 *     echo $e->getMessage();  // "error"
 * }
 * // Exception is cached and re-thrown on subsequent calls
 * try {
 *     $task->getResult();  // Throws same exception again
 * } catch (\Exception $e) {
 *     assert($e->getMessage() === 'error');
 * }
 *
 * // Wrapping existing sync code in Task interface
 * class LegacyService {
 *     public function processAsync(array $data): Task {
 *         return new ClosureTask(fn() => $this->processSync($data));
 *     }
 * }
 * ```
 *
 * NOT suitable for:
 * ```php
 * // BAD: Async I/O (use FiberTask instead)
 * $bad = new ClosureTask(fn() => $asyncStream->read(1024));
 * // This will fail - read() expects to be in a fiber!
 *
 * // BAD: Long-running work without yielding
 * $bad = new ClosureTask(function() {
 *     while (true) {  // Infinite loop blocks forever
 *         doWork();
 *     }
 * });
 * // This blocks the entire process!
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
