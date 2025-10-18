<?php

declare(strict_types=1);

namespace Glueful\Async\Http;

use Glueful\Async\Contracts\Http\HttpClient;
use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\Timeout;
use Glueful\Async\Task\CompletedTask;
use Glueful\Async\Task\FailedTask;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Fake HTTP client for testing purposes.
 *
 * FakeHttpClient provides a test double implementation of HttpClient that:
 * - Does not make real network requests
 * - Returns stubbed responses configured via stub()
 * - Records all request details for assertions via calls()
 * - Returns immediately with completed or failed tasks
 *
 * This client is ideal for unit testing code that depends on HttpClient without
 * requiring actual network I/O or external services.
 *
 * Usage in tests:
 * ```php
 * $fake = new FakeHttpClient();
 *
 * // Stub a response
 * $fake->stub('GET', 'https://api.example.com/users/1', new Response(200, [], '{"id":1}'));
 *
 * // Make requests (returns immediately with stubbed response)
 * $task = $fake->sendAsync(new Request('GET', 'https://api.example.com/users/1'));
 * $response = $task->getResult();
 *
 * // Assert the request was made
 * $calls = $fake->calls();
 * assert($calls[0]['method'] === 'GET');
 * assert($calls[0]['url'] === 'https://api.example.com/users/1');
 * ```
 *
 * Stubbing strategies:
 * - Static response: Pass a Response object
 * - Dynamic response: Pass a callable that receives the request and returns a Response
 * - Error simulation: Pass a Throwable to simulate network/HTTP errors
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<string, callable|\Psr\Http\Message\ResponseInterface|\Throwable> */
    private array $stubs = [];

    /**
     * Recorded request details for assertions.
     *
     * @var array<int, array{method:string,url:string,headers:array<string,array<int,string>>,body:string}>
     */
    private array $calls = [];

    /**
     * Registers a stubbed response for a specific method and URL.
     *
     * The stub is matched by HTTP method (case-insensitive) and exact URL.
     * When a matching request is made, the stubbed response is returned immediately.
     *
     * Stub types:
     * - Response: Returns the Response object directly
     * - Callable: Invokes the callable with the request, must return a Response
     * - Throwable: Throws the exception to simulate errors
     *
     * Example:
     * ```php
     * // Static response
     * $fake->stub('GET', 'https://api.example.com/users', new Response(200, [], '[]'));
     *
     * // Dynamic response based on request
     * $fake->stub('POST', 'https://api.example.com/users', function($request) {
     *     $body = json_decode($request->getBody(), true);
     *     return new Response(201, [], json_encode(['id' => 123, 'name' => $body['name']]));
     * });
     *
     * // Error simulation
     * $fake->stub('GET', 'https://api.example.com/error', new \RuntimeException('Network error'));
     * ```
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $url Full URL to match
     * @param callable|\Psr\Http\Message\ResponseInterface|\Throwable $reply Response, callable, or exception
     * @return void
     */
    public function stub(string $method, string $url, mixed $reply): void
    {
        $this->stubs[strtoupper($method) . ' ' . $url] = $reply;
    }

    /**
     * Returns all recorded request details for assertions.
     *
     * Each entry contains:
     * - method: HTTP method (GET, POST, etc.)
     * - url: Full request URL
     * - headers: Request headers as associative array
     * - body: Request body content
     *
     * Useful for asserting that expected requests were made with correct parameters.
     *
     * Example:
     * ```php
     * $fake->sendAsync(new Request('POST', 'https://api.example.com/users', [], '{"name":"John"}'));
     *
     * $calls = $fake->calls();
     * assert(count($calls) === 1);
     * assert($calls[0]['method'] === 'POST');
     * assert($calls[0]['url'] === 'https://api.example.com/users');
     * assert($calls[0]['body'] === '{"name":"John"}');
     * ```
     *
     * @return array<int, array{method:string,url:string,headers:array<string,array<int,string>>,body:string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Sends a fake HTTP request asynchronously.
     *
     * Records the request details and returns a Task that resolves to either:
     * - CompletedTask with the stubbed Response (if stub is configured)
     * - FailedTask with the stubbed Throwable (if stub is an exception)
     * - CompletedTask with default 200 OK response (if no stub configured)
     *
     * The task completes immediately without any network I/O or fiber suspension.
     *
     * @param RequestInterface $request PSR-7 HTTP request
     * @param Timeout|null $timeout Ignored (for interface compatibility)
     * @param \Glueful\Async\Contracts\CancellationToken|null $token Ignored (for interface compatibility)
     * @return Task CompletedTask or FailedTask based on stub configuration
     */
    public function sendAsync(
        RequestInterface $request,
        ?Timeout $timeout = null,
        ?\Glueful\Async\Contracts\CancellationToken $token = null
    ): Task {
        $this->record($request);
        return $this->resolve($request);
    }

    /**
     * Creates a pool of fake async HTTP requests.
     *
     * Records all requests and returns immediately completed or failed tasks
     * based on stub configuration. No actual concurrency or scheduling occurs.
     *
     * @param array<int, RequestInterface> $requests Array of PSR-7 HTTP requests
     * @param Timeout|null $timeout Ignored (for interface compatibility)
     * @param \Glueful\Async\Contracts\CancellationToken|null $token Ignored (for interface compatibility)
     * @return array<int, Task> Array of completed/failed tasks (same keys as input)
     */
    public function poolAsync(
        array $requests,
        ?Timeout $timeout = null,
        ?\Glueful\Async\Contracts\CancellationToken $token = null
    ): array {
        $tasks = [];
        foreach ($requests as $i => $req) {
            $this->record($req);
            $tasks[$i] = $this->resolve($req);
        }
        return $tasks;
    }

    /**
     * Records request details for later assertion via calls().
     *
     * Extracts method, URL, headers, and body from the request and appends
     * to the internal calls array.
     *
     * @param RequestInterface $request PSR-7 HTTP request to record
     * @return void
     */
    private function record(RequestInterface $request): void
    {
        $this->calls[] = [
            'method' => $request->getMethod(),
            'url' => (string)$request->getUri(),
            'headers' => $request->getHeaders(),
            'body' => (string)$request->getBody(),
        ];
    }

    /**
     * Resolves a request to a Task based on stub configuration.
     *
     * Looks up the stub by method+URL key. If found:
     * - Throwable: Returns FailedTask with the exception
     * - Callable: Invokes it with the request, returns CompletedTask with result
     * - Response: Returns CompletedTask with the response
     *
     * If no stub is configured, returns CompletedTask with default 200 OK response.
     *
     * @param RequestInterface $request PSR-7 HTTP request to resolve
     * @return Task CompletedTask or FailedTask
     */
    private function resolve(RequestInterface $request): Task
    {
        $key = strtoupper($request->getMethod()) . ' ' . (string)$request->getUri();
        $reply = $this->stubs[$key] ?? new Response(200, [], '');
        try {
            if ($reply instanceof \Throwable) {
                throw $reply;
            }
            if (is_callable($reply)) {
                $reply = $reply($request);
            }
            if (!$reply instanceof \Psr\Http\Message\ResponseInterface) {
                throw new \RuntimeException('FakeHttpClient: stub must return ResponseInterface or Throwable');
            }
            return new CompletedTask($reply);
        } catch (\Throwable $e) {
            return new FailedTask($e);
        }
    }
}
