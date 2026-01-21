<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions;

use Exception;
use Throwable;

/**
 * Base HTTP Exception
 *
 * Base exception class for all HTTP-related exceptions in the framework.
 * This class provides HTTP status code and header management for exceptions
 * that should be rendered as HTTP responses.
 *
 * All HTTP exception classes (client errors, server errors, domain exceptions)
 * should extend this class to leverage the exception handler's automatic
 * status code mapping and header support.
 *
 * @example
 * // Creating a custom HTTP exception
 * throw new HttpException(
 *     statusCode: 418,
 *     message: "I'm a teapot",
 *     headers: ['X-Teapot' => 'Yes']
 * );
 *
 * @example
 * // Extending for specific status codes
 * class PaymentRequiredException extends HttpException
 * {
 *     public function __construct(
 *         string $message = 'Payment Required',
 *         array $headers = [],
 *         ?Throwable $previous = null
 *     ) {
 *         parent::__construct(402, $message, $headers, 0, $previous);
 *     }
 * }
 */
class HttpException extends Exception
{
    /**
     * Create a new HTTP exception
     *
     * @param int $statusCode The HTTP status code (4xx or 5xx)
     * @param string $message The exception message
     * @param array<string, string> $headers Additional HTTP headers to include in the response
     * @param int $code The exception code (defaults to 0)
     * @param Throwable|null $previous The previous exception for chaining
     */
    public function __construct(
        protected int $statusCode,
        string $message = '',
        protected array $headers = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the HTTP status code
     *
     * @return int The HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the HTTP headers to include in the response
     *
     * @return array<string, string> The HTTP headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Set additional HTTP headers
     *
     * Merges the provided headers with existing headers.
     * Existing headers with the same key will be overwritten.
     *
     * @param array<string, string> $headers Headers to add/merge
     * @return static
     */
    public function setHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /**
     * Set a single HTTP header
     *
     * @param string $name Header name
     * @param string $value Header value
     * @return static
     */
    public function setHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Check if a specific header is set
     *
     * @param string $name Header name to check
     * @return bool
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    /**
     * Get a specific header value
     *
     * @param string $name Header name
     * @param string|null $default Default value if header doesn't exist
     * @return string|null
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->headers[$name] ?? $default;
    }
}
