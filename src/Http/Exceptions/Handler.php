<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions;

use Glueful\Http\Response;
use Glueful\Http\Exceptions\Contracts\ExceptionHandlerInterface;
use Glueful\Http\Exceptions\Contracts\RenderableException;
use Glueful\Http\Exceptions\Client\BadRequestException;
use Glueful\Http\Exceptions\Client\UnauthorizedException;
use Glueful\Http\Exceptions\Client\ForbiddenException;
use Glueful\Permissions\Exceptions\UnauthorizedException as PermissionUnauthorizedException;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Exceptions\Client\MethodNotAllowedException;
use Glueful\Http\Exceptions\Client\ConflictException;
use Glueful\Http\Exceptions\Client\UnprocessableEntityException;
use Glueful\Http\Exceptions\Client\TooManyRequestsException;
use Glueful\Http\Exceptions\Server\InternalServerException;
use Glueful\Http\Exceptions\Server\ServiceUnavailableException;
use Glueful\Http\Exceptions\Server\GatewayTimeoutException;
use Glueful\Http\Exceptions\Domain\ModelNotFoundException;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use Glueful\Http\Exceptions\Domain\AuthorizationException;
use Glueful\Http\Exceptions\Domain\TokenExpiredException;
use Glueful\Validation\ValidationException;
use Glueful\Events\Event;
use Glueful\Events\Http\ExceptionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Exception Handler
 *
 * Centralized exception handling for the application.
 * Maps exceptions to HTTP responses and handles reporting.
 *
 * Features:
 * - Automatic HTTP status code mapping from exception types
 * - Environment-aware error detail exposure (verbose in dev, minimal in prod)
 * - Exception reporting to PSR-3 loggers and custom reporters
 * - Custom exception rendering via RenderableException interface
 * - Configurable "don't report" list for expected exceptions
 *
 * @example
 * // Basic usage with DI
 * $handler = new Handler($logger, debug: false);
 * $response = $handler->handle($exception, $request);
 *
 * @example
 * // Configure don't report list
 * $handler->dontReport(BusinessRuleException::class);
 *
 * @example
 * // Add custom reporter (e.g., Sentry)
 * $handler->reportUsing(fn($e, $req) => Sentry::captureException($e));
 */
class Handler implements ExceptionHandlerInterface
{
    /**
     * Exceptions that should not be reported
     *
     * These exceptions are considered "expected" and don't need to be
     * logged or reported to external monitoring services.
     *
     * @var array<class-string<Throwable>>
     */
    protected array $dontReport = [
        ValidationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        NotFoundException::class,
        BadRequestException::class,
        UnauthorizedException::class,
        ForbiddenException::class,
        MethodNotAllowedException::class,
        ConflictException::class,
        UnprocessableEntityException::class,
        TooManyRequestsException::class,
        TokenExpiredException::class,
        PermissionUnauthorizedException::class,
    ];

    /**
     * Exception to HTTP status code mapping
     *
     * Maps exception class names to their corresponding HTTP status codes.
     * The handler checks this mapping to determine the response status.
     *
     * @var array<class-string<Throwable>, int>
     */
    protected array $httpMapping = [
        // Client errors (4xx)
        BadRequestException::class => 400,
        AuthenticationException::class => 401,
        UnauthorizedException::class => 401,
        TokenExpiredException::class => 401,
        AuthorizationException::class => 403,
        ForbiddenException::class => 403,
        PermissionUnauthorizedException::class => 403,
        NotFoundException::class => 404,
        ModelNotFoundException::class => 404,
        MethodNotAllowedException::class => 405,
        ConflictException::class => 409,
        ValidationException::class => 422,
        UnprocessableEntityException::class => 422,
        TooManyRequestsException::class => 429,

        // Server errors (5xx)
        InternalServerException::class => 500,
        ServiceUnavailableException::class => 503,
        GatewayTimeoutException::class => 504,
    ];

    /**
     * Custom exception renderers
     *
     * Allows registering custom rendering logic for specific exception types.
     *
     * @var array<class-string<Throwable>, callable(Throwable, ?Request): Response>
     */
    protected array $renderers = [];

    /**
     * Exception reporters
     *
     * Callbacks that are invoked when an exception is reported.
     * Useful for integration with external monitoring services.
     *
     * @var array<callable(Throwable, ?Request): void>
     */
    protected array $reporters = [];

    /**
     * Whether debug mode is enabled
     */
    protected bool $debug;

    /**
     * Create a new exception handler
     *
     * @param LoggerInterface|null $logger PSR-3 logger for exception reporting
     * @param bool $debug Enable debug mode (overrides environment detection)
     */
    public function __construct(
        protected ?LoggerInterface $logger = null,
        bool $debug = false
    ) {
        $this->debug = $debug || $this->detectDebugMode();
    }

