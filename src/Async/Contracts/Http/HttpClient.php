<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts\Http;

use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\Timeout;
use Glueful\Async\Contracts\CancellationToken;
use Psr\Http\Message\RequestInterface;

/**
 * Contract for asynchronous HTTP client implementations.
 *
 * HttpClient defines the interface for async HTTP request handling. Implementations
 * provide non-blocking HTTP requests that can be executed concurrently when used
 * with a Scheduler.
 *
 * The interface is deliberately minimal, focusing on core async capabilities:
 * - Send single async requests
 * - Create pools of concurrent requests
 * - Optional timeout support
 * - Full PSR-7 compatibility
 *
 * Key characteristics:
 * - **Non-blocking**: Returns immediately with a Task (doesn't wait for response)
 * - **PSR-7 compatible**: Uses standard RequestInterface and Response objects
 * - **Concurrent by default**: Multiple requests can execute simultaneously
 * - **Timeout support**: Optional per-request timeout control
 * - **Scheduler-friendly**: Tasks integrate with FiberScheduler for multiplexing
 *
 * Primary implementation: **CurlMultiHttpClient**
 * - Uses curl_multi for non-blocking HTTP
 * - Polls with 10ms intervals, yielding between polls
 * - Supports all HTTP methods, headers, and body content
 * - Automatic redirect following
 * - Metrics collection support
 *
 * Usage patterns:
 * ```php
 * $httpClient = new CurlMultiHttpClient();
 * $scheduler = new FiberScheduler();
 *
 * // Pattern 1: Single async request
 * $request = new Request('GET', 'https://api.example.com/users/1');
 * $task = $httpClient->sendAsync($request);
 * $response = $scheduler->all([$task])[0];
 * echo $response->getBody();
 *
 * // Pattern 2: Multiple concurrent requests
 * $requests = [
 *     new Request('GET', 'https://api.example.com/users'),
 *     new Request('GET', 'https://api.example.com/posts'),
 *     new Request('GET', 'https://api.example.com/comments'),
 * ];
 * $tasks = $httpClient->poolAsync($requests);
 * $responses = $scheduler->all($tasks);
 * [$users, $posts, $comments] = array_map(fn($r) => json_decode($r->getBody()), $responses);
 *
 * // Pattern 3: With timeout
 * $timeout = new Timeout(5.0); // 5 second timeout
 * $task = $httpClient->sendAsync($request, $timeout);
 * try {
 *     $response = $scheduler->all([$task])[0];
 * } catch (\RuntimeException $e) {
 *     // Handle timeout or network error
 * }
 *
 * // Pattern 4: Mix requests with other async operations
 * $apiTask = $httpClient->sendAsync(new Request('GET', 'https://api.example.com/data'));
 * $dbTask = $scheduler->spawn(fn() => queryDatabase());
 * $cacheTask = $scheduler->spawn(fn() => fetchFromCache());
 * [$apiData, $dbData, $cacheData] = $scheduler->all([$apiTask, $dbTask, $cacheTask]);
 * ```
 *
 * Performance benefits:
 * - Concurrent execution: Multiple requests don't wait for each other
 * - Resource efficient: Single event loop handles all requests
 * - Low overhead: Minimal polling cost with cooperative scheduling
 * - Scalable: Hundreds of concurrent requests with minimal resources
 *
 * Relationship to other contracts:
 * - **Task**: HTTP operations return tasks for async execution
 * - **Scheduler**: Executes HTTP tasks concurrently with other async work
 * - **Timeout**: Controls maximum duration for HTTP requests
 */
interface HttpClient
{
    /**
     * Sends an HTTP request asynchronously.
     *
     * Creates and returns a Task that will execute the HTTP request when run
     * by a scheduler. The task yields control while waiting for the network,
     * allowing other tasks to execute concurrently.
     *
     * The request is PSR-7 compliant, supporting:
     * - All HTTP methods (GET, POST, PUT, DELETE, PATCH, etc.)
     * - Custom headers
     * - Request body (for POST, PUT, etc.)
     * - URI with query parameters
     *
     * Example:
     * ```php
     * $request = new Request('POST', 'https://api.example.com/users', [
     *     'Content-Type' => 'application/json',
     * ], json_encode(['name' => 'John', 'email' => 'john@example.com']));
     *
     * $timeout = new Timeout(10.0);
     * $task = $httpClient->sendAsync($request, $timeout);
     * $response = $scheduler->all([$task])[0];
     * ```
     *
     * @param RequestInterface $request PSR-7 HTTP request to send
     * @param Timeout|null $timeout Optional timeout for the request (connect + total)
     * @param CancellationToken|null $token Optional cooperative cancellation token
     * @return Task A task that resolves to a PSR-7 Response
     */
    public function sendAsync(RequestInterface $request, ?Timeout $timeout = null, ?CancellationToken $token = null): Task;

    /**
     * Creates a pool of concurrent async HTTP requests.
     *
     * Convenience method that creates multiple async tasks for concurrent
     * execution. When passed to a scheduler, all requests execute concurrently,
     * sharing the event loop and maximizing throughput.
     *
     * This is functionally equivalent to calling sendAsync() for each request,
     * but provides a cleaner API for the common pattern of executing multiple
     * requests concurrently.
     *
     * Array keys are preserved in the returned tasks array, making it easy to
     * map requests to their corresponding responses:
     *
     * ```php
     * $requests = [
     *     'users' => new Request('GET', 'https://api.example.com/users'),
     *     'posts' => new Request('GET', 'https://api.example.com/posts'),
     *     'comments' => new Request('GET', 'https://api.example.com/comments'),
     * ];
     * $tasks = $httpClient->poolAsync($requests);
     * $responses = $scheduler->all($tasks);
     *
     * // Access by key
     * $usersResponse = $responses['users'];
     * $postsResponse = $responses['posts'];
     * ```
     *
     * @param array<int, RequestInterface> $requests Array of PSR-7 HTTP requests
     * @param Timeout|null $timeout Optional timeout applied to all requests
     * @param CancellationToken|null $token Optional cancellation token applied to all requests
     * @return array<int, Task> Array of tasks (same keys as input)
     */
    public function poolAsync(array $requests, ?Timeout $timeout = null, ?CancellationToken $token = null): array;
}
