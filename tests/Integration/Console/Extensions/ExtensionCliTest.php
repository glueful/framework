<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Console\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Console\Commands\Extensions\DisableCommand;
use Glueful\Console\Commands\Extensions\EnableCommand;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Glueful\Extensions\Schema\FileMigrationLock;
use Glueful\Extensions\Schema\SchemaReadiness;
use Glueful\Installer\DatabaseConfig;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/** Real executor, stubbed recompile (no booted app container in this harness). */
final class CliTestExecutor extends ExtensionSchemaExecutor
{
    protected function recompileProviderCache(): void
    {
        // Best-effort in production; a no-op here — the config write is what this test asserts.
    }
}

/**
 * End-to-end CLI test for extensions:enable / extensions:disable THROUGH the schema executor
 * (schema policy spec B5).
 *
 * The headline guarantees under test survive the executor rewrite: the proposed enabled list is
 * validated before writing, so a command never leaves config/extensions.php broken; protected
 * providers refuse before any short-circuit; disabling a depended-on provider refuses; and — new
 * with the manifest contract — an UNDECLARED package cannot participate in schema-on-enable.
 */
final class ExtensionCliTest extends TestCase
{
    private string $base;
    private ?string $prevEnv = null;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->prevEnv = getenv('APP_ENV') === false ? null : (string) getenv('APP_ENV');
        putenv('APP_ENV=testing');

        $this->base = sys_get_temp_dir() . '/glueful-cli-' . uniqid('', true);
        @mkdir($this->base . '/config', 0777, true);
        @mkdir($this->base . '/vendor/composer', 0777, true);

        file_put_contents(
            $this->base . '/config/extensions.php',
            "<?php\n\nreturn [\n    'enabled' => [\n    ],\n];\n"
        );

