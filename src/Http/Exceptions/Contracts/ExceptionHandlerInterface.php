<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Contracts;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Exception Handler Interface
 *
 * Defines the contract for handling exceptions and converting them to HTTP responses.
 * Implementations should provide centralized exception handling with proper
 * status code mapping, reporting, and environment-aware error detail exposure.
 */
interface ExceptionHandlerInterface
{
    /**
     * Handle an exception and return an HTTP response
     *
     * This is the main entry point for exception handling. It should:
     * 1. Report the exception if appropriate
     * 2. Render the exception into an HTTP response
     *
     * @param Throwable $e The exception to handle
     * @param Request|null $request The current request (if available)
     * @return Response The HTTP response
     */
    public function handle(Throwable $e, ?Request $request = null): Response;

    /**
     * Determine if the exception should be reported
     *
     * Some exceptions (like validation errors) are expected and don't
     * need to be logged or reported to external services.
     *
     * @param Throwable $e The exception to check
     * @return bool True if the exception should be reported
     */
    public function shouldReport(Throwable $e): bool;

    /**
     * Report an exception
     *
     * Log the exception and/or send it to external services
     * (Sentry, Bugsnag, etc.) for monitoring.
     *
     * @param Throwable $e The exception to report
     * @param Request|null $request The current request for context
     */
    public function report(Throwable $e, ?Request $request = null): void;

    /**
     * Render an exception into an HTTP response
     *
     * Convert the exception into an appropriate HTTP response.
     * Should respect environment (development vs production) for
     * error detail exposure.
     *
     * @param Throwable $e The exception to render
     * @param Request|null $request The current request
     * @return Response The HTTP response
     */
    public function render(Throwable $e, ?Request $request = null): Response;
}
