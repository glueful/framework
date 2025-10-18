<?php

declare(strict_types=1);

namespace Glueful\Async\Exceptions;

/**
 * Exception thrown when an async operation exceeds its timeout duration.
 *
 * TimeoutException is thrown when a Timeout object expires before an async
 * operation completes. This provides a way to enforce time limits on potentially
 * long-running operations like HTTP requests, database queries, or custom tasks.
 *
 * Timeout Mechanism:
 * - Timeout objects wrap expiration timestamps
 * - FiberScheduler checks timeouts during task execution
 * - HTTP client checks timeouts during request polling
 * - AsyncStream checks timeouts during I/O waits
 * - Tasks can check timeouts manually via Timeout::isExpired()
 *
 * When Thrown:
 * - HTTP request exceeds specified timeout duration
 * - Stream read/write operations take too long
 * - Scheduler operations (sleep, all, race) exceed timeout
 * - Custom task explicitly checks and detects expired timeout
 * - Long-running fiber doesn't yield within timeout period
 *
 * NOT Thrown For:
 * - Cancellation via CancellationToken - throws CancelledException instead
 * - Resource exhaustion - throws ResourceLimitException instead
 * - Operation completes just before timeout expires
 *
 * Usage Examples:
 * ```php
 * // Example 1: HTTP request with timeout
 * use Glueful\Async\Http\CurlMultiHttpClient;
 * use Glueful\Async\Timeout;
 * use Glueful\Async\Exceptions\TimeoutException;
 *
 * $httpClient = new CurlMultiHttpClient();
 * $request = new Request('GET', 'https://slow-api.example.com/data');
 * $timeout = Timeout::seconds(5); // 5 second timeout
 *
 * try {
 *     $task = $httpClient->sendAsync($request, $timeout);
 *     $response = $task->getResult();
 *     // Process response
 * } catch (TimeoutException $e) {
 *     // Request took longer than 5 seconds
 *     logger()->warning('Request timeout', [
 *         'url' => (string) $request->getUri(),
 *         'timeout' => 5,
 *         'error' => $e->getMessage()
 *     ]);
 * }
 *
 * // Example 2: Scheduler operations with timeout
 * $scheduler = new FiberScheduler();
 * $timeout = Timeout::seconds(10);
 *
 * $task1 = $scheduler->spawn(function() use ($scheduler) {
 *     $scheduler->sleep(15); // Takes too long
 *     return 'completed';
 * });
 *
 * try {
 *     $results = $scheduler->all([$task1], $timeout);
 * } catch (TimeoutException $e) {
 *     echo "Tasks did not complete within 10 seconds\n";
 * }
 *
 * // Example 3: Manual timeout checking in custom task
 * $timeout = Timeout::seconds(30);
 *
 * $task = $scheduler->spawn(function() use ($scheduler, $timeout) {
 *     for ($i = 0; $i < 100; $i++) {
 *         // Check timeout at regular intervals
 *         if ($timeout->isExpired()) {
 *             throw new TimeoutException('Processing timeout exceeded');
 *         }
 *
 *         processItem($i);
 *         $scheduler->sleep(0.5); // Each item takes 0.5s
 *     }
 * });
 *
 * try {
 *     $result = $task->getResult();
 * } catch (TimeoutException $e) {
 *     echo "Processing timed out after 30 seconds\n";
 * }
 *
 * // Example 4: Stream operations with timeout
 * $stream = new AsyncStream($resource);
 * $timeout = Timeout::seconds(5);
 *
 * try {
 *     $data = $stream->read(8192, $timeout);
 * } catch (TimeoutException $e) {
 *     fclose($resource);
 *     logger()->error('Stream read timeout', ['error' => $e->getMessage()]);
 * }
 *
 * // Example 5: Timeout with fallback strategy
 * $timeout = Timeout::seconds(3);
 *
 * try {
 *     $response = $httpClient->sendAsync($request, $timeout)->getResult();
 *     $data = json_decode($response->getBody(), true);
 * } catch (TimeoutException $e) {
 *     // Use cached data as fallback
 *     $data = cache()->get('api_data_fallback');
 *     logger()->warning('Using cached fallback due to timeout');
 * }
 *
 * // Example 6: Different timeout strategies
 * // Fast timeout for cache
 * $cacheTimeout = Timeout::milliseconds(100);
 *
 * // Medium timeout for database
 * $dbTimeout = Timeout::seconds(5);
 *
 * // Long timeout for external API
 * $apiTimeout = Timeout::seconds(30);
 *
 * try {
 *     $cachedData = getCachedData($cacheTimeout);
 * } catch (TimeoutException $e) {
 *     try {
 *         $dbData = getFromDatabase($dbTimeout);
 *     } catch (TimeoutException $e) {
 *         $apiData = getFromExternalAPI($apiTimeout);
 *     }
 * }
 * ```
 *
 * Best Practices:
 * - Set realistic timeouts based on expected operation duration
 * - Always handle TimeoutException to prevent user-facing errors
 * - Use shorter timeouts for operations with fallback strategies
 * - Log timeout events for performance monitoring
 * - Consider exponential backoff when retrying timed-out operations
 * - Check timeouts manually in long-running loops
 * - Use different timeout values for different operation types
 *
 * Timeout Factory Methods:
 * - `Timeout::seconds(int $seconds)` - Timeout after N seconds
 * - `Timeout::milliseconds(int $ms)` - Timeout after N milliseconds
 * - `Timeout::never()` - No timeout (useful for disabling timeouts)
 *
 * vs CancelledException:
 * - TimeoutException: Time-based limit exceeded (passive/automatic)
 * - CancelledException: Explicit cancellation requested (active/manual)
 *
 * Debugging:
 * - Check if timeout duration is too short for the operation
 * - Verify network latency isn't causing consistent timeouts
 * - Use profiling to find slow operations that need optimization
 * - Monitor timeout frequency to identify systemic issues
 * - Check if operations can be optimized to complete faster
 *
 * @see \Glueful\Async\Contracts\Timeout
 * @see \Glueful\Async\Timeout
 * @see \Glueful\Async\FiberScheduler
 * @see \Glueful\Async\Http\CurlMultiHttpClient
 * @see \Glueful\Async\IO\AsyncStream
 */
class TimeoutException extends AsyncException
{
}
