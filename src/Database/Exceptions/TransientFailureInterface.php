<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * Marker for failures that may succeed on a later attempt in SOME context
 * (e.g. after reconnecting). Implementing this does NOT mean the failure is
 * safe to retry inside the current transaction — that is
 * RetryableTransactionFailureInterface.
 */
interface TransientFailureInterface extends DatabaseExceptionInterface
{
}
