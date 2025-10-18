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
use Glueful\Async\Contracts\Http\HttpStreamingClient;

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
final class CurlMultiHttpClient implements HttpClient, HttpStreamingClient
{
    /**
     * Shared curl_multi handle used by all instances.
     *
     * Using a single shared handle allows multiple HTTP requests across different
     * client instances to be multiplexed together, improving efficiency and reducing
     * resource usage. The handle is initialized lazily on first use.
     *
     * @var \CurlMultiHandle|null
     */
    private static $sharedMulti = null;

    /**
     * Registry tracking active curl handles and their completion state.
     *
     * Maps curl handle IDs (integers) to their state information:
     * - done: Whether the request has completed
     * - handle: The curl handle (null after cleanup)
     * - raw: Raw response data including headers and body
     * - err: Error message if request failed
     * - info: Metadata from curl_getinfo()
     *
     * Entries are added when requests start and removed after completion.
     *
     * @var array<int, array{
     *   done: bool,
     *   handle: \CurlHandle|null,
     *   raw: string|null,
     *   err: string|null,
     *   info: array<string,mixed>|null
     * }>
     */
    private static array $registry = [];

    /**
     * Guard flag preventing concurrent curl_multi_exec() calls.
     *
     * Ensures only one fiber pumps the multi handle at a time to avoid
     * race conditions and undefined behavior from concurrent curl operations.
     *
     * @var bool
     */
    private static bool $pumping = false;

