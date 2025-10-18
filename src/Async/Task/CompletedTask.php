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
 * Key characteristics:
 * - **Zero overhead**: No fiber creation, no suspension, no execution
 * - **Immediate completion**: isCompleted() always returns true
 * - **Type-safe**: Can wrap any PHP value (scalars, objects, arrays, null, etc.)
 * - **Interface compliance**: Satisfies Task interface for polymorphic code
 * - **Immutable**: Value is set at construction and never changes
 *
 * Use cases:
 * - **Cached values**: Returning cached/memoized values through async APIs
 * - **Testing**: Providing pre-computed values for async code tests
 * - **Conditional async**: Mixing synchronous and asynchronous code paths
 * - **Short-circuiting**: Skipping async execution when result is already known
 * - **Default values**: Providing fallback values in async operations
 * - **API consistency**: Maintaining async API signature even for sync operations
 *
 * Performance benefits:
 * - No fiber allocation (saves memory)
 * - No scheduler overhead (no suspensions)
 * - Immediate value retrieval (no await delay)
 * - Suitable for hot code paths with cached values
 *
 * Usage examples:
 * ```php
 * // Cache-aside pattern
 * public function getUserAsync(int $id): Task
 * {
 *     // Check cache first
 *     if ($cached = $this->cache->get("user:$id")) {
 *         return new CompletedTask($cached);  // Immediate return
 *     }
 *
 *     // Cache miss - fetch from database asynchronously
 *     return new FiberTask(fn() => $this->db->fetchUser($id));
 * }
 *
 * // Testing async code with mock data
 * $mockTask = new CompletedTask(['id' => 1, 'name' => 'Test User']);
 * $result = $service->processUser($mockTask);
 *
 * // Conditional async execution
 * public function fetchDataAsync(bool $useAsync): Task
 * {
 *     if (!$useAsync) {
 *         $data = $this->fetchDataSync();  // Synchronous fetch
 *         return new CompletedTask($data);  // Wrap for consistency
 *     }
 *     return new FiberTask(fn() => $this->fetchDataAsync());
 * }
 *
 * // Multiple tasks with some pre-computed
 * $tasks = [
 *     new CompletedTask('immediate'),           // Already known
 *     new FiberTask(fn() => slowOperation()),   // Needs execution
 *     new CompletedTask($cachedValue),          // From cache
 * ];
 * $results = $scheduler->all($tasks);
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
