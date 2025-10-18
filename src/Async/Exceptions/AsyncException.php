<?php

declare(strict_types=1);

namespace Glueful\Async\Exceptions;

/**
 * Base exception for all async-related errors.
 *
 * AsyncException serves as the root of the async exception hierarchy, allowing
 * code to catch all async-related failures with a single exception type while
 * still providing specific exception types for granular error handling.
 *
 * Exception Hierarchy:
 * - AsyncException (base)
 *   - CancelledException (cooperative cancellation)
 *   - HttpException (HTTP client errors)
 *   - StreamException (stream I/O errors)
 *   - TimeoutException (timeout errors)
 *   - ResourceLimitException (resource exhaustion)
 *
 * Usage:
 * ```php
 * try {
 *     $scheduler = new FiberScheduler();
 *     $result = $scheduler->all($tasks);
 * } catch (TimeoutException $e) {
 *     // Handle specific timeout
 *     logger()->warning('Task timeout', ['error' => $e->getMessage()]);
 * } catch (AsyncException $e) {
 *     // Handle all other async errors
 *     logger()->error('Async operation failed', ['error' => $e->getMessage()]);
 * }
 * ```
 *
 * Benefits of Exception Hierarchy:
 * - Catch all async errors: `catch (AsyncException $e)`
 * - Catch specific errors: `catch (TimeoutException $e)`
 * - Type-safe error handling in catch blocks
 * - Clear exception semantics in method signatures
 * - Better IDE autocomplete and static analysis
 */
class AsyncException extends \RuntimeException
{
}
