# Exception Handler with HTTP Mapping Implementation Plan

> A comprehensive plan for implementing a centralized exception handler that maps exceptions to appropriate HTTP responses.

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [Exception Handler Class](#exception-handler-class)
6. [HTTP Exception Classes](#http-exception-classes)
7. [Renderable Exceptions](#renderable-exceptions)
8. [Exception Reporting](#exception-reporting)
9. [Environment-Aware Responses](#environment-aware-responses)
10. [Implementation Phases](#implementation-phases)
11. [Testing Strategy](#testing-strategy)
12. [API Reference](#api-reference)

---

## Executive Summary

This document outlines the implementation of a centralized Exception Handler for Glueful Framework. The handler provides:

- **Automatic HTTP status mapping** from exception types
- **Standardized error responses** across all endpoints
- **Environment-aware detail exposure** (verbose in dev, minimal in prod)
- **Exception reporting** to logging and external services
- **Custom exception rendering** via interface
- **Don't Report** list for expected exceptions

The implementation centralizes error handling logic that's currently scattered across controllers and middleware.

---

## Goals and Non-Goals

### Goals

- ✅ Centralize all exception handling in one place
- ✅ Map exceptions to appropriate HTTP status codes
- ✅ Standardize error response format
- ✅ Hide sensitive details in production
- ✅ Integrate with existing logging system
- ✅ Allow custom exception rendering
- ✅ Support "don't report" exceptions

### Non-Goals

- ❌ Replace PHP's error handling (exceptions only)
- ❌ Provide error monitoring service (use extensions)
- ❌ Handle fatal errors (PHP limitation)
- ❌ Generate error pages for HTML responses (API-focused)

---

## Current State Analysis

### Existing Infrastructure

Glueful has some exception-related components:

```
src/Http/
├── Response.php                    # Has error() method
├── SecureErrorResponse.php         # Security-focused error responses
├── Exceptions/
│   ├── HttpException.php           # Base HTTP exception
│   ├── HttpResponseException.php   # Exception with response
│   └── HttpClientException.php     # HTTP client errors

src/Validation/
└── ValidationException.php         # Validation errors

src/Events/Http/
└── ExceptionEvent.php              # Exception event
```

### Current Error Handling Pattern

```php
// Current: Scattered try-catch blocks in controllers
public function store(Request $request): Response
{
    try {
        $user = $this->userService->create($request->all());
        return Response::created($user);
    } catch (ValidationException $e) {
        return Response::error($e->getMessage(), 422, ['errors' => $e->errors()]);
    } catch (DuplicateEntryException $e) {
        return Response::error('User already exists', 409);
    } catch (\Exception $e) {
        Log::error('User creation failed', ['error' => $e->getMessage()]);
        return Response::error('An error occurred', 500);
    }
}
```

### Problems with Current Approach

| Problem | Impact |
|---------|--------|
| Repeated try-catch blocks | DRY violation, inconsistent handling |
| Manual status code mapping | Error-prone, inconsistent |
| Verbose error details in prod | Security risk |
| No centralized logging | Missed error tracking |
| Custom exceptions not mapped | Generic 500 errors |

---

## Architecture Design

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                       Request Lifecycle                         │
│                                                                 │
│  Request → Middleware → Controller → Response                   │
│                │            │                                   │
│                │      Exception thrown                          │
│                │            │                                   │
│                ▼            ▼                                   │
│         ┌──────────────────────────────────────┐                │
│         │         Exception Handler            │                │
│         │                                      │                │
│         │  1. Should Report? → Log/Report      │                │
│         │  2. Is Renderable? → Custom render   │                │
│         │  3. Map to HTTP    → Status code     │                │
│         │  4. Build Response → JSON response   │                │
│         │                                      │                │
│         └──────────────────────────────────────┘                │
│                          │                                      │
│                          ▼                                      │
│                    Error Response                               │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
src/Http/Exceptions/
├── Handler.php                  # Main exception handler
├── ExceptionHandler.php         # Interface
├── Contracts/
│   ├── ExceptionHandlerInterface.php
│   └── RenderableException.php  # Custom render interface
│
├── HttpException.php            # Base HTTP exception (existing)
├── HttpResponseException.php    # Exception with response (existing)
│
├── Client/
│   ├── BadRequestException.php      # 400
│   ├── UnauthorizedException.php    # 401
│   ├── ForbiddenException.php       # 403
│   ├── NotFoundException.php        # 404
│   ├── MethodNotAllowedException.php # 405
│   ├── ConflictException.php        # 409
│   ├── UnprocessableEntityException.php # 422
│   └── TooManyRequestsException.php # 429
│
├── Server/
│   ├── InternalServerException.php  # 500
│   ├── ServiceUnavailableException.php # 503
│   └── GatewayTimeoutException.php  # 504
│
└── Domain/
    ├── ModelNotFoundException.php   # 404 for models
    ├── AuthenticationException.php  # 401 for auth
    ├── AuthorizationException.php   # 403 for authz
    └── TokenExpiredException.php    # 401 for expired tokens
```

---

## Exception Handler Class

### Handler Implementation

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions;

use Glueful\Http\Response;
use Glueful\Http\Exceptions\Contracts\ExceptionHandlerInterface;
use Glueful\Http\Exceptions\Contracts\RenderableException;
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
     * @var array<class-string<Throwable>, callable>
     */
    protected array $renderers = [];

    /**
     * Exception reporters (e.g., Sentry, Bugsnag)
     *
     * @var array<callable>
     */
    protected array $reporters = [];

    public function __construct(
        protected ?LoggerInterface $logger = null,
        protected bool $debug = false
    ) {
        $this->debug = $debug ?: (env('APP_DEBUG', false) || env('APP_ENV') === 'development');
    }

    /**
     * Handle an exception and return a response
     */
    public function handle(Throwable $e, ?Request $request = null): Response
    {
        // Report the exception
        if ($this->shouldReport($e)) {
            $this->report($e, $request);
        }

        // Dispatch exception event
        Event::dispatch(new ExceptionEvent($e, $request));

        // Render the exception
        return $this->render($e, $request);
    }

    /**
     * Determine if the exception should be reported
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
     */
    public function report(Throwable $e, ?Request $request = null): void
    {
        // Log the exception
        $this->logger?->error($e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'request' => $request ? [
                'method' => $request->getMethod(),
                'uri' => $request->getRequestUri(),
                'ip' => $request->getClientIp(),
            ] : null,
        ]);

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
            $e instanceof HttpException => $this->renderHttpException($e),
            $e instanceof HttpResponseException => $e->getResponse(),
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

        $response = new Response([
            'success' => false,
            'message' => $e->getMessage() ?: $this->getDefaultMessage($statusCode),
            'error' => $this->buildErrorDetails($e, $statusCode),
        ], $statusCode, $headers);

        return $response;
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

        // Check if exception has a status code
        if (method_exists($e, 'getStatusCode')) {
            return $e->getStatusCode();
        }

        // Check exception code (if it's a valid HTTP code)
        $code = $e->getCode();
        if ($code >= 400 && $code < 600) {
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
     * @return array<array{file: string, line: int, function: string, class?: string}>
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
        return $_SERVER['HTTP_X_REQUEST_ID']
            ?? $_SERVER['REQUEST_ID']
            ?? substr(md5(uniqid('', true)), 0, 12);
    }

    /**
     * Add an exception type to the don't report list
     *
     * @param class-string<Throwable> $exception
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
     */
    public function map(string $exception, int $statusCode): static
    {
        $this->httpMapping[$exception] = $statusCode;

        return $this;
    }
}
```

### Exception Handler Interface

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Contracts;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Exception Handler Interface
 */
interface ExceptionHandlerInterface
{
    /**
     * Handle an exception and return a response
     */
    public function handle(Throwable $e, ?Request $request = null): Response;

    /**
     * Determine if the exception should be reported
     */
    public function shouldReport(Throwable $e): bool;

    /**
     * Report an exception
     */
    public function report(Throwable $e, ?Request $request = null): void;

    /**
     * Render an exception into an HTTP response
     */
    public function render(Throwable $e, ?Request $request = null): Response;
}
```

---

## HTTP Exception Classes

### Base HttpException

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions;

use Exception;

/**
 * Base HTTP Exception
 *
 * All HTTP-related exceptions should extend this class.
 */
class HttpException extends Exception
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        protected int $statusCode,
        string $message = '',
        protected array $headers = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Set additional response headers
     *
     * @param array<string, string> $headers
     */
    public function setHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }
}
```

### Client Error Exceptions (4xx)

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions;

/**
 * 400 Bad Request
 */
class BadRequestException extends HttpException
{
    public function __construct(
        string $message = 'Bad Request',
        array $headers = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(400, $message, $headers, 0, $previous);
    }
}

/**
 * 401 Unauthorized
 */
class UnauthorizedException extends HttpException
{
    public function __construct(
        string $message = 'Unauthorized',
        array $headers = [],
        ?\Throwable $previous = null
    ) {
        $headers['WWW-Authenticate'] ??= 'Bearer';
        parent::__construct(401, $message, $headers, 0, $previous);
    }
}

/**
 * 403 Forbidden
 */
class ForbiddenException extends HttpException
{
    public function __construct(
        string $message = 'Forbidden',
        array $headers = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(403, $message, $headers, 0, $previous);
    }
}

/**
 * 404 Not Found
 */
class NotFoundException extends HttpException
{
    public function __construct(
        string $message = 'Not Found',
        array $headers = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(404, $message, $headers, 0, $previous);
    }
}

/**
 * 405 Method Not Allowed
 */
class MethodNotAllowedException extends HttpException
{
    /**
     * @param array<string> $allowedMethods
     */
    public function __construct(
        array $allowedMethods = [],
        string $message = 'Method Not Allowed',
        ?\Throwable $previous = null
    ) {
        $headers = [];
        if (!empty($allowedMethods)) {
            $headers['Allow'] = implode(', ', $allowedMethods);
        }

        parent::__construct(405, $message, $headers, 0, $previous);
    }
}

/**
 * 409 Conflict
 */
class ConflictException extends HttpException
{
    public function __construct(
        string $message = 'Conflict',
        array $headers = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(409, $message, $headers, 0, $previous);
    }
}

/**
 * 422 Unprocessable Entity
 */
class UnprocessableEntityException extends HttpException
{
    public function __construct(
        string $message = 'Unprocessable Entity',
        array $headers = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(422, $message, $headers, 0, $previous);
    }
}

/**
 * 429 Too Many Requests
 */
class TooManyRequestsException extends HttpException
{
    public function __construct(
        int $retryAfter = 60,
        string $message = 'Too Many Requests',
        ?\Throwable $previous = null
    ) {
        parent::__construct(429, $message, [
            'Retry-After' => (string) $retryAfter,
        ], 0, $previous);
    }
}
```

### Domain Exceptions

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions;

/**
 * Model Not Found Exception
 *
 * Thrown when an ORM model is not found.
 */
class ModelNotFoundException extends HttpException
{
    protected string $model = '';
    protected mixed $ids = [];

    public function __construct(
        string $message = 'Resource not found',
        ?\Throwable $previous = null
    ) {
        parent::__construct(404, $message, [], 0, $previous);
    }

    /**
     * Set the affected model
     *
     * @param class-string $model
     * @param mixed $ids
     */
    public function setModel(string $model, mixed $ids = []): static
    {
        $this->model = $model;
        $this->ids = is_array($ids) ? $ids : [$ids];

        $this->message = "No query results for model [{$model}]";

        if (count($this->ids) > 0) {
            $this->message .= ' with ID(s): ' . implode(', ', $this->ids);
        }

        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @return array<mixed>
     */
    public function getIds(): array
    {
        return $this->ids;
    }
}

/**
 * Authentication Exception
 *
 * Thrown when authentication fails.
 */
class AuthenticationException extends HttpException
{
    public function __construct(
        string $message = 'Unauthenticated',
        protected array $guards = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(401, $message, [
            'WWW-Authenticate' => 'Bearer',
        ], 0, $previous);
    }

    /**
     * @return array<string>
     */
    public function guards(): array
    {
        return $this->guards;
    }
}

/**
 * Authorization Exception
 *
 * Thrown when authorization fails.
 */
class AuthorizationException extends HttpException
{
    public function __construct(
        string $message = 'This action is unauthorized.',
        protected ?string $ability = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct(403, $message, [], 0, $previous);
    }

    public function ability(): ?string
    {
        return $this->ability;
    }
}

/**
 * Token Expired Exception
 *
 * Thrown when a JWT or other token has expired.
 */
class TokenExpiredException extends HttpException
{
    public function __construct(
        string $message = 'Token has expired',
        ?\Throwable $previous = null
    ) {
        parent::__construct(401, $message, [
            'WWW-Authenticate' => 'Bearer error="invalid_token", error_description="The access token has expired"',
        ], 0, $previous);
    }
}
```

---

## Renderable Exceptions

### RenderableException Interface

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Contracts;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renderable Exception Interface
 *
 * Implement this interface to provide custom rendering logic for exceptions.
 */
interface RenderableException
{
    /**
     * Render the exception into an HTTP response
     */
    public function render(?Request $request = null): Response;
}
```

### Example Renderable Exception

```php
<?php

namespace App\Exceptions;

use Glueful\Http\Exceptions\HttpException;
use Glueful\Http\Exceptions\Contracts\RenderableException;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Quota Exceeded Exception
 *
 * Custom exception with specific rendering logic.
 */
class QuotaExceededException extends HttpException implements RenderableException
{
    public function __construct(
        private string $resource,
        private int $limit,
        private int $used,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            429,
            "Quota exceeded for {$resource}",
            ['Retry-After' => '3600'],
            0,
            $previous
        );
    }

    public function render(?Request $request = null): Response
    {
        return new Response([
            'success' => false,
            'message' => $this->getMessage(),
            'error' => [
                'code' => 'QUOTA_EXCEEDED',
                'resource' => $this->resource,
                'limit' => $this->limit,
                'used' => $this->used,
                'remaining' => max(0, $this->limit - $this->used),
                'reset_at' => date('c', strtotime('+1 hour')),
            ],
        ], 429, $this->getHeaders());
    }
}
```

---

## Exception Reporting

### Configuring Reporters

```php
<?php

// In a service provider or bootstrap

use Glueful\Http\Exceptions\Handler;

$handler = app(Handler::class);

// Report to Sentry
$handler->reportUsing(function (Throwable $e, ?Request $request) {
    if (class_exists(\Sentry\SentrySdk::class)) {
        \Sentry\captureException($e);
    }
});

// Report to Bugsnag
$handler->reportUsing(function (Throwable $e, ?Request $request) {
    if (class_exists(\Bugsnag\Client::class)) {
        app(\Bugsnag\Client::class)->notifyException($e);
    }
});

// Custom Slack notification for critical errors
$handler->reportUsing(function (Throwable $e, ?Request $request) {
    if ($e->getCode() >= 500) {
        app(SlackNotifier::class)->critical($e);
    }
});
```

### Don't Report Configuration

```php
<?php

// Add exceptions that shouldn't be reported
$handler->dontReport(App\Exceptions\BusinessRuleException::class);
$handler->dontReport(App\Exceptions\UserCancelledException::class);
```

---

## Environment-Aware Responses

### Development Response (APP_DEBUG=true)

```json
{
    "success": false,
    "message": "Call to undefined method User::nonExistentMethod()",
    "error": {
        "code": 500,
        "timestamp": "2026-01-21T10:30:00+00:00",
        "request_id": "a1b2c3d4e5f6",
        "exception": "Error",
        "file": "/var/www/app/Services/UserService.php",
        "line": 45,
        "trace": [
            {
                "file": "/var/www/app/Services/UserService.php",
                "line": 45,
                "function": "nonExistentMethod",
                "class": "App\\Models\\User"
            },
            {
                "file": "/var/www/app/Http/Controllers/UserController.php",
                "line": 23,
                "function": "process",
                "class": "App\\Services\\UserService"
            }
        ]
    }
}
```

### Production Response (APP_DEBUG=false)

```json
{
    "success": false,
    "message": "Internal Server Error",
    "error": {
        "code": 500,
        "timestamp": "2026-01-21T10:30:00+00:00",
        "request_id": "a1b2c3d4e5f6"
    }
}
```

---

## Middleware Integration

### Exception Handling Middleware

```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Glueful\Routing\Middleware\RouteMiddleware;
use Glueful\Http\Exceptions\Handler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Exception Handling Middleware
 *
 * Catches exceptions and delegates to the exception handler.
 */
class ExceptionMiddleware implements RouteMiddleware
{
    public function __construct(
        private Handler $handler
    ) {
    }

    public function handle(Request $request, callable $next, ...$params): Response
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            return $this->handler->handle($e, $request);
        }
    }
}
```

---

## Implementation Phases

### Phase 1: Core Handler (Week 1)

**Deliverables:**
- [ ] `Handler` class
- [ ] `ExceptionHandlerInterface`
- [ ] HTTP status code mapping
- [ ] Environment-aware responses
- [ ] Basic logging integration

**Acceptance Criteria:**
```php
// Exceptions automatically mapped to responses
throw new NotFoundException('User not found');
// Returns 404 with proper JSON structure

throw new UnauthorizedException();
// Returns 401 with WWW-Authenticate header
```

### Phase 2: Exception Classes (Week 1)

**Deliverables:**
- [ ] All client error exceptions (4xx)
- [ ] All server error exceptions (5xx)
- [ ] Domain exceptions (Model, Auth, etc.)
- [ ] `RenderableException` interface

**Acceptance Criteria:**
```php
// Domain exceptions work correctly
throw (new ModelNotFoundException())->setModel(User::class, $id);
// Returns 404 with model info

throw new TooManyRequestsException(retryAfter: 60);
// Returns 429 with Retry-After header
```

### Phase 3: Integration (Week 2)

**Deliverables:**
- [ ] `ExceptionMiddleware`
- [ ] Service provider registration
- [ ] Reporters integration
- [ ] Custom renderer support

**Acceptance Criteria:**
```php
// Custom exception rendering
class MyException extends HttpException implements RenderableException
{
    public function render(?Request $request): Response { ... }
}

// Custom reporters
$handler->reportUsing(fn($e) => Sentry::captureException($e));
```

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Http\Exceptions;

use PHPUnit\Framework\TestCase;
use Glueful\Http\Exceptions\Handler;
use Glueful\Http\Exceptions\NotFoundException;
use Glueful\Http\Exceptions\ModelNotFoundException;

class HandlerTest extends TestCase
{
    public function testMapsExceptionToStatusCode(): void
    {
        $handler = new Handler(debug: false);
        $response = $handler->handle(new NotFoundException('User not found'));

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testDoesNotReportExpectedExceptions(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $handler = new Handler($logger);
        $handler->handle(new NotFoundException());
    }

    public function testHidesDetailsInProduction(): void
    {
        $handler = new Handler(debug: false);
        $response = $handler->handle(new \Exception('Sensitive error'));

        $data = json_decode($response->getContent(), true);

        $this->assertArrayNotHasKey('trace', $data['error']);
        $this->assertArrayNotHasKey('file', $data['error']);
    }

    public function testShowsDetailsInDebugMode(): void
    {
        $handler = new Handler(debug: true);
        $response = $handler->handle(new \Exception('Error'));

        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('trace', $data['error']);
        $this->assertArrayHasKey('file', $data['error']);
    }
}
```

### Integration Tests

```php
<?php

namespace Glueful\Tests\Integration\Http\Exceptions;

use Glueful\Tests\TestCase;

class ExceptionHandlingTest extends TestCase
{
    public function testNotFoundReturns404(): void
    {
        $response = $this->get('/api/users/nonexistent-id');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function testValidationReturns422(): void
    {
        $response = $this->post('/api/users', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'success',
            'message',
            'errors',
        ]);
    }

    public function testUnauthorizedReturns401WithHeader(): void
    {
        $response = $this->get('/api/protected-route');

        $response->assertStatus(401);
        $response->assertHeader('WWW-Authenticate');
    }
}
```

---

## API Reference

### Handler Methods

| Method | Description |
|--------|-------------|
| `handle($e, $request)` | Handle exception, return response |
| `shouldReport($e)` | Check if exception should be logged |
| `report($e, $request)` | Log/report the exception |
| `render($e, $request)` | Render exception to response |
| `dontReport($class)` | Add to don't-report list |
| `renderable($class, $fn)` | Register custom renderer |
| `reportUsing($fn)` | Register reporter callback |
| `map($class, $code)` | Map exception to status code |

### Exception Classes

| Exception | Status | Use Case |
|-----------|--------|----------|
| `BadRequestException` | 400 | Malformed request |
| `UnauthorizedException` | 401 | Missing/invalid auth |
| `ForbiddenException` | 403 | Insufficient permissions |
| `NotFoundException` | 404 | Resource not found |
| `MethodNotAllowedException` | 405 | Wrong HTTP method |
| `ConflictException` | 409 | Resource conflict |
| `UnprocessableEntityException` | 422 | Validation failed |
| `TooManyRequestsException` | 429 | Rate limited |
| `InternalServerException` | 500 | Server error |
| `ServiceUnavailableException` | 503 | Service down |
| `ModelNotFoundException` | 404 | ORM model not found |
| `AuthenticationException` | 401 | Auth failed |
| `AuthorizationException` | 403 | Authz failed |
| `TokenExpiredException` | 401 | Token expired |
