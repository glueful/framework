<?php

namespace Glueful\Services\Archive;

use Glueful\Database\Connection;
use Glueful\Services\Archive\DTOs\HealthCheckResult;

/**
 * Archive Health Checker
 *
 * Monitors the health and integrity of the archive system including:
 * - File system integrity checks
 * - Storage usage monitoring
 * - Archive corruption detection
 * - Missing file detection
 * - Performance metrics
 *
 * @package Glueful\Services\Archive
 */
class ArchiveHealthChecker
{
    private string $archivePath;
    /** @var array<string, mixed> */
    private array $config;

    private Connection $db;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        ?Connection $connection = null,
        array $config = []
    ) {
        $this->db = $connection ?? new Connection();
        $this->config = array_merge([
            'storage_path' => config('archive.storage.path'),
            'disk_space_threshold' => config('archive.monitoring.disk_space_threshold_percent', 85),
            'enable_health_checks' => config('archive.monitoring.enable_health_checks', true),
            'max_failed_archives' => config('archive.monitoring.max_failed_archives', 5),
        ], $config);

        $this->archivePath = $this->config['storage_path'];
    }

    /**
     * Perform comprehensive health check
     *
     * @return HealthCheckResult
     */
    public function performHealthCheck(): HealthCheckResult
    {
        if (!(bool)($this->config['enable_health_checks'] ?? true)) {
            return new HealthCheckResult(true, [], [], ['health_checks' => 'disabled']);
        }

        $issues = [];
        $warnings = [];
        $metrics = [];

        try {
            // Check file system integrity
            $corruptedArchives = $this->findCorruptedArchives();
            if (count($corruptedArchives) > 0) {
                $issues[] = "Corrupted archives found: " . count($corruptedArchives) . " archives";
                $metrics['corrupted_archives'] = $corruptedArchives;
            }

            // Check storage usage
            $storageMetrics = $this->checkStorageUsage();
            $metrics['storage'] = $storageMetrics;

            if ($storageMetrics['usage_percent'] > $this->config['disk_space_threshold']) {
                $issues[] = sprintf(
                    "Archive storage is %.1f%% full (threshold: %d%%)",
                    $storageMetrics['usage_percent'],
                    $this->config['disk_space_threshold']
                );
            } elseif ($storageMetrics['usage_percent'] > 70) {
                $warnings[] = sprintf(
                    "Archive storage is %.1f%% full",
                    $storageMetrics['usage_percent']
                );
            }

            // Check missing archives
            $missingArchives = $this->findMissingArchives();
            if (count($missingArchives) > 0) {
                $issues[] = "Missing archive files: " . count($missingArchives) . " files";
                $metrics['missing_archives'] = $missingArchives;
            }

            // Check failed archives
            $failedArchives = $this->checkFailedArchives();
            if ($failedArchives > $this->config['max_failed_archives']) {
                $issues[] = "Too many failed archives: {$failedArchives} (max: {$this->config['max_failed_archives']})";
            }
            $metrics['failed_archives'] = $failedArchives;

            // Check archive age distribution
            $ageDistribution = $this->checkArchiveAgeDistribution();
            $metrics['age_distribution'] = $ageDistribution;

            // Check for stale archives
            $staleArchives = $this->findStaleArchives();
            if ($staleArchives > 0) {
                $warnings[] = "Found {$staleArchives} archives older than retention policy";
            }
        } catch (\Exception $e) {
            $issues[] = "Health check error: " . $e->getMessage();
        }

        return new HealthCheckResult(
            healthy: count($issues) === 0,
            issues: $issues,
            warnings: $warnings,
            metrics: $metrics
        );
    }

    /**
     * Find corrupted archives by verifying checksums
     *
     * @return array<int, string> List of corrupted archive UUIDs
     */
    private function findCorruptedArchives(): array
    {
        $corrupted = [];

        try {
            $archives = $this->db->table('archive_registry')
                ->select(['uuid', 'file_path', 'checksum_sha256'])
                ->where('status', '!=', 'deleted')
                ->limit(100) // Check latest 100 archives
                ->orderBy('created_at', 'DESC')
                ->get();

            foreach ($archives as $archive) {
                if (!file_exists($archive['file_path'])) {
                    continue; // Will be caught by missing archives check
                }

                $currentChecksum = hash_file('sha256', $archive['file_path']);
                if ($currentChecksum !== $archive['checksum_sha256']) {
                    $corrupted[] = $archive['uuid'];

                    // Update status in database
                    $this->db->table('archive_registry')
                        ->where('uuid', $archive['uuid'])
                        ->update(['status' => 'corrupted']);
                }
            }
        } catch (\Exception $e) {
            error_log("Error checking archive corruption: " . $e->getMessage());
        }

        return $corrupted;
    }

    /**
     * Check storage usage
     *
     * @return array<string, mixed> Storage metrics
     */
    private function checkStorageUsage(): array
    {
        $totalSpace = disk_total_space($this->archivePath);
        $freeSpace = disk_free_space($this->archivePath);

        if ($totalSpace === false || $freeSpace === false) {
            return [
                'issues' => ['Unable to determine disk space for archive path: ' . $this->archivePath],
                'warnings' => [],
                'metrics' => []
            ];
        }

        $usedSpace = $totalSpace - $freeSpace;

        // Get archive-specific usage
        $archiveSize = 0;
        try {
            $result = $this->db->table('archive_registry')
                ->selectRaw('SUM(file_size) as total_size')
                ->where('status', '!=', 'deleted')
                ->first();

            $archiveSize = $result['total_size'] ?? 0;
        } catch (\Exception $e) {
            error_log("Error calculating archive size: " . $e->getMessage());
        }

        return [
            'total_space' => $totalSpace,
            'used_space' => $usedSpace,
            'free_space' => $freeSpace,
            'archive_size' => $archiveSize,
            'usage_percent' => $totalSpace > 0 ? ($usedSpace / $totalSpace) * 100 : 0,
            'archive_percent' => $totalSpace > 0 ? ($archiveSize / $totalSpace) * 100 : 0,
        ];
    }

    /**
     * Find missing archive files
     *
     * @return array<int, string> List of missing archive UUIDs
     */
    private function findMissingArchives(): array
    {
        $missing = [];

        try {
            $archives = $this->db->table('archive_registry')
                ->select(['uuid', 'file_path'])
                ->where('status', '!=', 'deleted')
                ->get();

            foreach ($archives as $archive) {
                if (!file_exists($archive['file_path'])) {
                    $missing[] = $archive['uuid'];

                    // Update status in database
                    $this->db->table('archive_registry')
                        ->where('uuid', $archive['uuid'])
                        ->update(['status' => 'missing']);
                }
            }
        } catch (\Exception $e) {
            error_log("Error checking missing archives: " . $e->getMessage());
        }

        return $missing;
    }

    /**
     * Check number of failed archives
     *
     * @return int Number of failed archives
     */
    private function checkFailedArchives(): int
    {
        try {
            return $this->db->table('archive_registry')
                ->where('status', 'failed')
                ->count();
        } catch (\Exception $e) {
            error_log("Error counting failed archives: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check archive age distribution
     *
     * @return array<string, mixed> Age distribution metrics
     */
    private function checkArchiveAgeDistribution(): array
    {
        try {
            // Get counts for different time periods separately to avoid database-specific date functions
            $weekTimestamp = strtotime('-7 days');
            if ($weekTimestamp === false) {
                $weekTimestamp = time() - (7 * 24 * 3600); // Fallback: 7 days ago
            }
            $lastWeek = $this->db->table('archive_registry')
                ->where('created_at', '>', date('Y-m-d H:i:s', $weekTimestamp))
                ->where('status', '!=', 'deleted')
                ->count();

            $monthTimestamp = strtotime('-30 days');
            if ($monthTimestamp === false) {
                $monthTimestamp = time() - (30 * 24 * 3600); // Fallback: 30 days ago
            }
            $lastMonth = $this->db->table('archive_registry')
                ->where('created_at', '>', date('Y-m-d H:i:s', $monthTimestamp))
                ->where('status', '!=', 'deleted')
                ->count();

            $quarterTimestamp = strtotime('-90 days');
            if ($quarterTimestamp === false) {
                $quarterTimestamp = time() - (90 * 24 * 3600); // Fallback: 90 days ago
            }
            $lastQuarter = $this->db->table('archive_registry')
                ->where('created_at', '>', date('Y-m-d H:i:s', $quarterTimestamp))
                ->where('status', '!=', 'deleted')
                ->count();

            $yearTimestamp = strtotime('-365 days');
            if ($yearTimestamp === false) {
                $yearTimestamp = time() - (365 * 24 * 3600); // Fallback: 365 days ago
            }
            $lastYear = $this->db->table('archive_registry')
                ->where('created_at', '>', date('Y-m-d H:i:s', $yearTimestamp))
                ->where('status', '!=', 'deleted')
                ->count();

            $total = $this->db->table('archive_registry')
                ->where('status', '!=', 'deleted')
                ->count();

            return [
                'last_week' => $lastWeek,
                'last_month' => $lastMonth,
                'last_quarter' => $lastQuarter,
                'last_year' => $lastYear,
                'total' => $total
            ];
        } catch (\Exception $e) {
            error_log("Error checking archive age distribution: " . $e->getMessage());
            return [
                'last_week' => 0,
                'last_month' => 0,
                'last_quarter' => 0,
                'last_year' => 0,
                'total' => 0
            ];
        }
    }

    /**
     * Find archives that exceed retention policies
     *
     * @return int Number of stale archives
     */
    private function findStaleArchives(): int
    {
        try {
            $retentionPolicies = config('archive.retention_policies', []);
            $staleCount = 0;

            foreach ($retentionPolicies as $table => $policy) {
                $complianceYears = $policy['compliance_period_years'] ?? 7;
                $timestamp = strtotime("-{$complianceYears} years");
                if ($timestamp === false) {
                    continue; // Skip invalid date calculations
                }
                $cutoffDate = date('Y-m-d', $timestamp);

                $count = $this->db->table('archive_registry')
                    ->where('table_name', $table)
                    ->where('created_at', '<', $cutoffDate)
                    ->where('status', 'completed')
                    ->count();

                $staleCount += $count;
            }

            return $staleCount;
        } catch (\Exception $e) {
            error_log("Error checking stale archives: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get detailed health report
     *
     * @return array<string, mixed> Detailed health metrics
     */
    public function getDetailedHealthReport(): array
    {
        $healthCheck = $this->performHealthCheck();

        return [
            'healthy' => $healthCheck->healthy,
            'timestamp' => date('Y-m-d H:i:s'),
            'issues' => $healthCheck->issues,
            'warnings' => $healthCheck->warnings,
            'metrics' => $healthCheck->metrics,
            'recommendations' => $this->generateRecommendations($healthCheck)
        ];
    }

    /**
     * Generate recommendations based on health check
     *
     * @param HealthCheckResult $healthCheck
     * @return array<int, string> List of recommendations
     */
    private function generateRecommendations(HealthCheckResult $healthCheck): array
    {
        $recommendations = [];

        if (
            isset($healthCheck->metrics['storage']['usage_percent']) &&
            $healthCheck->metrics['storage']['usage_percent'] > 70
        ) {
            $recommendations[] = "Consider increasing storage capacity or implementing " .
                "more aggressive archival policies";
        }

        if (
            isset($healthCheck->metrics['corrupted_archives']) &&
            count($healthCheck->metrics['corrupted_archives'] ?? []) > 0
        ) {
            $recommendations[] = "Run integrity verification on all archives and consider re-archiving corrupted data";
        }

        if (
            isset($healthCheck->metrics['failed_archives']) &&
            $healthCheck->metrics['failed_archives'] > 0
        ) {
            $recommendations[] = "Review and retry failed archive operations";
        }

        if (
            isset($healthCheck->metrics['age_distribution']['total']) &&
            $healthCheck->metrics['age_distribution']['last_week'] == 0
        ) {
            $recommendations[] = "No archives created in the last week - verify automatic archiving is working";
        }

        return $recommendations;
    }
}
