<?php

declare(strict_types=1);

namespace Glueful\Async\Exceptions;

/**
 * Exception thrown when async stream I/O operations fail.
 *
 * StreamException is thrown by AsyncStream and related I/O operations when
 * stream operations encounter errors. This includes invalid resources, closed
 * streams, I/O errors, and other stream-related problems.
 *
 * When Thrown:
 * - Invalid resource passed to AsyncStream constructor
 * - Stream is closed during operation
 * - fread()/fwrite() encounters persistent errors
 * - Stream becomes invalid or corrupted
 * - Non-resource value provided where stream expected
 * - Stream metadata operations fail
 *
 * Common Scenarios:
 * - File handle closed prematurely
 * - Socket connection dropped
 * - Pipe broken (SIGPIPE)
 * - Resource limit reached (too many open files)
 * - Permission denied on stream operations
 * - Disk full during write operations
 *
 * Usage Examples:
 * ```php
 * // Example 1: Handle stream creation errors
 * try {
 *     $fp = fopen('/path/to/file.txt', 'r');
 *     if ($fp === false) {
 *         throw new StreamException('Failed to open file');
 *     }
 *     $stream = new AsyncStream($fp);
 * } catch (StreamException $e) {
 *     logger()->error('Stream error', ['error' => $e->getMessage()]);
 * }
 *
 * // Example 2: Handle I/O errors with cleanup
 * $stream = new AsyncStream($resource);
 *
 * try {
 *     $data = $stream->read(1024);
 *     $stream->write($processedData);
 * } catch (StreamException $e) {
 *     // Clean up before propagating
 *     fclose($resource);
 *     logger()->error('Stream I/O failed', ['error' => $e->getMessage()]);
 *     throw $e;
 * }
 *
 * // Example 3: Validate resource before use
 * function createAsyncStream($resource): AsyncStream
 * {
 *     if (!is_resource($resource)) {
 *         throw new StreamException('Expected stream resource, got ' . gettype($resource));
 *     }
 *
 *     if (feof($resource)) {
 *         throw new StreamException('Stream is already at EOF');
 *     }
 *
 *     return new AsyncStream($resource);
 * }
 *
 * // Example 4: Handle socket errors
 * $socket = stream_socket_client('tcp://api.example.com:80');
 *
 * if ($socket === false) {
 *     throw new StreamException('Failed to connect to socket');
 * }
 *
 * $stream = new AsyncStream($socket);
 *
 * try {
 *     $stream->write("GET / HTTP/1.1\r\n\r\n");
 *     $response = $stream->read(8192);
 * } catch (StreamException $e) {
 *     fclose($socket);
 *     logger()->error('Socket communication failed', ['error' => $e->getMessage()]);
 * }
 *
 * // Example 5: Buffered streams with error handling
 * try {
 *     $stream = new AsyncStream(fopen('large-file.txt', 'r'));
 *     $buffered = new BufferedAsyncStream($stream, 16384);
 *
 *     while (!feof($stream->getResource())) {
 *         $chunk = $buffered->read(1024);
 *         processChunk($chunk);
 *     }
 * } catch (StreamException $e) {
 *     logger()->error('Buffered read failed', ['error' => $e->getMessage()]);
 * }
 * ```
 *
 * Prevention:
 * - Always validate resources before creating AsyncStream
 * - Check return values from fopen(), stream_socket_client(), etc.
 * - Use try-finally or try-catch-finally for cleanup
 * - Monitor resource limits (ulimit -n on Linux)
 * - Handle SIGPIPE signals appropriately
 * - Check disk space before large writes
 *
 * Debugging:
 * - Check if resource is still valid: is_resource($resource)
 * - Check stream metadata: stream_get_meta_data($resource)
 * - Verify file/socket exists and is accessible
 * - Check system error logs for I/O errors
 * - Monitor file descriptor usage: lsof -p <pid>
 *
 * @see \Glueful\Async\IO\AsyncStream
 * @see \Glueful\Async\IO\BufferedAsyncStream
 */
class StreamException extends AsyncException
{
}