    /**
     * Count of currently active curl handles in the shared multi handle.
     *
     * Used for concurrency limiting when maxConcurrent is set. Incremented
     * when handles are added, decremented when they complete or are removed.
     *
     * @var int
     */
    private static int $activeHandles = 0;
    /**
     * Creates an async HTTP client with optional metrics collection.
     *
     * @param Metrics|null $metrics Metrics collector for observability.
     *                              Defaults to NullMetrics (no-op).
     * @param float $pollIntervalSeconds Polling interval used while waiting for curl_multi
     *                                   to make progress (default 0.01s = 10ms). Lower values
     *                                   improve responsiveness at the cost of higher CPU.
     */
    public function __construct(
        private ?Metrics $metrics = null,
        private float $pollIntervalSeconds = 0.01,
        private int $maxRetries = 0,
        private float $retryDelaySeconds = 0.0,
        /** @var array<int,int> */ private array $retryOnStatus = [],
        private int $maxConcurrent = 0
    ) {
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
                $attempt = 0;
                // Retry loop with break conditions inside (return on success, continue on retry)
                /** @phpstan-ignore-next-line (loop has explicit return/continue break conditions) */
                while (true) {
                    // Step 2: Initialize or reuse shared curl_multi for non-blocking execution
                    static $sharedMulti = null;
                    static $activeHandles = 0;
                    if (self::$sharedMulti === null) {
                        self::$sharedMulti = curl_multi_init();
                    }
                    if ($this->maxConcurrent > 0) {
                        while ($activeHandles >= $this->maxConcurrent) {
                            $token?->throwIfCancelled();
                            \Fiber::suspend(new SleepOp(microtime(true) + $this->pollIntervalSeconds, $token));
                        }
                    }
                    $ch = $this->buildHandle($request, $timeout);
                    // Enable header capture for parsing
                    curl_setopt($ch, CURLOPT_HEADER, true);
                    $id = (int) $ch;
                    self::$registry[$id] = [
                        'done' => false,
                        'raw' => null,
                        'err' => null,
                        'info' => null,
                        'handle' => $ch
                    ];
                    curl_multi_add_handle(self::$sharedMulti, $ch);
                    self::$activeHandles++;

                    // Step 3: Pump cooperatively until this handle completes
                    // Note: pumpOnce() modifies registry[$id]['done'] when complete
                    /** @phpstan-ignore-next-line (loop terminates when pumpOnce sets done=true) */
                    while (!self::$registry[$id]['done']) {
                        $this->pumpOnce($token);
                        $token?->throwIfCancelled();
                        \Fiber::suspend(new SleepOp(microtime(true) + $this->pollIntervalSeconds, $token));
                    }

                    // Step 4: Extract response data and clean up registry
                    // @phpstan-ignore-next-line (reachable - loop exits when pumpOnce sets done=true)
                    $entry = self::$registry[$id] ?? ['raw' => '', 'err' => '', 'info' => []];
                    $raw = $entry['raw'] ?? '';
                    $err = $entry['err'] ?? '';
                    /** @var array<string,mixed> $info */
                    $info = is_array($entry['info'] ?? []) ? $entry['info'] : [];
                    unset(self::$registry[$id]);

                    // Step 5: Extract status code and normalize for file:// URLs
                    $statusCode = (int)($info['http_code'] ?? 0);
                    // file:// protocol may not set http_code, normalize to 200 if no error
                    $scheme = strtolower((string) $request->getUri()->getScheme());
                    if ($statusCode === 0 && $err === '' && $scheme === 'file') {
                        $statusCode = 200;
                    }

                    // Step 6: Determine if retry is needed based on error or status code
                    $shouldRetry = false;
                    // Retry on curl errors (network, timeout, DNS failures, etc.)
                    if ($err !== '') {
                        $shouldRetry = ($attempt < $this->maxRetries);
                    } elseif ($this->retryOnStatus !== [] && in_array($statusCode, $this->retryOnStatus, true)) {
                        $shouldRetry = ($attempt < $this->maxRetries);
                    }

                    // Execute retry with optional delay
                    if ($shouldRetry) {
                        $attempt++;
                        if ($this->retryDelaySeconds > 0) {
                            \Fiber::suspend(new SleepOp(microtime(true) + $this->retryDelaySeconds, $token));
                        }
                        continue; // Restart the request loop
                    }

                    // Throw exception if request failed and retries exhausted
                    if ($err !== '') {
                        throw new \Glueful\Async\Exceptions\HttpException('curl error: ' . $err);
                    }

                    // Step 7: Parse HTTP headers from raw response
                    $headers = [];
                    if (isset($info['header_size']) && (int)$info['header_size'] > 0) {
                        $headerSize = (int)$info['header_size'];
                        // Split raw response into headers and body
                        $headerBlob = substr($raw, 0, $headerSize);
                        $bodyPart = substr($raw, $headerSize);

                        // Handle redirects: header blob may contain multiple HTTP responses
                        // Split on double CRLF (blank line between responses)
                        $sections = preg_split('/(?:\r\n){2}/', trim($headerBlob));
                        // Use only the last section (final response after redirects)
                        $last = $sections !== false && $sections !== [] ? end($sections) : '';

                        if (is_string($last) && $last !== '') {
                            // Parse individual header lines
                            $lines = preg_split('/\r\n/', $last);
                            if ($lines === false) {
                                $lines = [];
                            }

                            foreach ($lines as $line) {
                                // Skip empty lines and HTTP status line
                                if ($line === '' || str_starts_with($line, 'HTTP/')) {
                                    continue;
                                }

                                // Parse "Header-Name: value" format
                                $pos = strpos($line, ':');
                                if ($pos === false) {
                                    continue; // Malformed header, skip
                                }

                                $name = trim(substr($line, 0, $pos));
                                $value = trim(substr($line, $pos + 1));

                                // Handle multi-value headers (e.g., Set-Cookie)
                                if (isset($headers[$name])) {
                                    $existing = $headers[$name];
                                    $headers[$name] = is_array($existing)
                                        ? array_merge($existing, [$value])
                                        : [$existing, $value];
                                } else {
                                    $headers[$name] = $value;
                                }
                            }
                        }
                        $response = new Response($statusCode, $headers, $bodyPart);
                    } else {
                        // No headers available, use simple response builder
                        $response = $this->buildResponse($raw, $statusCode);
                    }

                    // Step 8: Record successful completion with duration
                    $dur = (microtime(true) - $start) * 1000.0;
                    $metrics->httpRequestCompleted($request, $statusCode, $dur);
                    return $response;
                }
            } catch (\Throwable $e) {
                // Record failure with duration before re-throwing
                $dur = (microtime(true) - $start) * 1000.0;
                $metrics->httpRequestFailed($request, $e, $dur);
                // Cleanup curl handles on error/cancellation (shared multi best effort)
                if ($ch !== null) {
                    @curl_multi_remove_handle(self::$sharedMulti, $ch);
                    @curl_close($ch);
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

        // Allow file protocol when used explicitly
        $scheme = strtolower($request->getUri()->getScheme());
        if ($scheme === 'file' && \defined('CURLPROTO_FILE')) {
            @curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_FILE);
            @curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_FILE);
        }

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

