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
 * 1. User code calls: $data = await($stream->read(1024))
 * 2. AsyncStream::read() suspends with: Fiber::suspend(new ReadOp($stream, $deadline, $token))
 * 3. FiberScheduler receives the ReadOp in task->step()
 * 4. Scheduler adds stream to read waiters queue (keyed by stream resource ID)
 * 5. Event loop calls stream_select() to monitor all pending streams
 * 6. When stream becomes readable, scheduler resumes the fiber
 * 7. AsyncStream::read() performs fread() and returns data
 *
 * Key characteristics:
 * - **Non-blocking**: Never blocks the event loop or other tasks
 * - **Multiplexed**: Multiple reads on different streams can wait concurrently
 * - **Timeout support**: Can fail with timeout if deadline is exceeded
 * - **Cancellable**: Can be cancelled via CancellationToken
 * - **Efficient**: Uses stream_select() for O(1) readiness checking
 *
 * Supported stream types:
 * - File streams opened with fopen()
 * - Socket streams from fsockopen() or stream_socket_client()
 * - Pipe streams from proc_open() or popen()
 * - Any stream resource that supports stream_select()
 *
 * Timeout behavior:
 * When a deadline is provided:
 * - Scheduler checks deadline on each event loop iteration
 * - If current time > deadline, task resumes with TimeoutException
 * - This allows read operations to fail fast instead of waiting indefinitely
 *
 * Cancellation behavior:
 * When a CancellationToken is provided and cancelled:
 * - Scheduler removes stream from read waiters
 * - Task resumes with cancellation exception
 * - Allows external cancellation of blocking reads
 *
 * Example (internal):
 * ```php
 * // Inside AsyncStream::read()
 * $deadline = $timeout ? microtime(true) + $timeout : null;
 * Fiber::suspend(new ReadOp($this->stream, $deadline, $token));
 * return fread($this->stream, $length); // Executes after resume
 * ```
 *
 * @internal This class is part of the async I/O implementation
 */
final class ReadOp
{
    /**
     * The stream resource to monitor for readability.
     *
     * This must be a valid PHP stream resource that supports stream_select().
     * The scheduler uses this to poll for read readiness in the event loop.
     *
     * When the stream becomes readable (data available, EOF, or error), the
     * scheduler resumes the waiting fiber so it can perform the actual read.
     *
     * The stream must remain valid until the operation completes. Closing or
     * destroying the stream while waiting will cause undefined behavior.
     *
     * @var resource Stream resource to monitor
     */
    public $stream;

    /**
     * Optional absolute deadline for the read operation.
     *
     * When provided, this is an absolute timestamp in microtime(true) format.
     * If the current time exceeds this deadline before the stream becomes
     * readable, the scheduler resumes the fiber with a TimeoutException.
     *
     * This allows read operations to fail fast instead of waiting indefinitely
     * for data that may never arrive (e.g., slow network, unresponsive peer).
     *
     * Set to null for no timeout (operation waits indefinitely until readable
     * or cancelled).
     *
     * @var float|null Absolute deadline timestamp or null for no timeout
     */
    public ?float $deadline;

    /**
     * Optional cancellation token for the read operation.
     *
     * When provided, the scheduler checks this token on each event loop iteration.
     * If cancelled, the scheduler removes the stream from the read waiters and
     * resumes the fiber with a cancellation exception.
     *
     * This allows external code to cancel in-flight reads, useful for:
     * - User-initiated request cancellation
     * - Parent task cancellation propagation
     * - Resource cleanup on shutdown
     * - Request timeout enforcement
     *
     * @var CancellationToken|null Cancellation token or null if not cancellable
     */
    public ?CancellationToken $token;

    /**
     * Creates a read operation suspension marker.
     *
     * @param resource $stream Stream resource to wait for readability
     * @param float|null $deadline Optional absolute deadline (microtime(true) format)
     * @param CancellationToken|null $token Optional cancellation token
     */
    public function __construct($stream, ?float $deadline = null, ?CancellationToken $token = null)
    {
        $this->stream = $stream;
        $this->deadline = $deadline;
        $this->token = $token;
    }
}
