<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Connection lost during statement execution or transaction control.
 *
 * Transient but NOT transaction-retryable: replaying a transaction on a dead
 * connection requires reconnect and transaction-state handling that the
 * framework does not provide yet.
 */
final class ConnectionLostException extends DatabaseException implements TransientFailureInterface
{
}
