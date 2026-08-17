<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\AdoptionService;
use Glueful\Extensions\Schema\AdoptionState;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\MigrationLockHandle;
use Glueful\Extensions\Schema\MigrationLockInterface;
use Glueful\Extensions\Schema\SchemaReadiness;
use Glueful\Extensions\Schema\StructuralVerifierInterface;
use Glueful\Installer\DatabaseConfig;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

/** Verifier that approves everything for acme/pass. */
final class PassingVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'acme/pass';
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return true;
    }
}

/** Verifier that refuses everything for acme/fail. */
final class RefusingVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'acme/fail';
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return false;
    }
}

/** Wrong source() — must classify its descriptor divergent before verify() runs. */
final class MismatchedSourceVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'someone/else';
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        throw new \LogicException('must never be called');
    }
}

/** Constructor with a required argument — not instantiable by the manifest contract. */
final class NeedyVerifier implements StructuralVerifierInterface
{
    public function __construct(private readonly string $mustProvide)
    {
    }

    public function source(): string
    {
        return 'acme/needy';
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return $this->mustProvide !== '';
    }
}

/** Not a verifier at all. */
final class NotAVerifier
{
}

final class AdoptionServiceTest extends TestCase
{
    private string $base;
    private Connection $connection;
    private DescriptorInventory $inventory;
    /** @var list<string> */
    private array $lockLog = [];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-adopt-' . uniqid('', true);
        mkdir($this->base . '/vendor/composer', 0777, true);

        $rows = [];
        $packages = [
            'acme/pass' => PassingVerifier::class,
            'acme/fail' => RefusingVerifier::class,
            'acme/none' => null,
            'acme/mismatch' => MismatchedSourceVerifier::class,
            'acme/needy' => NeedyVerifier::class,
            'acme/ghostclass' => 'Acme\\Ghost\\DoesNotExist',
            'acme/wrongiface' => NotAVerifier::class,
        ];
        foreach ($packages as $name => $verifier) {
            $dir = $this->base . '/vendor/' . $name . '/migrations';
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/001_Fixture.php', "<?php // {$name} fixture\n");
            $descriptor = [
                'id' => 'default',
                'path' => 'migrations',
                'priority' => 'default',
                'mode' => 'on_enable',
            ];
            if ($verifier !== null) {
                $descriptor['verifier'] = $verifier;
            }
            $rows[] = [
                'name' => $name,
                'type' => 'glueful-extension',
                'install-path' => '../' . $name,
                'extra' => ['glueful' => [
                    'provider' => 'Acme\\P' . substr(md5($name), 0, 6),
                    'migrations' => [$descriptor],
                ]],
            ];
        }
        file_put_contents(
            $this->base . '/vendor/composer/installed.json',
            json_encode(['packages' => $rows], JSON_UNESCAPED_SLASHES)
        );
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

    private function service(): AdoptionService
    {
        $log = &$this->lockLog;
        $lock = new class ($log) implements MigrationLockInterface {
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
        return new AdoptionService(
            $this->connection,
            $this->inventory,
            new SchemaReadiness($this->connection, $this->inventory),
            $lock
        );
    }

    private function seedReadyReceipt(string $source): void
    {
        $file = $this->base . '/vendor/' . $source . '/migrations/001_Fixture.php';
        $stmt = $this->connection->getPDO()->prepare(
            'INSERT INTO migrations (migration, batch, checksum, source) VALUES (?, 1, ?, ?)'
        );
        $stmt->execute(['001_Fixture.php', hash_file('sha256', $file), $source]);
    }

    public function testClassificationTruthTable(): void
    {
        $this->createLedger();
        $this->seedReadyReceipt('acme/pass'); // fully receipted => Ready regardless of verifier

        $classified = $this->service()->classify();

        self::assertSame(AdoptionState::Ready, $classified['acme/pass']['state']);
        self::assertSame(AdoptionState::Divergent, $classified['acme/fail']['state'], 'verifier refusal => divergent');
        self::assertNotEmpty(array_filter(
            $classified['acme/fail']['reasons'],
            static fn(string $r): bool => str_contains($r, '001_Fixture.php')
        ), 'the refusing basename is named');
        self::assertSame(AdoptionState::Divergent, $classified['acme/none']['state'], 'no verifier => divergent');
        self::assertSame(AdoptionState::Divergent, $classified['acme/mismatch']['state'], 'source mismatch');
        self::assertSame(AdoptionState::Divergent, $classified['acme/needy']['state'], 'needy constructor');
        self::assertSame(AdoptionState::Divergent, $classified['acme/ghostclass']['state'], 'missing class');
        self::assertSame(AdoptionState::Divergent, $classified['acme/wrongiface']['state'], 'wrong interface');
    }

    public function testPendingWithPassingVerifierIsAdoptableAndAdoptWritesAtomically(): void
    {
        $this->createLedger();

        $classified = $this->service()->classify();
        self::assertSame(AdoptionState::Adoptable, $classified['acme/pass']['state']);

        $report = $this->service()->adopt('acme/pass');

        self::assertSame(['001_Fixture.php'], $report->adopted);
        $row = $this->connection->getPDO()
            ->query("SELECT checksum FROM migrations WHERE source = 'acme/pass'")
            ->fetch(\PDO::FETCH_ASSOC);
        $file = $this->base . '/vendor/acme/pass/migrations/001_Fixture.php';
        self::assertSame(hash_file('sha256', $file), $row['checksum'], 'receipt carries the exact shipped checksum');
        self::assertContains('release', $this->lockLog, 'lock finally-released');
    }

    public function testAdoptRefusesNonAdoptableSources(): void
    {
        $this->createLedger();
        $this->expectException(\RuntimeException::class);
        $this->service()->adopt('acme/fail');
    }

    public function testLedgerAbsentClassifiesWithoutQueryingAndAdoptRefusesWithRemedy(): void
    {
        $classified = $this->service()->classify();
        self::assertSame(AdoptionState::Adoptable, $classified['acme/pass']['state']);
        $tables = $this->connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables, 'classification must not create the ledger');

        try {
            $this->service()->adopt('acme/pass');
            self::fail('expected refusal');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('migrate', $e->getMessage());
        }
    }
}
