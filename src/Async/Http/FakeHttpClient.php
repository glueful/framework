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

final class FakeHttpClient implements HttpClient
{
    /** @var array<string, callable|\Psr\Http\Message\ResponseInterface|\Throwable> */
    private array $stubs = [];

    /**
     * @var array<int, array{method:string,url:string,headers:array<string,array<int,string>>,body:string}>
     */
    private array $calls = [];

    public function stub(string $method, string $url, mixed $reply): void
    {
        $this->stubs[strtoupper($method) . ' ' . $url] = $reply;
    }

    /**
     * @return array<int, array{method:string,url:string,headers:array<string,array<int,string>>,body:string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function sendAsync(RequestInterface $request, ?Timeout $timeout = null): Task
    {
        $this->record($request);
        return $this->resolve($request);
    }
    /**
     * @param array<int, RequestInterface> $requests
     * @return array<int, Task>
     */
    public function poolAsync(array $requests, ?Timeout $timeout = null): array
    {
        $tasks = [];
        foreach ($requests as $i => $req) {
            $this->record($req);
            $tasks[$i] = $this->resolve($req);
        }
        return $tasks;
    }

    private function record(RequestInterface $request): void
    {
        $this->calls[] = [
            'method' => $request->getMethod(),
            'url' => (string)$request->getUri(),
            'headers' => $request->getHeaders(),
            'body' => (string)$request->getBody(),
        ];
    }

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
