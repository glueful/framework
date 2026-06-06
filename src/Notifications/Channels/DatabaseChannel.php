<?php

declare(strict_types=1);

namespace Glueful\Notifications\Channels;

use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;

/**
 * Database (in-app) notification channel.
 *
 * Notifications are already persisted by {@see \Glueful\Notifications\Services\NotificationService}
 * before dispatch, and delivery records are owned by the service (ensureDeliveryRecords /
 * recordDeliveryAttempt). This channel therefore performs **no** writes of its own — it simply
 * acknowledges in-app delivery so the `database` channel is a real, registered channel rather
 * than a `channel_not_found`.
 *
 * Availability tracks whether notification persistence is enabled: a database channel cannot
 * honestly report success when there is no store behind it, so it is unavailable (and never
 * succeeds) when persistence is off.
 *
 * @package Glueful\Notifications\Channels
 */
final class DatabaseChannel implements NotificationChannel
{
    public function __construct(private readonly bool $persistenceEnabled = true)
    {
    }

    public function getChannelName(): string
    {
        return 'database';
    }

    /**
     * Acknowledge in-app delivery. The notification row already exists (saved by the service);
     * there is nothing to write here. Succeeds only when persistence is active.
     *
     * @param array<string, mixed> $data
     */
    public function send(Notifiable $notifiable, array $data): bool
    {
        return $this->persistenceEnabled;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function format(array $data, Notifiable $notifiable): array
    {
        return $data;
    }

    public function isAvailable(): bool
    {
        return $this->persistenceEnabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return ['persistence_enabled' => $this->persistenceEnabled];
    }
}
