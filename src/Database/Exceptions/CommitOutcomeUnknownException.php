<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

/**
 * The connection was lost while COMMIT was in flight: the server may have
 * committed before the acknowledgement was lost. Replaying could duplicate
 * writes, so this failure is NEVER retried automatically — it implements
 * no transient marker. The classified loss is chained as previous.
 */
final class CommitOutcomeUnknownException extends DatabaseException
{
    public static function fromLoss(ConnectionLostException $loss): self
    {
        $exception = new self(
            'Transaction commit outcome unknown: the connection was lost while COMMIT was in flight. '
            . 'The transaction may or may not have committed; it was NOT replayed.',
            0,
            $loss
        );
        // \Exception's constructor only accepts int codes; PDO uses string
        // SQLSTATEs. The annotated intermediate keeps level 8 from seeing a
        // mixed assignment — exactly DatabaseException::fromPdo's pattern.
        /** @var int|string $originalCode */
        $originalCode = $loss->getCode();
        $exception->code = $originalCode;
        $exception->errorInfo = $loss->errorInfo;
        $exception->sqlStateValue = $loss->sqlState();
        $exception->driverCodeValue = $loss->driverCode();
        $exception->driverName = $loss->driver();

        return $exception;
    }
}
