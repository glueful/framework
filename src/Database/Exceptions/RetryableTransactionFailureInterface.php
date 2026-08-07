<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Marker for failures where re-running the whole transaction from the top is
 * a sound recovery strategy (deadlock victim, serialization failure, lock
 * contention). TransactionManager's retry loop keys on this interface only.
 */
interface RetryableTransactionFailureInterface extends TransientFailureInterface
{
}
