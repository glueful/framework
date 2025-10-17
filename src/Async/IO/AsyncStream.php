<?php

declare(strict_types=1);

namespace Glueful\Async\IO;

use Glueful\Async\Contracts\CancellationToken;
use Glueful\Async\Contracts\Timeout;
use Glueful\Async\Internal\ReadOp;
use Glueful\Async\Internal\WriteOp;

/**
 * Non-blocking asynchronous stream wrapper.
 *
 * AsyncStream wraps a PHP stream resource and provides non-blocking, cooperative
 * read and write operations that integrate with the FiberScheduler. When data is
 * not immediately available, operations suspend the current fiber, allowing the
 * scheduler to multiplex other tasks.
 *
 * The stream is automatically configured to non-blocking mode, and operations
 * cooperatively yield when they would block, resuming when I/O is ready.
 *
 * Usage with scheduler:
 * ```php
 * $stream = new AsyncStream(fopen('php://temp', 'r+'));
 * $scheduler = new FiberScheduler();
 *
 * $task = $scheduler->spawn(function() use ($stream) {
 *     $stream->write("Hello, async world!\n");
 *     $data = $stream->read(1024);
 *     return $data;
 * });
 *
 * $result = $scheduler->all([$task]);
 * ```
 *
 * Features:
 * - Non-blocking I/O with cooperative suspension
 * - Timeout support for read/write operations
 * - Cancellation token integration
 * - Automatic retry on EAGAIN/EWOULDBLOCK
 * - Works with any PHP stream resource (files, sockets, pipes, etc.)
 */
final class AsyncStream
{
    /** @var resource The underlying stream resource */
    private $stream;

