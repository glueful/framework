<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\ReadinessState;
use Glueful\Extensions\Schema\SchemaReadiness;
use Glueful\Extensions\Schema\UndeclaredSchemaException;
use Glueful\Installer\DatabaseConfig;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

final class SchemaReadinessTest extends TestCase
{
    private string $base;
    private Connection $connection;
    private DescriptorInventory $inventory;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-sr-' . uniqid('', true);
        mkdir($this->base . '/vendor/composer', 0777, true);
        $dir = $this->base . '/vendor/acme/widgets/migrations';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/001_A.php', "<?php // migration A v1\n");
        file_put_contents($dir . '/002_B.php', "<?php // migration B v1\n");
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
        ], [
            'name' => 'acme/legacy',
            'type' => 'glueful-extension',
            'install-path' => '../acme/legacy',
            'extra' => ['glueful' => ['provider' => 'Acme\\Legacy\\P']],
        ]]], JSON_UNESCAPED_SLASHES));
        $this->inventory = DescriptorInventory::fromManifest(
            new PackageManifest(new ApplicationContext($this->base)),
            dirname(__DIR__, 4),
            new FileFinder()
        );
        $config = new DatabaseConfig('sqlite', database: $this->base . '/db.sqlite');
        $this->connection = new Connection($config->toConnectionConfig());
    }

    private function createLedger(): void
    {
        $this->connection->getPDO()->exec(
            'CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(255), '
            . "batch INTEGER, applied_at TIMESTAMP, checksum VARCHAR(64), description TEXT, "
            . "extension VARCHAR(100), source VARCHAR(191) DEFAULT 'app')"
        );
    }

    private function seedReceipt(string $source, string $file): void
    {
        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO migrations (migration, batch, checksum, source) VALUES (?, 1, ?, ?)'
        );
        $stmt->execute([basename($file), hash_file('sha256', $file), $source]);
    }

    /** @return list<string> */
    private function tables(): array
    {
        return $this->connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function readiness(bool $aliasesNormalized = false): SchemaReadiness
    {
        return new SchemaReadiness($this->connection, $this->inventory, $aliasesNormalized);
    }

    private function widgetFile(string $basename): string
    {
        return $this->base . '/vendor/acme/widgets/migrations/' . $basename;
    }

    public function testCompleteMatchingReceiptsClassifyReady(): void
    {
        $this->createLedger();
        $this->seedReceipt('acme/widgets', $this->widgetFile('001_A.php'));
        $this->seedReceipt('acme/widgets', $this->widgetFile('002_B.php'));

        $d = $this->inventory->bySource('acme/widgets');
        self::assertSame(ReadinessState::Ready, $this->readiness()->classify($d));
    }

    public function testMissingLedgerClassifiesPendingWithZeroDdl(): void
    {
        $d = $this->inventory->bySource('acme/widgets');
        self::assertSame(ReadinessState::Pending, $this->readiness()->classify($d));
        self::assertSame([], $this->tables(), 'readiness reads must never create the ledger');
    }

    public function testChangedFileOnDiskClassifiesDivergentNamingTheFile(): void
    {
        $this->createLedger();
        $this->seedReceipt('acme/widgets', $this->widgetFile('001_A.php'));
        $this->seedReceipt('acme/widgets', $this->widgetFile('002_B.php'));
        file_put_contents($this->widgetFile('002_B.php'), "<?php // migration B v2 EDITED\n");

        $d = $this->inventory->bySource('acme/widgets');
        $readiness = $this->readiness();
        self::assertSame(ReadinessState::Divergent, $readiness->classify($d));
        self::assertNotEmpty(array_filter(
            $readiness->explain($d),
            static fn(string $r): bool => str_contains($r, '002_B.php')
        ));
    }

    public function testReceiptForARemovedFileClassifiesDivergent(): void
    {
        $this->createLedger();
        $this->seedReceipt('acme/widgets', $this->widgetFile('001_A.php'));
        $this->seedReceipt('acme/widgets', $this->widgetFile('002_B.php'));
        // A receipt whose file no longer ships.
        $this->connection->getPDO()->exec(
            "INSERT INTO migrations (migration, batch, checksum, source) "
            . "VALUES ('000_Gone.php', 1, 'deadbeef', 'acme/widgets')"
        );

        $d = $this->inventory->bySource('acme/widgets');
        self::assertSame(ReadinessState::Divergent, $this->readiness()->classify($d));
    }

    public function testAliasReceiptsAreDivergentUntilNormalizedThenReady(): void
    {
        $this->createLedger();
        $this->seedReceipt('acme-widgets', $this->widgetFile('001_A.php'));
        $this->seedReceipt('acme-widgets', $this->widgetFile('002_B.php'));

        $d = $this->inventory->bySource('acme/widgets');
        $before = $this->readiness(aliasesNormalized: false);
        self::assertSame(ReadinessState::Divergent, $before->classify($d));
        self::assertNotEmpty(array_filter(
            $before->explain($d),
            static fn(string $r): bool => str_contains($r, 'migrate:normalize-receipts')
        ));

        self::assertSame(ReadinessState::Ready, $this->readiness(aliasesNormalized: true)->classify($d));
    }

    public function testUndeclaredPackageFailsClosed(): void
    {
        $this->expectException(UndeclaredSchemaException::class);
        $this->readiness()->forPackage('acme/legacy');
    }
}
