<?php

declare(strict_types=1);

namespace Glueful\Notifications;

use Glueful\Notifications\Contracts\Notifiable;

/**
 * A user recipient rebuilt from a stored notifiable reference (scheduled sends, retries).
 *
 * Routes 'email' to the address the identity provider reported when it was rebuilt; every other
 * channel addresses the user by uuid (the database channel) and gets no route here.
 */
final class UserNotifiable implements Notifiable
{
    public function __construct(
        private readonly string $uuid,
        private readonly ?string $email = null
    ) {
    }

    public function routeNotificationFor(string $channel)
    {
        return $channel === 'email' ? $this->email : null;
    }

    public function getNotifiableId(): string
    {
        return $this->uuid;
    }

    public function getNotifiableType(): string
    {
        return 'user';
    }

    public function shouldReceiveNotification(string $notificationType, string $channel): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function getNotificationPreferences(): array
    {
        return [];
    }
}
