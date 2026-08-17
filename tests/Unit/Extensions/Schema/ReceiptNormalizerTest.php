<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\MigrationLockHandle;
use Glueful\Extensions\Schema\MigrationLockInterface;
use Glueful\Extensions\Schema\ReceiptNormalizer;
use Glueful\Installer\DatabaseConfig;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

final class ReceiptNormalizerTest extends TestCase
{
    private string $base;
    private Connection $connection;
    private DescriptorInventory $inventory;
    /** @var list<string> */
    private array $lockLog = [];
    private MigrationLockInterface $spyLock;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-rn-' . uniqid('', true);
        mkdir($this->base . '/vendor/composer', 0777, true);
        $dir = $this->base . '/vendor/acme/widgets/migrations';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/001_A.php', "<?php // migration A v1\n");
        file_put_contents($this->base . '/vendor/composer/installed.json', json_encode(['packages' => [[
            'name' => 'acme/widgets',
            'type' => 'glueful-extension',
            'install-path' => '../acme/widgets',
            'extra' => ['glueful' => [
                'provider' => 'Acme\\Widgets\\P',
                'migrations' => [[
                    'id' => 'default',
                    'path' => 'migrations',
                    'priority' => 'default',
                    'mode' => 'on_enable',
                    'legacyAliases' => ['acme-widgets'],
                ]],
            ]],
        ]]], JSON_UNESCAPED_SLASHES));
        $this->inventory = DescriptorInventory::fromManifest(
            new PackageManifest(new ApplicationContext($this->base)),
            dirname(__DIR__, 4),
            new FileFinder()
        );
        $config = new DatabaseConfig('sqlite', database: $this->base . '/db.sqlite');
        $this->connection = new Connection($config->toConnectionConfig());
        $log = &$this->lockLog;
        $this->spyLock = new class ($log) implements MigrationLockInterface {
            /** @param list<string> $log */
            public function __construct(private array &$log)
            {
            }

            public function acquireAll(array $sources, int $waitSeconds = 10): MigrationLockHandle
            {
                $this->log[] = 'acquire:' . implode(',', $sources);
                $log = &$this->log;
                return new MigrationLockHandle([static function () use (&$log): void {
                    $log[] = 'release';
                }]);
            }
        };
    }

    private function createLedger(): void
    {
        $this->connection->getPDO()->exec(
            'CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(255), '
            . "batch INTEGER, applied_at TIMESTAMP, checksum VARCHAR(64), description TEXT, "
            . "extension VARCHAR(100), source VARCHAR(191) DEFAULT 'app')"
        );
    }

    private function seed(string $source, string $migration, string $checksum): void
    {
        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO migrations (migration, batch, checksum, source) VALUES (?, 1, ?, ?)'
        );
        $stmt->execute([$migration, $checksum, $source]);
    }

    private function fileChecksum(): string
    {
        return hash_file('sha256', $this->base . '/vendor/acme/widgets/migrations/001_A.php');
    }

    private function normalizer(): ReceiptNormalizer
    {
        return new ReceiptNormalizer($this->connection, $this->inventory, $this->spyLock);
    }

    /** @return list<array{migration: string, source: string}> */
    private function rows(): array
    {
        return $this->connection->getPDO()
            ->query('SELECT migration, source FROM migrations ORDER BY id')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testChecksumMatchedAliasRowIsRewrittenToTheDescriptorSource(): void
    {
        $this->createLedger();
        $this->seed('acme-widgets', '001_A.php', $this->fileChecksum());

        $report = $this->normalizer()->normalize();

        self::assertCount(1, $report->rewritten);
        self::assertSame([], $report->refused);
        self::assertSame([['migration' => '001_A.php', 'source' => 'acme/widgets']], $this->rows());
        self::assertSame(['acquire:acme/widgets', 'release'], $this->lockLog, 'lock held and finally-released');
    }

    public function testChecksumMismatchRefusesTheAlias(): void
    {
        $this->createLedger();
        $this->seed('acme-widgets', '001_A.php', 'not-the-file-checksum');

        $report = $this->normalizer()->normalize();

        self::assertSame([], $report->rewritten);
        self::assertCount(1, $report->refused);
        self::assertSame('acme-widgets', $report->refused[0]['alias']);
        self::assertSame([['migration' => '001_A.php', 'source' => 'acme-widgets']], $this->rows());
    }

    public function testDuplicateTargetWithIdenticalChecksumReconcilesByDeletingTheAliasRow(): void
    {
        $this->createLedger();
        $this->seed('acme/widgets', '001_A.php', $this->fileChecksum());
        $this->seed('acme-widgets', '001_A.php', $this->fileChecksum());

        $report = $this->normalizer()->normalize();

        self::assertCount(1, $report->rewritten);
        self::assertSame([['migration' => '001_A.php', 'source' => 'acme/widgets']], $this->rows());
    }

    public function testDuplicateTargetWithDifferingChecksumRefuses(): void
    {
        $this->createLedger();
        $this->seed('acme/widgets', '001_A.php', 'different-earlier-checksum');
        $this->seed('acme-widgets', '001_A.php', $this->fileChecksum());

        $report = $this->normalizer()->normalize();

        self::assertCount(1, $report->refused);
        self::assertCount(2, $this->rows(), 'nothing rewritten or deleted on ambiguity');
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $this->createLedger();
        $this->seed('acme-widgets', '001_A.php', $this->fileChecksum());

        $report = $this->normalizer()->normalize(dryRun: true);

        self::assertCount(1, $report->rewritten);
        self::assertSame([['migration' => '001_A.php', 'source' => 'acme-widgets']], $this->rows());
    }

    public function testSecondRunIsIdempotent(): void
    {
        $this->createLedger();
        $this->seed('acme-widgets', '001_A.php', $this->fileChecksum());

        $this->normalizer()->normalize();
        $second = $this->normalizer()->normalize();

        self::assertSame([], $second->rewritten);
        self::assertSame([], $second->refused);
    }

    public function testLedgerAbsentReturnsEmptyReportWithZeroDdl(): void
    {
        $report = $this->normalizer()->normalize();

        self::assertSame([], $report->rewritten);
        self::assertSame([], $report->refused);
        $tables = $this->connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables, 'only migrate operations create the ledger');
    }
}
