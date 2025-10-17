<?php

declare(strict_types=1);

namespace Glueful\Async\Http;

use Glueful\Async\Contracts\Http\HttpClient;
use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\Timeout;
use Glueful\Async\Task\FiberTask;
use Glueful\Async\Internal\SleepOp;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Asynchronous HTTP client using curl_multi for non-blocking requests.
 *
 * CurlMultiHttpClient provides cooperative async HTTP requests by using PHP's
 * curl_multi extension. Each request runs in a fiber that yields control while
 * waiting for network I/O, allowing the scheduler to multiplex multiple HTTP
 * requests concurrently.
 *
 * How it works:
 * 1. Creates a curl_multi handle for each request
 * 2. Polls the handle with curl_multi_exec() in a loop
 * 3. Yields control (via SleepOp) when request is still running
 * 4. Scheduler resumes the fiber after brief delay (10ms)
 * 5. Returns PSR-7 response when complete
 *
 * This approach allows multiple HTTP requests to execute concurrently without
 * blocking, as the scheduler can switch between requests while each waits for
 * network I/O.
 *
 * Features:
 * - Non-blocking concurrent HTTP requests
 * - Timeout support via Timeout objects
 * - Full PSR-7 request/response compatibility
 * - Automatic metrics collection (start, complete, failed)
 * - Support for all HTTP methods (GET, POST, PUT, DELETE, etc.)
 * - Custom header support
 * - Automatic redirect following
 *
 * Usage:
 * ```php
 * $client = new CurlMultiHttpClient($metrics);
 * $scheduler = new FiberScheduler();
 *
 * // Single async request
 * $request = new Request('GET', 'https://api.example.com/users');
 * $task = $client->sendAsync($request);
 * $response = $scheduler->all([$task])[0];
 *
 * // Multiple concurrent requests
 * $requests = [
 *     new Request('GET', 'https://api.example.com/users'),
 *     new Request('GET', 'https://api.example.com/posts'),
 *     new Request('GET', 'https://api.example.com/comments'),
 * ];
 * $tasks = $client->poolAsync($requests);
 * $responses = $scheduler->all($tasks);
 *
 * // With timeout
 * $timeout = new class implements Timeout {
 *     public float $seconds = 5.0;
 * };
 * $task = $client->sendAsync($request, $timeout);
 * ```
 *
 * Performance:
 * - 10ms polling interval balances CPU usage and responsiveness
 * - curl_multi handles DNS, TCP, TLS handshakes efficiently
 * - Concurrent requests share event loop overhead
 * - Metrics collection has minimal overhead (or zero with NullMetrics)
 *
 * The polling approach is simple but effective for moderate concurrency (dozens
 * to hundreds of concurrent requests). For higher concurrency or more efficient
 * I/O multiplexing, consider using stream-based async HTTP clients with
 * stream_select() integration.
 */
final class CurlMultiHttpClient implements HttpClient
{
    /**
     * Creates an async HTTP client with optional metrics collection.
     *
     * @param Metrics|null $metrics Metrics collector for observability.
     *                              Defaults to NullMetrics (no-op).
     * @param float $pollIntervalSeconds Polling interval used while waiting for curl_multi
     *                                   to make progress (default 0.01s = 10ms). Lower values
     *                                   improve responsiveness at the cost of higher CPU.
     */
    public function __construct(private ?Metrics $metrics = null, private float $pollIntervalSeconds = 0.01)
    {
        $this->metrics = $this->metrics ?? new NullMetrics();
        // Ensure a sane, positive poll interval
        if ($this->pollIntervalSeconds <= 0) {
            $this->pollIntervalSeconds = 0.001; // 1ms minimum
        }
    }

