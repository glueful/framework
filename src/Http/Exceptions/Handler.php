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
use Glueful\Http\Exceptions\Domain\BusinessLogicException;
use Glueful\Http\Exceptions\Domain\DatabaseException;
use Glueful\Http\Exceptions\Domain\SecurityException;
use Glueful\Http\Exceptions\Domain\HttpAuthException;
use Glueful\Http\Exceptions\Domain\HttpProtocolException;
use Glueful\Http\Exceptions\Domain\ExtensionException;
use Glueful\Http\Exceptions\Domain\ProvisioningException;
use Glueful\Validation\ValidationException;
use Glueful\Events\EventService;
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
 * - Channel-based log routing for exception classification
 * - Optimized context building (lightweight for high-frequency exceptions)
 * - Test mode for capturing responses without output
 */
class Handler implements ExceptionHandlerInterface
{
    /**
     * Exceptions that should not be reported
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
        BusinessLogicException::class,
        DatabaseException::class,
        SecurityException::class,
        HttpAuthException::class,
        HttpProtocolException::class,
        ExtensionException::class,
    ];

    /**
     * Exception to HTTP status code mapping
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
        BusinessLogicException::class => 422,
        TooManyRequestsException::class => 429,

        // Server errors (5xx)
        DatabaseException::class => 500,
        ProvisioningException::class => 500,
        InternalServerException::class => 500,
        ServiceUnavailableException::class => 503,
        GatewayTimeoutException::class => 504,
    ];

    /**
     * Exception class to log channel mapping
     *
     * @var array<string, string>
     */
    protected array $channelMap = [
        ValidationException::class => 'validation',
        AuthenticationException::class => 'auth',
        NotFoundException::class => 'http',
        DatabaseException::class => 'database',
        SecurityException::class => 'security',
        TooManyRequestsException::class => 'ratelimit',
        ExtensionException::class => 'extensions',
        ProvisioningException::class => 'api',
        HttpProtocolException::class => 'http',
        HttpAuthException::class => 'auth',
        BusinessLogicException::class => 'api',
        HttpClientException::class => 'http_client',
        \Glueful\Permissions\Exceptions\PermissionException::class => 'permissions',
        \Glueful\Permissions\Exceptions\UnauthorizedException::class => 'auth',
        \Glueful\Permissions\Exceptions\ProviderNotFoundException::class => 'permissions',
    ];

    /**
     * Custom exception renderers
     *
     * @var array<class-string<Throwable>, callable(Throwable, ?Request): Response>
     */
    protected array $renderers = [];

    /**
     * Exception reporters
     *
     * @var array<callable(Throwable, ?Request): void>
     */
    protected array $reporters = [];

    /**
     * Whether debug mode is enabled
     */
    protected bool $debug;

    /**
     * Whether to build verbose context (request headers, memory, timing)
     */
    protected bool $verboseContext = true;

    /**
     * Whether test mode is enabled (captures response instead of outputting)
     */
    protected bool $testMode = false;

    /**
     * Captured response in test mode
     *
     * @var array<string, mixed>|null
     */
    protected ?array $testResponse = null;

    /**
     * Exception types that get lightweight context (high-frequency, low-priority)
     *
     * @var array<class-string<Throwable>>
     */
    protected array $lightweightContextExceptions = [
        ValidationException::class,
        NotFoundException::class,
    ];

