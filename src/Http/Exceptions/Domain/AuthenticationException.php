<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * Authentication Exception
 *
 * Thrown when authentication fails or is required but not provided.
 * This is a domain-specific exception for authentication-related failures.
 *
 * This exception tracks which authentication guards were attempted,
 * which is useful for debugging multi-guard authentication systems.
 *
 * The response includes the WWW-Authenticate header per RFC 7235.
 *
 * @example
 * // Basic usage
 * throw new AuthenticationException('Invalid credentials');
 *
 * @example
 * // With guards context
 * throw new AuthenticationException(
 *     message: 'Token authentication failed',
 *     guards: ['jwt', 'api']
 * );
 */
class AuthenticationException extends HttpException
{
    /**
     * Create a new Authentication exception
     *
     * @param string $message Error message
     * @param array<string> $guards The guards that were attempted
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Unauthenticated',
        protected array $guards = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(401, $message, [
            'WWW-Authenticate' => 'Bearer',
        ], 0, $previous);
    }

    /**
     * Get the guards that were attempted
     *
     * @return array<string>
     */
    public function guards(): array
    {
        return $this->guards;
    }

    /**
     * Add a guard that was attempted
     *
     * @param string $guard The guard name
     * @return static
     */
    public function addGuard(string $guard): static
    {
        $this->guards[] = $guard;

        return $this;
    }
}
