<?php

namespace Glueful\Tasks;

use Glueful\Database\Connection;
use Glueful\Logging\DatabaseLogPruner;

/**
 * Log Cleanup Task
 *
 * Handles cleanup of old log files and database log records.
 * Supports channel-based retention policies where different log channels
 * can have different retention periods.
 */
class LogCleanupTask
{
    /** @var array{deleted_files: int, deleted_db_logs: int, bytes_freed: int, errors: string[]} */
    private array $stats = [
        'deleted_files' => 0,
        'deleted_db_logs' => 0,
        'bytes_freed' => 0,
        'errors' => []
    ];

    private Connection $connection;

    public function __construct()
    {
        $this->connection = new Connection();
    }

    /**
     * Clean old log files from the filesystem
     */
    public function cleanFileSystemLogs(int $retentionDays): void
    {
        $logDir = config('app.paths.logs');

        if (!is_dir($logDir)) {
            return;
        }

        $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
        $files = glob($logDir . '/*.log');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            try {
                $mtime = filemtime($file);
                if ($mtime !== false && $mtime < $cutoffTime) {
                    $size = filesize($file);
                    if (unlink($file)) {
                        $this->stats['deleted_files']++;
                        $this->stats['bytes_freed'] += $size !== false ? $size : 0;
                    }
                }
            } catch (\Exception $e) {
                $this->stats['errors'][] = "Failed to delete file {$file}: " . $e->getMessage();
            }
        }
    }

    /**
     * Clean old database logs using simple age-based retention
     */
    public function cleanDatabaseLogs(int $retentionDays): void
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', time() - ($retentionDays * 24 * 60 * 60));

            /** @var int $affected */
            $affected = $this->connection->table('activity_logs')
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            $this->stats['deleted_db_logs'] = $affected;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean database logs: " . $e->getMessage();
        }
    }

    /**
     * Clean database logs using channel-specific retention policies
     *
     * This method respects the retention configuration in config/logging.php
     * where different channels can have different retention periods.
     * For example: auth/security logs kept 365 days, debug logs 7 days.
     */
    public function cleanDatabaseLogsByChannel(): void
    {
        try {
            $pruner = new DatabaseLogPruner();
            $deleted = $pruner->pruneByChannel();

            $this->stats['deleted_db_logs'] += $deleted;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean database logs by channel: " . $e->getMessage();
        }
    }

    /**
     * Log the cleanup results to a file
     */
    public function logResults(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $message = sprintf(
            "[%s] Log cleanup completed:\n" .
            "- Log files deleted: %d\n" .
            "- Database log records deleted: %d\n" .
            "- Disk space freed: %s\n",
            $timestamp,
            $this->stats['deleted_files'],
            $this->stats['deleted_db_logs'],
            $this->formatBytes($this->stats['bytes_freed'])
        );

        if (count($this->stats['errors']) > 0) {
            $message .= "Errors:\n- " . implode("\n- ", $this->stats['errors']) . "\n";
        }

        $logFile = base_path('storage/logs/log-cleanup.log');
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, $message . "\n", FILE_APPEND);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    /**
     * Run the log cleanup task
     *
     * @param array<string, mixed> $parameters
     * @return array{deleted_files: int, deleted_db_logs: int, bytes_freed: int, errors: string[]}
     */
    public function handle(array $parameters = []): array
    {
        $retentionDays = (int) ($parameters['retention_days'] ?? 30);
        $useChannelRetention = (bool) ($parameters['use_channel_retention'] ?? true);

        $this->cleanFileSystemLogs($retentionDays);

        if ($useChannelRetention) {
            // Use channel-specific retention from config/logging.php
            $this->cleanDatabaseLogsByChannel();
        } else {
            // Use simple age-based retention
            $this->cleanDatabaseLogs($retentionDays);
        }

        $this->logResults();

        return $this->stats;
    }
}
