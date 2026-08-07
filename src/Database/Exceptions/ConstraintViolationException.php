<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * A constraint was violated but the driver did not say which kind.
 * Under default SQLite configuration (no extended result codes), all
 * constraint failures land here — see the spec's SQLite decision.
 */
class ConstraintViolationException extends DatabaseException
{
}