    /**
     * Create a new exception handler
     *
     * @param LoggerInterface|null $logger PSR-3 logger for exception reporting
     * @param bool $debug Enable debug mode (overrides environment detection)
     * @param EventService|null $events Event service for dispatching exception events
     */
    public function __construct(
        protected ?LoggerInterface $logger = null,
        bool $debug = false,
        private ?EventService $events = null
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
                $this->events?->dispatch(new ExceptionEvent($request, $e));
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
     * Logs the exception with channel-based routing and optimized context,
     * then calls any registered reporters.
     *
     * @param Throwable $e The exception to report
     * @param Request|null $request The current request for context
     */
    public function report(Throwable $e, ?Request $request = null): void
    {
        $channel = $this->resolveLogChannel($e);
        $isFramework = $this->isFrameworkException($e);

        $context = $this->buildReportContext($e, $request);
        $context['channel'] = $channel;
        $context['type'] = $isFramework ? 'framework_exception' : 'application_exception';

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
     * Log an exception directly (convenience for callers outside the middleware pipeline)
     *
     * @param Throwable $e The exception to log
     * @param array<string, mixed> $extraContext Additional context to merge
     */
    public function logError(Throwable $e, array $extraContext = []): void
    {
        $channel = $this->resolveLogChannel($e);
        $isFramework = $this->isFrameworkException($e);

        $context = [
            'type' => $isFramework ? 'framework_exception' : 'application_exception',
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'channel' => $channel,
            'timestamp' => date('c'),
        ];

        if (count($extraContext) > 0) {
            $context = array_merge($context, $extraContext);
        }

        try {
            $this->logger?->error($e->getMessage(), $context);
        } catch (Throwable $logEx) {
            error_log("Error logging exception: {$e->getMessage()} - {$logEx->getMessage()}");
        }
    }

    /**
     * Resolve the log channel for an exception based on its type
     *
     * @param Throwable $e The exception
     * @return string Log channel name
     */
    protected function resolveLogChannel(Throwable $e): string
    {
        if ($this->isFrameworkException($e)) {
            return 'framework';
        }

        foreach ($this->channelMap as $exceptionClass => $channel) {
            if ($e instanceof $exceptionClass) {
                return $channel;
            }
        }

        return 'error';
    }

    /**
     * Build optimized context for reporting
     *
     * Uses lightweight context for high-frequency exceptions (validation, 404)
     * and full context with request/memory/timing for others.
     *
     * @param Throwable $e The exception
     * @param Request|null $request The current request
     * @return array<string, mixed>
     */
    protected function buildReportContext(Throwable $e, ?Request $request = null): array
    {
        $context = [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => date('c'),
        ];

        // Lightweight context for high-frequency exceptions
        if ($this->shouldUseLightweightContext($e)) {
            if ($request !== null) {
                $context['request'] = [
                    'method' => $request->getMethod(),
                    'uri' => $request->getRequestUri(),
                    'ip' => $request->getClientIp(),
                ];
            }
            $context['lightweight'] = true;
            return $context;
        }

        // Full context
        if ($request !== null) {
            $context['request'] = [
                'method' => $request->getMethod(),
                'uri' => $request->getRequestUri(),
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent', 'unknown'),
            ];

            if ($this->verboseContext) {
                $context['request']['query_string'] = $request->getQueryString();
                $context['request']['content_type'] = $request->getContentTypeFormat();
            }
        }

        if ($this->verboseContext) {
            $context['memory_usage'] = memory_get_usage(true);
            $context['peak_memory'] = memory_get_peak_usage(true);
            $context['processing_time'] = isset($_SERVER['REQUEST_TIME_FLOAT'])
                ? (microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT'])
                : null;
        }

        return $context;
    }

    /**
     * Determine if lightweight context should be used for this exception
     */
    protected function shouldUseLightweightContext(Throwable $e): bool
    {
        foreach ($this->lightweightContextExceptions as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * Classify whether an exception originates from the framework
     */
    protected function isFrameworkException(Throwable $e): bool
    {
        $frameworkTypes = [
            \ErrorException::class,
            \Error::class,
            \ParseError::class,
            \TypeError::class,
            \ArgumentCountError::class,
            HttpProtocolException::class,
            HttpAuthException::class,
        ];

        if (in_array(get_class($e), $frameworkTypes, true)) {
            return true;
        }

        // Path-based: files within framework src/ are framework exceptions
        $file = $e->getFile();
        if ($file === '') {
            return false;
        }

        $fileReal = realpath($file) !== false ? realpath($file) : $file;
        $frameworkSrc = realpath(dirname(__DIR__, 2));

        if ($frameworkSrc !== false) {
            if (strncmp($fileReal, $frameworkSrc . DIRECTORY_SEPARATOR, strlen($frameworkSrc) + 1) === 0) {
                return true;
            }
        }

        // Namespace-based fallback
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['class']) && is_string($frame['class'])) {
                if (strncmp($frame['class'], 'Glueful\\', 8) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Render an exception into an HTTP response
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
     */
    protected function renderHttpException(HttpException $e): Response
    {
        $statusCode = $e->getStatusCode();
        $headers = $e->getHeaders();

        $message = $e->getMessage();

        $error = $this->buildErrorDetails($e, $statusCode);

        if ($this->debug && $e->getContext() !== null) {
            $error['context'] = $e->getContext();
        }

        return new Response([
            'success' => false,
            'message' => $message !== '' ? $message : $this->getDefaultMessage($statusCode),
            'error' => $error,
        ], $statusCode, $headers);
    }

    /**
     * Render a permission unauthorized exception
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
     */
    protected function getRequestId(): string
    {
        if (function_exists('request_id')) {
            return request_id();
        }

        if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            return $_SERVER['HTTP_X_REQUEST_ID'];
        }

        if (isset($_SERVER['REQUEST_ID'])) {
            return $_SERVER['REQUEST_ID'];
        }

        return 'req_' . bin2hex(random_bytes(6));
    }

    // ===== Configuration API =====

    /**
     * Add an exception type to the don't report list
     *
     * @param class-string<Throwable> $exception
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
     * @param class-string<Throwable> $exception
     * @param callable(Throwable, ?Request): Response $renderer
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
     * @param callable(Throwable, ?Request): void $reporter
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
     * @param class-string<Throwable> $exception
     * @param int $statusCode HTTP status code (400-599)
     * @return static
     */
    public function map(string $exception, int $statusCode): static
    {
        $this->httpMapping[$exception] = $statusCode;

        return $this;
    }

    /**
     * Register a custom channel mapping for an exception class
     *
     * @param class-string<Throwable> $exceptionClass
     * @param string $channel Log channel name
     * @return static
     */
    public function mapChannel(string $exceptionClass, string $channel): static
    {
        $this->channelMap[$exceptionClass] = $channel;

        return $this;
    }

    /**
     * Enable or disable verbose context building
     */
    public function setVerboseContext(bool $enabled): static
    {
        $this->verboseContext = $enabled;

        return $this;
    }

    /**
     * Enable or disable test mode
     *
     * In test mode, handle() captures the response array instead of outputting it.
     */
    public function setTestMode(bool $enabled): void
    {
        $this->testMode = $enabled;
        $this->testResponse = null;
    }

    /**
     * Get the captured test response
     *
     * @return array<string, mixed>|null
     */
    public function getTestResponse(): ?array
    {
        return $this->testResponse;
    }

    /**
     * Handle an exception in test mode, capturing the response as an array
     *
     * Used by the bootstrap shim (ExceptionHandler) when in test mode.
     *
     * @param Throwable $e The exception to handle
     */
    public function handleForTest(Throwable $e): void
    {
        // Report if appropriate
        if ($this->shouldReport($e)) {
            $this->report($e);
        }

        $response = $this->render($e);
        $statusCode = $response->getStatusCode();

        $this->testResponse = [
            'success' => $statusCode < 400 ? true : false,
            'message' => $this->extractMessage($response),
            'code' => $statusCode,
        ];
    }

    /**
     * Extract the message from a response for test capture
     */
    private function extractMessage(Response $response): string
    {
        $content = $response->getContent();
        if ($content === false) {
            return 'An error occurred';
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return 'An error occurred';
    }

    /**
     * Check if debug mode is enabled
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Set debug mode
     *
     * @return static
     */
    public function setDebug(bool $debug): static
    {
        $this->debug = $debug;

        return $this;
    }
}
