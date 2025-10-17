<?php

declare(strict_types=1);

namespace Glueful\Async\Exceptions;

/**
 * Exception thrown when an async HTTP operation fails.
 *
 * HttpException is thrown by the async HTTP client (CurlMultiHttpClient) when
 * HTTP requests encounter errors. This includes connection failures, curl errors,
 * network timeouts, DNS resolution failures, and other HTTP-level problems.
 *
 * When Thrown:
 * - curl_error() returns a non-empty error message
 * - HTTP status code is 0 (connection failed)
 * - Network connection cannot be established
 * - DNS resolution fails
 * - SSL/TLS handshake fails
 * - Request is malformed
 * - curl_multi_exec() encounters errors
 *
 * NOT Thrown For:
 * - HTTP error status codes (4xx, 5xx) - these return normal responses
 * - Timeouts via Timeout objects - throws TimeoutException instead
 * - Cancellation via tokens - throws CancelledException instead
 *
 * Usage Examples:
 * ```php
 * // Example 1: Handle HTTP client errors
 * $httpClient = new CurlMultiHttpClient();
 * $request = new Request('GET', 'https://api.example.com/users');
 *
 * try {
 *     $task = $httpClient->sendAsync($request);
 *     $response = $task->getResult();
 * } catch (HttpException $e) {
 *     // Connection failed, DNS error, etc.
 *     logger()->error('HTTP request failed', [
 *         'error' => $e->getMessage(),
 *         'url' => (string) $request->getUri()
 *     ]);
 * }
 *
 * // Example 2: Retry on HTTP errors
 * $maxRetries = 3;
 * $attempt = 0;
 *
 * while ($attempt < $maxRetries) {
 *     try {
 *         $response = $httpClient->sendAsync($request)->getResult();
 *         break; // Success
 *     } catch (HttpException $e) {
 *         $attempt++;
 *         if ($attempt >= $maxRetries) {
 *             throw $e; // Give up
 *         }
 *         sleep(1); // Wait before retry
 *     }
 * }
 *
 * // Example 3: Differentiate HTTP vs other async errors
 * try {
 *     $response = $httpClient->sendAsync($request, $timeout)->getResult();
 * } catch (HttpException $e) {
 *     // Network/connection error
 *     logger()->error('Network error', ['error' => $e->getMessage()]);
 * } catch (TimeoutException $e) {
 *     // Request timed out
 *     logger()->warning('Request timeout', ['error' => $e->getMessage()]);
 * } catch (AsyncException $e) {
 *     // Other async error
 *     logger()->error('Async error', ['error' => $e->getMessage()]);
 * }
 *
 * // Example 4: Pool of requests with error handling
 * $requests = [
 *     new Request('GET', 'https://api1.example.com/data'),
 *     new Request('GET', 'https://api2.example.com/data'),
 *     new Request('GET', 'https://api3.example.com/data'),
 * ];
 *
 * $tasks = $httpClient->poolAsync($requests);
 * $results = [];
 *
 * foreach ($tasks as $i => $task) {
 *     try {
 *         $results[$i] = $task->getResult();
 *     } catch (HttpException $e) {
 *         $results[$i] = null; // Failed request
 *         logger()->warning('Request failed', [
 *             'index' => $i,
 *             'error' => $e->getMessage()
 *         ]);
 *     }
 * }
 * ```
 *
 * Common Error Messages:
 * - "curl error: Could not resolve host" - DNS failure
 * - "curl error: Connection timed out" - Network timeout
 * - "curl error: Failed to connect" - Connection refused
 * - "curl error: SSL certificate problem" - SSL/TLS error
 * - "curl error: Operation timed out" - Request timeout
 *
 * Debugging:
 * - Check the exception message for specific curl error
 * - Verify DNS resolution works (ping the hostname)
 * - Check firewall/proxy settings
 * - Test with a simple curl command in terminal
 * - Enable verbose logging in curl if needed
 *
 * @see \Glueful\Async\Http\CurlMultiHttpClient
 * @see \Glueful\Async\Contracts\Http\HttpClient
 */
class HttpException extends AsyncException
{
}
