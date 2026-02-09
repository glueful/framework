<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions\Domain;

use Glueful\Http\Exceptions\HttpException;
use Throwable;

/**
 * Database Exception
 *
 * Thrown when database operations fail, including connection failures,
 * query errors, constraint violations, and CRUD operation failures.
 *
 * @example
 * throw DatabaseException::connectionFailed('Connection refused');
 *
 * @example
 * throw DatabaseException::constraintViolation('users_email_unique');
 */
class DatabaseException extends HttpException
{
    /**
     * Create a new database exception
     *
     * @param string $message Exception message
     * @param int $statusCode HTTP status code (defaults to 500)
     * @param array<string, mixed>|null $context Additional error context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = 'Database operation failed',
        int $statusCode = 500,
        ?array $context = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($statusCode, $message, [], 0, $previous);
        $this->context = $context;
    }

    /**
     * Create exception for connection failure
     *
     * @param string $reason Connection failure reason
     * @param Throwable|null $previous Previous exception
     * @return static
     */
    public static function connectionFailed(string $reason, ?Throwable $previous = null): static
    {
        return new static(
            "Database connection failed: $reason",
            500,
            ['connection_error' => true, 'reason' => $reason],
            $previous
        );
    }

    /**
     * Create exception for query failure
     *
     * @param string $operation Database operation (SELECT, INSERT, etc.)
     * @param string $reason Failure reason
     * @param Throwable|null $previous Previous exception
     * @return static
     */
    public static function queryFailed(string $operation, string $reason, ?Throwable $previous = null): static
    {
        return new static(
            "Database $operation operation failed: $reason",
            500,
            ['query_error' => true, 'operation' => $operation, 'reason' => $reason],
            $previous
        );
    }

    /**
     * Create exception for constraint violation
     *
     * @param string $constraint Constraint name
     * @param Throwable|null $previous Previous exception
     * @return static
     */
    public static function constraintViolation(string $constraint, ?Throwable $previous = null): static
    {
        return new static(
            "Database constraint violation: $constraint",
            409,
            ['constraint_violation' => true, 'constraint' => $constraint],
            $previous
        );
    }

    /**
     * Create exception for record creation failures
     *
     * @param string $table Table name
     * @param Throwable|null $previous Previous exception
     * @return static
     */
    public static function createFailed(string $table, ?Throwable $previous = null): static
    {
        return new static(
            "Failed to create record in {$table}",
            500,
            ['create_error' => true, 'table' => $table],
            $previous
        );
    }

    /**
     * Create exception for record update failures
     *
     * @param string $table Table name
     * @param string $identifier Record identifier
     * @param Throwable|null $previous Previous exception
     * @return static
     */
    public static function updateFailed(string $table, string $identifier, ?Throwable $previous = null): static
    {
        return new static(
            "Failed to update record in {$table}: {$identifier}",
            500,
            ['update_error' => true, 'table' => $table, 'identifier' => $identifier],
            $previous
        );
    }

    /**
     * Create exception for record deletion failures
     *
     * @param string $table Table name
     * @param string $identifier Record identifier
     * @param Throwable|null $previous Previous exception
     * @return static
     */
    public static function deleteFailed(string $table, string $identifier, ?Throwable $previous = null): static
    {
        return new static(
            "Failed to delete record from {$table}: {$identifier}",
            500,
            ['delete_error' => true, 'table' => $table, 'identifier' => $identifier],
            $previous
        );
    }
}