    /**
     * Sends an HTTP request asynchronously, returning a Task.
     *
     * Creates a FiberTask that executes the HTTP request using curl_multi in
     * a non-blocking manner. The fiber yields control while waiting for the
     * request to complete, allowing the scheduler to multiplex other tasks.
     *
     * The request executes in these stages:
     * 1. Metrics: Records request start event
     * 2. Setup: Creates curl_multi handle and configures request
     * 3. Polling: Repeatedly checks request status, yielding between polls
     * 4. Completion: Extracts response and records metrics
     * 5. Error handling: Catches and records failures
     *
     * @param RequestInterface $request PSR-7 HTTP request to send
     * @param Timeout|null $timeout Optional timeout for request (both connect and total)
     * @param \Glueful\Async\Contracts\CancellationToken|null $token Optional cancellation token
     * @return Task A FiberTask that resolves to a PSR-7 Response
     *
     * @throws \RuntimeException If curl encounters an error during execution
     */
    public function sendAsync(
        RequestInterface $request,
        ?Timeout $timeout = null,
        ?\Glueful\Async\Contracts\CancellationToken $token = null
    ): Task {
        $metrics = $this->metrics;
        return new FiberTask(function () use ($request, $timeout, $metrics, $token) {
            // Step 1: Record request start for metrics/observability
            $metrics->httpRequestStarted($request);
            $start = microtime(true);
            $multi = null;
            $ch = null;

            try {
                // Step 2: Initialize curl_multi for non-blocking execution
                $multi = curl_multi_init();
                $ch = $this->buildHandle($request, $timeout);
                curl_multi_add_handle($multi, $ch);

                // Step 3: Poll curl_multi until request completes
                // This is the cooperative async magic: we yield control while waiting
                do {
                    $status = curl_multi_exec($multi, $running);
                    if ($running) {
                        // Request still in progress - yield for configured interval
                        // Scheduler will resume us after delay, allowing other tasks to run
                        $token?->throwIfCancelled();
                        \Fiber::suspend(new SleepOp(microtime(true) + $this->pollIntervalSeconds));
                    }
                } while ($running && $status === CURLM_OK);

                // Step 4: Extract response data and clean up curl resources
                $raw = curl_multi_getcontent($ch);
                $err = curl_error($ch);
                $info = curl_getinfo($ch);
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                $ch = null;
                curl_multi_close($multi);
                $multi = null;

                // Step 5: Check for curl errors and build response
                if ($err !== '') {
                    throw new \RuntimeException('curl error: ' . $err);
                }
                $statusCode = (int)($info['http_code'] ?? 200);
                $response = $this->buildResponse($raw, $statusCode);

                // Step 6: Record successful completion with duration
                $dur = (microtime(true) - $start) * 1000.0;
                $metrics->httpRequestCompleted($request, $statusCode, $dur);
                return $response;
            } catch (\Throwable $e) {
                // Record failure with duration before re-throwing
                $dur = (microtime(true) - $start) * 1000.0;
                $metrics->httpRequestFailed($request, $e, $dur);
                // Cleanup curl handles on error/cancellation
                if ($multi !== null && $ch !== null) {
                    @curl_multi_remove_handle($multi, $ch);
                }
                if ($ch !== null) {
                    @curl_close($ch);
                }
                if ($multi !== null) {
                    @curl_multi_close($multi);
                }
                throw $e;
            }
        }, $this->metrics, 'http:' . $request->getMethod() . ' ' . (string)$request->getUri(), $token);
    }

    /**
     * Creates a pool of concurrent async HTTP requests.
     *
     * This is a convenience method that creates multiple async tasks for
     * concurrent execution. When passed to FiberScheduler->all(), all requests
     * will execute concurrently, sharing the event loop and yielding control
     * to each other while waiting for network I/O.
     *
     * Example:
     * ```php
     * $requests = [
     *     new Request('GET', 'https://api.example.com/users'),
     *     new Request('GET', 'https://api.example.com/posts'),
     *     new Request('GET', 'https://api.example.com/comments'),
     * ];
     * $tasks = $client->poolAsync($requests);
     * $responses = $scheduler->all($tasks); // All execute concurrently
     * ```
     *
     * @param array<int, RequestInterface> $requests Array of PSR-7 HTTP requests
     * @param Timeout|null $timeout Optional timeout applied to all requests
     * @param \Glueful\Async\Contracts\CancellationToken|null $token Cancellation token
     * @return array<int, Task> Array of FiberTasks (same keys as input array)
     */
    public function poolAsync(
        array $requests,
        ?Timeout $timeout = null,
        ?\Glueful\Async\Contracts\CancellationToken $token = null
    ): array {
        $tasks = [];
        foreach ($requests as $i => $req) {
            $tasks[$i] = $this->sendAsync($req, $timeout, $token);
        }
        return $tasks;
    }

