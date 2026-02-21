<?php

declare(strict_types=1);

namespace Glueful\Repository;

use DateTime;
use Glueful\Notifications\Models\Notification;
use Glueful\Notifications\Models\NotificationPreference;
use Glueful\Notifications\Models\NotificationTemplate;
use Glueful\Helpers\Utils;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Repository\Concerns\QueryFilterTrait;

/**
 * Notification Repository
 *
 * Handles all database operations related to notifications:
 * - Persisting notifications to database
 * - Retrieving notifications by various criteria
 * - Managing notification preferences
 * - Storing and retrieving notification templates
 *
 * Extends BaseRepository to leverage common CRUD operations
 * and audit logging functionality for notification-related activities.
 *
 * @package Glueful\Repository
 */
class NotificationRepository extends BaseRepository
{
    use QueryFilterTrait;

    /**
     * Initialize repository
     *
     * Sets up database connection and dependencies
     */
    public function __construct(?Connection $connection = null, ?ApplicationContext $context = null)
    {
        // Configure repository settings before calling parent
        $this->defaultFields = ['*'];

        // Call parent constructor to set up database connection and audit logger
        parent::__construct($connection, $context);
    }

    /**
     * Get the table name for this repository
     *
     * @return string The table name
     */
    public function getTableName(): string
    {
        return 'notifications';
    }

    /**
     * Save a notification to the database
     *
     * Creates or updates a notification record.
     *
     * @param Notification $notification The notification to save
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool Success status
     */
    public function save(Notification $notification, ?string $userId = null): bool
    {
        $data = $notification->toArray();

        $payloadForIdempotency = $data['data'] ?? null;
        if (is_string($payloadForIdempotency)) {
            $payloadForIdempotency = json_decode($payloadForIdempotency, true);
        }
        $data['idempotency_key'] = null;
        if (is_array($payloadForIdempotency)) {
            $meta = $payloadForIdempotency['_meta'] ?? null;
            $metaIdempotencyKey = $meta['idempotency_key'] ?? null;
            if (is_array($meta) && is_string($metaIdempotencyKey) && $metaIdempotencyKey !== '') {
                $data['idempotency_key'] = $meta['idempotency_key'];
            }
        }

        // Convert data field to JSON
        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = json_encode($data['data']);
        }

        // Ensure UUID is present for new notifications
        if (!isset($data['uuid']) || ($data['uuid'] ?? '') === '') {
            $data['uuid'] = Utils::generateNanoID();
        }

        // Check if notification exists by UUID
        $existing = null;
        if (($data['uuid'] ?? '') !== '') {
            $existing = $this->findByUuid($data['uuid']);
        }

