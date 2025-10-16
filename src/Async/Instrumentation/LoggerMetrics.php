<?php

declare(strict_types=1);

namespace Glueful\Async\Instrumentation;

use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

final class LoggerMetrics implements Metrics
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function taskStarted(string $name, array $context = []): void
    {
        $this->logger->info('async.task.started', ['name' => $name] + $context);
    }

    public function taskCompleted(string $name, array $context = []): void
    {
        $this->logger->info('async.task.completed', ['name' => $name] + $context);
    }

    public function taskFailed(string $name, \Throwable $e, array $context = []): void
    {
        $this->logger->error('async.task.failed', ['name' => $name, 'exception' => $e] + $context);
    }

    public function httpRequestStarted(RequestInterface $request, array $context = []): void
    {
        $this->logger->info('async.http.started', [
            'method' => $request->getMethod(),
            'url' => (string)$request->getUri()
        ] + $context);
    }

    public function httpRequestCompleted(
        RequestInterface $request,
        int $statusCode,
        float $durationMs,
        array $context = []
    ): void {
        $this->logger->info('async.http.completed', [
            'method' => $request->getMethod(),
            'url' => (string)$request->getUri(),
            'status' => $statusCode,
            'duration_ms' => $durationMs
        ] + $context);
    }

    public function httpRequestFailed(
        RequestInterface $request,
        \Throwable $e,
        float $durationMs,
        array $context = []
    ): void {
        $this->logger->error('async.http.failed', [
            'method' => $request->getMethod(),
            'url' => (string)$request->getUri(),
            'duration_ms' => $durationMs,
            'exception' => $e
        ] + $context);
    }
}