    /**
     * Detect if debug mode is enabled from environment
     */
    private function detectDebugMode(): bool
    {
        if (!function_exists('env')) {
            return false;
        }

        $appDebug = env('APP_DEBUG', false);
        $appEnv = env('APP_ENV', 'production');

        return $appDebug === true || $appEnv === 'development';
    }

    /**
     * Handle an exception and return a response
     *
     * This is the main entry point for exception handling. It:
     * 1. Reports the exception if appropriate
     * 2. Dispatches an exception event
     * 3. Renders the exception into an HTTP response
     *
     * @param Throwable $e The exception to handle
     * @param Request|null $request The current request (if available)
     * @return Response The HTTP response
     */
    public function handle(Throwable $e, ?Request $request = null): Response
    {
        // Report the exception if appropriate
        if ($this->shouldReport($e)) {
            $this->report($e, $request);
        }

        // Dispatch exception event (only if request is available)
        if ($request !== null) {
            try {
                Event::dispatch(new ExceptionEvent($request, $e));
            } catch (Throwable) {
                // Don't let event dispatch failures break error handling
            }
        }

        // Render the exception
        return $this->render($e, $request);
    }

    /**
     * Determine if the exception should be reported
     *
     * Exceptions in the dontReport list are considered expected
     * and won't be logged or sent to external services.
     *
     * @param Throwable $e The exception to check
     * @return bool True if the exception should be reported
     */
    public function shouldReport(Throwable $e): bool
    {
        foreach ($this->dontReport as $type) {
            if ($e instanceof $type) {
                return false;
            }
        }

        return true;
    }

    /**
     * Report an exception
     *
     * Logs the exception and calls any registered reporters.
     *
     * @param Throwable $e The exception to report
     * @param Request|null $request The current request for context
     */
    public function report(Throwable $e, ?Request $request = null): void
    {
        // Build log context
        $context = [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];

        // Add request context if available
        if ($request !== null) {
            $context['request'] = [
                'method' => $request->getMethod(),
                'uri' => $request->getRequestUri(),
                'ip' => $request->getClientIp(),
            ];
        }

        // Log the exception
        $this->logger?->error($e->getMessage(), $context);

        // Call custom reporters
        foreach ($this->reporters as $reporter) {
            try {
                $reporter($e, $request);
            } catch (Throwable) {
                // Don't let reporters break error handling
            }
        }
    }

    /**
     * Render an exception into an HTTP response
     *
     * Checks for custom renderers, renderable exceptions, and falls back
     * to default rendering based on exception type.
     *
     * @param Throwable $e The exception to render
     * @param Request|null $request The current request
     * @return Response The HTTP response
     */
    public function render(Throwable $e, ?Request $request = null): Response
    {
        // Check for renderable exception
        if ($e instanceof RenderableException) {
            return $e->render($request);
        }

        // Check for custom renderer
        foreach ($this->renderers as $type => $renderer) {
            if ($e instanceof $type) {
                return $renderer($e, $request);
            }
        }

        // Handle specific exception types
        return match (true) {
            $e instanceof ValidationException => $this->renderValidationException($e),
            $e instanceof PermissionUnauthorizedException => $this->renderPermissionException($e),
            $e instanceof HttpException => $this->renderHttpException($e),
            default => $this->renderGenericException($e),
        };
    }

    /**
     * Render a validation exception
     *
     * @param ValidationException $e The validation exception
     * @return Response JSON response with validation errors
     */
    protected function renderValidationException(ValidationException $e): Response
    {
        return new Response([
            'success' => false,
            'message' => $e->getMessage(),
            'errors' => $e->errors(),
        ], 422);
    }

    /**
     * Render an HTTP exception
     *
     * @param HttpException $e The HTTP exception
     * @return Response JSON response with error details
     */
    protected function renderHttpException(HttpException $e): Response
    {
        $statusCode = $e->getStatusCode();
        $headers = $e->getHeaders();

        $message = $e->getMessage();

        return new Response([
            'success' => false,
            'message' => $message !== '' ? $message : $this->getDefaultMessage($statusCode),
            'error' => $this->buildErrorDetails($e, $statusCode),
        ], $statusCode, $headers);
    }

    /**
     * Render a permission unauthorized exception
     *
     * Always shows the user-friendly message since permission exceptions
     * are designed to be safe to display to users.
     *
     * @param PermissionUnauthorizedException $e The permission exception
     * @return Response JSON response with 403 status
     */
    protected function renderPermissionException(PermissionUnauthorizedException $e): Response
    {
        return new Response([
            'success' => false,
            'message' => $e->getMessage(),
            'code' => 403,
            'error_code' => 'FORBIDDEN',
        ], 403);
    }