        if ($existing !== null) {
            // Update existing notification using BaseRepository's update method
            // This automatically handles audit logging
            $data['id'] = $existing->getId();
            return $this->update($data['uuid'], $data);
        } else {
            // Remove the ID field if it's NULL to let the database auto-increment
            // Note: isset() returns false for null values, so use array_key_exists()
            if (array_key_exists('id', $data) && $data['id'] === null) {
                unset($data['id']);
            }

            // Create new notification using BaseRepository's create method
            $result = $this->create($data);
            return $result !== '';
        }
    }

    /**
     * Find notification by UUID
     *
     * This is the preferred method for looking up notifications
     * as it aligns with the UUID-based identifier pattern used across the system.
     *
     * @param string $uuid Notification UUID
     * @return Notification|null The notification or null if not found
     */
    public function findByUuid(string $uuid): ?Notification
    {
        // Use BaseRepository's findBy method for consistent behavior
        $result = $this->findBy($this->primaryKey, $uuid);

        if ($result === null) {
            return null;
        }

        return Notification::fromArray($result);
    }

    /**
     * Execute operation with temporary table switching
     *
     * This method eliminates the boilerplate code for temporarily switching
     * the repository's table and primary key for operations on related tables.
     *
     * @param string $tableName The table name to switch to
     * @param string|null $primaryKey The primary key to use (null to keep current)
     * @param callable $operation The operation to execute with the switched table
     * @return mixed The result of the operation
     */
    private function withTable(string $tableName, ?string $primaryKey, callable $operation)
    {
        $originalTable = $this->table;
        $originalPrimaryKey = $this->primaryKey;

        try {
            $this->table = $tableName;
            if ($primaryKey !== null) {
                $this->primaryKey = $primaryKey;
            }

            return $operation();
        } finally {
            // Always restore original values
            $this->table = $originalTable;
            $this->primaryKey = $originalPrimaryKey;
        }
    }

    /**
     * Find notifications for a specific recipient
     *
     * @param string $notifiableType Recipient type
     * @param string $notifiableId Recipient ID
     * @param bool|null $onlyUnread Whether to get only unread notifications
     * @param int|null $limit Maximum number of notifications to retrieve
     * @param int|null $offset Pagination offset
     * @param array<string, mixed> $filters Optional additional filters (type, priority, date range)
     * @return array<int, array<string, mixed>> Array of notification data arrays
     */
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
        $query = $this->db->table($this->table)->select(['*']);

        // Apply notifiable filters using trait method
        $this->applyNotifiableFilters($query, $notifiableType, $notifiableId, $onlyUnread ?? false);

        // Apply additional filters using trait method
        $this->applyFilters($query, $filters);

        // Order by creation date, newest first
        $query->orderBy(['created_at' => 'DESC']);

        if ($limit !== null) {
            $query->limit($limit);

            if ($offset !== null) {
                $query->offset($offset);
            }
        }

        $results = $query->get();

        if ($results === []) {
            return [];
        }

        $notifications = [];
        foreach ($results as $row) {
            $notification = Notification::fromArray($row);
            $notifications[] = $notification->toArray();
        }

        return $notifications;
    }

    /**
     * Find notifications for a specific recipient with built-in pagination
     *
     * @param string $notifiableType Recipient type
     * @param string $notifiableId Recipient ID
     * @param bool $onlyUnread Whether to get only unread notifications
     * @param int $page Page number (1-based)
     * @param int $perPage Number of items per page
     * @param array<string, mixed> $filters Optional additional filters (type, priority, date range)
     * @return array<string, mixed> Paginated results with data and pagination info
     */
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
        $query = $this->db->table($this->table)->select(['*']);

        // Apply notifiable filters using trait method
        $this->applyNotifiableFilters($query, $notifiableType, $notifiableId, $onlyUnread);

        // Apply additional filters using trait method
        $this->applyFilters($query, $filters);

        // Order by creation date, newest first
        $query->orderBy(['created_at' => 'DESC']);

        // Use QueryBuilder's built-in pagination
        $paginatedResults = $query->paginate($page, $perPage);

        // Transform the data to Notification objects and remove internal id
        $notifications = [];
        foreach ($paginatedResults['data'] as $row) {
            $notification = Notification::fromArray($row);
            $notificationArray = $notification->toArray();
            unset($notificationArray['id']); // Remove internal database ID
            $notifications[] = $notificationArray;
        }

        // Return the paginated results with transformed data
        // QueryBuilder's paginate() returns pagination metadata at the top level
        return array_merge($paginatedResults, [
            'data' => $notifications
        ]);
    }

    /**
     * Find pending scheduled notifications ready to be sent
     *
     * @param DateTime|null $now Current time (defaults to now)
     * @param int|null $limit Maximum number to retrieve
     * @return array<Notification> Array of Notification objects
     */
    /**
     * @return array<Notification>
     */
    public function findPendingScheduled(?DateTime $now = null, ?int $limit = null): array
    {
        $now = $now ?? new DateTime();
        $currentTime = $now->format('Y-m-d H:i:s');

        $query = $this->db->table($this->table)
            ->select(['*'])
            ->whereNotNull('scheduled_at')
            ->whereNull('sent_at')
            ->where('scheduled_at', '<=', $currentTime);

        if ($limit !== null) {
            $query->limit($limit);
        }

        $results = $query->get();

        if ($results === []) {
            return [];
        }

        $notifications = [];
        foreach ($results as $row) {
            $notifications[] = Notification::fromArray($row);
        }

        return $notifications;
    }

    /**
     * Find an existing notification by idempotency key in a recent time window.
     *
     * @param string $notifiableType Recipient type
     * @param string $notifiableId Recipient ID
     * @param string $type Notification type
     * @param string $idempotencyKey Idempotency key
     * @param int $windowSeconds Lookback window in seconds
     * @return Notification|null The matched notification or null if not found
     */
    public function findRecentByIdempotencyKey(
        string $notifiableType,
        string $notifiableId,
        string $type,
        string $idempotencyKey,
        int $windowSeconds
    ): ?Notification {
        $windowStart = (new DateTime())->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');

        $result = $this->db->table($this->table)
            ->select(['*'])
            ->where([
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId,
                'type' => $type,
                'idempotency_key' => $idempotencyKey
            ])
            ->where('created_at', '>=', $windowStart)
            ->orderBy(['created_at' => 'DESC'])
            ->first();

        if ($result === null || $result === []) {
            return null;
        }

        return Notification::fromArray($result);
    }

    /**
     * Ensure delivery rows exist for a notification/channels set.
     *
     * @param string $notificationUuid
     * @param array<string> $channels
     * @param string $initialStatus
     * @return void
     */
    public function ensureDeliveryRecords(string $notificationUuid, array $channels, string $initialStatus = 'pending'): void
    {
        $normalized = array_values(array_unique(array_filter($channels, static function ($channel): bool {
            return is_string($channel) && $channel !== '';
        })));

        foreach ($normalized as $channel) {
            $existing = $this->db->table('notification_deliveries')
                ->select(['id'])
                ->where([
                    'notification_uuid' => $notificationUuid,
                    'channel' => $channel,
                ])
                ->limit(1)
                ->get();

            if ($existing !== []) {
                continue;
            }

            $this->db->table('notification_deliveries')->insert([
                'notification_uuid' => $notificationUuid,
                'channel' => $channel,
                'status' => $initialStatus,
                'attempt_count' => 0,
                'created_at' => (new DateTime())->format('Y-m-d H:i:s'),
                'updated_at' => (new DateTime())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Filter requested channels down to channels that still need dispatch.
     *
     * A channel needs dispatch if it has no row yet or is currently pending/failed.
     *
     * @param string $notificationUuid
     * @param array<string> $requestedChannels
     * @return array<string>
     */
    public function getChannelsNeedingDispatch(string $notificationUuid, array $requestedChannels): array
    {
        $normalized = array_values(array_unique(array_filter($requestedChannels, static function ($channel): bool {
            return is_string($channel) && $channel !== '';
        })));

        if ($normalized === []) {
            return [];
        }

        $rows = $this->db->table('notification_deliveries')
            ->select(['channel', 'status'])
            ->where('notification_uuid', '=', $notificationUuid)
            ->get();

        $statusByChannel = [];
        foreach ($rows as $row) {
            $statusByChannel[(string)($row['channel'] ?? '')] = (string)($row['status'] ?? 'pending');
        }

        $dispatchable = [];
        foreach ($normalized as $channel) {
            $status = $statusByChannel[$channel] ?? null;
            if ($status === null || $status === 'pending' || $status === 'failed') {
                $dispatchable[] = $channel;
            }
        }

        return $dispatchable;
    }

    /**
     * Update per-channel delivery status and metadata.
     *
     * @param string $notificationUuid
     * @param string $channel
     * @param string $status
     * @param string|null $lastError
     * @return void
     */
    public function recordDeliveryAttempt(
        string $notificationUuid,
        string $channel,
        string $status,
        ?string $lastError = null
    ): void {
        $now = (new DateTime())->format('Y-m-d H:i:s');

        $existing = $this->db->table('notification_deliveries')
            ->select(['id', 'attempt_count'])
            ->where([
                'notification_uuid' => $notificationUuid,
                'channel' => $channel,
            ])
            ->limit(1)
            ->get();

        if ($existing === []) {
            $this->db->table('notification_deliveries')->insert([
                'notification_uuid' => $notificationUuid,
                'channel' => $channel,
                'status' => $status,
                'attempt_count' => 1,
                'last_error' => $lastError,
                'last_attempt_at' => $now,
                'sent_at' => $status === 'sent' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        $attemptCount = (int)($existing[0]['attempt_count'] ?? 0) + 1;
        $update = [
            'status' => $status,
            'attempt_count' => $attemptCount,
            'last_error' => $lastError,
            'last_attempt_at' => $now,
            'updated_at' => $now,
        ];
        if ($status === 'sent') {
            $update['sent_at'] = $now;
        }

        $this->db->table('notification_deliveries')
            ->where('id', '=', $existing[0]['id'])
            ->update($update);
    }

    /**
     * Return channels that are currently failed (optionally filtered by request list).
     *
     * @param string $notificationUuid
     * @param array<string>|null $requestedChannels
     * @return array<string>
     */
    public function getFailedDeliveryChannels(string $notificationUuid, ?array $requestedChannels = null): array
    {
        $query = $this->db->table('notification_deliveries')
            ->select(['channel'])
            ->where([
                'notification_uuid' => $notificationUuid,
                'status' => 'failed',
            ]);

        $rows = $query->get();
        $channels = array_values(array_unique(array_filter(array_map(static function ($row): string {
            return (string)($row['channel'] ?? '');
        }, $rows), static function (string $channel): bool {
            return $channel !== '';
        })));

        if ($requestedChannels === null) {
            return $channels;
        }

        $filter = array_values(array_unique(array_filter($requestedChannels, static function ($channel): bool {
            return is_string($channel) && $channel !== '';
        })));

        if ($filter === []) {
            return [];
        }

        return array_values(array_intersect($channels, $filter));
    }

    /**
     * Save a notification preference to the database
     *
     * @param NotificationPreference $preference The preference to save
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool Success status
     */
    public function savePreference(NotificationPreference $preference, ?string $userId = null): bool
    {
        return $this->withTable('notification_preferences', 'uuid', function () use ($preference) {
            $data = [
                'id' => $preference->getId(),
                'uuid' => $preference->getUuid() ?? Utils::generateNanoID(),
                'notifiable_type' => $preference->getNotifiableType(),
                'notifiable_id' => $preference->getNotifiableId(),
                'notification_type' => $preference->getNotificationType(),
                'channels' => json_encode($preference->getChannels()),
                'enabled' => $preference->isEnabled() ? 1 : 0,
                'settings' => json_encode($preference->getSettings())
            ];

            // Check if preference exists by UUID
            $existing = null;
            if (($preference->getUuid() ?? '') !== '') {
                $existing = $this->findPreferenceByUuid($preference->getUuid());
            }

            if ($existing !== null) {
                // Update existing preference
                return $this->update($data['uuid'], $data);
            } else {
                // Create new preference
                $result = $this->create($data);
                return $result !== '';
            }
        });
    }

    /**
     * Find notification preference by UUID
     *
     * @param string $uuid Preference UUID
     * @return NotificationPreference|null The preference or null if not found
     */
    public function findPreferenceByUuid(string $uuid): ?NotificationPreference
    {
        return $this->withTable('notification_preferences', null, function () use ($uuid) {
            // Use BaseRepository's findBy method
            $data = $this->findBy('uuid', $uuid);

            if ($data === null) {
                return null;
            }

            $channels = json_decode($data['channels'], true);
            $settings = json_decode($data['settings'], true);

            return new NotificationPreference(
                $data['id'],
                $data['notifiable_type'],
                $data['notifiable_id'],
                $data['notification_type'],
                $channels,
                (bool)$data['enabled'],
                $settings,
                $data['uuid'] ?? null
            );
        });
    }

    /**
     * Find preferences for a specific recipient
     *
     * @param string $notifiableType Recipient type
     * @param string $notifiableId Recipient ID
     * @return array<NotificationPreference> Array of NotificationPreference objects
     */
    public function findPreferencesForNotifiable(string $notifiableType, string $notifiableId): array
    {
        return $this->withTable('notification_preferences', null, function () use ($notifiableType, $notifiableId) {
            $results = $this->db->table($this->table)
                ->select(['*'])
                ->where([
                    'notifiable_type' => $notifiableType,
                    'notifiable_id' => $notifiableId
                ])
                ->get();

            if ($results === []) {
                return [];
            }

            $preferences = [];
            foreach ($results as $row) {
                $channels = json_decode($row['channels'], true);
                $settings = json_decode($row['settings'], true);

                $preferences[] = new NotificationPreference(
                    $row['id'],
                    $row['notifiable_type'],
                    $row['notifiable_id'],
                    $row['notification_type'],
                    $channels,
                    (bool)$row['enabled'],
                    $settings,
                    $row['uuid'] ?? null
                );
            }

            return $preferences;
        });
    }

    /**
     * Save a notification template to the database
     *
     * @param NotificationTemplate $template The template to save
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool Success status
     */
    public function saveTemplate(NotificationTemplate $template, ?string $userId = null): bool
    {
        return $this->withTable('notification_templates', 'uuid', function () use ($template) {
            $data = [
                'id' => $template->getId(),
                'uuid' => $template->getUuid() ?? Utils::generateNanoID(),
                'name' => $template->getName(),
                'notification_type' => $template->getNotificationType(),
                'channel' => $template->getChannel(),
                'content' => $template->getContent(),
                'parameters' => json_encode($template->getParameters())
            ];

            // Check if template exists by UUID
            $existing = null;
            if (($template->getUuid() ?? '') !== '') {
                $existing = $this->findTemplateByUuid($template->getUuid());
            }

            if ($existing !== null) {
                // Update existing template
                return $this->update($data['uuid'], $data);
            } else {
                // Create new template
                $result = $this->create($data);
                return $result !== '';
            }
        });
    }

    /**
     * Find notification template by UUID
     *
     * @param string $uuid Template UUID
     * @return NotificationTemplate|null The template or null if not found
     */
    public function findTemplateByUuid(string $uuid): ?NotificationTemplate
    {
        return $this->withTable('notification_templates', null, function () use ($uuid) {
            // Use BaseRepository's findBy method
            $data = $this->findBy('uuid', $uuid);

            if ($data === null) {
                return null;
            }

            $parameters = json_decode($data['parameters'], true) ?? [];

            return new NotificationTemplate(
                $data['id'],
                $data['name'],
                $data['notification_type'],
                $data['channel'],
                $data['content'],
                $parameters,
                $data['uuid'] ?? null
            );
        });
    }

    /**
     * Find templates for a notification type and channel
     *
     * @param string $notificationType Notification type
     * @param string $channel Channel name
     * @return array<NotificationTemplate> Array of NotificationTemplate objects
     */
    public function findTemplates(string $notificationType, string $channel): array
    {
        return $this->withTable('notification_templates', null, function () use ($notificationType, $channel) {
            $results = $this->db->table($this->table)
                ->select(['*'])
                ->where([
                    'notification_type' => $notificationType,
                    'channel' => $channel
                ])
                ->get();

            if ($results === []) {
                return [];
            }

            $templates = [];
            foreach ($results as $row) {
                $parameters = json_decode($row['parameters'], true) ?? [];

                $templates[] = new NotificationTemplate(
                    $row['id'],
                    $row['name'],
                    $row['notification_type'],
                    $row['channel'],
                    $row['content'],
                    $parameters,
                    $row['uuid'] ?? null
                );
            }

            return $templates;
        });
    }

    /**
     * Count all notifications for a recipient
     *
     * @param string $notifiableType Recipient type
     * @param string $notifiableId Recipient ID
     * @param bool $onlyUnread Whether to count only unread notifications
     * @param array<string, mixed> $filters Optional additional filters
     * @return int Total count of notifications
     */
    public function countForNotifiable(
        string $notifiableType,
        string $notifiableId,
        bool $onlyUnread = false,
        array $filters = []
    ): int {
        // Build basic conditions
        $conditions = [
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId
        ];

        if ($onlyUnread) {
            $conditions['read_at'] = null;
        }

        // For simple cases, use the count method directly
        if ($filters === []) {
            return $this->db->table($this->table)->where($conditions)->count();
        }

        // For complex filters, count the results of a select query
        $query = $this->db->table($this->table)->select(['id']);
        $query->where($conditions);
        $this->applyFilters($query, $filters);

        return count($query->get());
    }

    /**
     * Mark all notifications as read for a recipient
     *
     * @param string $notifiableType Recipient type
     * @param string $notifiableId Recipient ID
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return int Number of notifications updated
     */
    public function markAllAsRead(string $notifiableType, string $notifiableId, ?string $userId = null): int
    {
        $now = (new DateTime())->format('Y-m-d H:i:s');

        // Get all unread notifications for this recipient
        $unreadNotifications = $this->db->table($this->table)
            ->select(['*'])
            ->where([
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId
            ])
            ->whereNull('read_at')
            ->get();

        if ($unreadNotifications === []) {
            return 0;
        }

        // Start a transaction for batch updates
        $this->beginTransaction();

        try {
            // Use bulk update to avoid N+1 queries while maintaining transaction safety
            $updated = $this->db->table($this->table)
                ->where([
                    'notifiable_type' => $notifiableType,
                    'notifiable_id' => $notifiableId,
                    'read_at' => null  // Only update unread notifications
                ])
                ->update(['read_at' => $now]);

            // If audit logging is critical, we can batch create audit entries
            if ($updated > 0 && $userId !== null) {
                $this->logBulkNotificationUpdate($unreadNotifications, $userId, 'marked_as_read');
            }

            $this->commit();
            return $updated;
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Delete old notifications
     *
     * @param int $olderThanDays Delete notifications older than this many days
     * @param int|null $limit Maximum number to delete (not supported in current implementation)
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool Success status
     */
    public function deleteOldNotifications(int $olderThanDays, ?int $limit = null, ?string $userId = null): bool
    {
        $cutoffDate = (new DateTime())->modify("-$olderThanDays days")->format('Y-m-d H:i:s');

        // First find the notifications to delete (to ensure proper audit logging)
        $oldNotifications = $this->db->table($this->table)
            ->select(['uuid'])
            ->where('created_at', '<', $cutoffDate)
            ->limit($limit)
            ->get();

        if ($oldNotifications === []) {
            return true;  // Nothing to delete
        }

        // Start a transaction for batch deletes
        $this->beginTransaction();

        try {
            // Use bulk delete to avoid N+1 queries while maintaining transaction safety
            $uuidsToDelete = array_column($oldNotifications, 'uuid');
            $deletedCount = $this->bulkDelete($uuidsToDelete);
            $success = $deletedCount > 0;

            // If audit logging is critical, we can batch create audit entries
            if ($success && $userId !== null) {
                $this->logBulkNotificationUpdate($oldNotifications, $userId, 'deleted');
            }

            $this->commit();
            return $success;
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a single notification by UUID
     *
     * @param string $uuid The UUID of the notification to delete
     * @param string|null $userId ID of user performing the action, for audit logging
     * @return bool Success status
     */
    public function deleteNotificationByUuid(string $uuid, ?string $userId = null): bool
    {
        // Use BaseRepository's delete method which handles audit logging
        return $this->delete($uuid);
    }

    /**
     * Log bulk notification updates for audit purposes
     *
     * @param array<array<string, mixed>> $notifications Array of notification records
     * @param string $userId ID of user performing the action
     * @param string $action Action performed (e.g., 'marked_as_read', 'deleted')
     * @return void
     */
    private function logBulkNotificationUpdate(array $notifications, string $userId, string $action): void
    {
        // If audit logging is required, implement batch audit logging here
        // For now, we'll keep it simple and just log a summary
        $count = count($notifications);
        $notificationIds = array_column($notifications, 'uuid');

        error_log("Bulk notification {$action}: User {$userId} performed {$action} on {$count} notifications: " .
                  implode(', ', array_slice($notificationIds, 0, 10)) .
                  ($count > 10 ? ' and ' . ($count - 10) . ' more...' : ''));

        // In a production system, you might want to:
        // 1. Insert into an audit_logs table
        // 2. Send to a logging service
        // 3. Create audit trail entries in batch
    }
}
