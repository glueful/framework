<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

final class SerializationFailureException extends DatabaseException implements RetryableTransactionFailureInterface
{
}
