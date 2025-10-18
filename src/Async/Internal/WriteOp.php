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
 * 1. User code calls: await($stream->write($data))
 * 2. AsyncStream::write() suspends with: Fiber::suspend(new WriteOp($stream, $deadline, $token))
 * 3. FiberScheduler receives the WriteOp in task->step()
 * 4. Scheduler adds stream to write waiters queue (keyed by stream resource ID)
 * 5. Event loop calls stream_select() to monitor all pending streams
 * 6. When stream becomes writable (buffer space available), scheduler resumes the fiber
 * 7. AsyncStream::write() performs fwrite() and returns bytes written
 *
 * Key characteristics:
 * - **Non-blocking**: Never blocks the event loop or other tasks
 * - **Multiplexed**: Multiple writes on different streams can wait concurrently
 * - **Separate queue**: Write waiters are tracked separately from read waiters
 * - **Timeout support**: Can fail with timeout if deadline is exceeded
 * - **Cancellable**: Can be cancelled via CancellationToken
 * - **Efficient**: Uses stream_select() for O(1) readiness checking
 *
 * Supported stream types:
 * - File streams opened with fopen() in write mode
 * - Socket streams from fsockopen() or stream_socket_client()
 * - Pipe streams from proc_open() or popen()
 * - Any stream resource that supports stream_select()
 *
 * Write buffering considerations:
 * A stream becomes writable when:
 * - The kernel buffer has space available
 * - The stream is connected and ready
 * - No errors have occurred
 *
 * Even when writable, fwrite() may write fewer bytes than requested if:
 * - Buffer is partially full
 * - Network congestion occurs
 * - Resource limits are reached
 *
 * Async write wrappers typically loop until all data is written or an error occurs.
 *
 * Timeout behavior:
 * When a deadline is provided:
 * - Scheduler checks deadline on each event loop iteration
 * - If current time > deadline, task resumes with TimeoutException
 * - This prevents writes from hanging indefinitely on slow/stalled connections
 *
 * Cancellation behavior:
 * When a CancellationToken is provided and cancelled:
 * - Scheduler removes stream from write waiters
 * - Task resumes with cancellation exception
 * - Allows external cancellation of blocking writes
 *
 * Example (internal):
 * ```php
 * // Inside AsyncStream::write()
 * $deadline = $timeout ? microtime(true) + $timeout : null;
 * Fiber::suspend(new WriteOp($this->stream, $deadline, $token));
 * $written = fwrite($this->stream, $data); // Executes after resume
 * ```
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
     * The stream resource to monitor for writability.
     *
     * This must be a valid PHP stream resource that supports stream_select().
     * The scheduler uses this to poll for write readiness in the event loop.
     *
     * When the stream becomes writable (buffer has space), the scheduler resumes
     * the waiting fiber so it can perform the actual write operation.
     *
     * Writability means the kernel buffer has available space, NOT that all data
     * will be written. Large writes may need multiple write cycles.
     *
     * The stream must remain valid until the operation completes. Closing or
     * destroying the stream while waiting will cause undefined behavior.
     *
     * @var resource Stream resource to monitor
     */
    public $stream;

    /**
     * Optional absolute deadline for the write operation.
     *
     * When provided, this is an absolute timestamp in microtime(true) format.
     * If the current time exceeds this deadline before the stream becomes
     * writable, the scheduler resumes the fiber with a TimeoutException.
     *
     * This allows write operations to fail fast instead of waiting indefinitely
     * for buffer space or network congestion to clear (e.g., slow consumer,
     * network backpressure).
     *
     * Set to null for no timeout (operation waits indefinitely until writable
     * or cancelled).
     *
     * @var float|null Absolute deadline timestamp or null for no timeout
     */
    public ?float $deadline;

    /**
     * Optional cancellation token for the write operation.
     *
     * When provided, the scheduler checks this token on each event loop iteration.
     * If cancelled, the scheduler removes the stream from the write waiters and
     * resumes the fiber with a cancellation exception.
     *
     * This allows external code to cancel in-flight writes, useful for:
     * - User-initiated request cancellation
     * - Parent task cancellation propagation
     * - Resource cleanup on shutdown
     * - Request timeout enforcement
     * - Abandoning slow connections
     *
     * @var CancellationToken|null Cancellation token or null if not cancellable
     */
    public ?CancellationToken $token;

    /**
     * Creates a write operation suspension marker.
     *
     * @param resource $stream Stream resource to wait for writability
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
