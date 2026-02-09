<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;

/**
 * HTTP Protocol Exception
 *
 * Thrown for HTTP protocol violations and malformed requests such as
 * malformed JSON, missing required headers, and other HTTP standard violations.
 *
 * @example
 * throw HttpProtocolException::malformedJson('Unexpected token at position 42');
 *
 * @example
 * throw HttpProtocolException::requestTooLarge(1048576, 5242880);
 */
class HttpProtocolException extends HttpException
{
    /** @var string|null The error code for categorizing protocol violations */
    protected ?string $errorCode = null;

    /** @var array<string, mixed> Additional context about the protocol violation */
    protected array $protocolContext = [];

    /**
     * Create a new HTTP protocol exception
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (typically 400)
     * @param string|null $errorCode Specific error code for categorization
     * @param array<string, mixed> $protocolContext Additional context about the violation
     */
    public function __construct(
        string $message,
        int $statusCode = 400,
        ?string $errorCode = null,
        array $protocolContext = []
    ) {
        parent::__construct($statusCode, $message);
        $this->errorCode = $errorCode;
        $this->protocolContext = $protocolContext;
        $this->context = ['error_code' => $errorCode];
    }

    /**
     * Get the protocol error code
     *
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Get additional protocol context
     *
     * @return array<string, mixed>
     */
    public function getProtocolContext(): array
    {
        return $this->protocolContext;
    }

    /**
     * Create exception for malformed JSON
     *
     * @param string $jsonError JSON error description
     * @return static
     */
    public static function malformedJson(string $jsonError = 'Invalid JSON format'): static
    {
        return new static(
            'Malformed JSON request body',
            400,
            'JSON_PARSE_ERROR',
            ['json_error' => $jsonError]
        );
    }

    /**
     * Create exception for missing required header
     *
     * @param string $headerName The missing header name
     * @return static
     */
    public static function missingHeader(string $headerName): static
    {
        return new static(
            "Missing required header: {$headerName}",
            400,
            'MISSING_HEADER',
            ['missing_header' => $headerName]
        );
    }

    /**
     * Create exception for invalid content type
     *
     * @param string $expected Expected content type
     * @param string $actual Actual content type
     * @return static
     */
    public static function invalidContentType(string $expected, string $actual): static
    {
        return new static(
            "Invalid content type. Expected {$expected}, got {$actual}",
            400,
            'INVALID_CONTENT_TYPE',
            ['expected' => $expected, 'actual' => $actual]
        );
    }

    /**
     * Create exception for request body too large
     *
     * @param int $maxSize Maximum allowed size
     * @param int $actualSize Actual request size
     * @return static
     */
    public static function requestTooLarge(int $maxSize, int $actualSize): static
    {
        return new static(
            "Request body too large. Maximum {$maxSize} bytes allowed",
            413,
            'REQUEST_TOO_LARGE',
            ['max_size' => $maxSize, 'actual_size' => $actualSize]
        );
    }
}
