<?php

declare(strict_types=1);

namespace Glueful\Async\Task;

use Glueful\Async\Contracts\Task;

/**
 * A task that wraps a pre-existing error/exception.
 *
 * FailedTask provides a Task wrapper for errors that have already occurred,
 * allowing them to be propagated through async/await patterns consistently.
 * This is useful for implementing async interfaces when failures are detected
 * synchronously or when you need to defer error handling.
 *
 * Use cases:
 * - Propagating validation errors through async APIs
 * - Testing error handling in async code
 * - Converting synchronous errors to async task failures
 * - Short-circuiting async operations when pre-condition fails
 *
 * Example:
 * ```php
 * // Early validation error
 * if (!$this->validator->validate($input)) {
 *     return new FailedTask(new ValidationException('Invalid input'));
 * }
 * return new FiberTask(fn() => $this->process($input));
 * ```
 *
 * Another example - error propagation:
 * ```php
 * try {
 *     $config = $this->loadConfig();
 * } catch (\Throwable $e) {
 *     // Defer error throwing to when result is accessed
 *     return new FailedTask($e);
 * }
 * ```
 *
 * This is the async equivalent of Promise.reject() in JavaScript.
 */
final class FailedTask implements Task
{
    /**
     * Creates a task that will throw the given error when accessed.
     *
     * @param \Throwable $e The exception to throw from getResult()
     */
    public function __construct(private \Throwable $e)
    {
    }

    /**
     * Always returns false since the task is already completed (with failure).
     *
     * @return bool False - failed tasks are never running
     */
    public function isRunning(): bool
    {
        return false;
    }

    /**
     * Always returns true since the task is pre-completed (with failure).
     *
     * Note: A task is considered "completed" even if it failed.
     * Use getResult() in a try-catch to distinguish success from failure.
     *
     * @return bool True - the task is always completed
     */
    public function isCompleted(): bool
    {
        return true;
    }

    /**
     * Throws the pre-existing exception.
     *
     * This method always throws the exception provided at construction.
     * It never returns normally - calling code should be prepared to catch
     * the exception.
     *
     * @return mixed Never returns
     * @throws \Throwable Always throws the exception from construction
     */
    public function getResult(): mixed
    {
        throw $this->e;
    }

    /**
     * No-op cancellation method.
     *
     * Cancellation has no effect on already-failed tasks.
     * This method exists only to satisfy the Task interface.
     *
     * @return void
     */
    public function cancel(): void
    {
        // No-op: Task has already failed
    }
}