    /**
     * Executes a single HTTP request synchronously (blocking).
     *
     * This is a legacy/utility method that performs a blocking HTTP request
     * using curl_exec(). It does not use fibers or yielding, so it blocks
     * the entire thread until the request completes.
     *
     * Note: This method is not currently used by the async flow (sendAsync uses
     * curl_multi instead). It may be useful for synchronous fallback scenarios
     * or testing.
     *
     * @param RequestInterface $request PSR-7 HTTP request to execute
     * @param Timeout|null $timeout Optional timeout for the request
     * @return Response PSR-7 HTTP response
     *
     * @throws \RuntimeException If curl encounters an error
     */
    private function executeSingle(RequestInterface $request, ?Timeout $timeout): Response
    {
        $ch = $this->buildHandle($request, $timeout);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('curl error: ' . $err);
        }
        $info = curl_getinfo($ch);
        curl_close($ch);
        $status = (int)($info['http_code'] ?? 200);
        return $this->buildResponse($raw, $status);
    }

    /**
     * Builds a configured curl handle from a PSR-7 request.
     *
     * This method translates PSR-7 request properties into curl options,
     * including:
     * - URI and HTTP method
     * - Request body (for POST, PUT, etc.)
     * - Custom headers
     * - Timeout settings (both connection and total request timeout)
     * - Response handling (return transfer, follow redirects)
     *
     * Configuration:
     * - CURLOPT_RETURNTRANSFER: Return response body as string
     * - CURLOPT_FOLLOWLOCATION: Automatically follow redirects
     * - CURLOPT_HEADER: Exclude headers from response body
     * - CURLOPT_TIMEOUT_MS: Total request timeout in milliseconds
     * - CURLOPT_CONNECTTIMEOUT_MS: Connection timeout in milliseconds
     *
     * @param RequestInterface $request PSR-7 HTTP request
     * @param Timeout|null $timeout Optional timeout for both connect and total duration
     * @return \CurlHandle Configured curl handle ready for execution
     */
    private function buildHandle(RequestInterface $request, ?Timeout $timeout)
    {
        // Initialize curl with request URI
        $ch = curl_init((string)$request->getUri());

        // Configure response handling
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  // Return body as string
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  // Follow redirects
        curl_setopt($ch, CURLOPT_HEADER, false);         // Don't include headers in body

        // Configure HTTP method and request body
        $method = strtoupper($request->getMethod());
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            $body = (string)$request->getBody();
            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        // Configure request headers
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $v) {
                $headers[] = $name . ': ' . $v;
            }
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // Configure timeouts (applies to both connection and total duration)
        if ($timeout !== null) {
            $ms = (int)($timeout->seconds * 1000);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $ms);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $ms);
        }

        return $ch;
    }

    /**
     * Builds a PSR-7 Response from curl response data.
     *
     * Creates a simple Response object with status code and body. Headers are
     * currently not extracted from the curl response (CURLOPT_HEADER is false),
     * so the response contains only the body content and status code.
     *
     * Note: Future enhancement could parse response headers using CURLOPT_HEADERFUNCTION
     * or by enabling CURLOPT_HEADER and parsing the raw response.
     *
     * @param string $body Response body content
     * @param int $status HTTP status code (e.g., 200, 404, 500)
     * @return Response PSR-7 response object
     */
    private function buildResponse(string $body, int $status): Response
    {
        return new Response($status, [], $body);
    }
}
