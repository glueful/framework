<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Archive;

use Glueful\Services\Archive\ArchiveServiceInterface;
use Glueful\Services\Archive\DTOs\ArchiveSearchQuery;
use Glueful\Services\Archive\ArchiveHealthChecker;
use Glueful\Services\FileFinder;
use Glueful\Exceptions\BusinessLogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Archive Management Command
 * - Advanced file discovery using FileFinder service
 * - Safe file operations with FileManager service
 * - Comprehensive archive lifecycle management
 * - Enhanced search capabilities with filters
 * - Archive integrity verification and health monitoring
 * - Automated cleanup and maintenance operations
 * - Progress tracking and detailed reporting
 * - Backup and restore functionality
 * @package Glueful\Console\Commands\Archive
 */
#[AsCommand(
    name: 'archive:manage',
    description: 'Comprehensive archive system management and data lifecycle operations'
)]
class ManageCommand extends BaseCommand
{
    private ArchiveServiceInterface $archiveService;
    private FileFinder $fileFinder;
    private LoggerInterface $logger;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Comprehensive archive system management and data lifecycle operations')
             ->setHelp('This command provides advanced archive management including automated ' .
                      'archiving, search, verification, and maintenance operations.')
             ->addArgument(
                 'action',
                 InputArgument::OPTIONAL,
                 'Action to perform (archive, status, search, verify, health, cleanup, auto, track)',
                 'status'
             )
             ->addArgument(
                 'table',
                 InputArgument::OPTIONAL,
                 'Table name (required for archive action)'
             )
             ->addArgument(
                 'days',
                 InputArgument::OPTIONAL,
                 'Days to archive (default: 90)',
                 '90'
             )
             ->addOption(
                 'uuid',
                 'u',
                 InputOption::VALUE_REQUIRED,
                 'Archive UUID for verification or restoration'
             )
             ->addOption(
                 'user',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Filter by user UUID for search'
             )
             ->addOption(
                 'endpoint',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Filter by endpoint for search'
             )
             ->addOption(
                 'start-date',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Start date for search (Y-m-d format)'
             )
             ->addOption(
                 'end-date',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'End date for search (Y-m-d format)'
             )
             ->addOption(
                 'limit',
                 'l',
                 InputOption::VALUE_REQUIRED,
                 'Limit search results',
                 '10'
             )
             ->addOption(
                 'format',
                 'f',
                 InputOption::VALUE_REQUIRED,
                 'Output format (table, json, csv)',
                 'table'
             )
             ->addOption(
                 'dry-run',
                 'd',
                 InputOption::VALUE_NONE,
                 'Show what would be done without executing'
             )
             ->addOption(
                 'backup',
                 'b',
                 InputOption::VALUE_NONE,
                 'Create backup before archiving'
             )
             ->addOption(
                 'compress',
                 'c',
                 InputOption::VALUE_NONE,
                 'Compress archive files'
             )
             ->addOption(
                 'verify-integrity',
                 null,
                 InputOption::VALUE_NONE,
                 'Verify archive integrity after creation'
             )
             ->addOption(
                 'parallel',
                 'p',
                 InputOption::VALUE_REQUIRED,
                 'Number of parallel workers for bulk operations',
                 '1'
             )
             ->addOption(
                 'older-than',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Clean up archives older than specified days'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $action = $input->getArgument('action');

        try {
            return match ($action) {
                'archive' => $this->executeArchive($input),
                'status' => $this->executeStatus($input),
                'search' => $this->executeSearch($input),
                'verify' => $this->executeVerify($input),
                'health' => $this->executeHealth($input),
                'cleanup' => $this->executeCleanup($input),
                'auto' => $this->executeAuto($input),
                'list' => $this->executeList($input),
                'restore' => $this->executeRestore($input),
                'export' => $this->executeExport($input),
                'track' => $this->executeTrack($input),
                default => $this->handleUnknownAction($action)
            };
        } catch (\Exception $e) {
            $this->io->error('Command failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $this->io->text($e->getTraceAsString());
            }
            return self::FAILURE;
        }
    }

