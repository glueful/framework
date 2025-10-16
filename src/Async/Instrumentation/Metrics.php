<?php

declare(strict_types=1);

namespace Glueful\Async\Instrumentation;

use Psr\Http\Message\RequestInterface;

interface Metrics
{
    /**
     * @param array<string, mixed> $context
     */
    public function taskStarted(string $name, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function taskCompleted(string $name, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function taskFailed(string $name, \Throwable $e, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function httpRequestStarted(RequestInterface $request, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function httpRequestCompleted(
        RequestInterface $request,
        int $statusCode,
        float $durationMs,
        array $context = []
    ): void;

    /**
     * @param array<string, mixed> $context
     */
    public function httpRequestFailed(
        RequestInterface $request,
        \Throwable $e,
        float $durationMs,
        array $context = []
    ): void;
}
