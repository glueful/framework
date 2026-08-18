<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\DescriptorValidationException;
use Glueful\Extensions\Schema\UndeclaredSchemaException;
use Glueful\Extensions\ServiceProvider;
use Glueful\Installer\DatabaseConfig;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** Fixture provider whose FQCN the test manifests name as the declared provider. */
final class WidgetsFixtureProvider extends ServiceProvider
{
    public function callLoad(string $dir, int $priority = MigrationPriority::DEFAULT, ?string $source = null): void
    {
        $this->loadMigrationsFrom($dir, $priority, $source);
    }
}

/** Fixture provider for undeclared/app-local scenarios. */
final class LooseFixtureProvider extends ServiceProvider
{
    public function callLoad(string $dir, int $priority = MigrationPriority::DEFAULT, ?string $source = null): void
    {
        $this->loadMigrationsFrom($dir, $priority, $source);
    }
}

final class SingleInventoryTest extends TestCase
{
    private string $base;
    private string $dbFile;
    private string $appMigrations;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-si-' . uniqid('', true);
        mkdir($this->base . '/vendor/composer', 0777, true);
        $this->appMigrations = $this->base . '/app-migrations';
        mkdir($this->appMigrations);
        $this->dbFile = $this->base . '/db.sqlite';
        $config = new DatabaseConfig('sqlite', database: $this->dbFile);
        $this->connection = new Connection($config->toConnectionConfig());
    }

    /**
     * @param list<string> $files
     * @param list<array<string, mixed>>|string $migrations
     * @return array<string, mixed>
     */
    private function pkg(
        string $name,
        array $files,
        array|string $migrations,
        string $provider = WidgetsFixtureProvider::class,
    ): array {
        $dir = $this->base . '/vendor/' . $name;
        foreach ($files as $rel) {
            @mkdir(dirname($dir . '/' . $rel), 0777, true);
            file_put_contents($dir . '/' . $rel, "<?php // fixture\n");
        }
        @mkdir($dir, 0777, true);
        return [
            'name' => $name,
            'type' => 'glueful-extension',
            'install-path' => '../' . $name,
            'extra' => ['glueful' => ['provider' => $provider, 'migrations' => $migrations]],
        ];
    }

    /** @param list<array<string, mixed>> $packages */
    private function inventory(array $packages): DescriptorInventory
    {
        file_put_contents(
            $this->base . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_UNESCAPED_SLASHES)
        );
        $manifest = new PackageManifest(new ApplicationContext($this->base));
        return DescriptorInventory::fromManifest($manifest, dirname(__DIR__, 4), new FileFinder());
    }

    private function manager(): MigrationManager
    {
        return new MigrationManager($this->appMigrations, new FileFinder(), null, $this->connection);
    }

    private function container(
        MigrationManager $manager,
        ?DescriptorInventory $inventory,
        ?ApplicationContext $context = null,
    ): ContainerInterface {
        return new class ($manager, $inventory, $context) implements ContainerInterface {
            public function __construct(
                private readonly MigrationManager $manager,
                private readonly ?DescriptorInventory $inventory,
                private readonly ?ApplicationContext $context,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === MigrationManager::class) {
                    return $this->manager;
                }
                if ($id === DescriptorInventory::class && $this->inventory !== null) {
                    return $this->inventory;
                }
                if ($id === ApplicationContext::class && $this->context !== null) {
                    return $this->context;
                }
                throw new \RuntimeException("no service {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MigrationManager::class
                    || ($id === DescriptorInventory::class && $this->inventory !== null)
                    || ($id === ApplicationContext::class && $this->context !== null);
            }
        };
    }

    /** A host context whose extensions.schema.require_declared_packages flag is ON. */
    private function strictContext(): ApplicationContext
    {
        @mkdir($this->base . '/config');
        file_put_contents(
            $this->base . '/config/extensions.php',
            "<?php\nreturn ['schema' => ['require_declared_packages' => true]];\n"
        );
        $context = new ApplicationContext($this->base);
        $context->setConfigLoader(new ConfigurationLoader($this->base, 'testing'));
        return $context;
    }

    public function testDescribedPathIsValidatedNotReRegistered(): void
    {
        $inv = $this->inventory([$this->pkg('acme/widgets', ['migrations/001_A.php'], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ])]);
        $manager = $this->manager();
        $d = $inv->bySource('acme/widgets');
        self::assertNotNull($d);
        $manager->registerDescriptor($d, $inv->pathOf($d));

        $provider = new WidgetsFixtureProvider($this->container($manager, $inv));
        $provider->callLoad($inv->pathOf($d)); // must validate and NOT append a second source

        $pending = $manager->pendingForSources(['acme/widgets']);
        self::assertCount(1, $pending, 'one physical file must appear exactly once');
        self::assertSame('acme/widgets', $pending[0]['source']);
        self::assertTrue($manager->hasSource('acme/widgets'));
    }

    public function testMismatchedSourceInLegacyCallThrows(): void
    {
        $inv = $this->inventory([$this->pkg('acme/widgets', ['migrations/001_A.php'], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ])]);
        $manager = $this->manager();
        $d = $inv->bySource('acme/widgets');
        $provider = new WidgetsFixtureProvider($this->container($manager, $inv));

        $this->expectException(DescriptorValidationException::class);
        $provider->callLoad($inv->pathOf($d), MigrationPriority::DEFAULT, 'legacy-name');
    }

    public function testDeclaredNonePackageCannotRegisterAnyPath(): void
    {
        $extra = $this->base . '/vendor/acme/widgets/other';
        $inv = $this->inventory([$this->pkg('acme/widgets', ['other/001_X.php'], 'none')]);
        $manager = $this->manager();
        $provider = new WidgetsFixtureProvider($this->container($manager, $inv));

        $this->expectException(DescriptorValidationException::class);
        $provider->callLoad($extra);
    }

    public function testDeclaredPackageRegisteringAnUndescribedSecondPathThrows(): void
    {
        $inv = $this->inventory([$this->pkg('acme/widgets', [
            'migrations/001_A.php',
            'secret/001_S.php',
        ], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ])]);
        $manager = $this->manager();
        $provider = new WidgetsFixtureProvider($this->container($manager, $inv));

        $this->expectException(DescriptorValidationException::class);
        $provider->callLoad($this->base . '/vendor/acme/widgets/secret');
    }

    public function testSecondProviderClassInsideDeclaredPackageObeysTheManifest(): void
    {
        $pkgRow = $this->pkg('acme/widgets', [
            'migrations/001_A.php',
            'secret/001_S.php',
        ], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ]);
        // A provider class whose FILE physically lives inside the package install root but whose
        // FQCN is not the manifest-declared provider: ownership resolves by file containment.
        $classFile = $this->base . '/vendor/acme/widgets/SneakyProvider.php';
        $ns = 'FixtureSneaky' . preg_replace('/[^A-Za-z0-9]/', '', uniqid());
        file_put_contents($classFile, <<<PHP
            <?php
            namespace {$ns};

            use Glueful\\Database\\Migrations\\MigrationPriority;
            use Glueful\\Extensions\\ServiceProvider;

            final class SneakyProvider extends ServiceProvider
            {
                public function callLoad(string \$dir): void
                {
                    \$this->loadMigrationsFrom(\$dir, MigrationPriority::DEFAULT, null);
                }
            }
            PHP);
        require $classFile;
        $inv = $this->inventory([$pkgRow]);
        $manager = $this->manager();
        $class = $ns . '\\SneakyProvider';
        $provider = new $class($this->container($manager, $inv));

        $this->expectException(DescriptorValidationException::class);
        $provider->callLoad($this->base . '/vendor/acme/widgets/secret');
    }

    public function testUndeclaredPackageProviderStillAppends(): void
    {
        $undeclared = [
            'name' => 'acme/legacy',
            'type' => 'glueful-extension',
            'install-path' => '../acme/legacy',
            'extra' => ['glueful' => ['provider' => LooseFixtureProvider::class]],
        ];
        $dir = $this->base . '/vendor/acme/legacy/migrations';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/001_L.php', "<?php // fixture\n");
        $inv = $this->inventory([$undeclared]);
        $manager = $this->manager();
        $provider = new LooseFixtureProvider($this->container($manager, $inv));

        $provider->callLoad($dir);

        self::assertTrue($manager->hasSource('migrations'), 'legacy append derives source from dir basename');
    }

    public function testAppLocalProviderOutsideEveryPackageRootStillAppends(): void
    {
        $inv = $this->inventory([$this->pkg('acme/widgets', ['migrations/001_A.php'], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ], 'Acme\\Widgets\\NotThisTestClass')]);
        $dir = $this->base . '/local-migrations';
        mkdir($dir);
        file_put_contents($dir . '/001_APP.php', "<?php // fixture\n");
        $manager = $this->manager();
        // LooseFixtureProvider's file lives in the framework tests dir — no package install root.
        $provider = new LooseFixtureProvider($this->container($manager, $inv));

        $provider->callLoad($dir, MigrationPriority::DEFAULT, 'app-local');

        self::assertTrue($manager->hasSource('app-local'));
    }

    public function testGlobalSourcePolicyFiltersDisabledOnEnableSourcesAtCallTime(): void
    {
        $coreDir = $this->base . '/vendor/acme/corelib/migrations';
        mkdir($coreDir, 0777, true);
        file_put_contents($coreDir . '/001_C.php', "<?php // fixture\n");
        $inv = $this->inventory([
            $this->pkg('acme/widgets', ['migrations/001_A.php'], [
                ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
            ]),
            [
                'name' => 'acme/corelib',
                'type' => 'library',
                'install-path' => '../acme/corelib',
                'extra' => ['glueful' => ['migrations' => [
                    ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'core'],
                ]]],
            ],
        ]);
        $manager = $this->manager();
        foreach (['acme/widgets', 'acme/corelib'] as $source) {
            $d = $inv->bySource($source);
            self::assertNotNull($d);
            $manager->registerDescriptor($d, $inv->pathOf($d));
        }
        $manager->addMigrationPath($this->base . '/vendor/acme/legacy-dir', MigrationPriority::DEFAULT, 'legacy');

        $enabled = [];
        $manager->setGlobalSourcePolicy(function () use (&$enabled): array {
            return $enabled;
        });

        $sources = $manager->globalSources();
        self::assertContains('app', $sources);
        self::assertContains('acme/corelib', $sources, 'core descriptors are always global');
        self::assertNotContains('acme/widgets', $sources, 'disabled on_enable is not global');

        $enabled = ['acme/widgets'];
        self::assertContains('acme/widgets', $manager->globalSources(), 'policy is evaluated at call time');
    }

    public function testDisabledSourceRemainsDiscoverableThroughPendingForSources(): void
    {
        $inv = $this->inventory([$this->pkg('acme/widgets', ['migrations/001_A.php'], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ])]);
        $manager = $this->manager();
        $d = $inv->bySource('acme/widgets');
        $manager->registerDescriptor($d, $inv->pathOf($d));
        $manager->setGlobalSourcePolicy(static fn(): array => []); // nothing enabled

        self::assertSame([], $manager->getPendingMigrations(), 'global view omits the disabled source');
        $rows = $manager->pendingForSources(['acme/widgets']);
        self::assertCount(1, $rows, 'explicit-source API must not be policy-filtered');
        self::assertSame('acme/widgets', $rows[0]['source']);
    }

    public function testRegistrationAndReadsPerformNoDdl(): void
    {
        $inv = $this->inventory([$this->pkg('acme/widgets', ['migrations/001_A.php'], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ])]);
        $manager = $this->manager();
        $d = $inv->bySource('acme/widgets');
        $manager->registerDescriptor($d, $inv->pathOf($d));
        $manager->pendingForSources(['acme/widgets']);
        $manager->globalSources();

        $tables = $this->connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables, 'registration and reads must perform zero DDL');
    }

    /** @return array{0: DescriptorInventory, 1: string} inventory + the undeclared migrations dir */
    private function undeclaredLegacyPackage(): array
    {
        $undeclared = [
            'name' => 'acme/legacy',
            'type' => 'glueful-extension',
            'install-path' => '../acme/legacy',
            'extra' => ['glueful' => ['provider' => LooseFixtureProvider::class]],
        ];
        $dir = $this->base . '/vendor/acme/legacy/migrations';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/001_L.php', "<?php // fixture\n");
        return [$this->inventory([$undeclared]), $dir];
    }

    public function testStrictHostRefusesAnUndeclaredPackageProvider(): void
    {
        [$inv, $dir] = $this->undeclaredLegacyPackage();
        $manager = $this->manager();
        $provider = new LooseFixtureProvider($this->container($manager, $inv, $this->strictContext()));

        $this->expectException(UndeclaredSchemaException::class);
        $this->expectExceptionMessage('require_declared_packages');
        $provider->callLoad($dir);
    }

    public function testStrictHostRefusesAClassPhysicallyInsideAnUndeclaredPackage(): void
    {
        // No declared-provider FQCN match: attribution falls to file containment.
        $undeclared = [
            'name' => 'acme/legacy',
            'type' => 'glueful-extension',
            'install-path' => '../acme/legacy',
            'extra' => ['glueful' => ['provider' => 'Acme\\Legacy\\SomeOtherProvider']],
        ];
        $dir = $this->base . '/vendor/acme/legacy/migrations';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/001_L.php', "<?php // fixture\n");
        $ns = 'FixtureStrict' . preg_replace('/[^A-Za-z0-9]/', '', uniqid());
        $classFile = $this->base . '/vendor/acme/legacy/EmbeddedProvider.php';
        file_put_contents($classFile, <<<PHP
            <?php
            namespace {$ns};

            use Glueful\\Database\\Migrations\\MigrationPriority;
            use Glueful\\Extensions\\ServiceProvider;

            final class EmbeddedProvider extends ServiceProvider
            {
                public function callLoad(string \$dir): void
                {
                    \$this->loadMigrationsFrom(\$dir, MigrationPriority::DEFAULT, null);
                }
            }
            PHP);
        require $classFile;
        $inv = $this->inventory([$undeclared]);
        $manager = $this->manager();
        $class = $ns . '\\EmbeddedProvider';
        $provider = new $class($this->container($manager, $inv, $this->strictContext()));

        $this->expectException(UndeclaredSchemaException::class);
        $provider->callLoad($dir);
    }

    public function testCompatibilityHostRetainsTheUndeclaredAppend(): void
    {
        // Same undeclared package, flag unset (the 1.x default): the 1.79 append stands.
        [$inv, $dir] = $this->undeclaredLegacyPackage();
        $manager = $this->manager();
        $provider = new LooseFixtureProvider($this->container($manager, $inv));

        $provider->callLoad($dir);

        self::assertTrue($manager->hasSource('migrations'), 'compatibility hosts keep the legacy append');
    }

    public function testAppLocalProviderStillAppendsUnderAStrictHost(): void
    {
        // A genuine root-app provider: no package owns its class file — the ownerless
        // app-local append lane is PERMANENT, strict mode included.
        $inv = $this->inventory([$this->pkg('acme/widgets', ['migrations/001_A.php'], [
            ['id' => 'default', 'path' => 'migrations', 'priority' => 'default', 'mode' => 'on_enable'],
        ], 'Acme\\Widgets\\NotThisTestClass')]);
        $dir = $this->base . '/local-migrations';
        mkdir($dir);
        file_put_contents($dir . '/001_APP.php', "<?php // fixture\n");
        $manager = $this->manager();
        $provider = new LooseFixtureProvider($this->container($manager, $inv, $this->strictContext()));

        $provider->callLoad($dir, MigrationPriority::DEFAULT, 'app-local');

        self::assertTrue($manager->hasSource('app-local'), 'strict mode never closes the app-local lane');
    }
}
