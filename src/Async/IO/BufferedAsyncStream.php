<?php

declare(strict_types=1);

namespace Glueful\Async\IO;

use Glueful\Async\Contracts\CancellationToken;
use Glueful\Async\Contracts\Timeout;

/**
 * Buffered wrapper around AsyncStream to reduce syscalls for small I/O.
 *
 * BufferedAsyncStream wraps an AsyncStream and adds internal buffering to minimize
 * system calls for small read/write operations. This improves performance when
 * making many small I/O operations by batching them into larger chunks.
 *
 * Features:
 * - Read buffer: Pre-fetches data to satisfy future reads without syscalls
 * - Write buffer: Accumulates writes until buffer is full, then flushes
 * - Configurable buffer size (default 8KB)
 * - Manual flush() method for write buffer
 * - Delegates all operations to wrapped AsyncStream
 *
 * Usage:
 * ```php
 * $stream = new AsyncStream(fopen('file.txt', 'r+'));
 * $buffered = new BufferedAsyncStream($stream, 16384); // 16KB buffer
 *
 * // Small reads are served from buffer (fewer syscalls)
 * $data = $buffered->read(100);
 *
 * // Small writes accumulate until buffer fills
 * $buffered->write("small chunk\n");
 * $buffered->flush(); // Force write buffer to stream
 * ```
 */
final class BufferedAsyncStream
{
    private string $readBuffer = '';
    private string $writeBuffer = '';

    /**
     * Creates a buffered async stream wrapper.
     *
     * @param AsyncStream $stream The underlying async stream to wrap
     * @param int $bufferSize Size of read/write buffers in bytes (default 8192)
     */
    public function __construct(
        private AsyncStream $stream,
        private int $bufferSize = 8192
    ) {
        $this->bufferSize = max(1, $bufferSize);
    }

    /**
     * Gets the underlying AsyncStream instance.
     *
     * @return AsyncStream The wrapped stream
     */
    public function getStream(): AsyncStream
    {
        return $this->stream;
    }

    /**
     * Gets the underlying stream resource.
     *
     * @return resource The wrapped stream resource
     */
    public function getResource()
    {
        return $this->stream->getResource();
    }

    /**
     * Reads data from the stream with buffering for improved performance.
     *
     * Implements read-ahead buffering to minimize system calls for small reads.
     * When reading from the underlying stream, always reads at least bufferSize
     * bytes to satisfy the request and prefetch data for future reads.
     *
     * Buffering strategy:
     * 1. If buffer has enough data, serve directly from buffer (no syscall)
     * 2. If buffer has partial data, serve it and read more from stream
     * 3. If buffer is empty, read at least bufferSize bytes and buffer extras
     *
     * Performance benefits:
     * - Reduces syscalls for small sequential reads
     * - Example: 100x 10-byte reads becomes ~1-2 syscalls with 8KB buffer
     * - Particularly effective for line-oriented or small-chunk reading
     *
     * Trade-offs:
     * - May read more data than immediately needed (bufferSize overhead)
     * - Buffered data is stored in memory
     * - Not ideal for random access or seeking
     *
     * Use cases:
     * - Reading HTTP headers line-by-line
     * - Parsing text protocols with small tokens
     * - Reading structured binary data with small fixed-size fields
     * - Any scenario with many small sequential reads
     *
     * Example:
     * ```php
     * $buffered = new BufferedAsyncStream($stream, 8192);
     *
     * // These 10 reads might only cause 1 underlying read
     * for ($i = 0; $i < 10; $i++) {
     *     $chunk = $buffered->read(100); // Fast - served from buffer
     * }
     * ```
     *
     * @param int $length Maximum number of bytes to read
     * @param Timeout|null $timeout Optional timeout for the operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return string The data read (may be shorter than $length if EOF reached)
     */
    public function read(int $length, ?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        if ($length <= 0) {
            return '';
        }

        // Case 1: Buffer has data - try to serve from buffer first
        if ($this->readBuffer !== '') {
            // Extract requested bytes from buffer (or all of it if buffer is smaller)
            $chunk = substr($this->readBuffer, 0, $length);
            // Remove extracted bytes from buffer
            $this->readBuffer = substr($this->readBuffer, strlen($chunk));

            // Check if we satisfied the request entirely from buffer
            if (strlen($chunk) >= $length) {
                return $chunk; // Fast path - no syscall needed
            }

            // Buffer had partial data - need to read more
            $needed = $length - strlen($chunk);
            // Read at least bufferSize to replenish buffer for future reads
            $more = $this->stream->read(max($this->bufferSize, $needed), $timeout, $token);
            // Store excess bytes in buffer for next read
            $this->readBuffer .= substr($more, $needed);
            // Return partial buffer + requested portion of new data
            return $chunk . substr($more, 0, $needed);
        }

        // Case 2: Buffer is empty - read fresh data
        // Always read at least bufferSize to fill the read-ahead buffer
        $data = $this->stream->read(max($this->bufferSize, $length), $timeout, $token);

        // If we got less than or exactly what was requested, return it all
        if (strlen($data) <= $length) {
            return $data;
        }

        // We got more than requested - return requested portion and buffer the rest
        $ret = substr($data, 0, $length);
        $this->readBuffer = substr($data, $length); // Store excess for next read
        return $ret;
    }

