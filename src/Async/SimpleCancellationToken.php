<?php

declare(strict_types=1);

namespace Glueful\Async;

use Glueful\Async\Contracts\CancellationToken;

/**
 * Simple cancellation token for cooperative task cancellation.
 *
 * SimpleCancellationToken provides a basic implementation of the CancellationToken
 * interface, allowing tasks to be cancelled cooperatively. Tasks can check the token
 * periodically and exit gracefully when cancellation is requested.
 *
 * Important: This is a cooperative cancellation mechanism. Tasks must explicitly
 * check the token - cancellation is not forced or preemptive.
 *
 * Usage pattern:
 * ```php
 * $token = new SimpleCancellationToken();
 * $task = $scheduler->spawn(function() use ($token) {
 *     while (!$token->isCancelled()) {
 *         // Do work...
 *         performOperation();
 *     }
 *     return 'Cancelled gracefully';
 * }, $token);
 *
 * // Later, request cancellation
 * $token->cancel();
 * ```
 *
 * Features:
 * - Thread-safe flag-based cancellation
 * - Non-reversible (once cancelled, always cancelled)
 * - Exception-based interruption support
 * - Used by FiberScheduler for I/O and timer operations
 */
final class SimpleCancellationToken implements CancellationToken
{
    /** @var bool Cancellation flag - true when cancel() has been called */
    private bool $cancelled = false;

    /**
     * Requests cancellation of associated operations.
     *
     * Sets the cancellation flag to true. This is a one-way operation -
     * once cancelled, the token cannot be reset. All operations checking
     * this token should exit gracefully.
     *
     * Note: This does not force cancellation - tasks must cooperatively
     * check isCancelled() or call throwIfCancelled() to respect the request.
     *
     * @return void
     */
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    /**
     * Checks if cancellation has been requested.
     *
     * Tasks should check this method periodically (e.g., in loops) to
     * determine if they should stop processing and exit gracefully.
     *
     * @return bool True if cancel() has been called, false otherwise
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Throws an exception if cancellation has been requested.
     *
     * This is a convenience method for enforcing cancellation by throwing
     * an exception. Use this when you want cancellation to immediately
     * interrupt execution rather than checking isCancelled() manually.
     *
     * @return void
     * @throws \RuntimeException If the token has been cancelled
     */
    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new \Glueful\Async\Exceptions\CancelledException('Operation cancelled');
        }
    }
}
