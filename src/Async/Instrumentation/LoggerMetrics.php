<?php

declare(strict_types=1);

namespace Glueful\Async\Instrumentation;

use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * PSR-3 logger-based metrics implementation.
 *
 * LoggerMetrics provides observability for async operations by logging all task
 * and HTTP request events to a PSR-3 logger. This is useful for development,
 * debugging, and production monitoring when integrated with log aggregation systems.
 *
 * Log messages use structured logging with consistent event names and context data,
 * making them easy to parse, filter, and analyze.
 *
 * Event naming convention:
 * - Task events: `async.task.{started|completed|failed}`
 * - HTTP events: `async.http.{started|completed|failed}`
 *
 * Usage:
 * ```php
 * $logger = app(LoggerInterface::class);
 * $metrics = new LoggerMetrics($logger);
 * $scheduler = new FiberScheduler($metrics);
 *
 * // All async operations are now logged
 * $task = $scheduler->spawn(fn() => fetchData());
 * ```
 *
 * Log output example:
 * ```
 * [info] async.task.started {"name":"fiber@Service.php:42"}
 * [info] async.task.completed {"name":"fiber@Service.php:42"}
 * [info] async.http.started {"method":"GET","url":"https://api.example.com/users"}
 * [info] async.http.completed {"method":"GET","url":"...","status":200,"duration_ms":45.2}
 * ```
 */
final class LoggerMetrics implements Metrics
{
    /**
     * Creates a logger-based metrics collector.
     *
     * @param LoggerInterface $logger PSR-3 logger for metric events
     */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Logs task start event at INFO level.
     *
     * Emits: async.task.started with task name and context
     *
     * @param string $name Task name
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    public function taskStarted(string $name, array $context = []): void
    {
        $this->logger->info('async.task.started', ['name' => $name] + $context);
    }

    /**
     * Logs task completion event at INFO level.
     *
     * Emits: async.task.completed with task name and context
     *
     * @param string $name Task name
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    public function taskCompleted(string $name, array $context = []): void
    {
        $this->logger->info('async.task.completed', ['name' => $name] + $context);
    }

    /**
     * Logs task failure event at ERROR level.
     *
     * Emits: async.task.failed with task name, exception, and context
     *
     * @param string $name Task name
     * @param \Throwable $e The exception that caused failure
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    public function taskFailed(string $name, \Throwable $e, array $context = []): void
    {
        $this->logger->error('async.task.failed', ['name' => $name, 'exception' => $e] + $context);
    }

    /**
     * Logs HTTP request start event at INFO level.
     *
     * Emits: async.http.started with method, URL, and context
     *
     * @param RequestInterface $request PSR-7 HTTP request
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    public function httpRequestStarted(RequestInterface $request, array $context = []): void
    {
        $this->logger->info('async.http.started', [
            'method' => $request->getMethod(),
            'url' => (string)$request->getUri()
        ] + $context);
    }

    /**
     * Logs successful HTTP request completion at INFO level.
     *
     * Emits: async.http.completed with method, URL, status code, duration, and context
     *
     * @param RequestInterface $request PSR-7 HTTP request
     * @param int $statusCode HTTP response status code
     * @param float $durationMs Request duration in milliseconds
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
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

    /**
     * Logs HTTP request failure at ERROR level.
     *
     * Emits: async.http.failed with method, URL, exception, duration, and context
     *
     * @param RequestInterface $request PSR-7 HTTP request
     * @param \Throwable $e The exception that caused failure
     * @param float $durationMs Time elapsed before failure in milliseconds
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
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