    /**
     * Writes data to the stream with buffering for improved performance.
     *
     * Implements write buffering to batch small writes into larger chunks,
     * minimizing system calls. Data is accumulated in an internal buffer and
     * only flushed to the underlying stream when the buffer fills.
     *
     * Buffering strategy:
     * 1. Append data to write buffer
     * 2. If buffer >= bufferSize, flush full chunks to stream
     * 3. Keep remainder in buffer for next write
     * 4. Call flush() to force buffered data to stream
     *
     * Performance benefits:
     * - Reduces syscalls for many small writes
     * - Example: 100x 10-byte writes becomes ~1-2 syscalls with 8KB buffer
     * - Particularly effective for streaming output or protocol commands
     *
     * IMPORTANT: Write buffer behavior
     * - Data is NOT immediately written to the stream
     * - Buffered data is in memory only until flushed
     * - MUST call flush() before:
     *   - Reading a response (request/response protocols)
     *   - Closing the stream
     *   - Timing-sensitive operations
     *   - Process termination
     *
     * Return value semantics:
     * - Returns bytes actually written to underlying stream (not buffered count)
     * - May return 0 if all data was buffered without flushing
     * - Total data accepted is always strlen($buffer), even if buffered
     *
     * Use cases:
     * - Writing HTTP request headers line-by-line
     * - Sending many small protocol commands
     * - Streaming output with small chunks
     * - Building responses incrementally
     *
     * Example:
     * ```php
     * $buffered = new BufferedAsyncStream($stream, 8192);
     *
     * // These writes are buffered, not immediately sent
     * $buffered->write("GET /api/users HTTP/1.1\r\n");
     * $buffered->write("Host: example.com\r\n");
     * $buffered->write("Connection: close\r\n");
     * $buffered->write("\r\n");
     *
     * // MUST flush before reading response
     * $buffered->flush(); // Now the HTTP request is actually sent
     *
     * $response = $buffered->readLine();
     * ```
     *
     * @param string $buffer The data to write (will be buffered)
     * @param Timeout|null $timeout Optional timeout for flush operations
     * @param CancellationToken|null $token Optional cancellation token
     * @return int Number of bytes actually written to stream (not buffered count)
     */
    public function write(string $buffer, ?Timeout $timeout = null, ?CancellationToken $token = null): int
    {
        // Append new data to write buffer
        $this->writeBuffer .= $buffer;
        $written = 0;

        // Flush full bufferSize chunks while buffer is large enough
        while (strlen($this->writeBuffer) >= $this->bufferSize) {
            // Extract one bufferSize chunk to write
            $chunk = substr($this->writeBuffer, 0, $this->bufferSize);
            // Write chunk to underlying stream
            $n = $this->stream->write($chunk, $timeout, $token);
            $written += $n;
            // Remove written bytes from buffer
            $this->writeBuffer = substr($this->writeBuffer, $n);

            if ($n < strlen($chunk)) {
                // Partial write occurred - stop flushing and keep remainder in buffer
                // This can happen due to backpressure or EAGAIN on underlying stream
                break;
            }
        }

        // Return number of bytes actually written (not including what's still buffered)
        return $written;
    }

