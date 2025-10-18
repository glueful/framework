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
 * Key characteristics:
 * - **Zero overhead**: No fiber creation, no suspension, no execution
 * - **Immediate completion**: isCompleted() always returns true
 * - **Lazy error throwing**: Exception is thrown when getResult() is called
 * - **Interface compliance**: Satisfies Task interface for polymorphic error handling
 * - **Immutable**: Exception is set at construction and never changes
 * - **Preserves stack trace**: Original exception stack trace is maintained
 *
 * Use cases:
 * - **Early validation**: Propagating validation errors through async APIs
 * - **Testing**: Simulating async failures for error handling tests
 * - **Error conversion**: Converting synchronous errors to async task failures
 * - **Pre-condition checks**: Short-circuiting async operations when pre-condition fails
 * - **Deferred throwing**: Delaying exception throw until result is accessed
 * - **API consistency**: Maintaining async API signature even for sync errors
 *
 * Benefits of deferred error throwing:
 * - Allows error to propagate through async pipeline
 * - Enables uniform error handling for sync and async errors
 * - Supports error recovery via try-catch at result access time
 * - Maintains execution context for better debugging
 *
 * Usage examples:
 * ```php
 * // Early validation with async API consistency
 * public function processDataAsync(array $data): Task
 * {
 *     // Validate input before starting async operation
 *     if (!$this->validator->validate($data)) {
 *         return new FailedTask(
 *             new ValidationException('Invalid input data')
 *         );
 *     }
 *
 *     // Validation passed - proceed with async processing
 *     return new FiberTask(fn() => $this->process($data));
 * }
 *
 * // Error propagation pattern (defer throwing)
 * public function loadConfigAsync(): Task
 * {
 *     try {
 *         $config = $this->loadConfig();  // Synchronous load
 *         return new CompletedTask($config);
 *     } catch (\Throwable $e) {
 *         // Defer error throwing to when result is accessed
 *         // Allows consistent error handling in async pipeline
 *         return new FailedTask($e);
 *     }
 * }
 *
 * // Testing error handling in async code
 * $failedTask = new FailedTask(new \RuntimeException('Test error'));
 * try {
 *     $service->processTask($failedTask);
 *     assert(false, 'Should have thrown');
 * } catch (\RuntimeException $e) {
 *     assert($e->getMessage() === 'Test error');
 * }
 *
 * // Multiple tasks with some failures
 * $tasks = [
 *     new CompletedTask('success'),
 *     new FailedTask(new \Exception('error1')),
 *     new FiberTask(fn() => operation()),
 * ];
 * // Scheduler can handle mixed success/failure tasks
 * $results = $scheduler->allSettled($tasks);
 *
 * // Pre-condition check pattern
 * public function updateUserAsync(int $id, array $data): Task
 * {
 *     if ($id <= 0) {
 *         return new FailedTask(
 *             new \InvalidArgumentException('Invalid user ID')
 *         );
 *     }
 *     return new FiberTask(fn() => $this->updateUser($id, $data));
 * }
 * ```
 *
 * Error handling at result access:
 * ```php
 * $task = new FailedTask(new \RuntimeException('Operation failed'));
 *
 * // Exception is thrown here (not at construction)
 * try {
 *     $result = $task->getResult();
 * } catch (\RuntimeException $e) {
 *     // Handle the error
 *     echo "Error: " . $e->getMessage();
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
