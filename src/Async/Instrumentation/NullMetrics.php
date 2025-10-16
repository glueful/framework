<?php

declare(strict_types=1);

namespace Glueful\Async\Instrumentation;

use Psr\Http\Message\RequestInterface;

final class NullMetrics implements Metrics
{
    public function taskStarted(string $name, array $context = []): void
    {
    }

    public function taskCompleted(string $name, array $context = []): void
    {
    }

    public function taskFailed(string $name, \Throwable $e, array $context = []): void
    {
    }

    public function httpRequestStarted(RequestInterface $request, array $context = []): void
    {
    }

    public function httpRequestCompleted(
        RequestInterface $request,
        int $statusCode,
        float $durationMs,
        array $context = []
    ): void {
    }

    public function httpRequestFailed(
        RequestInterface $request,
        \Throwable $e,
        float $durationMs,
        array $context = []
    ): void {
    }
}
