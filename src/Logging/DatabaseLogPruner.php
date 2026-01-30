<?php

declare(strict_types=1);

namespace Glueful\Logging;

use Glueful\Database\Connection;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Log Pruner for DatabaseLogHandler
 *
 * Handles cleanup of old database logs based on configurable retention settings.
 * Supports channel-based retention policies where different log channels can have
 * different retention periods (e.g., auth/security logs kept longer for compliance).
 */
class DatabaseLogPruner
{
    private Connection $db;
    private int $maxAgeInDays;
    private int $maxRecords;
    private string $table;
    private ?ApplicationContext $context;

    public function __construct(
        int $maxAgeInDays = 90,
        int $maxRecords = 1000000,
        string $table = 'activity_logs',
        ?ApplicationContext $context = null
    ) {
        $this->maxAgeInDays = $maxAgeInDays;
        $this->maxRecords = $maxRecords;
        $this->table = $table;
        $this->context = $context;

        $connection = new Connection([], $this->context);
        $this->db = $connection;
    }

    /**
     * Prune old log entries based on age and quantity limits
     *
     * @return array{deleted_by_age: int, deleted_by_quantity: int, deleted_by_channel: int}
     */
    public function prune(): array
    {
        $deletedByAge = $this->pruneByAge();
        $deletedByQuantity = $this->pruneByQuantity();

        return [
            'deleted_by_age' => $deletedByAge,
            'deleted_by_quantity' => $deletedByQuantity,
            'deleted_by_channel' => 0, // Use pruneByChannel() for channel-specific cleanup
        ];
    }

    /**
     * Prune logs using channel-specific retention policies
     *
     * Reads retention configuration from config/logging.php and applies
     * different retention periods per channel. Logs not matching any
     * configured channel use the default retention period.
     *
     * @return int Total number of deleted records
     */
    public function pruneByChannel(): int
    {
        $totalDeleted = 0;
        $retentionConfig = $this->getChannelRetention();
        $defaultRetention = $this->getDefaultRetention();

        // Clean each configured channel with its specific retention
        foreach ($retentionConfig as $channel => $days) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            /** @var int $deleted */
            $deleted = $this->db->table($this->table)
                ->where('channel', '=', $channel)
                ->where('created_at', '<', $cutoff)
                ->delete();

            $totalDeleted += $deleted;
        }

        // Clean logs from unconfigured channels using default retention
        $configuredChannels = array_keys($retentionConfig);
        if ($configuredChannels !== []) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$defaultRetention} days"));

            /** @var int $deleted */
            $deleted = $this->db->table($this->table)
                ->whereNotIn('channel', $configuredChannels)
                ->where('created_at', '<', $cutoff)
                ->delete();

            $totalDeleted += $deleted;
        }

        return $totalDeleted;
    }

    /**
     * Get channel-specific retention configuration
     *
     * @return array<string, int> Channel name => retention days
     */
    private function getChannelRetention(): array
    {
        if ($this->context === null) {
            return [];
        }

        $config = config($this->context, 'logging.retention.channels', []);

        return is_array($config) ? $config : [];
    }

    /**
     * Get default retention period
     *
     * @return int Default retention in days
     */
    private function getDefaultRetention(): int
    {
        if ($this->context === null) {
            return $this->maxAgeInDays;
        }

        return (int) config($this->context, 'logging.retention.default', $this->maxAgeInDays);
    }

    /**
     * Delete logs older than maxAgeInDays (simple age-based cleanup)
     *
     * @return int Number of deleted records
     */
    private function pruneByAge(): int
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$this->maxAgeInDays} days"));

        /** @var int $deleted */
        $deleted = $this->db->table($this->table)
            ->where('created_at', '<', $cutoffDate)
            ->delete();

        return $deleted;
    }

    /**
     * Keep only the most recent maxRecords
     *
     * @return int Number of deleted records
     */
    private function pruneByQuantity(): int
    {
        $totalRecords = $this->db->table($this->table)->count();

        if ($totalRecords <= $this->maxRecords) {
            return 0;
        }

        // Find ID threshold for deletion
        $result = $this->db->table($this->table)
            ->select(['id'])
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->offset($this->maxRecords)
            ->get();

        $threshold = $result[0] ?? null;

        if ($threshold === null) {
            return 0;
        }

        /** @var int $deleted */
        $deleted = $this->db->table($this->table)
            ->where('id', '<', $threshold['id'])
            ->delete();

        return $deleted;
    }
}
