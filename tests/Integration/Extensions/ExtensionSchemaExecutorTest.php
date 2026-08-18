<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Database\Connection;
use Glueful\Database\Exceptions\LockContentionException;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\ExtensionOperation;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Glueful\Extensions\Schema\FileMigrationLock;
use Glueful\Extensions\Schema\SchemaNotBootstrappedException;
use Glueful\Extensions\Schema\SchemaReadiness;
use Glueful\Extensions\ServiceProvider;
use Glueful\Installer\DatabaseConfig;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

final class WidgetsExecProvider extends ServiceProvider
{
}

final class OtherExecProvider extends ServiceProvider
{
}

/** Executor with a stubbable recompile seam (no booted container in this harness). */
final class TestableExecutor extends ExtensionSchemaExecutor
{
    public bool $failRecompile = false;

    protected function recompileProviderCache(): void
    {
        if ($this->failRecompile) {
            throw new \RuntimeException('cache dir not writable');
        }
    }
}

final class ExtensionSchemaExecutorTest extends TestCase
{
    private string $base;
    private ApplicationContext $context;
    private Connection $connection;
    private MigrationManager $manager;
    private DescriptorInventory $inventory;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-exec-' . uniqid('', true);
        mkdir($this->base . '/vendor/composer', 0777, true);
        mkdir($this->base . '/config');
        mkdir($this->base . '/app-migrations');

        // Fake framework root carrying ONLY the extensions leaf (the real migration file), so
        // bootstrap is cheap and every other built-in leaf is skipped by FrameworkDescriptors.
        // Class names get a per-test suffix: include_once tracks paths, and each test copies
        // the fixtures to a fresh temp dir — an unsuffixed class would redeclare.
        $suffix = 'X' . substr(md5($this->base), 0, 8);
        mkdir($this->base . '/fw/migrations/extensions', 0777, true);
        $bootstrapSrc = (string) file_get_contents(
            dirname(__DIR__, 3) . '/migrations/extensions/001_CreateExtensionOperationsTable.php'
        );
        file_put_contents(
            $this->base . '/fw/migrations/extensions/001_CreateExtensionOperationsTable' . $suffix . '.php',
            str_replace('CreateExtensionOperationsTable', 'CreateExtensionOperationsTable' . $suffix, $bootstrapSrc)
        );

        $this->writeMigrationFixture('acme/widgets', '001', 'CreateAcmeWidgetsTable' . $suffix, 'acme_widgets');
        $this->writeMigrationFixture('acme/other', '001', 'CreateAcmeOtherTable' . $suffix, 'acme_other');

        $this->writeInstalled([
            $this->pkgRow('acme/widgets', WidgetsExecProvider::class),
            $this->pkgRow('acme/other', OtherExecProvider::class, requires: [WidgetsExecProvider::class]),
        ]);
        $this->writeEnabled([]);

