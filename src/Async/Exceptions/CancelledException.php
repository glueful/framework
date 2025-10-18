<?php

declare(strict_types=1);

namespace Glueful\Async\Exceptions;

/**
 * Exception thrown when an async operation is cooperatively cancelled.
 *
 * CancelledException is thrown when a CancellationToken is triggered and
 * checked by async operations. Unlike preemptive cancellation, cooperative
 * cancellation allows tasks to complete their current operation, clean up
 * resources, and then throw this exception at a safe checkpoint.
 *
 * Cancellation is cooperative, meaning:
 * - Tasks check cancellation tokens at suspension points
 * - Tasks can clean up resources before throwing
 * - No mid-operation interruption or corruption
 * - Safe for critical sections and transactions
 *
 * When Thrown:
 * - CancellationToken.throwIfCancelled() is called on a cancelled token
 * - FiberScheduler detects cancellation during task execution
 * - AsyncStream operations check cancellation during I/O waits
 * - HTTP client checks cancellation during polling loops
 * - Sleep operations check cancellation before resuming
 *
 * Usage Examples:
 * ```php
 * // Example 1: Cancelling a long-running task
 * $token = new SimpleCancellationToken();
 * $scheduler = new FiberScheduler();
 *
 * $task = $scheduler->spawn(function() use ($scheduler, $token) {
 *     for ($i = 0; $i < 100; $i++) {
 *         // Check cancellation at safe points
 *         $token->throwIfCancelled();
 *
 *         processItem($i);
 *         $scheduler->sleep(0.1, $token);
 *     }
 * }, $token);
 *
 * // Cancel after 5 seconds
 * $scheduler->spawn(function() use ($scheduler, $token) {
 *     $scheduler->sleep(5.0);
 *     $token->cancel();
 * });
 *
 * try {
 *     $result = $scheduler->all([$task]);
 * } catch (CancelledException $e) {
 *     echo "Task was cancelled\n";
 * }
 *
 * // Example 2: HTTP request with timeout via cancellation
 * $token = new SimpleCancellationToken();
 * $httpClient = new CurlMultiHttpClient();
 *
 * $task = $httpClient->sendAsync($request, $timeout, $token);
 *
 * try {
 *     $response = $task->getResult();
 * } catch (CancelledException $e) {
 *     echo "Request cancelled: " . $e->getMessage() . "\n";
 * }
 *
 * // Example 3: Resource cleanup on cancellation
 * $token = new SimpleCancellationToken();
 *
 * try {
 *     $resource = acquireResource();
 *     $token->throwIfCancelled(); // Check before proceeding
 *     performWork($resource, $token);
 * } catch (CancelledException $e) {
 *     // Clean up resources before propagating
 *     releaseResource($resource);
 *     throw $e;
 * }
 * ```
 *
 * Best Practices:
 * - Always clean up resources in finally blocks or catch handlers
 * - Check cancellation at regular intervals in loops
 * - Pass cancellation tokens to all nested async operations
 * - Don't catch CancelledException without re-throwing (unless intentional)
 * - Use specific exception messages to identify cancellation source
 *
 * @see \Glueful\Async\Contracts\CancellationToken
 * @see \Glueful\Async\SimpleCancellationToken
 */
class CancelledException extends AsyncException
{
}
