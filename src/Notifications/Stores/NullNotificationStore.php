<?php

declare(strict_types=1);

namespace Glueful\Notifications\Stores;

use DateTime;
use Glueful\Notifications\Contracts\NotificationStoreInterface;
use Glueful\Notifications\Exceptions\NotificationPersistenceDisabledException;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Models\NotificationPreference;

/**
 * No-op notification store used when notification persistence is disabled
 * (`NOTIFICATIONS_DATABASE_STORE=false`). It degrades explicitly per the no-DB matrix rather
 * than failing on a missing table or silently losing durable state:
 *
 *  - reads/counts        → empty / null / zero
 *  - fire-and-forget     → silent no-op (transient save + delivery-record writes)
 *  - durability-implying → throw {@see NotificationPersistenceDisabledException}
 *
 * Note: idempotency (`findRecentByIdempotencyKey`) is unavailable without persistence — it
 * always reports "no prior notification", so duplicate sends are possible.
 *
 * @package Glueful\Notifications\Stores
 */
final class NullNotificationStore implements NotificationStoreInterface
{
    public function isPersistent(): bool
    {
        return false;
    }

    // --- fire-and-forget: transient, honest no-ops -----------------------------------------

    public function save(Notification $notification, ?string $userId = null): bool
    {
        // The notification is delivered ephemerally; nothing is persisted.
        return true;
    }

    /**
     * @param array<string> $channels
     */
    public function ensureDeliveryRecords(
        string $notificationUuid,
        array $channels,
        string $initialStatus = 'pending'
    ): void {
        // No delivery records without persistence.
    }

    public function recordDeliveryAttempt(
        string $notificationUuid,
        string $channel,
        string $status,
        ?string $lastError = null
    ): void {
        // No delivery records without persistence.
    }

    // --- reads: empty / null / zero --------------------------------------------------------

    public function findByUuid(string $uuid): ?Notification
    {
        return null;
    }

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
    ): array {
        return [];
    }

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
    ): array {
        return [];
    }

    /**
     * @return array<Notification>
     */
    public function findPendingScheduled(?DateTime $now = null, ?int $limit = null): array
    {
        return [];
    }

    public function findRecentByIdempotencyKey(
        string $notifiableType,
        string $notifiableId,
        string $type,
        string $idempotencyKey,
        int $windowSeconds
    ): ?Notification {
        // Idempotency cannot be enforced without persistence — always "not seen".
        return null;
    }

    /**
     * @param array<string> $requestedChannels
     * @return array<string>
     */
    public function getChannelsNeedingDispatch(string $notificationUuid, array $requestedChannels): array
    {
        return [];
    }

    /**
     * @param array<string>|null $requestedChannels
     * @return array<string>
     */
    public function getFailedDeliveryChannels(string $notificationUuid, ?array $requestedChannels = null): array
    {
        return [];
    }

    /**
     * @return array<NotificationPreference>
     */
    public function findPreferencesForNotifiable(string $notifiableType, string $notifiableId): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countForNotifiable(
        string $notifiableType,
        string $notifiableId,
        bool $onlyUnread = false,
        array $filters = []
    ): int {
        return 0;
    }

    // --- durability-implying: fail loudly --------------------------------------------------

    public function savePreference(NotificationPreference $preference, ?string $userId = null): bool
    {
        throw NotificationPersistenceDisabledException::forOperation('savePreference');
    }

    public function markAllAsRead(string $notifiableType, string $notifiableId, ?string $userId = null): int
    {
        throw NotificationPersistenceDisabledException::forOperation('markAllAsRead');
    }

    public function deleteOldNotifications(int $olderThanDays, ?int $limit = null, ?string $userId = null): bool
    {
        throw NotificationPersistenceDisabledException::forOperation('deleteOldNotifications');
    }
}
