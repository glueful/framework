<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Migrations;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Database\Migrations\MigrationScopeException;
use Glueful\Installer\DatabaseConfig;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

final class TransactionalMigrationTest extends TestCase
{
    private string $base;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-txn-' . uniqid('', true);
        mkdir($this->base . '/app', 0777, true);
        $config = new DatabaseConfig('sqlite', database: $this->base . '/db.sqlite');
        $this->connection = new Connection($config->toConnectionConfig());
    }

    private function manager(): MigrationManager
    {
        return new MigrationManager($this->base . '/app', new FileFinder(), null, $this->connection);
    }

    /** Filename derives the class (NNN_Class.php => Class); a unique suffix avoids redeclares. */
    private function writeMigration(string $dir, string $number, string $classStem, string $upBody): string
    {
        @mkdir($dir, 0777, true);
        $class = $classStem . 'X' . substr(md5($dir . $number), 0, 8);
        $file = $dir . '/' . $number . '_' . $class . '.php';
        file_put_contents($file, <<<PHP
            <?php

            use Glueful\\Database\\Migrations\\MigrationInterface;
            use Glueful\\Database\\Schema\\Interfaces\\SchemaBuilderInterface;

            class {$class} implements MigrationInterface
            {
                public function up(SchemaBuilderInterface \$schema): void
                {
                    {$upBody}
                }

                public function down(SchemaBuilderInterface \$schema): void
                {
                }

                public function getDescription(): string
                {
                    return 'fixture';
                }
            }
            PHP);
        return $file;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return $this->connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function allReceiptCount(): int
    {
        return (int) $this->connection->getPDO()
            ->query('SELECT COUNT(*) FROM migrations')
            ->fetchColumn();
    }

    public function testFailedMigrationRollsBackBothDdlAndReceiptOnTransactionalDrivers(): void
    {
        $this->writeMigration(
            $this->base . '/app',
            '001',
            'CreateThenThrow',
            "\$schema->createTable('doomed', function (\$t) { \$t->string('name', 50); });\n"
            . "            throw new \\RuntimeException('boom after DDL');"
        );
        $manager = $this->manager();

        $result = $manager->migrate();

        self::assertSame([], $result['applied']);
        self::assertCount(1, $result['failed']);
        self::assertNotContains('doomed', $this->tables(), 'partial DDL must roll back with its receipt');
        self::assertSame(0, $this->allReceiptCount());
    }

    public function testSuccessfulMigrationLandsTableAndReceiptTogether(): void
    {
        $this->writeMigration(
            $this->base . '/app',
            '001',
            'CreateWidgets',
            "\$schema->createTable('widgets', function (\$t) { \$t->string('name', 50); });"
        );
        $manager = $this->manager();

        $result = $manager->migrate();

        self::assertCount(1, $result['applied']);
        self::assertContains('widgets', $this->tables());
        self::assertSame(1, $this->allReceiptCount());
    }

    public function testMigrateSourcesRunsOnlyTheNamedSourcesAndReports(): void
    {
        $aDir = $this->base . '/pkg-a';
        $bDir = $this->base . '/pkg-b';
        $this->writeMigration(
            $aDir,
            '001',
            'CreateAlpha',
            "\$schema->createTable('alpha', function (\$t) { \$t->string('name', 50); });"
        );
        $this->writeMigration(
            $bDir,
            '001',
            'CreateBeta',
            "\$schema->createTable('beta', function (\$t) { \$t->string('name', 50); });"
        );
        $manager = $this->manager();
        $manager->addMigrationPath($aDir, MigrationPriority::DEFAULT, 'pkg/a');
        $manager->addMigrationPath($bDir, MigrationPriority::DEFAULT, 'pkg/b');

        $report = $manager->migrateSources(['pkg/a']);

        self::assertCount(1, $report->outcomes);
        self::assertSame('pkg/a', $report->outcomes[0]['source']);
        self::assertSame('applied', $report->outcomes[0]['status']);
        self::assertContains('alpha', $this->tables());
        self::assertNotContains('beta', $this->tables(), 'unnamed sources must not run');
        self::assertNull($report->firstFailure());
    }

    public function testMigrateSourcesStopsAtTheFirstFailureLeavingLaterFilesPending(): void
    {
        $dir = $this->base . '/pkg-c';
        $this->writeMigration(
            $dir,
            '001',
            'FailsFirst',
            "throw new \\RuntimeException('first fails');"
        );
        $this->writeMigration(
            $dir,
            '002',
            'NeverRuns',
            "\$schema->createTable('never', function (\$t) { \$t->string('name', 50); });"
        );
        $manager = $this->manager();
        $manager->addMigrationPath($dir, MigrationPriority::DEFAULT, 'pkg/c');

        $report = $manager->migrateSources(['pkg/c']);

        self::assertCount(1, $report->outcomes, 'execution stops at the first failure');
        self::assertSame('failed', $report->outcomes[0]['status']);
        self::assertFalse($report->outcomes[0]['requiresManualRepair'], 'sqlite is transactional');
        self::assertNotContains('never', $this->tables());
        $stillPending = array_map(
            static fn(array $r): string => basename($r['file']),
            $manager->pendingForSources(['pkg/c'])
        );
        // BOTH files stay pending: the failed one rolled back (no receipt), and the one after
        // it never ran — nothing is half-recorded.
        self::assertCount(2, $stillPending);
        self::assertStringStartsWith('001_', $stillPending[0]);
        self::assertStringStartsWith('002_', $stillPending[1]);
    }

    public function testNonTransactionalDriverFailureFlagsManualRepair(): void
    {
        $dir = $this->base . '/pkg-d';
        $this->writeMigration($dir, '001', 'BadOne', "throw new \\RuntimeException('bad');");
        $manager = new class (
            $this->base . '/app',
            new FileFinder(),
            null,
            $this->connection
        ) extends MigrationManager {
            protected function transactionalDdl(): bool
            {
                return false; // simulate a mysql-like driver
            }
        };
        $manager->addMigrationPath($dir, MigrationPriority::DEFAULT, 'pkg/d');

        $report = $manager->migrateSources(['pkg/d']);

        self::assertSame('failed', $report->outcomes[0]['status']);
        self::assertTrue($report->outcomes[0]['requiresManualRepair']);
    }

    public function testTolerantDropOfAMissingIndexCannotPoisonTheMigrationTransaction(): void
    {
        // dropIndex is tolerant by contract (SchemaBuilder swallows its failure), but before the
        // IF EXISTS fix the errored statement poisoned the wrapped transaction and failed every
        // later statement — the payvia 006 fresh-chain regression.
        $dir = $this->base . '/pkg-drop';
        $this->writeMigration(
            $dir,
            '001',
            'DropThenCreate',
            "\$schema->dropIndex('ghost_table_never_existed', 'idx_ghost');\n"
            . "            \$schema->createTable('after_drop', function (\$t) { \$t->string('name', 50); });"
        );
        $manager = $this->manager();
        $manager->addMigrationPath($dir, MigrationPriority::DEFAULT, 'pkg/drop');

        $report = $manager->migrateSources(['pkg/drop']);

        self::assertSame('applied', $report->outcomes[0]['status'], $report->outcomes[0]['error'] ?? '');
        self::assertContains('after_drop', $this->tables());
    }

    public function testSelfTransactingMigrationRunsUnwrappedAndStillGetsItsReceipt(): void
    {
        // Shipped migrations may manage their own PDO transaction inside up() (e.g. payvia's
        // 012 attempt-lifecycle rebuild). The runner's wrapper must detect the clean nested-begin
        // failure and re-run unwrapped — the migration supplies its own atomicity.
        $dir = $this->base . '/pkg-txn';
        $this->writeMigration(
            $dir,
            '001',
            'SelfTxn',
            "\$pdo = \$schema->getConnection()->getPDO();\n"
            . "            \$pdo->beginTransaction();\n"
            . "            \$pdo->exec('CREATE TABLE selftxn (name VARCHAR(50))');\n"
            . "            \$pdo->commit();"
        );
        $manager = $this->manager();
        $manager->addMigrationPath($dir, MigrationPriority::DEFAULT, 'pkg/txn');

        $report = $manager->migrateSources(['pkg/txn']);

        self::assertSame('applied', $report->outcomes[0]['status']);
        self::assertContains('selftxn', $this->tables());
        self::assertSame(1, $this->allReceiptCount());
    }

    public function testExplicitMigrateArgumentsCannotBypassTheGlobalPolicy(): void
    {
        $dir = $this->base . '/pkg-e';
        $disabledFile = $this->writeMigration(
            $dir,
            '001',
            'DisabledOne',
            "\$schema->createTable('sneaky', function (\$t) { \$t->string('name', 50); });"
        );
        $allowedFile = $this->writeMigration(
            $this->base . '/app',
            '001',
            'AllowedOne',
            "\$schema->createTable('allowed', function (\$t) { \$t->string('name', 50); });"
        );
        $manager = $this->manager();
        // pkg-e is an on_enable descriptor source belonging to a DISABLED package.
        $manager->registerDescriptor(new \Glueful\Extensions\Schema\MigrationDescriptor(
            id: 'default',
            package: 'acme/e',
            packageType: 'glueful-extension',
            relativePath: 'migrations',
            priority: MigrationPriority::DEFAULT,
            mode: \Glueful\Extensions\Schema\DescriptorMode::OnEnable,
        ), $dir);
        $manager->setGlobalSourcePolicy(static fn(): array => []);

        try {
            $manager->migrate($disabledFile);
            self::fail('string form must throw MigrationScopeException');
        } catch (MigrationScopeException) {
        }
        try {
            $manager->migrate([$allowedFile, $disabledFile]);
            self::fail('array form must throw MigrationScopeException');
        } catch (MigrationScopeException) {
        }
        self::assertNotContains('sneaky', $this->tables());
        self::assertNotContains('allowed', $this->tables(), 'no earlier allowed file may run before validation');

        // The explicit, named scoped bypass remains available to the executor.
        $report = $manager->migrateSources(['acme/e']);
        self::assertSame('applied', $report->outcomes[0]['status']);
        self::assertContains('sneaky', $this->tables());
    }
}
