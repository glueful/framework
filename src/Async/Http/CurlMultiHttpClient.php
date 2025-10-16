<?php

declare(strict_types=1);

namespace Glueful\Async\Http;

use Glueful\Async\Contracts\Http\HttpClient;
use Glueful\Async\Contracts\Task;
use Glueful\Async\Contracts\Timeout;
use Glueful\Async\Task\FiberTask;
use Glueful\Async\Internal\SleepOp;
use Glueful\Async\Task\CompletedTask;
use Glueful\Async\Task\FailedTask;
use Glueful\Async\Instrumentation\Metrics;
use Glueful\Async\Instrumentation\NullMetrics;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class CurlMultiHttpClient implements HttpClient
{
    public function __construct(private ?Metrics $metrics = null)
    {
        $this->metrics = $this->metrics ?? new NullMetrics();
    }

    public function sendAsync(RequestInterface $request, ?Timeout $timeout = null): Task
    {
        $metrics = $this->metrics;
        return new FiberTask(function () use ($request, $timeout, $metrics) {
            $metrics->httpRequestStarted($request);
            $start = microtime(true);
            try {
                $multi = curl_multi_init();
                $ch = $this->buildHandle($request, $timeout);
                curl_multi_add_handle($multi, $ch);

                do {
                    $status = curl_multi_exec($multi, $running);
                    if ($running) {
                        \Fiber::suspend(new SleepOp(microtime(true) + 0.01));
                    }
                } while ($running && $status === CURLM_OK);

                $raw = curl_multi_getcontent($ch);
                $err = curl_error($ch);
                $info = curl_getinfo($ch);
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                curl_multi_close($multi);

                if ($err !== '') {
                    throw new \RuntimeException('curl error: ' . $err);
                }
                $statusCode = (int)($info['http_code'] ?? 200);
                $response = $this->buildResponse($raw, $statusCode);
                $dur = (microtime(true) - $start) * 1000.0;
                $metrics->httpRequestCompleted($request, $statusCode, $dur);
                return $response;
            } catch (\Throwable $e) {
                $dur = (microtime(true) - $start) * 1000.0;
                $metrics->httpRequestFailed($request, $e, $dur);
                throw $e;
            }
        }, $this->metrics, 'http:' . $request->getMethod() . ' ' . (string)$request->getUri());
    }

    /**
     * @param array<int, RequestInterface> $requests
     * @return array<int, Task>
     */
    public function poolAsync(array $requests, ?Timeout $timeout = null): array
    {
        $tasks = [];
        foreach ($requests as $i => $req) {
            $tasks[$i] = $this->sendAsync($req, $timeout);
        }
        return $tasks;
    }

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
     * @return \CurlHandle
     */
    private function buildHandle(RequestInterface $request, ?Timeout $timeout)
    {
        $ch = curl_init((string)$request->getUri());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);

        $method = strtoupper($request->getMethod());
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            $body = (string)$request->getBody();
            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $v) {
                $headers[] = $name . ': ' . $v;
            }
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($timeout !== null) {
            $ms = (int)($timeout->seconds * 1000);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $ms);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $ms);
        }
        return $ch;
    }

    private function buildResponse(string $body, int $status): Response
    {
        return new Response($status, [], $body);
    }
}
