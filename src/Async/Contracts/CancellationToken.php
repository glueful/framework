<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts;

/**
 * Contract for cooperative task cancellation.
 *
 * CancellationToken provides a mechanism for cooperative cancellation of async
 * operations. "Cooperative" means cancellation is not forced - the task must
 * explicitly check the token and decide how to respond to cancellation requests.
 *
 * This is similar to:
 * - C#'s CancellationToken
 * - Go's context.Context
 * - JavaScript's AbortSignal
 *
 * Key principles:
 * - **Cooperative**: Cancellation is signaled, not forced
 * - **Explicit checking**: Tasks must check isCancelled() or call throwIfCancelled()
 * - **Graceful cleanup**: Tasks can perform cleanup before exiting
 * - **Propagation**: Tokens can be passed through async operation chains
 *
 * Why cooperative instead of preemptive?
 * - Allows tasks to clean up resources (close files, connections, etc.)
 * - Prevents corruption from mid-operation termination
 * - Task controls when and how to respond to cancellation
 * - Safe for critical sections and transactional operations
 *
 * Primary implementation: **SimpleCancellationToken**
 * - Simple boolean flag that can be set to cancelled state
 * - Thread-safe for basic use cases
 * - Throws \Exception when cancelled (via throwIfCancelled())
 *
 * Usage patterns:
 * ```php
 * // Pattern 1: Check and abort early
 * function processItems(array $items, ?CancellationToken $token = null): void {
 *     foreach ($items as $item) {
 *         $token?->throwIfCancelled(); // Abort if cancelled
 *         processItem($item);
 *     }
 * }
 *
 * // Pattern 2: Check and cleanup gracefully
 * function downloadFile(string $url, ?CancellationToken $token = null): void {
 *     $handle = fopen($url, 'r');
 *     try {
 *         while (!feof($handle)) {
 *             if ($token?->isCancelled()) {
 *                 // Cleanup before aborting
 *                 fclose($handle);
 *                 unlink($tempFile);
 *                 $token->throwIfCancelled();
 *             }
 *             $data = fread($handle, 8192);
 *             writeToFile($data);
 *         }
 *     } finally {
 *         fclose($handle);
 *     }
 * }
 *
 * // Pattern 3: Pass token through async operations
 * $token = new SimpleCancellationToken();
 * $task = $scheduler->spawn(fn() => longOperation($scheduler, $token), $token);
 *
 * // Later: request cancellation
 * $token->cancel(); // Sets cancelled flag
 * // Task will check token and abort at next check point
 * ```
 *
 * Integration with async framework:
 * - Tasks accept optional CancellationToken parameter
 * - Suspension operations (SleepOp, ReadOp, WriteOp) support cancellation
 * - Scheduler checks tokens during event loop iterations
 * - Task->cancel() propagates to the task's token
 *
 * Best practices:
 * - Check token frequently in long-running loops
 * - Use throwIfCancelled() for quick abort
 * - Use isCancelled() + manual throw for cleanup
 * - Always make token parameter nullable and optional
 * - Pass token through to nested async operations
 */
interface CancellationToken
{
    /**
     * Checks if cancellation has been requested.
     *
     * Returns true if cancel() has been called on this token. The task should
     * check this periodically during long operations and abort if cancelled.
     *
     * This method is useful when you need to perform cleanup before aborting:
     * ```php
     * if ($token->isCancelled()) {
     *     // Perform cleanup
     *     cleanup();
     *     // Then abort
     *     $token->throwIfCancelled();
     * }
     * ```
     *
     * @return bool True if cancellation requested, false otherwise
     */
    public function isCancelled(): bool;

    /**
     * Throws an exception if cancellation has been requested.
     *
     * This is a convenience method that checks isCancelled() and throws if true.
     * Use this for simple cancellation points where you don't need cleanup logic:
     *
     * ```php
     * foreach ($items as $item) {
     *     $token?->throwIfCancelled(); // Abort if cancelled
     *     processItem($item);
     * }
     * ```
     *
     * The exception type is implementation-defined but should clearly indicate
     * cancellation (e.g., \Exception with message "Operation cancelled").
     *
     * @return void
     * @throws \Exception If cancellation has been requested
     */
    public function throwIfCancelled(): void;
}