        $this->context = new ApplicationContext($this->base);
        $this->context->setConfigLoader(new ConfigurationLoader($this->base, 'testing'));
        $config = new DatabaseConfig('sqlite', database: $this->base . '/db.sqlite');
        $this->connection = new Connection($config->toConnectionConfig());
        $this->rebuildInventoryAndManager();
    }

    private function rebuildInventoryAndManager(): void
    {
        $this->inventory = DescriptorInventory::fromManifest(
            new PackageManifest($this->context),
            $this->base . '/fw',
            new FileFinder()
        );
        $this->manager = new MigrationManager(
            $this->base . '/app-migrations',
            new FileFinder(),
            $this->context,
            $this->connection
        );
        foreach ($this->inventory->all() as $descriptor) {
            $this->manager->registerDescriptor($descriptor, $this->inventory->pathOf($descriptor));
        }
    }

    /** @param list<string> $requires */
    private function pkgRow(string $name, string $provider, array $requires = []): array
    {
        return [
            'name' => $name,
            'type' => 'glueful-extension',
            'install-path' => '../' . $name,
            'extra' => ['glueful' => [
                'provider' => $provider,
                'requires' => ['extensions' => $requires],
                'migrations' => [
                    ['id' => 'default', 'path' => 'migrations', 'priority' => 'dependent', 'mode' => 'on_enable'],
                ],
            ]],
        ];
    }

    /** @param list<array<string, mixed>> $packages */
    private function writeInstalled(array $packages): void
    {
        file_put_contents(
            $this->base . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_UNESCAPED_SLASHES)
        );
    }

    private function writeMigrationFixture(string $package, string $number, string $class, string $table): void
    {
        $dir = $this->base . '/vendor/' . $package . '/migrations';
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/' . $number . '_' . $class . '.php', <<<PHP
            <?php

            use Glueful\\Database\\Migrations\\MigrationInterface;
            use Glueful\\Database\\Schema\\Interfaces\\SchemaBuilderInterface;

            class {$class} implements MigrationInterface
            {
                public function up(SchemaBuilderInterface \$schema): void
                {
                    \$schema->createTable('{$table}', function (\$t) { \$t->string('name', 50); });
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

    /**
     * @param list<string> $providers
     * @param array<string, array{reason?: string, managed_by?: string}> $protected
     */
    private function writeEnabled(array $providers, array $protected = []): void
    {
        // The state writer only edits SHORT-syntax flat string-literal lists.
        $exported = '[' . implode(', ', array_map(
            static fn(string $p): string => var_export($p, true),
            $providers
        )) . ']';
        $protectedExport = var_export($protected, true);
        file_put_contents(
            $this->base . '/config/extensions.php',
            "<?php\nreturn ['enabled' => {$exported}, 'protected' => {$protectedExport}];\n"
        );
        if (isset($this->context)) {
            $this->context->clearConfigCache();
        }
    }

    /** @return list<string> */
    private function enabledList(): array
    {
        $config = require $this->base . '/config/extensions.php';
        return $config['enabled'];
    }

    private function executor(): TestableExecutor
    {
        return new TestableExecutor(
            $this->context,
            $this->inventory,
            $this->manager,
            new SchemaReadiness($this->connection, $this->inventory),
            new FileMigrationLock($this->base . '/locks'),
            $this->connection,
            lockWaitSeconds: 1,
        );
    }

    private function bootstrap(): void
    {
        $report = $this->manager->migrateSources(['glueful/framework:extensions']);
        self::assertNull($report->firstFailure(), 'bootstrap migrate must succeed');
    }

    /** @return list<string> */
    private function tables(): array
    {
        return $this->connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return array<string, mixed>|null */
    private function lastOperationRow(): ?array
    {
        $rows = $this->connection->getPDO()
            ->query('SELECT * FROM extension_operations ORDER BY id DESC LIMIT 1')
            ->fetchAll(\PDO::FETCH_ASSOC);
        return $rows[0] ?? null;
    }

    public function testEnableBeforeBootstrapThrowsAndTouchesNothing(): void
    {
        try {
            $this->executor()->enable('acme/widgets', 'tester');
            self::fail('expected SchemaNotBootstrappedException');
        } catch (SchemaNotBootstrappedException $e) {
            self::assertStringContainsString('migrate:run', $e->getMessage());
        }
        self::assertSame([], $this->tables(), 'no operation row can be attempted before bootstrap');
        self::assertSame([], $this->enabledList());
    }

    public function testEnableHappyPathMigratesThenEnablesAndRecords(): void
    {
        $this->bootstrap();

        $operation = $this->executor()->enable('acme/widgets', 'tester');

        self::assertSame(ExtensionOperation::STATUS_SUCCEEDED, $operation->status);
        self::assertContains('acme_widgets', $this->tables());
        self::assertContains(WidgetsExecProvider::class, $this->enabledList());
        $row = $this->lastOperationRow();
        self::assertSame('succeeded', $row['status']);
        self::assertSame('acme/widgets', $row['package']);
        // Source-scope proof: the other disabled extension's schema stays uncreated.
        self::assertNotContains('acme_other', $this->tables());
    }

    public function testFailingMigrationLeavesExtensionDisabledWithFailedOperation(): void
    {
        $this->bootstrap();
        // Replace acme/other's migration with a failing one.
        $dir = $this->base . '/vendor/acme/other/migrations';
        array_map('unlink', glob($dir . '/*.php'));
        $boomClass = 'BoomOther' . substr(md5($this->base), 0, 8);
        file_put_contents($dir . '/001_' . $boomClass . '.php', <<<PHP
            <?php

            use Glueful\\Database\\Migrations\\MigrationInterface;
            use Glueful\\Database\\Schema\\Interfaces\\SchemaBuilderInterface;

            class {$boomClass} implements MigrationInterface
            {
                public function up(SchemaBuilderInterface \$schema): void
                {
                    throw new \\RuntimeException('boom');
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
        $this->rebuildInventoryAndManager();
        // acme/other requires widgets enabled first.
        $this->writeEnabled([WidgetsExecProvider::class]);

        $operation = $this->executor()->enable('acme/other', 'tester');

        self::assertSame(ExtensionOperation::STATUS_FAILED, $operation->status);
        self::assertStringStartsWith('001_BoomOther', (string) $operation->failedMigration);
        self::assertNotContains(OtherExecProvider::class, $this->enabledList(), 'enabled state is written LAST');
        self::assertSame('failed', $this->lastOperationRow()['status']);
    }

    public function testDisableOfADependedOnProviderRefuses(): void
    {
        $this->bootstrap();
        $this->writeEnabled([WidgetsExecProvider::class, OtherExecProvider::class]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing_dependency');
        $this->executor()->disable('acme/widgets', 'tester');
    }

    public function testHeldLockContends(): void
    {
        $this->bootstrap();
        $holder = new FileMigrationLock($this->base . '/locks');
        $held = $holder->acquireAll(['acme/widgets']);
        try {
            $this->expectException(LockContentionException::class);
            $this->executor()->enable('acme/widgets', 'tester');
        } finally {
            $held->release();
        }
    }

    public function testRecompileFailureIsEnabledCacheStale(): void
    {
        $this->bootstrap();
        $executor = $this->executor();
        $executor->failRecompile = true;

        $operation = $executor->enable('acme/widgets', 'tester');

        self::assertSame(ExtensionOperation::STATUS_CACHE_STALE, $operation->status);
        self::assertContains(WidgetsExecProvider::class, $this->enabledList(), 'the config write stands');
        self::assertStringContainsString('extensions:cache', (string) $operation->error);
    }

    public function testDisablePreservesTablesAndData(): void
    {
        $this->bootstrap();
        $this->executor()->enable('acme/widgets', 'tester');
        $this->connection->getPDO()->exec("INSERT INTO acme_widgets (name) VALUES ('keep-me')");

        $operation = $this->executor()->disable('acme/widgets', 'tester');

        self::assertSame(ExtensionOperation::STATUS_SUCCEEDED, $operation->status);
        self::assertNotContains(WidgetsExecProvider::class, $this->enabledList());
        self::assertContains('acme_widgets', $this->tables(), 'disable never drops');
        $count = (int) $this->connection->getPDO()
            ->query('SELECT COUNT(*) FROM acme_widgets')->fetchColumn();
        self::assertSame(1, $count, 'data preserved');
    }

    public function testDryRunWritesNothingAnywhere(): void
    {
        $this->bootstrap();
        $before = $this->tables();

        $operation = $this->executor()->enable('acme/widgets', 'tester', dryRun: true);

        self::assertSame('dry-run', $operation->step);
        self::assertSame($before, $this->tables(), 'no migrations in dry-run');
        self::assertSame([], $this->enabledList(), 'no config write in dry-run');
        $ops = (int) $this->connection->getPDO()
            ->query('SELECT COUNT(*) FROM extension_operations')->fetchColumn();
        self::assertSame(0, $ops, 'no operation row in dry-run');
    }

    public function testMigrateProtectedAppliesSchemaWithoutTogglingState(): void
    {
        $this->writeEnabled([], protected: [WidgetsExecProvider::class => [
            'reason' => 'Managed by the widgets lifecycle.',
            'managed_by' => 'acme/widgets flow',
        ]]);
        $this->bootstrap();

        $executor = $this->executor();
        // The protected lane must never touch the cache seam: a poisoned recompile is invisible.
        $executor->failRecompile = true;
        $operation = $executor->migrateProtected('acme/widgets', 'tester');

        self::assertSame(ExtensionOperation::STATUS_SUCCEEDED, $operation->status);
        self::assertSame('protected_migrate', $operation->operation);
        self::assertContains('acme_widgets', $this->tables(), 'the package schema applied');
        self::assertSame([], $this->enabledList(), 'the protected lane cannot toggle provider state');

        $row = $this->lastOperationRow();
        self::assertSame('protected_migrate', $row['operation'] ?? null);
        self::assertSame(ExtensionOperation::STATUS_SUCCEEDED, $row['status'] ?? null);
    }

    public function testMigrateProtectedRefusesANonProtectedPackage(): void
    {
        $this->bootstrap();

        try {
            $this->executor()->migrateProtected('acme/widgets', 'tester');
            self::fail('a non-protected package must be refused the protected lane');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not a protected provider', $e->getMessage());
        }
        self::assertNotContains('acme_widgets', $this->tables(), 'no schema applied on refusal');
        self::assertNull($this->lastOperationRow(), 'no operation is recorded for a refused package');
        self::assertSame([], $this->enabledList());
    }
}
