<?php

declare(strict_types=1);

namespace Glueful\Notifications\Exceptions;

use RuntimeException;

/**
 * Thrown when a notification job/command/task needs to dispatch but has no `ApplicationContext`.
 *
 * Notification dispatch is resolved through the container (the shared `ChannelManager` /
 * `NotificationDispatcher` carrying core + extension registrations). Without a context there is
 * no container to resolve from, and building ad-hoc managers would silently miss those
 * registrations — so these paths fail loudly instead.
 *
 * @package Glueful\Notifications\Exceptions
 */
final class NotificationContextRequiredException extends RuntimeException
{
    public static function forConsumer(string $consumer): self
    {
        return new self(sprintf(
            "%s requires an ApplicationContext to resolve the notification dispatcher; "
            . "none was available.",
            $consumer
        ));
    }
}