    /**
     * Progress the shared curl_multi once and harvest completions into the registry.
     * Uses a static, per-class guard to ensure only one fiber pumps at a time.
     *
     * @param \Glueful\Async\Contracts\CancellationToken|null $token
     * @return void
     */
    private function pumpOnce(?\Glueful\Async\Contracts\CancellationToken $token = null): void
    {
        if (self::$sharedMulti === null) {
            return;
        }
        if (self::$pumping) {
            return;
        }
        self::$pumping = true;
        try {
            @curl_multi_exec(self::$sharedMulti, $running);
            while (true) {
                $i = @curl_multi_info_read(self::$sharedMulti, $rem);
                if ($i === false) {
                    break;
                }
                if (!isset($i['handle'])) {
                    continue;
                }
                $ch = $i['handle'];
                $id = (int) $ch;
                // curl_multi_getcontent returns string|false|null
                $raw = @curl_multi_getcontent($ch) ?? '';
                // curl_error always returns string
                $err = @curl_error($ch);
                /** @var array<string,mixed> $info */
                $info = @curl_getinfo($ch);
                $info = (is_array($info)) ? $info : [];
                @curl_multi_remove_handle(self::$sharedMulti, $ch);
                @curl_close($ch);
                self::$activeHandles = max(0, self::$activeHandles - 1);
                if (isset(self::$registry[$id])) {
                    self::$registry[$id]['raw'] = $raw;
                    self::$registry[$id]['err'] = $err;
                    self::$registry[$id]['info'] = $info;
                    self::$registry[$id]['done'] = true;
                    self::$registry[$id]['handle'] = null;
                }
            }
        } finally {
            self::$pumping = false;
        }
    }

