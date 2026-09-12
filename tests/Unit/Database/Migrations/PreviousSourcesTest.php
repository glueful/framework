<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Migrations;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Services\FileFinder;
use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

/**
 * A lane whose files were recorded under another source name before — an application that
 * became a package, a renamed package, a lane split out of a package — declares those names as
 * `previous_sources`. A row the ledger holds under a previous source counts as applied for the
 * lane, and the first run adopts it under the current name: nothing re-runs, nothing looks
 * pending, and the previous names disappear from the ledger.
 */
final class PreviousSourcesTest extends TestCase
{
    private string $dbFile;
    private string $dir;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/mm_prev_' . uniqid() . '.sqlite';
        $this->dir = sys_get_temp_dir() . '/mm_prev_dir_' . uniqid();
        mkdir($this->dir);
        $config = new DatabaseConfig('sqlite', database: $this->dbFile);
        $this->connection = new Connection($config->toConnectionConfig());
        $this->writeMigration('001_CreateThings.php', 'CreateThings');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/001_CreateThings.php');
        @rmdir($this->dir);
        @unlink($this->dbFile);
    }

    private function writeMigration(string $file, string $class): void
    {
        file_put_contents($this->dir . '/' . $file, <<<PHP
            <?php

            use Glueful\\Database\\Migrations\\MigrationInterface;
            use Glueful\\Database\\Schema\\Interfaces\\SchemaBuilderInterface;

            class {$class} implements MigrationInterface
            {
                public function up(SchemaBuilderInterface \$schema): void
                {
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
    }

    private function manager(): MigrationManager
    {
        $empty = sys_get_temp_dir() . '/mm_prev_app_' . uniqid();
        mkdir($empty);
        return new MigrationManager($empty, new FileFinder(), null, $this->connection);
    }

    /** Create the ledger (as a first run would) and record $file under $source. */
    private function recordApplied(string $source, string $file): void
    {
        $this->manager()->migrate([]); // ensures the ledger table; nothing to run
        $this->connection->table('migrations')->insert([
            'migration' => $file,
            'batch' => 1,
            'checksum' => 'x',
            'description' => 'fixture',
            'extension' => null,
            'source' => $source,
        ]);
    }

    /** @return list<string> */
    private function sourcesRecordedFor(string $file): array
    {
        $rows = $this->connection->table('migrations')->select(['source'])->where('migration', '=', $file)->get();
        return array_values(array_map(static fn (array $r): string => (string) $r['source'], $rows));
    }

    public function testRowsUnderAPreviousSourceCountAsAppliedForTheLane(): void
    {
        $this->recordApplied('app', '001_CreateThings.php');
        $manager = $this->manager();
        $manager->addMigrationPath($this->dir, MigrationPriority::DEFAULT, 'glueful/thing-core', ['app']);

        self::assertSame([], $manager->pendingForSources(['glueful/thing-core']));
        self::assertSame([], $manager->getPendingMigrations());
    }

    public function testWithoutTheDeclarationTheSameRowLooksPending(): void
    {
        $this->recordApplied('app', '001_CreateThings.php');
        $manager = $this->manager();
        $manager->addMigrationPath($this->dir, MigrationPriority::DEFAULT, 'glueful/thing-core');

        self::assertCount(1, $manager->pendingForSources(['glueful/thing-core']));
    }

    public function testARunAdoptsPreviouslyRecordedRowsUnderTheCurrentSource(): void
    {
        $this->recordApplied('app:dependent', '001_CreateThings.php');
        $manager = $this->manager();
        $manager->addMigrationPath(
            $this->dir,
            MigrationPriority::DEPENDENT,
            'glueful/thing-core:dependent',
            ['app:dependent'],
        );

        $report = $manager->migrateSources(['glueful/thing-core:dependent']);

        self::assertSame([], $report->outcomes, 'nothing re-runs');
        self::assertSame(['glueful/thing-core:dependent'], $this->sourcesRecordedFor('001_CreateThings.php'));
    }

    public function testAdoptionOnlyTouchesFilesTheLaneActuallyShips(): void
    {
        $this->recordApplied('app', '001_CreateThings.php');
        $this->connection->table('migrations')->insert([
            'migration' => '002_SomethingElse.php', 'batch' => 1, 'checksum' => 'x',
            'description' => 'fixture', 'extension' => null, 'source' => 'app',
        ]);
        $manager = $this->manager();
        $manager->addMigrationPath($this->dir, MigrationPriority::DEFAULT, 'glueful/thing-core', ['app']);

        $manager->migrate();

        self::assertSame(['glueful/thing-core'], $this->sourcesRecordedFor('001_CreateThings.php'));
        self::assertSame(['app'], $this->sourcesRecordedFor('002_SomethingElse.php'), 'the operator\'s own row stays');
    }
}
