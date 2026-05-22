<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Services\Archive;

use Glueful\Database\Connection;
use Glueful\Services\Archive\ArchiveService;
use Glueful\Services\Archive\DTOs\ArchiveRestoreOptions;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip tests for {@see ArchiveService::restoreFromArchive()}.
 *
 * Each test runs on its own temp SQLite database with a minimal schema:
 *  - `archive_registry` matching the production migration shape
 *  - One sample data table that gets archived and restored
 *
 * Verifies the post-TG-4 contract: archived rows can actually be replayed,
 * conflict resolution honors skip/overwrite, offset/limit slice correctly,
 * and unsupported options fail loudly instead of silently.
 */
final class ArchiveRestoreTest extends TestCase
{
    private string $dbPath;
    private string $archiveDir;
    private Connection $connection;
    private ArchiveService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/glueful-archive-restore-' . uniqid('', true) . '.sqlite';
        $this->archiveDir = sys_get_temp_dir() . '/glueful-archive-restore-' . uniqid('', true);

        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);

        $pdo = $this->connection->getPDO();
        $pdo->exec(
            'CREATE TABLE archive_registry (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT UNIQUE NOT NULL,
                table_name TEXT NOT NULL,
                archive_date TEXT,
                period_start TEXT,
                period_end TEXT,
                record_count INTEGER,
                file_path TEXT,
                file_size INTEGER,
                checksum_sha256 TEXT,
                metadata TEXT,
                status TEXT DEFAULT "completed",
                created_at TEXT
            )'
        );

        $pdo->exec(
            'CREATE TABLE sample_records (
                uuid TEXT PRIMARY KEY,
                payload TEXT,
                created_at TEXT,
                deleted_at TEXT
            )'
        );

        $pdo->exec(
            'CREATE TABLE archive_table_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_name TEXT UNIQUE NOT NULL,
                current_size_bytes INTEGER DEFAULT 0,
                current_row_count INTEGER DEFAULT 0,
                last_archive_date TEXT,
                next_archive_date TEXT,
                archive_threshold_rows INTEGER DEFAULT 100000,
                archive_threshold_days INTEGER DEFAULT 30,
                auto_archive_enabled INTEGER DEFAULT 1,
                created_at TEXT,
                updated_at TEXT
            )'
        );

        $this->service = new ArchiveService(
            connection: $this->connection,
            config: [
                'storage_path' => $this->archiveDir,
                'compression' => 'gzip',
                'verify_checksums' => true,
                'chunk_size' => 100,
            ]
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        if (is_dir($this->archiveDir)) {
            foreach (glob($this->archiveDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->archiveDir);
        }
        parent::tearDown();
    }

    public function testRestoreReplaysAllArchivedRows(): void
    {
        $this->seedRecords(5);
        $archiveUuid = $this->archiveOldRows();
        $this->truncateSourceTable();

        $result = $this->service->restoreFromArchive($archiveUuid);

        self::assertTrue($result->success, $result->error ?? '');
        self::assertSame(5, $result->recordsRestored);
        self::assertSame('sample_records', $result->targetTable);
        self::assertCount(5, $this->fetchAllRecords());
    }

    public function testSkipConflictResolutionSkipsExistingRowsAndReportsThem(): void
    {
        $this->seedRecords(3);
        $archiveUuid = $this->archiveOldRows();
        // Leave one existing row in place, delete the rest
        $this->connection->getPDO()->exec("DELETE FROM sample_records WHERE uuid <> 'rec_001'");

        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            new ArchiveRestoreOptions(conflictResolution: 'skip')
        );

        self::assertTrue($result->success);
        self::assertSame(2, $result->recordsRestored);
        self::assertTrue($result->hasConflicts());
        self::assertSame(1, $result->getConflictCount());
        self::assertSame(['uuid=rec_001'], $result->conflicts);
        self::assertCount(3, $this->fetchAllRecords());
    }

    public function testOverwriteConflictResolutionReplacesExistingRows(): void
    {
        $this->seedRecords(2);
        $archiveUuid = $this->archiveOldRows();

        // Mutate the existing row so we can verify it was actually overwritten
        $this->connection->getPDO()->exec(
            "UPDATE sample_records SET payload = 'mutated' WHERE uuid = 'rec_001'"
        );

        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            new ArchiveRestoreOptions(conflictResolution: 'overwrite')
        );

        self::assertTrue($result->success);
        self::assertSame(2, $result->recordsRestored);
        self::assertFalse($result->hasConflicts());

        $rows = $this->fetchAllRecords();
        $byUuid = [];
        foreach ($rows as $row) {
            $byUuid[$row['uuid']] = $row['payload'];
        }
        self::assertSame('payload-1', $byUuid['rec_001']);
    }

    public function testLimitAndOffsetSliceArchivePayload(): void
    {
        $this->seedRecords(5);
        $archiveUuid = $this->archiveOldRows();
        $this->truncateSourceTable();

        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            new ArchiveRestoreOptions(limit: 2, offset: 1)
        );

        self::assertTrue($result->success);
        self::assertSame(2, $result->recordsRestored);
        self::assertCount(2, $this->fetchAllRecords());
    }

    public function testMissingArchiveReturnsFailure(): void
    {
        $result = $this->service->restoreFromArchive('does-not-exist');

        self::assertFalse($result->success);
        self::assertNotNull($result->error);
        self::assertStringContainsString('not found', $result->error);
    }

    public function testUnsupportedConflictResolutionReturnsFailure(): void
    {
        $this->seedRecords(1);
        $archiveUuid = $this->archiveOldRows();

        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            new ArchiveRestoreOptions(conflictResolution: 'rename')
        );

        self::assertFalse($result->success);
        self::assertNotNull($result->error);
        self::assertStringContainsString('Unsupported conflict resolution', $result->error);
    }

    public function testMissingTargetTableReturnsFailureInsteadOfSilentlySucceeding(): void
    {
        $this->seedRecords(1);
        $archiveUuid = $this->archiveOldRows();
        $this->connection->getPDO()->exec('DROP TABLE sample_records');

        // createTableIfNotExists must be false so we exercise the "table missing"
        // branch (not the "auto-create unsupported" branch).
        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            new ArchiveRestoreOptions(createTableIfNotExists: false)
        );

        self::assertFalse($result->success);
        self::assertNotNull($result->error);
        self::assertStringContainsString("does not exist", $result->error);
    }

    public function testCreateTableIfNotExistsIsExplicitlyUnsupported(): void
    {
        $this->seedRecords(1);
        $archiveUuid = $this->archiveOldRows();
        $this->connection->getPDO()->exec('DROP TABLE sample_records');

        $result = $this->service->restoreFromArchive(
            $archiveUuid,
            ArchiveRestoreOptions::fullRestore()
        );

        self::assertFalse($result->success);
        self::assertNotNull($result->error);
        self::assertStringContainsString('Auto-creating', $result->error);
    }

    private function seedRecords(int $count): void
    {
        $stmt = $this->connection->getPDO()->prepare(
            "INSERT INTO sample_records (uuid, payload, created_at)
             VALUES (?, ?, '2020-01-01 00:00:00')"
        );
        for ($i = 1; $i <= $count; $i++) {
            $stmt->execute([
                sprintf('rec_%03d', $i),
                "payload-{$i}",
            ]);
        }
    }

    private function archiveOldRows(): string
    {
        $cutoff = new \DateTime('2030-01-01 00:00:00');
        $result = $this->service->archiveTable('sample_records', $cutoff);
        self::assertTrue($result->success, $result->error ?? '');
        self::assertNotNull($result->archiveUuid);

        return (string) $result->archiveUuid;
    }

    private function truncateSourceTable(): void
    {
        $this->connection->getPDO()->exec('DELETE FROM sample_records');
    }

    /**
     * @return list<array{uuid: string, payload: string, created_at: string}>
     */
    private function fetchAllRecords(): array
    {
        $stmt = $this->connection->getPDO()->query(
            'SELECT uuid, payload, created_at FROM sample_records ORDER BY uuid'
        );
        if ($stmt === false) {
            return [];
        }

        /** @var list<array{uuid: string, payload: string, created_at: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $rows;
    }
}
