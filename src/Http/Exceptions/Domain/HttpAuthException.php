<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;

/**
 * HTTP Authentication Exception
 *
 * Exception for HTTP protocol-level authentication failures such as
 * missing authorization headers, malformed tokens, and JWT format violations.
 * This is distinct from business authentication logic.
 *
 * @example
 * throw HttpAuthException::missingAuthorizationHeader();
 *
 * @example
 * throw HttpAuthException::invalidJwtFormat($token);
 */
class HttpAuthException extends HttpException
{
    /** @var string|null The authentication scheme that failed */
    protected ?string $authScheme = null;

    /** @var string|null Token prefix for logging (without sensitive data) */
    protected ?string $tokenPrefix = null;

    /**
     * Create a new HTTP auth exception
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (typically 401)
     * @param string|null $authScheme The authentication scheme (e.g., 'Bearer', 'Basic')
     * @param string|null $tokenPrefix Safe token prefix for logging
     */
    public function __construct(
        string $message,
        int $statusCode = 401,
        ?string $authScheme = null,
        ?string $tokenPrefix = null
    ) {
        parent::__construct($statusCode, $message, [
            'WWW-Authenticate' => $authScheme ?? 'Bearer',
        ]);
        $this->authScheme = $authScheme;
        $this->tokenPrefix = $tokenPrefix;
    }

    /**
     * Get the authentication scheme
     *
     * @return string|null
     */
    public function getAuthScheme(): ?string
    {
        return $this->authScheme;
    }

    /**
     * Get the token prefix (safe for logging)
     *
     * @return string|null
     */
    public function getTokenPrefix(): ?string
    {
        return $this->tokenPrefix;
    }

    /**
     * Create exception for missing authorization header
     *
     * @return static
     */
    public static function missingAuthorizationHeader(): static
    {
        return new static(
            'Authorization header required',
            401,
            null,
            null
        );
    }

    /**
     * Create exception for malformed authorization header
     *
     * @param string $headerValue The malformed header value (will be sanitized)
     * @return static
     */
    public static function malformedAuthorizationHeader(string $headerValue): static
    {
        $parts = explode(' ', $headerValue, 2);
        $scheme = $parts[0] ?? 'unknown';

        return new static(
            'Malformed authorization header',
            401,
            $scheme,
            null
        );
    }

    /**
     * Create exception for invalid JWT token format
     *
     * @param string $token The invalid token (will be sanitized)
     * @return static
     */
    public static function invalidJwtFormat(string $token): static
    {
        $tokenPrefix = strlen($token) > 10 ? substr($token, 0, 10) : null;

        return new static(
            'Invalid token format',
            401,
            'Bearer',
            $tokenPrefix
        );
    }

    /**
     * Create exception for expired token
     *
     * @param string $tokenType Type of token (defaults to 'Bearer')
     * @param string|null $token The expired token (will be sanitized)
     * @return static
     */
    public static function tokenExpired(string $tokenType = 'Bearer', ?string $token = null): static
    {
        $tokenPrefix = ($token !== null && strlen($token) > 10) ? substr($token, 0, 10) : null;

        return new static(
            'Token has expired',
            401,
            $tokenType,
            $tokenPrefix
        );
    }

    /**
     * Create exception for unsupported authentication scheme
     *
     * @param string $scheme The unsupported scheme
     * @return static
     */
    public static function unsupportedScheme(string $scheme): static
    {
        return new static(
            "Unsupported authentication scheme: {$scheme}",
            401,
            $scheme,
            null
        );
    }
}
