<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Migrations;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Installer\DatabaseConfig;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

/**
 * The lazy-ledger contract (schema policy spec 2026-08-17, A1):
 *
 * - Constructing the manager and registering paths perform zero DDL.
 * - Status/pending reads treat a missing ledger as zero applied migrations, no DDL.
 * - rollback() reports nothing-to-rollback when the ledger is absent, no DDL.
 * - migrate() is the ONLY operation that ensures/creates the ledger.
 */
final class LazyLedgerContractTest extends TestCase
{
    private string $dbFile;
    private string $emptyMigrationsDir;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/mm_lazy_' . uniqid() . '.sqlite';
        $this->emptyMigrationsDir = sys_get_temp_dir() . '/mm_lazy_dir_' . uniqid();
        mkdir($this->emptyMigrationsDir);
        $config = new DatabaseConfig('sqlite', database: $this->dbFile);
        $this->connection = new Connection($config->toConnectionConfig());
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
        @rmdir($this->emptyMigrationsDir);
    }

    /** @return array<string> */
    private function tables(): array
    {
        return $this->connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function manager(): MigrationManager
    {
        return new MigrationManager($this->emptyMigrationsDir, new FileFinder(), null, $this->connection);
    }

    public function testConstructionAndPathRegistrationCreateNothing(): void
    {
        $manager = $this->manager();
        $manager->addMigrationPath($this->emptyMigrationsDir, source: 'some-package');

        self::assertSame([], $this->tables(), 'construction/registration must perform zero DDL');
    }

    public function testStatusAndPendingOnLedgerlessDatabaseReturnEmptyWithoutDdl(): void
    {
        $manager = $this->manager();

        self::assertSame([], $manager->getPendingMigrations());
        self::assertSame(['pending' => [], 'applied' => []], $manager->getMigrationStatus());
        self::assertSame([], $manager->getAppliedMigrationsList());
        self::assertSame([], $this->tables(), 'reads must not create the ledger');
    }

    public function testRollbackOnLedgerlessDatabaseReportsNothingWithoutDdl(): void
    {
        $manager = $this->manager();

        self::assertSame(['reverted' => [], 'failed' => []], $manager->rollback());
        self::assertSame([], $this->tables(), 'rollback must not create the ledger');
    }

    public function testMigrateIsTheOnlyLedgerCreator(): void
    {
        $manager = $this->manager();

        $result = $manager->migrate();

        self::assertSame(['applied' => [], 'failed' => []], $result);
        self::assertContains('migrations', $this->tables(), 'migrate() must ensure the ledger');

        // Idempotent on a second pass.
        self::assertSame(['applied' => [], 'failed' => []], $manager->migrate());
    }
}
