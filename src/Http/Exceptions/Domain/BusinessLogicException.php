<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * Business Logic Exception
 *
 * Thrown when a request violates business rules or logic constraints.
 * The request may be technically valid but cannot be processed due to
 * application business rules.
 *
 * @example
 * throw BusinessLogicException::operationNotAllowed('delete', 'Resource is locked');
 *
 * @example
 * throw BusinessLogicException::limitExceeded('api_calls', 950, 1000);
 */
class BusinessLogicException extends HttpException
{
    /**
     * Create a new business logic exception
     *
     * @param string $message Error message
     * @param array<string, mixed> $context Additional context information
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct(string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct(422, $message, [], 0, $previous);
        $this->context = $context !== [] ? $context : null;
    }

    /**
     * Create exception for operation not allowed
     *
     * @param string $operation Operation that was attempted
     * @param string $reason Why it's not allowed
     * @return static
     */
    public static function operationNotAllowed(string $operation, string $reason): static
    {
        return new static(
            "Operation '{$operation}' is not allowed: {$reason}",
            ['operation' => $operation, 'reason' => $reason]
        );
    }

    /**
     * Create exception for state conflicts
     *
     * @param string $resource Resource type
     * @param string $currentState Current state
     * @param string $requiredState Required state
     * @return static
     */
    public static function invalidState(string $resource, string $currentState, string $requiredState): static
    {
        return new static(
            "Cannot perform operation on {$resource}: currently '{$currentState}', requires '{$requiredState}'",
            [
                'resource' => $resource,
                'current_state' => $currentState,
                'required_state' => $requiredState,
            ]
        );
    }

    /**
     * Create exception for quota/limit violations
     *
     * @param string $resource Resource type
     * @param int $current Current count
     * @param int $limit Maximum allowed
     * @return static
     */
    public static function limitExceeded(string $resource, int $current, int $limit): static
    {
        return new static(
            "Limit exceeded for {$resource}: {$current}/{$limit}",
            [
                'resource' => $resource,
                'current' => $current,
                'limit' => $limit,
            ]
        );
    }

    /**
     * Create exception for dependency violations
     *
     * @param string $resource Resource being operated on
     * @param array<string> $dependencies List of dependent resources
     * @return static
     */
    public static function hasDependencies(string $resource, array $dependencies): static
    {
        return new static(
            "Cannot delete {$resource}: has dependencies on " . implode(', ', $dependencies),
            [
                'resource' => $resource,
                'dependencies' => $dependencies,
            ]
        );
    }
}
