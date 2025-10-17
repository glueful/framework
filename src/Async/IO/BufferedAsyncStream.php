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
     * Reads data from the stream with buffering.
     *
     * Attempts to serve data from the internal read buffer first. If more data
     * is needed, reads at least bufferSize bytes from the underlying stream
     * to satisfy the request and prefetch for future reads.
     *
     * @param int $length Maximum number of bytes to read
     * @param Timeout|null $timeout Optional timeout for the operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return string The data read
     */
    public function read(int $length, ?Timeout $timeout = null, ?CancellationToken $token = null): string
    {
        if ($length <= 0) {
            return '';
        }

        // Serve from buffer first
        if ($this->readBuffer !== '') {
            $chunk = substr($this->readBuffer, 0, $length);
            $this->readBuffer = substr($this->readBuffer, strlen($chunk));
            if (strlen($chunk) >= $length) {
                return $chunk;
            }
            // Need more data; accumulate
            $needed = $length - strlen($chunk);
            $more = $this->stream->read(max($this->bufferSize, $needed), $timeout, $token);
            $this->readBuffer .= substr($more, $needed);
            return $chunk . substr($more, 0, $needed);
        }

        // Empty buffer: read at least bufferSize
        $data = $this->stream->read(max($this->bufferSize, $length), $timeout, $token);
        if (strlen($data) <= $length) {
            return $data;
        }

        $ret = substr($data, 0, $length);
        $this->readBuffer = substr($data, $length);
        return $ret;
    }

    /**
     * Writes data to the stream with buffering.
     *
     * Accumulates data in the internal write buffer. When the buffer reaches
     * or exceeds bufferSize, flushes full chunks to the underlying stream.
     * Call flush() to force all buffered data to be written immediately.
     *
     * @param string $buffer The data to write
     * @param Timeout|null $timeout Optional timeout for the operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return int Number of bytes written (immediately, not buffered)
     */
    public function write(string $buffer, ?Timeout $timeout = null, ?CancellationToken $token = null): int
    {
        $this->writeBuffer .= $buffer;
        $written = 0;

        while (strlen($this->writeBuffer) >= $this->bufferSize) {
            $chunk = substr($this->writeBuffer, 0, $this->bufferSize);
            $n = $this->stream->write($chunk, $timeout, $token);
            $written += $n;
            $this->writeBuffer = substr($this->writeBuffer, $n);
            if ($n < strlen($chunk)) {
                // Partial write; stop and leave remaining in buffer
                break;
            }
        }

        return $written;
    }

    /**
     * Flushes the write buffer to the underlying stream.
     *
     * Forces all buffered write data to be written to the underlying stream
     * immediately. This should be called before closing or when you need to
     * ensure data is written (e.g., before reading a response).
     *
     * @param Timeout|null $timeout Optional timeout for the operation
     * @param CancellationToken|null $token Optional cancellation token
     * @return void
     */
    public function flush(?Timeout $timeout = null, ?CancellationToken $token = null): void
    {
        while ($this->writeBuffer !== '') {
            $n = $this->stream->write($this->writeBuffer, $timeout, $token);
            $this->writeBuffer = substr($this->writeBuffer, $n);
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