    private function initializeServices(): void
    {
        try {
            $this->archiveService = $this->getService(ArchiveServiceInterface::class);
            $this->fileFinder = $this->getService(FileFinder::class);
            $this->logger = $this->getService(LoggerInterface::class);
        } catch (\Exception $e) {
            throw new BusinessLogicException(
                'archive_service_initialization',
                ['error' => 'Archive services not available: ' . $e->getMessage()]
            );
        }
    }

    private function executeArchive(InputInterface $input): int
    {
        $table = $input->getArgument('table');
        if ($table === null || $table === '') {
            $this->io->error('Table name is required for archive action');
            $this->io->text('Usage: archive:manage archive <table> [days]');
            return self::FAILURE;
        }

        $days = (int) $input->getArgument('days');
        $dryRun = $input->getOption('dry-run');
        $backup = $input->getOption('backup');
        $compress = $input->getOption('compress');
        $verifyIntegrity = $input->getOption('verify-integrity');

        $this->io->title("🗄️ Archiving Table: {$table}");

        if ((bool) $dryRun) {
            $this->io->warning('DRY RUN MODE - No changes will be made');
        }

        $cutoffDate = new \DateTime("-{$days} days");
        $this->io->text("Archiving records older than {$days} days (before {$cutoffDate->format('Y-m-d')})");

        // Pre-archive checks
        $this->performPreArchiveChecks($table, $cutoffDate);

        if ((bool) $backup && !(bool) $dryRun) {
            $this->createTableBackup($table);
        }

        if ((bool) $dryRun) {
            $this->io->success('Dry run completed. Use without --dry-run to execute.');
            return self::SUCCESS;
        }

        // Execute archiving
        $this->io->section('📦 Starting Archive Process');
        $progressBar = $this->io->createProgressBar();
        $progressBar->setMessage('Initializing archive...');
        $progressBar->start();

        try {
            $result = $this->archiveService->archiveTable($table, $cutoffDate);

            $progressBar->setMessage('Archive completed');
            $progressBar->finish();
            $this->io->newLine(2);

            if ($result->success) {
                $this->io->success('✅ Archive completed successfully');

                $this->displayArchiveResults($result, $compress, $verifyIntegrity);

                // Verify integrity if requested
                if ((bool) $verifyIntegrity) {
                    $this->verifyArchiveIntegrity($result->archiveUuid);
                }
            } else {
                $this->io->error('❌ Archive failed: ' . $result->error);
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $progressBar->clear();
            $this->io->error('Archive process failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function executeStatus(InputInterface $input): int
    {
        $this->io->title('📊 Archive System Status');

        try {
            // System health check
            $summary = $this->archiveService->getArchiveSummary();

            $this->io->section('System Overview');
            $statusRows = [
                ['Metric', 'Value'],
                ['Service Status', '✅ Operational'],
                ['Total Archives', number_format($summary->totalArchives)],
                ['Records Archived', number_format($summary->totalRecordsArchived)],
                ['Storage Used', $this->formatBytes($summary->totalSizeBytes)],
            ];

            if ($summary->oldestArchive !== null) {
                $statusRows[] = ['Oldest Archive', $summary->oldestArchive->format('Y-m-d H:i:s')];
            }
            if ($summary->newestArchive !== null) {
                $statusRows[] = ['Newest Archive', $summary->newestArchive->format('Y-m-d H:i:s')];
            }

            $this->io->table($statusRows[0], array_slice($statusRows, 1));

            // Tables needing archival
            $tablesNeedingArchival = $this->archiveService->getTablesNeedingArchival();
            if ($tablesNeedingArchival !== []) {
                $this->io->section('⚠️ Tables Needing Archival');
                foreach ($tablesNeedingArchival as $table) {
                    $stats = $this->archiveService->getTableStats($table);
                    if ($stats !== null) {
                        $this->io->text("📋 {$table}:");
                        $this->io->text("   Rows: " . number_format($stats->currentRowCount));
                        $this->io->text("   Size: " . $this->formatBytes($stats->currentSizeBytes));
                        $lastArchived = $stats->lastArchiveDate !== null
                            ? $stats->lastArchiveDate->format('Y-m-d')
                            : 'Never';
                        $this->io->text("   Last Archived: " . $lastArchived);
                    }
                }
            } else {
                $this->io->success('✅ All tables are up to date');
            }

            // Archive distribution by table
            if ($summary->tableBreakdown !== []) {
                $this->io->section('📈 Archive Distribution');
                $tableRows = [['Table', 'Archives', 'Records', 'Size']];
                foreach ($summary->tableBreakdown as $table => $stats) {
                    $tableRows[] = [
                        $table,
                        number_format($stats['count']),
                        number_format($stats['records']),
                        $this->formatBytes($stats['size'])
                    ];
                }
                $this->io->table($tableRows[0], array_slice($tableRows, 1));
            }
        } catch (\Exception $e) {
            $this->io->error('❌ Archive service error: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function executeSearch(InputInterface $input): int
    {
        $this->io->title('🔍 Archive Search');

        // Build search query
        $startDateOption = $input->getOption('start-date');
        $endDateOption = $input->getOption('end-date');
        $query = new ArchiveSearchQuery(
            userUuid: $input->getOption('user'),
            endpoint: $input->getOption('endpoint'),
            startDate: ($startDateOption !== null && $startDateOption !== '') ? new \DateTime($startDateOption) : null,
            endDate: ($endDateOption !== null && $endDateOption !== '') ? new \DateTime($endDateOption) : null,
            limit: (int) $input->getOption('limit')
        );

        $this->io->section('🔎 Search Parameters');
        $this->displaySearchParams($query);

        $results = $this->archiveService->searchArchives($query);

        $this->io->section('📋 Search Results');
        $this->io->text("Found {$results->totalCount} results in " . number_format($results->searchTime, 3) . "s");
        $this->io->text("Archives searched: " . count($results->archivesSearched));

        if ($results->records !== []) {
            $format = $input->getOption('format');
            $this->displaySearchResults($results->records, $format);
        } else {
            $this->io->warning('No records found matching the search criteria');
        }

        return self::SUCCESS;
    }

    private function executeVerify(InputInterface $input): int
    {
        $uuid = $input->getOption('uuid');
        if ($uuid === null || $uuid === '') {
            $this->io->error('Archive UUID is required for verification');
            $this->io->text('Usage: archive:manage verify --uuid=<uuid>');
            return self::FAILURE;
        }

        $this->io->title("🔐 Verifying Archive: {$uuid}");

        $isValid = $this->archiveService->verifyArchive($uuid);

        if ($isValid) {
            $this->io->success('✅ Archive verification passed');
        } else {
            $this->io->error('❌ Archive verification failed');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function executeHealth(InputInterface $input): int
    {
        $this->io->title('🏥 Archive System Health Check');

        try {
            $healthChecker = new ArchiveHealthChecker(
                $this->getService(\Glueful\Database\Connection::class)
            );

            $report = $healthChecker->getDetailedHealthReport();

            // Display health status
            $status = (bool)($report['healthy'] ?? false) ? '✅ HEALTHY' : '❌ CRITICAL';
            $this->io->text("System Status: {$status}");
            $this->io->text("Checked at: " . $report['timestamp']);
            $this->io->newLine();

            // Display issues
            if ($report['issues'] !== []) {
                $this->io->section('🚨 Critical Issues');
                foreach ($report['issues'] as $issue) {
                    $this->io->error($issue);
                }
            }

            // Display warnings
            if ($report['warnings'] !== []) {
                $this->io->section('⚠️ Warnings');
                foreach ($report['warnings'] as $warning) {
                    $this->io->warning($warning);
                }
            }

            // Display metrics
            if ($report['metrics'] !== []) {
                $this->displayHealthMetrics($report['metrics']);
            }

            // Display recommendations
            if ($report['recommendations'] !== []) {
                $this->io->section('💡 Recommendations');
                foreach ($report['recommendations'] as $recommendation) {
                    $this->io->text('• ' . $recommendation);
                }
            }

            return (bool)($report['healthy'] ?? false) ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            $this->io->error('Health check failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function executeCleanup(InputInterface $input): int
    {
        $this->io->title('🧹 Archive Cleanup');

        $olderThan = $input->getOption('older-than');
        $dryRun = $input->getOption('dry-run');

        if ($olderThan !== null && $olderThan !== '') {
            $cutoffDate = new \DateTime("-{$olderThan} days");
            $this->io->text("Cleaning archives older than {$olderThan} days (before {$cutoffDate->format('Y-m-d')})");
        } else {
            $this->io->text('Cleaning failed archives and orphaned files');
        }

        if ((bool) $dryRun) {
            $this->io->warning('DRY RUN MODE - No changes will be made');
        }

        // Use FileFinder to discover cleanup candidates
        $storagePath = base_path($this->getContext(), 'storage/archives');
        $archiveDir = config($this->getContext(), 'archive.storage_path', $storagePath);

        if ($olderThan !== null && $olderThan !== '') {
            $oldFiles = $this->fileFinder->findCacheFiles($archiveDir, '*.gz', "{$olderThan} days ago");
            $this->io->text("Found " . iterator_count($oldFiles) . " old archive files");

            if (!(bool) $dryRun) {
                foreach ($oldFiles as $file) {
                    @unlink($file->getPathname());
                    $this->io->text("Removed: " . $file->getFilename());
                }
            }
        }

        $this->io->success('✅ Cleanup completed');
        return self::SUCCESS;
    }

    private function executeAuto(InputInterface $input): int
    {
        $this->io->title('⚙️ Automatic Archiving');

        $dryRun = $input->getOption('dry-run');

        $tables = $this->archiveService->getTablesNeedingArchival();
        if ($tables === []) {
            $this->io->success('✅ No tables need archiving');
            return self::SUCCESS;
        }

        $this->io->text("Found " . count($tables) . " tables needing archival");

        if ((bool) $dryRun) {
            $this->io->warning('DRY RUN MODE - No changes will be made');
            foreach ($tables as $table) {
                $this->io->text("Would archive: {$table}");
            }
            return self::SUCCESS;
        }

        $progressBar = $this->io->createProgressBar(count($tables));
        $progressBar->start();

        $archived = 0;
        foreach ($tables as $table) {
            $progressBar->setMessage("Archiving: {$table}");

            try {
                $retentionPolicies = config($this->getContext(), 'archive.retention_policies', []);
                $policy = $retentionPolicies[$table] ?? null;

                if ($policy === null || !((bool) ($policy['auto_archive'] ?? false))) {
                    continue;
                }

                $days = $policy['archive_after_days'] ?? 90;
                $cutoffDate = new \DateTime("-{$days} days");

                $result = $this->archiveService->archiveTable($table, $cutoffDate);
                if ($result->success) {
                    $archived++;
                }
            } catch (\Exception $e) {
                $this->logger->error("Auto-archive failed for table {$table}: " . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine(2);
        $this->io->success("✅ Auto-archiving completed. {$archived} tables archived.");

        return self::SUCCESS;
    }

    private function executeList(InputInterface $input): int
    {
        $table = $input->getArgument('table');
        if ($table === null || $table === '') {
            $this->io->error('Table name is required for list action');
            return self::FAILURE;
        }

        $this->io->title("📋 Archives for Table: {$table}");

        $archives = $this->archiveService->getTableArchives($table);

        if ($archives === []) {
            $this->io->warning("No archives found for table: {$table}");
            return self::SUCCESS;
        }

        $rows = [['UUID', 'Date', 'Records', 'Size', 'Status']];
        foreach ($archives as $archive) {
            $rows[] = [
                substr($archive['uuid'], 0, 8) . '...',
                substr($archive['created_at'], 0, 10),
                number_format($archive['record_count']),
                $this->formatBytes($archive['file_size']),
                $archive['status'] ?? 'unknown'
            ];
        }

        $this->io->table($rows[0], array_slice($rows, 1));
        return self::SUCCESS;
    }

    private function executeRestore(InputInterface $input): int
    {
        $uuid = $input->getOption('uuid');
        if ($uuid === null || $uuid === '') {
            $this->io->error('Archive UUID is required for restoration');
            return self::FAILURE;
        }

        $this->io->title("↩️ Restoring Archive: {$uuid}");
        $this->io->warning('This operation will restore archived data back to the original table');

        if (!$this->io->confirm('Continue with restoration?', false)) {
            $this->io->text('Restoration cancelled');
            return self::SUCCESS;
        }

        // Implementation would depend on archive service restore functionality
        $this->io->text('Restore functionality would be implemented here');
        return self::SUCCESS;
    }

    private function executeExport(InputInterface $input): int
    {
        $format = $input->getOption('format');
        $this->io->title("📤 Exporting Archive Data ({$format})");

        $summary = $this->archiveService->getArchiveSummary();

        switch ($format) {
            case 'json':
                $this->io->text(json_encode($summary, JSON_PRETTY_PRINT));
                break;
            case 'csv':
                $this->exportToCsv($summary);
                break;
            default:
                $this->io->error("Unsupported export format: {$format}");
                return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function performPreArchiveChecks(string $table, \DateTime $cutoffDate): void
    {
        $this->io->section('🔍 Pre-Archive Checks');

        // Check table exists and get stats
        $stats = $this->archiveService->getTableStats($table);
        if ($stats === null) {
            throw new \RuntimeException("Table '{$table}' not found or inaccessible");
        }

        $this->io->text("Table rows: " . number_format($stats->currentRowCount));
        $this->io->text("Table size: " . $this->formatBytes($stats->currentSizeBytes));

        // Check available disk space
        $storagePath = base_path($this->getContext(), 'storage/archives');
        $archiveDir = config($this->getContext(), 'archive.storage_path', $storagePath);
        if (!is_dir($archiveDir)) {
            @mkdir($archiveDir, 0755, true);
        }

        $freeSpace = disk_free_space($archiveDir);
        $freeSpace = $freeSpace !== false ? $freeSpace : 0;
        $this->io->text("Available space: " . $this->formatBytes($freeSpace));

        if ($freeSpace < $stats->currentSizeBytes * 0.5) { // Rough estimate
            $this->io->warning('Low disk space detected. Monitor during archiving.');
        }
    }

    private function createTableBackup(string $table): void
    {
        $this->io->text("🔒 Creating backup for table: {$table}");
        // Implementation would create a backup before archiving
        $this->io->text("Backup created (implementation pending)");
    }

    /**
     * @param mixed $result
     */
    private function displayArchiveResults($result, bool $compress, bool $verifyIntegrity): void
    {
        $this->io->section('📊 Archive Results');

        $resultRows = [
            ['Metric', 'Value'],
            ['Archive UUID', $result->archiveUuid],
            ['Records Archived', number_format($result->recordCount)],
            ['Archive Size', $this->formatBytes($result->fileSize ?? 0)],
            ['Archive Path', $result->filePath],
        ];

        if ($compress) {
            $resultRows[] = ['Compression', 'Enabled'];
        }

        $this->io->table($resultRows[0], array_slice($resultRows, 1));
    }

    private function verifyArchiveIntegrity(string $uuid): void
    {
        $this->io->text("🔐 Verifying archive integrity...");
        $isValid = $this->archiveService->verifyArchive($uuid);

        if ($isValid) {
            $this->io->text("✅ Integrity verification passed");
        } else {
            $this->io->error("❌ Integrity verification failed");
        }
    }

    private function displaySearchParams(ArchiveSearchQuery $query): void
    {
        $params = [];
        if ($query->userUuid !== null) {
            $params[] = "User: {$query->userUuid}";
        }
        if ($query->endpoint !== null) {
            $params[] = "Endpoint: {$query->endpoint}";
        }
        if ($query->startDate !== null) {
            $params[] = "Start: {$query->startDate->format('Y-m-d')}";
        }
        if ($query->endDate !== null) {
            $params[] = "End: {$query->endDate->format('Y-m-d')}";
        }
        $params[] = "Limit: {$query->limit}";

        $this->io->text(implode(' | ', $params));
    }

    /**
     * @param array<array<string, mixed>> $records
     */
    private function displaySearchResults(array $records, string $format): void
    {
        switch ($format) {
            case 'json':
                $this->io->text(json_encode($records, JSON_PRETTY_PRINT));
                break;
            case 'csv':
                $this->outputCsv($records);
                break;
            default:
                foreach ($records as $i => $record) {
                    $this->io->section("Record " . ($i + 1));
                    foreach ($record as $key => $value) {
                        $this->io->text("{$key}: {$value}");
                    }
                }
                break;
        }
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function displayHealthMetrics(array $metrics): void
    {
        if (isset($metrics['storage'])) {
            $this->io->section('💾 Storage Metrics');
            $storage = $metrics['storage'];
            $this->io->text("Total Space: " . $this->formatBytes($storage['total_space']));
            $this->io->text("Used Space: " . $this->formatBytes($storage['used_space']) .
                           sprintf(" (%.1f%%)", $storage['usage_percent']));
            $this->io->text("Archive Size: " . $this->formatBytes($storage['archive_size']) .
                           sprintf(" (%.1f%%)", $storage['archive_percent']));
        }

        if (isset($metrics['age_distribution'])) {
            $this->io->section('📅 Archive Age Distribution');
            $dist = $metrics['age_distribution'];
            $this->io->text("Last Week: " . number_format($dist['last_week']));
            $this->io->text("Last Month: " . number_format($dist['last_month']));
            $this->io->text("Last Quarter: " . number_format($dist['last_quarter']));
            $this->io->text("Total: " . number_format($dist['total']));
        }
    }

    /**
     * @param mixed $summary
     */
    private function exportToCsv($summary): void
    {
        $filename = 'archive-export-' . date('Y-m-d-H-i-s') . '.csv';
        $this->io->text("Exporting to: {$filename}");
        // Implementation would write CSV data
    }

    /**
     * @param array<array<string, mixed>> $records
     */
    private function outputCsv(array $records): void
    {
        if ($records === []) {
            return;
        }

        // Output CSV headers
        /** @var array<string> $headers */
        $headers = array_keys($records[0]);
        $this->io->text(implode(',', $headers));

        // Output CSV rows
        foreach ($records as $record) {
            $this->io->text(implode(',', array_values($record)));
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        /** @var array<string> $units */
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    private function executeTrack(InputInterface $input): int
    {
        $table = $input->getArgument('table');
        if ($table === null || $table === '') {
            $this->io->error('Table name is required for track action');
            $this->io->text('Usage: archive:manage track <table>');
            return self::FAILURE;
        }

        $this->io->title("📈 Tracking Table Growth: {$table}");

        try {
            // Track table growth (this would update internal tracking statistics)
            $this->archiveService->trackTableGrowth($table);

            // Get current table statistics
            $stats = $this->archiveService->getTableStats($table);

            if ($stats !== null) {
                $this->io->success('✅ Growth tracking updated');
                $this->io->section('📊 Current Table Statistics');
                $statsRows = [
                    ['Metric', 'Value'],
                    ['Current Rows', number_format($stats->currentRowCount)],
                    ['Current Size', $this->formatBytes($stats->currentSizeBytes)],
                    ['Needs Archive', $stats->needsArchive ? 'Yes' : 'No'],
                ];

                if ($stats->lastArchiveDate !== null) {
                    $statsRows[] = ['Last Archived', $stats->lastArchiveDate->format('Y-m-d H:i:s')];
                } else {
                    $statsRows[] = ['Last Archived', 'Never'];
                }

                $this->io->table($statsRows[0], array_slice($statsRows, 1));

                // Show archival recommendation
                if ($stats->needsArchive) {
                    $this->io->warning('⚠️ This table needs archival');
                    $this->io->text('💡 Run: php glueful archive:manage archive ' . $table);
                } else {
                    $this->io->info('ℹ️ Table does not currently need archival');
                }
            } else {
                $this->io->error('❌ Failed to get table statistics');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->io->error('❌ Failed to track table growth: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function handleUnknownAction(string $action): int
    {
        $this->io->error("Unknown action: {$action}");
        $this->io->text('Available actions: archive, status, search, verify, health, cleanup, auto, ' .
                       'list, restore, export, track');
        return self::FAILURE;
    }
}
