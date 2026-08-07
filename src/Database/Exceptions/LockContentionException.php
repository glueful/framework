<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Lock could not be acquired: MySQL 1205 lock-wait timeout, PostgreSQL 55P03
 * lock_not_available, SQLite SQLITE_BUSY / SQLITE_LOCKED.
 */
final class LockContentionException extends DatabaseException implements RetryableTransactionFailureInterface
{
}
