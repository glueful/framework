<?php

declare(strict_types=1);

namespace Glueful\Async\Internal;

use Glueful\Async\Contracts\CancellationToken;

/**
 * Write operation suspension marker for async I/O.
 *
 * WriteOp is an internal data object suspended by fibers to signal that they are
 * waiting for a stream to become writable. The FiberScheduler intercepts this
 * suspension, adds the stream to its I/O poll, and resumes the fiber when the
 * stream is ready for writing.
 *
 * This class is not meant to be instantiated directly by user code. It's created
 * internally by AsyncStream::write() or similar async I/O wrappers.
 *
 * Flow:
 * 1. AsyncStream::write() suspends with: Fiber::suspend(new WriteOp($stream))
 * 2. FiberScheduler receives the WriteOp in task->step()
 * 3. Scheduler adds stream to write waiters queue
 * 4. stream_select() monitors the stream
 * 5. When writable, scheduler resumes the fiber
 * 6. AsyncStream::write() continues writing
 *
 * The write waiter queue is separate from the read queue, allowing the scheduler
 * to efficiently multiplex tasks waiting for different I/O operations on the
 * same or different streams.
 *
 * @internal This class is part of the async I/O implementation
 */
final class WriteOp
{
    /**
     * @var resource The stream resource to monitor for writability
     */
    public $stream;

    /**
     * @var float|null Absolute timestamp when the operation should timeout
     *                 (microtime(true) format), or null for no timeout
     */
    public ?float $deadline;

    /**
     * @var CancellationToken|null Optional cancellation token for the operation
     */
    public ?CancellationToken $token;

    /**
     * Creates a write operation suspension marker.
     *
     * @param resource $stream The stream to wait for
     * @param float|null $deadline Optional absolute deadline timestamp
     * @param CancellationToken|null $token Optional cancellation token
     */
    public function __construct($stream, ?float $deadline = null, ?CancellationToken $token = null)
    {
        $this->stream = $stream;
        $this->deadline = $deadline;
        $this->token = $token;
    }
}
