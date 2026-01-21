<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * Authorization Exception
 *
 * Thrown when a user is authenticated but lacks the required permissions
 * to perform an action. Unlike AuthenticationException (who are you?),
 * this exception indicates a permissions issue (you can't do that).
 *
 * Tracks the ability (permission/action) that was denied, which is useful
 * for debugging and logging authorization failures.
 *
 * @example
 * // Basic usage
 * throw new AuthorizationException('You cannot delete this resource');
 *
 * @example
 * // With ability context
 * throw new AuthorizationException(
 *     message: 'Insufficient permissions to manage users',
 *     ability: 'users.manage'
 * );
 */
class AuthorizationException extends HttpException
{
    /**
     * Create a new Authorization exception
     *
     * @param string $message Error message
     * @param string|null $ability The ability/permission that was denied
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'This action is unauthorized.',
        protected ?string $ability = null,
        ?Throwable $previous = null
    ) {
        parent::__construct(403, $message, [], 0, $previous);
    }

    /**
     * Get the ability that was denied
     *
     * @return string|null The ability/permission name, or null if not set
     */
    public function ability(): ?string
    {
        return $this->ability;
    }

    /**
     * Set the ability that was denied
     *
     * @param string $ability The ability/permission name
     * @return static
     */
    public function withAbility(string $ability): static
    {
        $this->ability = $ability;

        return $this;
    }
}
