<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Base class for classified database failures, and the generic fallback for
 * failures no rule matches.
 *
 * Extends \PDOException so every existing catch (\PDOException) site in the
 * framework, extensions, and applications keeps matching classified failures.
 */
class DatabaseException extends \PDOException implements DatabaseExceptionInterface
{
    protected ?string $sqlStateValue = null;
    protected int|string|null $driverCodeValue = null;
    protected string $driverName = '';

    final public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Build a classified exception from a raw PDO failure, preserving all of
     * its state. Inheritance alone copies nothing.
     */
    public static function fromPdo(\PDOException $e, string $driver): static
    {
        $exception = new static($e->getMessage(), 0, $e);
        // \Exception's constructor only accepts int codes; PDO uses string
        // SQLSTATEs, so the original code is restored by property assignment.
        /** @var int|string $originalCode */
        $originalCode = $e->getCode();
        $exception->code = $originalCode;
        $exception->errorInfo = $e->errorInfo;
        $exception->sqlStateValue = self::extractSqlState($e);
        $exception->driverCodeValue = self::extractDriverCode($e);
        $exception->driverName = $driver;

        return $exception;
    }

    public function sqlState(): ?string
    {
        return $this->sqlStateValue;
    }

    public function driverCode(): int|string|null
    {
        return $this->driverCodeValue;
    }

    public function driver(): string
    {
        return $this->driverName;
    }

    /**
     * SQLSTATE from errorInfo[0], falling back to getCode() only when it
     * has the five-character alphanumeric SQLSTATE shape.
     */
    public static function extractSqlState(\PDOException $e): ?string
    {
        $candidate = $e->errorInfo[0] ?? null;
        if (is_string($candidate) && preg_match('/^[A-Z0-9]{5}$/', $candidate) === 1) {
            return $candidate;
        }

        $code = $e->getCode();
        if (is_string($code) && preg_match('/^[A-Z0-9]{5}$/', $code) === 1) {
            return $code;
        }

        return null;
    }

    /** Driver-specific error code from errorInfo[1]. */
    public static function extractDriverCode(\PDOException $e): int|string|null
    {
        $value = $e->errorInfo[1] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
    }
}
