<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Pins the contract that ServiceProvider::mergeConfig() actually makes an extension's config
 * defaults visible to config()/ApplicationContext::getConfig() — previously a silent no-op because
 * no 'config.manager' service was ever registered.
 */
final class ConfigDefaultsMergeTest extends TestCase
{
    private string $appPath;

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->appPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->appPath);
        }
        parent::tearDown();
    }

    /** @param array<string,string> $files filename(without dir) => php-return-body */
    private function context(array $files = []): ApplicationContext
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-cfgmerge-' . uniqid();
        $cfgDir = $this->appPath . '/config';
        mkdir($cfgDir, 0755, true);
        foreach ($files as $name => $body) {
            file_put_contents($cfgDir . '/' . $name, "<?php\nreturn " . $body . ";");
        }
        $ctx = ApplicationContext::forTesting($this->appPath);
        $ctx->setConfigLoader(new ConfigurationLoader($this->appPath, 'testing'));
        return $ctx;
    }

    public function test_merged_defaults_are_visible_to_get_config(): void
    {
        $ctx = $this->context(); // no app config file for 'demo'
        $ctx->mergeConfigDefaults('demo', ['a' => 1, 'nested' => ['x' => 'd']]);

        self::assertSame(1, $ctx->getConfig('demo.a'));
        self::assertSame('d', $ctx->getConfig('demo.nested.x'));
    }

    public function test_app_config_file_overrides_merged_defaults(): void
    {
        // App ships config/demo.php overriding 'a' but not 'b'.
        $ctx = $this->context(['demo.php' => "['a' => 'fromApp']"]);
        $ctx->mergeConfigDefaults('demo', ['a' => 'default', 'b' => 'default']);

        self::assertSame('fromApp', $ctx->getConfig('demo.a'), 'app file wins over extension default');
        self::assertSame('default', $ctx->getConfig('demo.b'), 'unset key falls back to default');
    }

    public function test_defaults_survive_clear_config_cache(): void
    {
        $ctx = $this->context();
        $ctx->mergeConfigDefaults('demo', ['k' => 'v']);
        self::assertSame('v', $ctx->getConfig('demo.k'));

        $ctx->clearConfigCache();
        self::assertSame('v', $ctx->getConfig('demo.k'), 'registered defaults persist a cache clear');
    }

    public function test_service_provider_mergeConfig_delegates_to_context(): void
    {
        $ctx = $this->context();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === ApplicationContext::class
        );
        $container->method('get')->willReturnCallback(
            static fn(string $id) => $id === ApplicationContext::class ? $ctx : throw new \RuntimeException("unexpected get($id)")
        );

        $provider = new class ($container) extends ServiceProvider {
            /** @param array<string,mixed> $defaults */
            public function publicMerge(string $key, array $defaults): void
            {
                $this->mergeConfig($key, $defaults);
            }
        };
        $provider->publicMerge('ext', ['feature' => ['enabled' => true]]);

        self::assertTrue($ctx->getConfig('ext.feature.enabled'), 'mergeConfig() reaches config()');
    }
}
