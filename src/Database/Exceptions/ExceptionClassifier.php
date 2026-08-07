<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Deterministic PDO-failure classifier: SQLSTATE plus vendor codes in, one
 * typed DatabaseException out. Stateless — construct anywhere.
 *
 * Precedence is specificity-first: driver vendor codes are consulted BEFORE
 * the exact-SQLSTATE map because MySQL reports deadlock 1213 with SQLSTATE
 * 40001 — SQLSTATE-first would misclassify it as a serialization failure.
 * No message matching: driver wording varies by version and locale.
 */
final class ExceptionClassifier
{
    /**
     * Unambiguous SQLSTATEs only — 23000 deliberately excluded (MySQL uses it for both unique and FK violations).
     */
    private const SQLSTATE_MAP = [
        '23505' => UniqueConstraintViolationException::class,
        '23503' => ForeignKeyConstraintViolationException::class,
        '23502' => NotNullConstraintViolationException::class,
        '40001' => SerializationFailureException::class,
        '40P01' => DeadlockException::class,
        '55P03' => LockContentionException::class,
        '57P01' => ConnectionLostException::class,
        '57P02' => ConnectionLostException::class,
        '57P03' => ConnectionLostException::class,
    ];

    /**
     * Vendor-specific error code mappings by database driver.
     */
    private const VENDOR_MAP = [
        'mysql' => [
            1062 => UniqueConstraintViolationException::class,
            1451 => ForeignKeyConstraintViolationException::class,
            1452 => ForeignKeyConstraintViolationException::class,
            1048 => NotNullConstraintViolationException::class,
            1213 => DeadlockException::class,
            1205 => LockContentionException::class,
            2006 => ConnectionLostException::class,
            2013 => ConnectionLostException::class,
        ],
        'sqlite' => [
            5 => LockContentionException::class,
            6 => LockContentionException::class,
            // Extended result codes — honored when supplied; the framework
            // does not enable PDO::SQLITE_ATTR_EXTENDED_RESULT_CODES itself
            // (see the spec's SQLite compatibility decision).
            2067 => UniqueConstraintViolationException::class,
            1555 => UniqueConstraintViolationException::class,
            1299 => NotNullConstraintViolationException::class,
            787 => ForeignKeyConstraintViolationException::class,
            // Bare SQLITE_CONSTRAINT: kind is unknowable without messages.
            19 => ConstraintViolationException::class,
        ],
        // PostgreSQL SQLSTATEs are specific; the exact-SQLSTATE map suffices.
        'pgsql' => [],
    ];

    /**
     * Keyed by two-character SQLSTATE class for fallback family matching.
     */
    private const SQLSTATE_FAMILY_MAP = [
        '23' => ConstraintViolationException::class,
        '08' => ConnectionLostException::class,
    ];

    public function classify(\PDOException $exception, string $driver): DatabaseException
    {
        if ($exception instanceof DatabaseException) {
            return $exception;
        }

        $sqlState = DatabaseException::extractSqlState($exception);
        $vendorCode = $this->normalizeVendorCode(DatabaseException::extractDriverCode($exception));

        $class = null;
        if ($vendorCode !== null) {
            $class = self::VENDOR_MAP[$driver][$vendorCode] ?? null;
        }
        if ($class === null && $sqlState !== null) {
            $class = self::SQLSTATE_MAP[$sqlState] ?? null;
        }
        if ($class === null && $sqlState !== null) {
            $class = self::SQLSTATE_FAMILY_MAP[substr($sqlState, 0, 2)] ?? null;
        }

        return ($class ?? DatabaseException::class)::fromPdo($exception, $driver);
    }

    private function normalizeVendorCode(int|string|null $code): ?int
    {
        if (is_int($code)) {
            return $code;
        }
        if (is_string($code) && preg_match('/^\d+$/', $code) === 1) {
            return (int) $code;
        }

        return null;
    }
}
