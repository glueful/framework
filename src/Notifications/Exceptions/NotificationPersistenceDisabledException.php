<?php

declare(strict_types=1);

namespace Glueful\Notifications\Exceptions;

use RuntimeException;

/**
 * Thrown when a durability-implying notification operation is attempted while notification
 * persistence is disabled (the `notifications` capability is off, so no store is backing it).
 *
 * Fire-and-forget operations (transient save, delivery-record writes) and reads degrade
 * silently; operations whose contract implies a durable store — preference writes, read/unread
 * state changes, scheduled/retry flows, maintenance deletes — fail loudly with this exception
 * rather than reporting a false success.
 *
 * @package Glueful\Notifications\Exceptions
 */
final class NotificationPersistenceDisabledException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(sprintf(
            "Notification persistence is disabled (NOTIFICATIONS_DATABASE_STORE=false); "
            . "the operation '%s' requires a notification store.",
            $operation
        ));
    }
}