        // Three declared extensions (migrations: none — schema-free) plus one UNDECLARED
        // legacy package. gadgets requires base's provider.
        $packages = [
            $this->pkg('vendor/widgets', 'Vendor\\Widgets\\Provider'),
            $this->pkg('vendor/base', 'Vendor\\Base\\Provider'),
            $this->pkg('vendor/gadgets', 'Vendor\\Gadgets\\Provider', requires: ['Vendor\\Base\\Provider']),
            [
                'name' => 'vendor/legacy',
                'type' => 'glueful-extension',
                'install-path' => '../vendor/legacy',
                'extra' => ['glueful' => ['provider' => 'Vendor\\Legacy\\Provider']],
            ],
        ];
        file_put_contents(
            $this->base . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_UNESCAPED_SLASHES)
        );

        // Bootstrap: a fake framework root carrying only the extensions leaf, migrated once.
        $suffix = 'X' . substr(md5($this->base), 0, 8);
        mkdir($this->base . '/fw/migrations/extensions', 0777, true);
        $bootstrapSrc = (string) file_get_contents(
            dirname(__DIR__, 4) . '/migrations/extensions/001_CreateExtensionOperationsTable.php'
        );
        file_put_contents(
            $this->base . '/fw/migrations/extensions/001_CreateExtensionOperationsTable' . $suffix . '.php',
            str_replace('CreateExtensionOperationsTable', 'CreateExtensionOperationsTable' . $suffix, $bootstrapSrc)
        );
        $config = new DatabaseConfig('sqlite', database: $this->base . '/db.sqlite');
        $this->connection = new Connection($config->toConnectionConfig());
        [, $manager] = $this->services($this->context());
        $report = $manager->migrateSources(['glueful/framework:extensions']);
        self::assertNull($report->firstFailure(), 'bootstrap migrate must succeed');
    }

    protected function tearDown(): void
    {
        if ($this->prevEnv === null) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->prevEnv);
        }
    }

    /** @param list<string> $requires @return array<string, mixed> */
    private function pkg(string $name, string $provider, array $requires = []): array
    {
        return [
            'name' => $name,
            'type' => 'glueful-extension',
            'install-path' => '../' . $name,
            'extra' => ['glueful' => [
                'provider' => $provider,
                'requires' => ['extensions' => $requires],
                'migrations' => 'none',
            ]],
        ];
    }

    /** @return list<string> */
    private function enabled(): array
    {
        $config = require $this->base . '/config/extensions.php';
        return array_values($config['enabled']);
    }

    private function context(): ApplicationContext
    {
        $ctx = new ApplicationContext($this->base, 'testing', [
            'framework' => $this->base . '/config',
            'application' => $this->base . '/config',
        ]);
        $ctx->setConfigLoader(new ConfigurationLoader($this->base, 'testing', $this->base . '/config'));
        return $ctx;
    }

    /** @return array{0: DescriptorInventory, 1: MigrationManager} */
    private function services(ApplicationContext $ctx): array
    {
        $inventory = DescriptorInventory::fromManifest(
            new PackageManifest($ctx),
            $this->base . '/fw',
            new FileFinder()
        );
        $manager = new MigrationManager($this->base . '/fw/migrations', new FileFinder(), $ctx, $this->connection);
        foreach ($inventory->all() as $descriptor) {
            $manager->registerDescriptor($descriptor, $inventory->pathOf($descriptor));
        }
        return [$inventory, $manager];
    }

    private function container(ApplicationContext $ctx): ContainerInterface
    {
        [$inventory, $manager] = $this->services($ctx);
        $executor = new CliTestExecutor(
            $ctx,
            $inventory,
            $manager,
            new SchemaReadiness($this->connection, $inventory),
            new FileMigrationLock($this->base . '/locks'),
            $this->connection,
            lockWaitSeconds: 1,
        );
        return new class ($ctx, $executor) implements ContainerInterface {
            public function __construct(
                private readonly ApplicationContext $ctx,
                private readonly ExtensionSchemaExecutor $executor,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->ctx;
                }
                if ($id === ExtensionSchemaExecutor::class) {
                    return $this->executor;
                }
                throw new class ("no {$id}") extends \RuntimeException implements
                    \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class || $id === ExtensionSchemaExecutor::class;
            }
        };
    }

    /** @param array<string, string> $args */
    private function runEnable(array $args): CommandTester
    {
        $ctx = $this->context();
        $tester = new CommandTester(new EnableCommand($this->container($ctx), $ctx));
        $tester->execute($args);
        return $tester;
    }

    /** @param array<string, string> $args */
    private function runDisable(array $args): CommandTester
    {
        $ctx = $this->context();
        $tester = new CommandTester(new DisableCommand($this->container($ctx), $ctx));
        $tester->execute($args);
        return $tester;
    }

    /** Writes the temp extensions.php with vendor/widgets protected (and optionally enabled). */
    private function protectWidgets(bool $enabled): void
    {
        $enabledBlock = $enabled ? "        'Vendor\\\\Widgets\\\\Provider',\n" : '';
        file_put_contents(
            $this->base . '/config/extensions.php',
            "<?php\n\nreturn [\n"
            . "    'protected' => [\n"
            . "        'Vendor\\\\Widgets\\\\Provider' => [\n"
            . "            'reason' => 'Managed by the widgets lifecycle flow.',\n"
            . "            'managed_by' => 'vendor/widgets lifecycle',\n"
            . "        ],\n"
            . "    ],\n"
            . "    'enabled' => [\n{$enabledBlock}    ],\n];\n"
        );
    }

    public function testEnableRefusesAProtectedProviderBeforeAnyShortCircuit(): void
    {
        // Protected AND already enabled: the refusal must win over "already enabled".
        $this->protectWidgets(enabled: true);

        $tester = $this->runEnable(['extension' => 'widgets']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Managed by the widgets lifecycle flow.', $tester->getDisplay());
        self::assertSame(['Vendor\\Widgets\\Provider'], $this->enabled());
    }

    public function testDisableRefusesAProtectedProviderBeforeAnyShortCircuit(): void
    {
        // Protected but NOT enabled: the refusal must win over "not enabled".
        $this->protectWidgets(enabled: false);

        $tester = $this->runDisable(['extension' => 'widgets']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Managed by the widgets lifecycle flow.', $tester->getDisplay());
        self::assertSame([], $this->enabled());
    }

    public function testEnableAddsProviderToConfig(): void
    {
        $tester = $this->runEnable(['extension' => 'widgets']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['Vendor\\Widgets\\Provider'], $this->enabled());
    }

    public function testEnableMatchesSlugCaseInsensitively(): void
    {
        // Package slug is "widgets"; the capitalized "Widgets" must still resolve.
        $tester = $this->runEnable(['extension' => 'Widgets']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['Vendor\\Widgets\\Provider'], $this->enabled());
    }

    public function testEnableIsIdempotent(): void
    {
        $this->runEnable(['extension' => 'widgets']);
        $tester = $this->runEnable(['extension' => 'widgets']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['Vendor\\Widgets\\Provider'], $this->enabled(), 'no duplicate entry on re-enable');
    }

    public function testDisableRemovesProviderButNeverSchema(): void
    {
        $this->runEnable(['extension' => 'widgets']);
        $tester = $this->runDisable(['extension' => 'widgets']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame([], $this->enabled());
    }

    public function testEnableUnknownFailsAndLeavesConfigUntouched(): void
    {
        $tester = $this->runEnable(['extension' => 'does-not-exist']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found among installed packages', $tester->getDisplay());
        $this->assertSame([], $this->enabled());
    }

    public function testEnableWithUnmetDependencyIsRefusedAndConfigNotModified(): void
    {
        // gadgets requires base, which is not enabled → must refuse, no write.
        $tester = $this->runEnable(['extension' => 'gadgets']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('missing_dependency', $tester->getDisplay());
        $this->assertSame([], $this->enabled());
    }

    public function testDisableDependencyInUseIsRefused(): void
    {
        // Enable base, then gadgets (which depends on base).
        $this->runEnable(['extension' => 'base']);
        $this->runEnable(['extension' => 'gadgets']);
        $this->assertEqualsCanonicalizing(
            ['Vendor\\Base\\Provider', 'Vendor\\Gadgets\\Provider'],
            $this->enabled()
        );

        // Disabling base while gadgets still requires it must be refused.
        $tester = $this->runDisable(['extension' => 'base']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('missing_dependency', $tester->getDisplay());
        $this->assertEqualsCanonicalizing(
            ['Vendor\\Base\\Provider', 'Vendor\\Gadgets\\Provider'],
            $this->enabled()
        );
    }

    public function testUndeclaredLegacyPackageCannotParticipateInSchemaOnEnable(): void
    {
        // vendor/legacy has extra.glueful but no migrations declaration: fail closed with the
        // manifest remedy (spec B1) — it stays bootable, but enable refuses.
        $tester = $this->runEnable(['extension' => 'legacy']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('migrations', $tester->getDisplay());
        $this->assertSame([], $this->enabled());
    }
}
