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
 * No message matching, with ONE narrow exception: SQLite's bare
 * SQLITE_CONSTRAINT (19) — the default, since the framework does not enable
 * extended result codes — is disambiguated by SQLite's fixed, non-localized
 * message prefixes ("UNIQUE constraint failed", "FOREIGN KEY constraint
 * failed", "NOT NULL constraint failed"). Unrecognized wording (e.g. CHECK)
 * stays the generic ConstraintViolationException. Other drivers' wording
 * varies by version and locale and is never consulted.
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
            // Bare SQLITE_CONSTRAINT: refined by SQLITE_CONSTRAINT_MESSAGES below.
            19 => ConstraintViolationException::class,
        ],
        // PostgreSQL SQLSTATEs are specific; the exact-SQLSTATE map suffices.
        'pgsql' => [],
    ];

    /**
     * SQLite's fixed (non-localized) constraint message prefixes, consulted only
     * for the bare SQLITE_CONSTRAINT code (19). Case-sensitive, matched at the
     * start of the driver message.
     */
    private const SQLITE_CONSTRAINT_MESSAGES = [
        'UNIQUE constraint failed' => UniqueConstraintViolationException::class,
        'PRIMARY KEY must be unique' => UniqueConstraintViolationException::class,
        'FOREIGN KEY constraint failed' => ForeignKeyConstraintViolationException::class,
        'NOT NULL constraint failed' => NotNullConstraintViolationException::class,
    ];

    private const SQLITE_CONSTRAINT = 19;

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
        if ($driver === 'sqlite' && $vendorCode === self::SQLITE_CONSTRAINT) {
            $class = $this->classifySqliteConstraint($exception) ?? $class;
        }
        if ($class === null && $sqlState !== null) {
            $class = self::SQLSTATE_MAP[$sqlState] ?? null;
        }
        if ($class === null && $sqlState !== null) {
            $class = self::SQLSTATE_FAMILY_MAP[substr($sqlState, 0, 2)] ?? null;
        }

        return ($class ?? DatabaseException::class)::fromPdo($exception, $driver);
    }

    /**
     * @return class-string<DatabaseException>|null
     */
    private function classifySqliteConstraint(\PDOException $exception): ?string
    {
        $driverMessage = $exception->errorInfo[2] ?? null;
        $candidates = is_string($driverMessage) ? [$driverMessage] : [];
        // PDO's own message embeds the driver text after "...: 19 ".
        $candidates[] = $exception->getMessage();

        foreach ($candidates as $message) {
            foreach (self::SQLITE_CONSTRAINT_MESSAGES as $prefix => $class) {
                if (str_starts_with($message, $prefix) || str_contains($message, ' 19 ' . $prefix)) {
                    return $class;
                }
            }
        }

        return null;
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