    /**
     * Flushes all buffered write data to the underlying stream.
     *
     * Forces immediate transmission of any data sitting in the write buffer.
     * Loops until the entire buffer has been written to the underlying stream,
     * handling partial writes automatically.
     *
     * When to call flush():
     * - **REQUIRED before reading**: Request/response protocols need flush before read
     * - **Before closing**: Ensure all data is sent before closing stream
     * - **Explicit synchronization**: When data must be sent immediately
     * - **Transaction boundaries**: Before committing or confirming operations
     * - **Periodic flushing**: Long-running writes to show progress
     *
     * Behavior:
     * - Writes entire buffer contents to stream
     * - Handles partial writes by looping
     * - Cooperatively suspends when stream would block
     * - Clears write buffer when complete
     * - Respects timeout across all write attempts
     *
     * Performance trade-offs:
     * - Explicit flush sacrifices buffering efficiency for immediacy
     * - Useful when correctness requires immediate transmission
     * - Can reduce throughput if called too frequently
     * - Balance between latency (flush often) and throughput (flush rarely)
     *
     * Common mistakes:
     * - Forgetting to flush before reading response (causes deadlock/timeout)
     * - Flushing after every small write (defeats buffering purpose)
     * - Not flushing before closing (loses buffered data)
     *
     * Example patterns:
     * ```php
     * // Request/response protocol (HTTP, RPC, etc.)
     * $buffered->write($request);
     * $buffered->flush(); // REQUIRED before reading
     * $response = $buffered->readLine();
     *
     * // Streaming with periodic flush
     * foreach ($chunks as $chunk) {
     *     $buffered->write($chunk);
     *     if ($chunkCount % 100 === 0) {
     *         $buffered->flush(); // Show progress every 100 chunks
     *     }
     * }
     * $buffered->flush(); // Final flush
     *
     * // Cleanup before close
     * $buffered->write($finalData);
     * $buffered->flush(); // Ensure data is sent
     * ```
     *
     * @param Timeout|null $timeout Optional timeout for the entire flush operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return void
     * @throws \Glueful\Async\Exceptions\TimeoutException If timeout expires before buffer is flushed
     * @throws \Exception If operation is cancelled
     */
    public function flush(?Timeout $timeout = null, ?CancellationToken $token = null): void
    {
        // Loop until entire write buffer is flushed
        while ($this->writeBuffer !== '') {
            // Write as much as possible to underlying stream
            $n = $this->stream->write($this->writeBuffer, $timeout, $token);
            // Remove written bytes from buffer
            $this->writeBuffer = substr($this->writeBuffer, $n);
            // Loop continues until buffer is empty
        }
    }

    /**
     * Reads a single line from the stream.
     *
     * @param Timeout|null $timeout Optional timeout
     * @param CancellationToken|null $token Optional cancellation token
     * @return string Line including trailing newline (if present)
     */
    public function readLine(?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        return $this->stream->readLine($timeout, $token);
    }

    /**
     * Reads the entire stream until EOF.
     *
     * @param Timeout|null $timeout Optional timeout
     * @param CancellationToken|null $token Optional cancellation token
     * @return string Full contents until EOF
     */
    public function readAll(?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        return $this->stream->readAll($timeout, $token);
    }

    /**
     * Reads exactly N bytes unless EOF is reached.
     *
     * @param int $length Number of bytes to read
     * @param Timeout|null $timeout Optional timeout
     * @param CancellationToken|null $token Optional cancellation token
     * @return string Exactly $length bytes (or less if EOF)
     */
    public function readExactly(int $length, ?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        return $this->stream->readExactly($length, $timeout, $token);
    }

    /**
     * Writes a line (data + newline) to the stream.
     *
     * @param string $line Line without trailing newline
     * @param Timeout|null $timeout Optional timeout
     * @param CancellationToken|null $token Optional cancellation token
     * @return int Bytes written
     */
    public function writeLine(string $line, ?Timeout $timeout = null, ?CancellationToken $token = null): int
    {
        return $this->write($line . "\n", $timeout, $token);
    }
}
