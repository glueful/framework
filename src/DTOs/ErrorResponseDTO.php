<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Error Response DTO
 *
 * Standardized error response with controlled information exposure
 * based on environment and user permissions.
 */
class ErrorResponseDTO
{
    public bool $success = false;
    public string $error;
    public string $message;
    public int $code;
    public ?string $type = null;

    /** @var array<string, mixed>|null */
    public ?array $details = null;

    /** @var array<string, mixed>|null */
    public ?array $errorContext = null;

    /** @var array<string, mixed>|null */
    public ?array $validation = null;
    public ?string $file = null;
    public ?int $line = null;

    /** @var array<int, array<string, mixed>>|null */
    public ?array $trace = null;

    /** @var array<string, mixed>|null */
    public ?array $previous = null;
    public ?string $requestId = null;
    public ?string $userId = null;
    public ?string $requestUri = null;
    public ?string $requestMethod = null;
    public ?string $userAgent = null;
    public ?string $ipAddress = null;
    public \DateTime $timestamp;
    public ?\Throwable $originalException = null;
    private ?ApplicationContext $context = null;

    public function __construct(
        string $error,
        string $message,
        int $code = 500,
        ?string $type = null,
        ?ApplicationContext $context = null
    ) {
        $this->error = $error;
        $this->message = $message;
        $this->code = $code;
        $this->type = $type;
        $this->timestamp = new \DateTime();
        $this->context = $context;
    }

    /**
     * Create from exception
     */
    public static function fromException(
        \Throwable $exception,
        bool $includeTrace = false,
        ?ApplicationContext $context = null
    ): self {
        $error = new self(
            $exception::class,
            $exception->getMessage(),
            $exception->getCode() !== 0 ? $exception->getCode() : 500,
            'exception',
            $context
        );

        $error->originalException = $exception;
        $error->file = $exception->getFile();
        $error->line = $exception->getLine();

        if ($includeTrace) {
            $error->trace = array_slice($exception->getTrace(), 0, 10); // Limit trace
        }

        // Handle previous exceptions
        if ($exception->getPrevious() !== null) {
            $error->previous = [
                'class' => $exception->getPrevious()::class,
                'message' => $exception->getPrevious()->getMessage(),
                'code' => $exception->getPrevious()->getCode(),
            ];
        }

        return $error;
    }

    /**
     * Create validation error
     *
     * @param array<string, mixed> $errors
     */
    public static function createValidationError(
        array $errors,
        string $message = 'Validation failed',
        ?ApplicationContext $context = null
    ): self {
        $error = new self(
            'ValidationError',
            $message,
            422,
            'validation',
            $context
        );

        $error->validation = $errors;
        return $error;
    }

    /**
     * Create authentication error
     */
    public static function authentication(
        string $message = 'Authentication required',
        ?ApplicationContext $context = null
    ): self {
        return new self(
            'AuthenticationError',
            $message,
            401,
            'authentication',
            $context
        );
    }

    /**
     * Create authorization error
     */
    public static function authorization(
        string $message = 'Access denied',
        ?ApplicationContext $context = null
    ): self {
        return new self(
            'AuthorizationError',
            $message,
            403,
            'authorization',
            $context
        );
    }

    /**
     * Create not found error
     */
    public static function notFound(
        string $resource = 'Resource',
        ?string $identifier = null,
        ?ApplicationContext $context = null
    ): self {
        $message = $identifier !== null
            ? "{$resource} with identifier '{$identifier}' not found"
            : "{$resource} not found";

        return new self(
            'NotFoundError',
            $message,
            404,
            'not_found',
            $context
        );
    }

    /**
     * Create rate limit error
     */
    public static function rateLimit(?int $retryAfter = null, ?ApplicationContext $context = null): self
    {
        $error = new self(
            'RateLimitError',
            'Rate limit exceeded',
            429,
            'rate_limit',
            $context
        );

        if ($retryAfter !== null) {
            $error->details = ['retry_after' => $retryAfter];
        }

        return $error;
    }

    /**
     * Create server error
     */
    public static function server(
        string $message = 'Internal server error',
        ?ApplicationContext $context = null
    ): self {
        return new self(
            'ServerError',
            $message,
            500,
            'server',
            $context
        );
    }

    /**
     * Add request context
     */
    public function withRequestContext(
        string $requestId,
        string $method,
        string $uri,
        ?string $userId = null,
        ?string $userAgent = null,
        ?string $ipAddress = null
    ): self {
        $this->requestId = $requestId;
        $this->requestMethod = $method;
        $this->requestUri = $uri;
        $this->userId = $userId;
        $this->userAgent = $userAgent;
        $this->ipAddress = $ipAddress;
        return $this;
    }

    /**
     * Add additional details
     *
     * @param array<string, mixed> $details
     */
    public function withDetails(array $details): self
    {
        $this->details = array_merge($this->details ?? [], $details);
        return $this;
    }

    /**
     * Add context information
     *
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        $this->errorContext = array_merge($this->errorContext ?? [], $context);
        return $this;
    }

    /**
     * Get HTTP status code
     */
    public function getHttpStatusCode(): int
    {
        return match (true) {
            $this->code >= 400 && $this->code < 600 => $this->code,
            default => 500
        };
    }

    /**
     * Check if this is a client error
     */
    public function isClientError(): bool
    {
        return $this->getHttpStatusCode() >= 400 && $this->getHttpStatusCode() < 500;
    }

    /**
     * Check if this is a server error
     */
    public function isServerError(): bool
    {
        return $this->getHttpStatusCode() >= 500;
    }

    /**
     * Get safe message for production
     */
    public function getSafeMessage(): string
    {
        // In production, sanitize certain error messages
        if ($this->isServerError() && !$this->isDebugMode()) {
            return 'An internal error occurred. Please try again later.';
        }

        return $this->message;
    }

    private function isDebugMode(): bool
    {
        if ($this->context === null) {
            return false;
        }

        return (bool) config($this->context, 'app.debug', false);
    }

    /**
     * Get error summary for logging
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'error' => $this->error,
            'message' => $this->message,
            'code' => $this->code,
            'type' => $this->type,
            'file' => $this->file,
            'line' => $this->line,
            'request_id' => $this->requestId,
            'timestamp' => $this->timestamp->format('c'),
        ];
    }
}