    /**
     * Sends an HTTP request asynchronously with streaming body support.
     *
     * Similar to sendAsync(), but invokes a callback with response body chunks
     * as they arrive from the network. This allows processing large responses
     * incrementally without buffering the entire body in memory.
     *
     * The callback is invoked multiple times as data arrives:
     * - Each invocation receives a chunk of the response body
     * - Chunks arrive in order as received from the network
     * - Headers are still buffered and parsed normally
     * - Final response includes headers and full body
     *
     * Example:
     * ```php
     * $bytesReceived = 0;
     * $task = $client->sendAsyncStream(
     *     $request,
     *     function(string $chunk) use (&$bytesReceived) {
     *         $bytesReceived += strlen($chunk);
     *         echo "Received " . strlen($chunk) . " bytes\n";
     *         // Process chunk (e.g., write to file, parse JSON stream)
     *     }
     * );
     * $response = $scheduler->all([$task])[0];
     * ```
     *
     * @param RequestInterface $request PSR-7 HTTP request to send
     * @param callable $onChunk Callback invoked with each body chunk: function(string $chunk): void
     * @param Timeout|null $timeout Optional timeout for request (both connect and total)
     * @param \Glueful\Async\Contracts\CancellationToken|null $token Optional cancellation token
     * @return Task A FiberTask that resolves to a PSR-7 Response with complete body
     */
    public function sendAsyncStream(
        RequestInterface $request,
        callable $onChunk,
        ?Timeout $timeout = null,
        ?\Glueful\Async\Contracts\CancellationToken $token = null
    ): Task {
        $metrics = $this->metrics;
        return new FiberTask(function () use ($request, $timeout, $metrics, $token, $onChunk) {
            $metrics->httpRequestStarted($request);
            $start = microtime(true);
            $ch = null;
            $body = '';
            try {
                $attempt = 0;
                // Retry loop with break conditions inside (return on success, continue on retry)
                /** @phpstan-ignore-next-line (loop has explicit return/continue break conditions) */
                while (true) {
                    if (self::$sharedMulti === null) {
                        self::$sharedMulti = curl_multi_init();
                    }
                    $ch = $this->buildHandle($request, $timeout);
                    // Enable headers, and install write callback for streaming
                    curl_setopt($ch, CURLOPT_HEADER, true);
                    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($resource, string $data) use (&$body, $onChunk) {
                        $onChunk($data);
                        $body .= $data;
                        return strlen($data);
                    });
                    $id = (int) $ch;
                    self::$registry[$id] = [
                        'done' => false,
                        'raw' => null,
                        'err' => null,
                        'info' => null,
                        'handle' => $ch
                    ];
                    curl_multi_add_handle(self::$sharedMulti, $ch);

                    // Note: pumpOnce() modifies registry[$id]['done'] when complete
                    /** @phpstan-ignore-next-line (loop terminates when pumpOnce sets done=true) */
                    while (!self::$registry[$id]['done']) {
                        $this->pumpOnce($token);
                        $token?->throwIfCancelled();
                        \Fiber::suspend(new SleepOp(microtime(true) + $this->pollIntervalSeconds, $token));
                    }

                    // @phpstan-ignore-next-line (reachable - loop exits when pumpOnce sets done=true)
                    $err = (string) (self::$registry[$id]['err'] ?? '');
                    /** @var array<string,mixed> $info */
                    $info = (array) (self::$registry[$id]['info'] ?? []);
                    unset(self::$registry[$id]);

                    $statusCode = (int)($info['http_code'] ?? 0);
                    // Normalize file scheme where http_code may be 0 on success
                    $scheme = strtolower((string) $request->getUri()->getScheme());
                    if ($statusCode === 0 && $err === '' && $scheme === 'file') {
                        $statusCode = 200;
                    }
                    $shouldRetry = false;
                    if ($err !== '') {
                        $shouldRetry = ($attempt < $this->maxRetries);
                    } elseif ($this->retryOnStatus !== [] && in_array($statusCode, $this->retryOnStatus, true)) {
                        $shouldRetry = ($attempt < $this->maxRetries);
                    }
                    if ($shouldRetry) {
                        $attempt++;
                        if ($this->retryDelaySeconds > 0) {
                            \Fiber::suspend(new SleepOp(microtime(true) + $this->retryDelaySeconds, $token));
                        }
                        $body = '';
                        continue;
                    }
                    if ($err !== '') {
                        throw new \Glueful\Async\Exceptions\HttpException('curl error: ' . $err);
                    }

                    // Separate headers from body in accumulated $body
                    $headers = [];
                    $finalBody = $body;
                    if (isset($info['header_size']) && (int)$info['header_size'] > 0) {
                        $headerSize = (int)$info['header_size'];
                        $headerBlob = substr($body, 0, $headerSize);
                        $finalBody = substr($body, $headerSize);
                        $sections = preg_split('/(?:\r\n){2}/', trim($headerBlob));
                        $last = $sections !== false && $sections !== [] ? end($sections) : '';
                        if (is_string($last) && $last !== '') {
                            $lines = preg_split('/\r\n/', $last);
                            if ($lines === false) {
                                $lines = [];
                            }
                            foreach ($lines as $line) {
                                if ($line === '' || str_starts_with($line, 'HTTP/')) {
                                    continue;
                                }
                                $pos = strpos($line, ':');
                                if ($pos === false) {
                                    continue;
                                }
                                $name = trim(substr($line, 0, $pos));
                                $value = trim(substr($line, $pos + 1));
                                if (isset($headers[$name])) {
                                    $existing = $headers[$name];
                                    $headers[$name] = is_array($existing)
                                        ? array_merge($existing, [$value])
                                        : [$existing, $value];
                                } else {
                                    $headers[$name] = $value;
                                }
                            }
                        }
                    }

                    $dur = (microtime(true) - $start) * 1000.0;
                    $metrics->httpRequestCompleted($request, $statusCode, $dur);
                    return new Response($statusCode, $headers, $finalBody);
                }
            } catch (\Throwable $e) {
                $dur = (microtime(true) - $start) * 1000.0;
                $metrics->httpRequestFailed($request, $e, $dur);
                if ($ch !== null) {
                    @curl_multi_remove_handle(self::$sharedMulti, $ch);
                    @curl_close($ch);
                }
                throw $e;
            }
        }, $this->metrics, 'http-stream:' . $request->getMethod() . ' ' . (string)$request->getUri(), $token);
    }
}
