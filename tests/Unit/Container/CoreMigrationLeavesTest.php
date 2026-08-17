<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Schema\MigrationManagerFactory;
use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class CoreMigrationLeavesTest extends TestCase
{
    private string $base;
    private ApplicationContext $context;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-cml-' . uniqid('', true);
        mkdir($this->base . '/vendor/composer', 0777, true);
        mkdir($this->base . '/config');
        $widgets = $this->base . '/vendor/acme/widgets/migrations';
        mkdir($widgets, 0777, true);
        file_put_contents($widgets . '/001_W.php', "<?php // fixture\n");
        file_put_contents($this->base . '/vendor/composer/installed.json', json_encode(['packages' => [[
            'name' => 'acme/widgets',
            'type' => 'glueful-extension',
            'install-path' => '../acme/widgets',
            'extra' => ['glueful' => [
                'provider' => 'Acme\\Widgets\\P',
                'migrations' => [
                    ['id' => 'default', 'path' => 'migrations', 'priority' => 'dependent', 'mode' => 'on_enable'],
                ],
            ]],
        ]]], JSON_UNESCAPED_SLASHES));
        $this->writeEnabled([]);
        // The old config gates for locks/queue/uploads/etc. are deliberately ABSENT (all off):
        // core leaves must register regardless.
        $this->context = new ApplicationContext($this->base);
        $this->context->setConfigLoader(
            new \Glueful\Bootstrap\ConfigurationLoader($this->base, 'testing')
        );
        $config = new DatabaseConfig('sqlite', database: $this->base . '/db.sqlite');
        $this->connection = new Connection($config->toConnectionConfig());
    }

    /** @param list<string> $providers */
    private function writeEnabled(array $providers): void
    {
        $exported = var_export($providers, true);
        file_put_contents(
            $this->base . '/config/extensions.php',
            "<?php\nreturn ['enabled' => {$exported}];\n"
        );
    }

    public function testFactoryRegistersEveryCoreLeafUnconditionally(): void
    {
        $manager = MigrationManagerFactory::create($this->context, $this->connection);

        foreach (
            [
            'glueful/framework',
            'glueful/framework:locks',
            'glueful/framework:metrics',
            'glueful/framework:notifications',
            'glueful/framework:queue',
            'glueful/framework:scheduler',
            'glueful/framework:uploads',
            ] as $source
        ) {
            self::assertTrue($manager->hasSource($source), "core leaf {$source} must register with gates off");
        }
        self::assertNotEmpty(
            $manager->pendingForSources(['glueful/framework:locks']),
            'locks leaf must be discoverable even though its config gate is off'
        );
    }

    public function testFactoryRegistersManifestDescriptorsNotProviderBoot(): void
    {
        $manager = MigrationManagerFactory::create($this->context, $this->connection);

        self::assertTrue($manager->hasSource('acme/widgets'));
        $rows = $manager->pendingForSources(['acme/widgets']);
        self::assertCount(1, $rows);
        self::assertStringEndsWith('001_W.php', $rows[0]['file']);
    }

    public function testGlobalSourcePolicyReadsCurrentContextStateNotACapturedList(): void
    {
        $manager = MigrationManagerFactory::create($this->context, $this->connection);

        self::assertNotContains('acme/widgets', $manager->globalSources(), 'disabled on_enable is not global');

        $this->writeEnabled(['Acme\\Widgets\\P']);
        $this->context->clearConfigCache(); // production writes clear via writeCacheNow(); mirror that
        self::assertContains('acme/widgets', $manager->globalSources(), 'policy must evaluate at call time');

        $this->writeEnabled([]);
        $this->context->clearConfigCache();
        self::assertNotContains('acme/widgets', $manager->globalSources());
    }
}
