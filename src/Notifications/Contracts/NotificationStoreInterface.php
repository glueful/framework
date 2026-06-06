<?php

declare(strict_types=1);

namespace Glueful\Notifications\Contracts;

use DateTime;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Models\NotificationPreference;

/**
 * Notification persistence seam.
 *
 * Abstracts the storage operations that {@see \Glueful\Notifications\Services\NotificationService}
 * (and the retry service) depend on, so persistence can be swapped out — a real database store
 * when the `notifications` capability is enabled, or a {@see \Glueful\Notifications\Stores\NullNotificationStore}
 * that degrades explicitly when it is disabled.
 *
 * Signatures intentionally mirror {@see \Glueful\Repository\NotificationRepository}, which
 * implements this interface so existing callers passing a repository keep working unchanged.
 *
 * @package Glueful\Notifications\Contracts
 */
interface NotificationStoreInterface
{
    /**
     * Whether this store durably persists notifications. `false` for the null store, letting
     * callers refuse durability-implying flows (scheduling, retry, preferences) up front.
     */
    public function isPersistent(): bool;

    public function save(Notification $notification, ?string $userId = null): bool;

    public function findByUuid(string $uuid): ?Notification;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function findForNotifiable(
        string $notifiableType,
        string $notifiableId,
        ?bool $onlyUnread = false,
        ?int $limit = null,
        ?int $offset = null,
        array $filters = []
    ): array;

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function findForNotifiableWithPagination(
        string $notifiableType,
        string $notifiableId,
        bool $onlyUnread = false,
        int $page = 1,
        int $perPage = 20,
        array $filters = []
    ): array;

    /**
     * @return array<Notification>
     */
    public function findPendingScheduled(?DateTime $now = null, ?int $limit = null): array;

    public function findRecentByIdempotencyKey(
        string $notifiableType,
        string $notifiableId,
        string $type,
        string $idempotencyKey,
        int $windowSeconds
    ): ?Notification;

    /**
     * @param array<string> $channels
     */
    public function ensureDeliveryRecords(
        string $notificationUuid,
        array $channels,
        string $initialStatus = 'pending'
    ): void;

    /**
     * @param array<string> $requestedChannels
     * @return array<string>
     */
    public function getChannelsNeedingDispatch(string $notificationUuid, array $requestedChannels): array;

    public function recordDeliveryAttempt(
        string $notificationUuid,
        string $channel,
        string $status,
        ?string $lastError = null
    ): void;

    /**
     * @param array<string>|null $requestedChannels
     * @return array<string>
     */
    public function getFailedDeliveryChannels(string $notificationUuid, ?array $requestedChannels = null): array;

    public function savePreference(NotificationPreference $preference, ?string $userId = null): bool;

    /**
     * @return array<NotificationPreference>
     */
    public function findPreferencesForNotifiable(string $notifiableType, string $notifiableId): array;

    /**
     * @param array<string, mixed> $filters
     */
    public function countForNotifiable(
        string $notifiableType,
        string $notifiableId,
        bool $onlyUnread = false,
        array $filters = []
    ): int;

    public function markAllAsRead(string $notifiableType, string $notifiableId, ?string $userId = null): int;

    public function deleteOldNotifications(int $olderThanDays, ?int $limit = null, ?string $userId = null): bool;
}