    /**
     * Creates an async stream wrapper around a stream resource.
     *
     * The stream is automatically configured to non-blocking mode.
     * This constructor validates that the parameter is a valid resource.
     *
     * @param resource $stream Readable/writable stream resource
     * @throws \InvalidArgumentException If the parameter is not a resource
     */
    public function __construct($stream)
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('AsyncStream expects a stream resource');
        }
        $this->stream = $stream;

        // Configure stream for non-blocking operation
        @stream_set_blocking($this->stream, false);
    }

    /**
     * Gets the underlying stream resource.
     *
     * Use this to access the raw resource when needed (e.g., for stream_select,
     * resource metadata, or passing to non-async functions).
     *
     * @return resource The wrapped stream resource
     */
    public function getResource()
    {
        return $this->stream;
    }

    /**
     * Reads data from the stream asynchronously.
     *
     * Attempts to read up to $length bytes from the stream. If data is not
     * immediately available, suspends the current fiber (via ReadOp) and allows
     * the scheduler to multiplex other tasks. Resumes when the stream becomes readable.
     *
     * Behavior:
     * - Returns early if EOF is reached before $length bytes are read
     * - Cooperatively suspends when the stream would block (EAGAIN/EWOULDBLOCK)
     * - Respects timeout and cancellation token if provided
     * - Accumulates data across multiple read attempts
     *
     * Note: Must be called from within a fiber managed by FiberScheduler.
     * Calling from non-fiber context will throw an error.
     *
     * @param int $length Maximum number of bytes to read
     * @param Timeout|null $timeout Optional timeout for the entire operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return string The data read (may be shorter than $length if EOF reached)
     * @throws \Glueful\Async\Exceptions\TimeoutException If timeout expires
     * @throws \Exception If operation is cancelled
    */
    public function read(int $length, ?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        // Calculate absolute deadline from timeout
        $deadline = $timeout !== null ? (microtime(true) + max(0.0, $timeout->seconds)) : null;
        $data = '';

        // Read until we have enough data or EOF
        while (\strlen($data) < $length) {
            // Check for cancellation
            if ($token !== null && $token->isCancelled()) {
                $token->throwIfCancelled();
            }

            // Attempt non-blocking read
            $chunk = @fread($this->stream, $length - \strlen($data));
            if (\is_string($chunk) && $chunk !== '') {
                $data .= $chunk;
                continue;
            }

            // Check for EOF
            if (feof($this->stream)) {
                return $data; // Return what we have so far
            }

            // Check for timeout
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new \Glueful\Async\Exceptions\TimeoutException('async read timeout');
            }

            // Data not ready - suspend fiber until stream is readable
            \Fiber::suspend(new ReadOp($this->stream, $deadline, $token));
        }

        return $data;
    }

    /**
     * Writes data to the stream asynchronously.
     *
     * Attempts to write the entire buffer to the stream. If the stream is not
     * immediately ready for writing, suspends the current fiber (via WriteOp) and
     * allows the scheduler to multiplex other tasks. Resumes when the stream becomes writable.
     *
     * Behavior:
     * - Writes the entire buffer, handling partial writes automatically
     * - Cooperatively suspends when the stream would block (EAGAIN/EWOULDBLOCK)
     * - Respects timeout and cancellation token if provided
     * - Tracks total bytes written across multiple write attempts
     *
     * Note: Must be called from within a fiber managed by FiberScheduler.
     * Calling from non-fiber context will throw an error.
     *
     * @param string $buffer The data to write
     * @param Timeout|null $timeout Optional timeout for the entire operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return int Total number of bytes written (always equals strlen($buffer) on success)
     * @throws \Glueful\Async\Exceptions\TimeoutException If timeout expires
     * @throws \Exception If operation is cancelled
    */
    public function write(string $buffer, ?Timeout $timeout = null, ?CancellationToken $token = null): int
    {
        // Calculate absolute deadline from timeout
        $deadline = $timeout !== null ? (microtime(true) + max(0.0, $timeout->seconds)) : null;
        $written = 0;
        $len = \strlen($buffer);

        // Write until entire buffer is sent
        while ($written < $len) {
            // Check for cancellation
            if ($token !== null && $token->isCancelled()) {
                $token->throwIfCancelled();
            }

            // Attempt non-blocking write
            $n = @fwrite($this->stream, substr($buffer, $written));
            if (\is_int($n) && $n > 0) {
                $written += $n;
                continue;
            }

            // Check for timeout
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new \Glueful\Async\Exceptions\TimeoutException('async write timeout');
            }

            // Stream not ready - suspend fiber until stream is writable
            \Fiber::suspend(new WriteOp($this->stream, $deadline, $token));
        }

        return $written;
    }

    /**
     * Reads a single line (ending with \n) from the stream.
     * The returned string includes the trailing newline if present.
     * If EOF is reached before a newline, returns the accumulated data.
     *
     * @param Timeout|null $timeout Optional timeout for the entire operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return string
     */
    public function readLine(?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        $deadline = $timeout !== null ? (microtime(true) + max(0.0, $timeout->seconds)) : null;
        $buf = '';
        while (true) {
            // Check for newline already read
            $pos = strpos($buf, "\n");
            if ($pos !== false) {
                return substr($buf, 0, $pos + 1);
            }

            // Cancellation/timeout checks
            if ($token !== null && $token->isCancelled()) {
                $token->throwIfCancelled();
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new \Glueful\Async\Exceptions\TimeoutException('async readLine timeout');
            }

            // If EOF, return whatever we have
            if (feof($this->stream)) {
                return $buf;
            }

            // Read next chunk (use a moderate chunk size)
            $remaining = $deadline !== null ? max(0.0, $deadline - microtime(true)) : null;
            $chunkTimeout = $remaining !== null ? new Timeout($remaining) : null;
            $buf .= $this->read(1024, $chunkTimeout, $token);
        }
    }

    /**
     * Reads the entire stream until EOF.
     * Be careful: without a timeout, this may block if the stream never closes.
     *
     * @param Timeout|null $timeout Optional timeout for the entire operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return string Full contents until EOF
     */
    public function readAll(?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        $deadline = $timeout !== null ? (microtime(true) + max(0.0, $timeout->seconds)) : null;
        $data = '';
        while (!feof($this->stream)) {
            if ($token !== null && $token->isCancelled()) {
                $token->throwIfCancelled();
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new \Glueful\Async\Exceptions\TimeoutException('async readAll timeout');
            }

            $remaining = $deadline !== null ? max(0.0, $deadline - microtime(true)) : null;
            $chunkTimeout = $remaining !== null ? new Timeout($remaining) : null;
            $chunk = $this->read(8192, $chunkTimeout, $token);
            if ($chunk === '') {
                // read() returns empty string only on EOF; exit loop
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    /**
     * Reads exactly $length bytes unless EOF is reached sooner.
     * Throws on timeout.
     *
     * @param int $length Number of bytes to read
     * @param Timeout|null $timeout Optional timeout for the entire operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return string
     */
    public function readExactly(int $length, ?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        $deadline = $timeout !== null ? (microtime(true) + max(0.0, $timeout->seconds)) : null;
        $data = '';
        while (strlen($data) < $length) {
            if ($token !== null && $token->isCancelled()) {
                $token->throwIfCancelled();
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new \Glueful\Async\Exceptions\TimeoutException('async readExactly timeout');
            }
            if (feof($this->stream)) {
                // Return what we have (could be shorter than requested)
                return $data;
            }
            $remaining = $deadline !== null ? max(0.0, $deadline - microtime(true)) : null;
            $chunkTimeout = $remaining !== null ? new Timeout($remaining) : null;
            $data .= $this->read($length - strlen($data), $chunkTimeout, $token);
        }
        return $data;
    }

    /**
     * Writes a line (data + trailing \n) to the stream.
     * Returns total bytes written.
     *
     * @param string $line Line without trailing newline (it will be added)
     * @param Timeout|null $timeout Optional timeout
     * @param CancellationToken|null $token Optional cancellation
     * @return int
     */
    public function writeLine(string $line, ?Timeout $timeout = null, ?CancellationToken $token = null): int
    {
        return $this->write($line . "\n", $timeout, $token);
    }
}
