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
     * Reads a single line (ending with \n) from the stream asynchronously.
     *
     * Reads data in 1024-byte chunks until a newline character is found or EOF
     * is reached. This is more efficient than reading byte-by-byte for line-oriented
     * protocols (HTTP headers, SMTP, etc.).
     *
     * Behavior:
     * - Returns the line INCLUDING the trailing newline if present
     * - Returns partial line if EOF is reached before newline
     * - Returns empty string if already at EOF
     * - Cooperatively suspends when waiting for data
     * - Respects timeout across all read attempts
     *
     * Line endings:
     * - Only searches for LF (\n), not CRLF (\r\n)
     * - For CRLF-based protocols, the returned line will include both \r\n
     * - Caller can trim \r if needed
     *
     * Use cases:
     * - Reading HTTP response headers
     * - Line-oriented text protocols (SMTP, FTP, etc.)
     * - Reading line-delimited JSON/CSV streams
     * - Reading log files line by line
     *
     * Example:
     * ```php
     * $stream = new AsyncStream($socket);
     * while ($line = $stream->readLine(new Timeout(30.0))) {
     *     if ($line === "\r\n") break; // HTTP headers end
     *     echo "Header: $line";
     * }
     * ```
     *
     * @param Timeout|null $timeout Optional timeout for the entire read operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return string Line with trailing newline, or partial line if EOF reached
     * @throws \Glueful\Async\Exceptions\TimeoutException If timeout expires
     * @throws \Exception If operation is cancelled
     */
    public function readLine(?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        $deadline = $timeout !== null ? (microtime(true) + max(0.0, $timeout->seconds)) : null;
        $buf = '';

        while (true) {
            // Check if we already have a complete line in buffer
            $pos = strpos($buf, "\n");
            if ($pos !== false) {
                // Found newline - return line including the \n
                return substr($buf, 0, $pos + 1);
            }

            // Check for cancellation before attempting next read
            if ($token !== null && $token->isCancelled()) {
                $token->throwIfCancelled();
            }

            // Check if timeout has been exceeded
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new \Glueful\Async\Exceptions\TimeoutException('async readLine timeout');
            }

            // Check for EOF - return partial line if any
            if (feof($this->stream)) {
                return $buf;
            }

            // Read next chunk (1024 bytes balances efficiency and memory)
            // Calculate remaining timeout for this chunk read
            $remaining = $deadline !== null ? max(0.0, $deadline - microtime(true)) : null;
            $chunkTimeout = $remaining !== null ? new Timeout($remaining) : null;
            $buf .= $this->read(1024, $chunkTimeout, $token);
        }
    }

    /**
     * Reads the entire stream until EOF asynchronously.
     *
     * Reads in 8KB chunks until the stream ends, cooperatively yielding between
     * chunks. This is useful for reading complete responses or file contents where
     * the size is unknown in advance.
     *
     * WARNING: Be careful with unbounded streams!
     * - Without a timeout, this will wait indefinitely for EOF
     * - Network streams may never close if the peer doesn't disconnect
     * - Can consume large amounts of memory for big streams
     * - Consider using streaming/chunked processing for large data
     *
     * Behavior:
     * - Reads entire stream contents into memory as a single string
     * - Cooperatively suspends between 8KB chunks
     * - Returns accumulated data when EOF is reached
     * - Respects timeout across all read attempts
     *
     * Memory considerations:
     * - All data is accumulated in memory before returning
     * - For large streams (>100MB), consider BufferedAsyncStream or streaming
     * - Each 8KB chunk read may temporarily double memory usage during concatenation
     *
     * Recommended timeouts:
     * - Network streams: Always use a timeout (e.g., 30-60 seconds)
     * - File streams: Usually safe without timeout
     * - Pipe/socket streams: Use timeout based on expected data size
     *
     * Use cases:
     * - Reading complete HTTP response bodies
     * - Loading entire file contents
     * - Reading process output from pipes
     * - Downloading resources of unknown size
     *
     * Example:
     * ```php
     * // Read HTTP response body with timeout
     * $stream = new AsyncStream($socket);
     * $body = $stream->readAll(new Timeout(60.0)); // Max 60 seconds
     * ```
     *
     * @param Timeout|null $timeout Optional timeout for the entire operation (RECOMMENDED)
     * @param CancellationToken|null $token Optional cancellation token
     * @return string Full stream contents until EOF
     * @throws \Glueful\Async\Exceptions\TimeoutException If timeout expires before EOF
     * @throws \Exception If operation is cancelled
     */
    public function readAll(?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        $deadline = $timeout !== null ? (microtime(true) + max(0.0, $timeout->seconds)) : null;
        $data = '';

        while (!feof($this->stream)) {
            // Check for cancellation before each chunk
            if ($token !== null && $token->isCancelled()) {
                $token->throwIfCancelled();
            }

            // Check if timeout has been exceeded
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new \Glueful\Async\Exceptions\TimeoutException('async readAll timeout');
            }

            // Read next 8KB chunk with remaining timeout
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
     * Reads exactly the specified number of bytes from the stream.
     *
     * Continues reading until exactly $length bytes have been read, or EOF is
     * reached. This is useful for binary protocols where the message length is
     * known in advance (e.g., reading a length-prefixed message).
     *
     * Behavior:
     * - Reads until exactly $length bytes are accumulated
     * - Returns early if EOF is reached (may return fewer than $length bytes)
     * - Cooperatively suspends when waiting for data
     * - Respects timeout across all read attempts
     * - Returns empty string if $length is 0
     *
     * Difference from read():
     * - read($length): May return fewer bytes if stream would block
     * - readExactly($length): Keeps reading until $length bytes or EOF
     *
     * Use cases:
     * - Reading fixed-size binary headers
     * - Reading length-prefixed messages (read 4 bytes for length, then readExactly(length))
     * - Binary protocols with known message sizes
     * - Reading structured binary data
     *
     * Example:
     * ```php
     * // Read binary protocol message
     * $stream = new AsyncStream($socket);
     *
     * // Read 4-byte length prefix (big-endian)
     * $lengthBytes = $stream->readExactly(4, new Timeout(5.0));
     * $length = unpack('N', $lengthBytes)[1];
     *
     * // Read exact message body
     * $message = $stream->readExactly($length, new Timeout(30.0));
     * ```
     *
     * @param int $length Exact number of bytes to read
     * @param Timeout|null $timeout Optional timeout for the entire operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return string Exactly $length bytes, or fewer if EOF reached
     * @throws \Glueful\Async\Exceptions\TimeoutException If timeout expires before reading $length bytes
     * @throws \Exception If operation is cancelled
     */
    public function readExactly(int $length, ?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        $deadline = $timeout !== null ? (microtime(true) + max(0.0, $timeout->seconds)) : null;
        $data = '';

        // Keep reading until we have exactly $length bytes
        while (strlen($data) < $length) {
            // Check for cancellation before each read attempt
            if ($token !== null && $token->isCancelled()) {
                $token->throwIfCancelled();
            }

            // Check if timeout has been exceeded
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new \Glueful\Async\Exceptions\TimeoutException('async readExactly timeout');
            }

            // Check for EOF - return partial data if stream ended early
            if (feof($this->stream)) {
                return $data; // May be shorter than requested
            }

            // Read remaining bytes with updated timeout
            $remaining = $deadline !== null ? max(0.0, $deadline - microtime(true)) : null;
            $chunkTimeout = $remaining !== null ? new Timeout($remaining) : null;
            $data .= $this->read($length - strlen($data), $chunkTimeout, $token);
        }

        return $data;
    }

    /**
     * Writes a line of text to the stream with automatic newline.
     *
     * Convenience method that appends a newline character to the provided string
     * and writes it to the stream. This is useful for line-oriented text protocols.
     *
     * Behavior:
     * - Appends \n to the provided string
     * - Writes entire line + newline atomically
     * - Cooperatively suspends if stream would block
     * - Returns total bytes written (line length + 1 for newline)
     *
     * Line ending:
     * - Only appends LF (\n), not CRLF (\r\n)
     * - For protocols requiring CRLF, append \r manually or use write($line . "\r\n")
     *
     * Use cases:
     * - Sending commands in text protocols (SMTP, FTP, etc.)
     * - Writing line-delimited JSON (NDJSON)
     * - Writing log entries
     * - Sending chat messages
     *
     * Example:
     * ```php
     * $stream = new AsyncStream($socket);
     *
     * // Send SMTP command (would need \r\n for real SMTP)
     * $stream->writeLine("HELO example.com");
     *
     * // Write NDJSON
     * $stream->writeLine(json_encode(['event' => 'user.created', 'id' => 123]));
     * ```
     *
     * @param string $line Line content without trailing newline
     * @param Timeout|null $timeout Optional timeout for the write operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return int Total bytes written (strlen($line) + 1)
     * @throws \Glueful\Async\Exceptions\TimeoutException If timeout expires
     * @throws \Exception If operation is cancelled
     */
    public function writeLine(string $line, ?Timeout $timeout = null, ?CancellationToken $token = null): int
    {
        return $this->write($line . "\n", $timeout, $token);
    }
}
