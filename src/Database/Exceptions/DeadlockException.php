<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

final class DeadlockException extends DatabaseException implements RetryableTransactionFailureInterface
{
}