    /**
     * Render a generic exception
     *
     * For non-HTTP exceptions, determines the status code and builds
     * an appropriate response with environment-aware detail exposure.
     *
     * @param Throwable $e The exception
     * @return Response JSON response
     */
    protected function renderGenericException(Throwable $e): Response
    {
        $statusCode = $this->getStatusCode($e);
        $message = $this->debug ? $e->getMessage() : $this->getDefaultMessage($statusCode);

        return new Response([
            'success' => false,
            'message' => $message,
            'error' => $this->buildErrorDetails($e, $statusCode),
        ], $statusCode);
    }

    /**
     * Build error details for the response
     *
     * In debug mode, includes exception class, file, line, and trace.
     * In production, only includes minimal information.
     *
     * @param Throwable $e The exception
     * @param int $statusCode The HTTP status code
     * @return array<string, mixed>
     */
    protected function buildErrorDetails(Throwable $e, int $statusCode): array
    {
        $details = [
            'code' => $statusCode,
            'timestamp' => date('c'),
            'request_id' => $this->getRequestId(),
        ];

        // Add debug info in development
        if ($this->debug) {
            $details['exception'] = get_class($e);
            $details['file'] = $e->getFile();
            $details['line'] = $e->getLine();
            $details['trace'] = $this->formatTrace($e);
        }

        return $details;
    }

    /**
     * Get the HTTP status code for an exception
     *
     * Checks the httpMapping, exception methods, and exception code
     * to determine the appropriate status code.
     *
     * @param Throwable $e The exception
     * @return int HTTP status code
     */
    protected function getStatusCode(Throwable $e): int
    {
        // Check direct mapping
        foreach ($this->httpMapping as $type => $code) {
            if ($e instanceof $type) {
                return $code;
            }
        }

        // Check if exception has a status code method
        if (method_exists($e, 'getStatusCode')) {
            /** @var callable(): int $getter */
            $getter = [$e, 'getStatusCode'];
            return $getter();
        }

        // Check exception code (if it's a valid HTTP code)
        $code = $e->getCode();
        if (is_int($code) && $code >= 400 && $code < 600) {
            return $code;
        }

        // Default to 500
        return 500;
    }

    /**
     * Get default message for a status code
     *
     * @param int $statusCode HTTP status code
     * @return string Human-readable error message
     */
    protected function getDefaultMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'An error occurred',
        };
    }

    /**
     * Format exception trace for debug output
     *
     * Limits trace to first 10 frames and extracts key information.
     *
     * @param Throwable $e The exception
     * @return array<array{file: string, line: int, function: string, class?: string|null}>
     */
    protected function formatTrace(Throwable $e): array
    {
        $trace = [];

        foreach (array_slice($e->getTrace(), 0, 10) as $frame) {
            $trace[] = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
                'class' => $frame['class'] ?? null,
            ];
        }

        return $trace;
    }

    /**
     * Get the current request ID
     *
     * Uses the request_id() helper if available, otherwise checks
     * server variables or generates a new ID.
     *
     * @return string Request ID
     */
    protected function getRequestId(): string
    {
        // Use global helper if available
        if (function_exists('request_id')) {
            return request_id();
        }

        // Check server variables
        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            return $_SERVER['HTTP_X_REQUEST_ID'];
        }

        if (isset($_SERVER['REQUEST_ID'])) {
            return $_SERVER['REQUEST_ID'];
        }

        // Generate a new ID
        return 'req_' . bin2hex(random_bytes(6));
    }

    /**
     * Add an exception type to the don't report list
     *
     * Exceptions in this list won't be logged or reported to
     * external monitoring services.
     *
     * @param class-string<Throwable> $exception Exception class name
     * @return static
     */
    public function dontReport(string $exception): static
    {
        $this->dontReport[] = $exception;

        return $this;
    }

    /**
     * Register a custom exception renderer
     *
     * The renderer callback receives the exception and request,
     * and should return a Response instance.
     *
     * @param class-string<Throwable> $exception Exception class name
     * @param callable(Throwable, ?Request): Response $renderer Renderer callback
     * @return static
     */
    public function renderable(string $exception, callable $renderer): static
    {
        $this->renderers[$exception] = $renderer;

        return $this;
    }

    /**
     * Register an exception reporter
     *
     * Reporters are called for all exceptions that should be reported.
     * Useful for integrating with services like Sentry or Bugsnag.
     *
     * @param callable(Throwable, ?Request): void $reporter Reporter callback
     * @return static
     */
    public function reportUsing(callable $reporter): static
    {
        $this->reporters[] = $reporter;

        return $this;
    }

    /**
     * Map an exception type to an HTTP status code
     *
     * @param class-string<Throwable> $exception Exception class name
     * @param int $statusCode HTTP status code (400-599)
     * @return static
     */
    public function map(string $exception, int $statusCode): static
    {
        $this->httpMapping[$exception] = $statusCode;

        return $this;
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Set debug mode
     *
     * @param bool $debug Enable or disable debug mode
     * @return static
     */
    public function setDebug(bool $debug): static
    {
        $this->debug = $debug;

        return $this;
    }
}
