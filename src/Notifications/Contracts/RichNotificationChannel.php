<?php

declare(strict_types=1);

namespace Glueful\Notifications\Contracts;

use Glueful\Notifications\Results\NotificationResult;

/**
 * Opt-in richer channel contract.
 *
 * A `RichNotificationChannel` is a {@see NotificationChannel} that can return a structured
 * {@see NotificationResult} (provider message id, error code/message, retryability, latency)
 * instead of a bare `bool`. The dispatcher prefers `sendNotification()` when a channel
 * implements this interface, and falls back to the legacy `send(): bool` otherwise — so this is
 * purely additive and `NotificationChannel::send()` is unchanged.
 *
 * @package Glueful\Notifications\Contracts
 */
interface RichNotificationChannel extends NotificationChannel
{
    /**
     * Send the notification and return a structured result.
     *
     * @param array<string, mixed> $data Formatted notification data
     */
    public function sendNotification(Notifiable $notifiable, array $data): NotificationResult;
}
