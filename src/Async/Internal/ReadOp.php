<?php

declare(strict_types=1);

namespace Glueful\Async\Internal;

use Glueful\Async\Contracts\CancellationToken;

/**
 * Read operation suspension marker for async I/O.
 *
 * ReadOp is an internal data object suspended by fibers to signal that they are
 * waiting for a stream to become readable. The FiberScheduler intercepts this
 * suspension, adds the stream to its I/O poll, and resumes the fiber when the
 * stream is ready for reading.
 *
 * This class is not meant to be instantiated directly by user code. It's created
 * internally by AsyncStream::read() or similar async I/O wrappers.
 *
 * Flow:
 * 1. AsyncStream::read() suspends with: Fiber::suspend(new ReadOp($stream))
 * 2. FiberScheduler receives the ReadOp in task->step()
 * 3. Scheduler adds stream to read waiters queue
 * 4. stream_select() monitors the stream
 * 5. When readable, scheduler resumes the fiber
 * 6. AsyncStream::read() continues reading
 *
 * @internal This class is part of the async I/O implementation
 */
final class ReadOp
{
    /**
     * @var resource The stream resource to monitor for readability
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
     * Creates a read operation suspension marker.
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
