<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;

/**
 * A task that wraps an already-completed value.
 *
 * CompletedTask provides a Task wrapper for values that are already available,
 * allowing them to be used in async/await patterns without any execution overhead.
 * This is useful for implementing async interfaces when values are computed synchronously
 * or are already cached.
 *
 * Use cases:
 * - Returning cached/memoized values through async APIs
 * - Testing async code with pre-computed values
 * - Mixing synchronous and asynchronous code paths
 * - Short-circuiting async operations when result is already known
 *
 * Example:
 * ```php
 * // Return cached value in async API
 * if ($cached = $cache->get($key)) {
 *     return new CompletedTask($cached);
 * }
 * return new FiberTask(fn() => $this->fetchFromDatabase($key));
 * ```
 *
 * This is the async equivalent of Promise.resolve() in JavaScript.
 */
final class CompletedTask implements Task
{
    /**
     * Creates a task with a pre-computed result.
     *
     * @param mixed $value The value to return from getResult()
     */
    public function __construct(private mixed $value)
    {
    }

    /**
     * Always returns false since the task is already completed.
     *
     * @return bool False - completed tasks are never running
     */
    public function isRunning(): bool
    {
        return false;
    }

    /**
     * Always returns true since the task is pre-completed.
     *
     * @return bool True - the task is always completed
     */
    public function isCompleted(): bool
    {
        return true;
    }

    /**
     * Returns the pre-computed value immediately.
     *
     * This method has zero overhead - it simply returns the value
     * provided to the constructor without any computation, I/O, or delay.
     *
     * @return mixed The value provided at construction
     */
    public function getResult(): mixed
    {
        return $this->value;
    }

    /**
     * No-op cancellation method.
     *
     * Cancellation has no effect on already-completed tasks.
     * This method exists only to satisfy the Task interface.
     *
     * @return void
     */
    public function cancel(): void
    {
        // No-op: Task is already completed
    }
}
