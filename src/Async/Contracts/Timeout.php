<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts;

/**
 * Timeout value object for async operations.
 *
 * Timeout represents a duration limit for async operations like HTTP requests,
 * stream reads/writes, or sleeps. It's a simple value object that carries a
 * timeout duration in seconds (with microsecond precision via float).
 *
 * This class provides a type-safe way to pass timeout configurations throughout
 * the async framework, making method signatures clearer and preventing confusion
 * between timeout values and other numeric parameters.
 *
 * Usage:
 * ```php
 * // Create a 5-second timeout
 * $timeout = new Timeout(5.0);
 *
 * // Use with HTTP client
 * $task = $httpClient->sendAsync($request, $timeout);
 *
 * // Create sub-second timeouts (e.g., 500ms)
 * $shortTimeout = new Timeout(0.5);
 *
 * // Use with stream operations
 * $data = $stream->read(1024, $timeout);
 * ```
 *
 * Design rationale:
 * - Using a class instead of raw floats makes code more self-documenting
 * - Public property allows easy access without getter boilerplate
 * - Final class prevents extension (simple value objects shouldn't be extended)
 * - Float type supports sub-second precision for fine-grained control
 *
 * Common timeout values:
 * - `new Timeout(1.0)` - 1 second (quick operations)
 * - `new Timeout(5.0)` - 5 seconds (typical HTTP requests)
 * - `new Timeout(30.0)` - 30 seconds (long-running operations)
 * - `new Timeout(0.1)` - 100ms (performance-sensitive operations)
 */
final class Timeout
{
    /**
     * Creates a timeout with the specified duration.
     *
     * @param float $seconds Timeout duration in seconds (supports microsecond precision)
     *                       Examples: 1.0 (1s), 0.5 (500ms), 10.0 (10s)
     */
    public function __construct(public float $seconds)
    {
    }
}
