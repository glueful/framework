<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Cache;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Cache\CacheFactory;
use Glueful\Cache\Drivers\FileCacheDriver;
use PHPUnit\Framework\TestCase;

/**
 * The file cache lives where the app configures it, never inside the framework's own folder: under
 * vendor/ it is unwritable wherever the web server cannot write the code.
 */
final class FileCachePathTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/file_cache_path_' . uniqid();
        mkdir($this->base . '/config', 0755, true);
        file_put_contents($this->base . '/config/app.php', "<?php return ['env' => 'testing'];");
    }

    protected function tearDown(): void
    {
        CacheFactory::setDefaultContext(null);
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testTheConfiguredFileCachePathIsUsed(): void
    {
        $configured = $this->base . '/var/app-cache/';
        file_put_contents(
            $this->base . '/config/cache.php',
            "<?php return ['default' => 'file', 'stores' => ['file' => ['driver' => 'file', 'path' => "
                . var_export($configured, true) . "]]];"
        );
        self::assertSame($configured, $this->directoryOf(CacheFactory::create('file', $this->context())));
        self::assertDirectoryExists($configured);
    }

    public function testWithoutAConfiguredPathTheAppsStorageIsUsed(): void
    {
        $config = "<?php return ['stores' => ['file' => ['driver' => 'file', 'path' => null]]];";
        file_put_contents($this->base . '/config/cache.php', $config);
        $driver = CacheFactory::create('file', $this->context());
        self::assertSame($this->base . '/storage/cache/', $this->directoryOf($driver));
    }

    public function testACallerWithoutAContextGetsTheBootContextsPath(): void
    {
        // Many services create their cache with no context; the framework sets one at boot.
        $config = "<?php return ['stores' => ['file' => ['driver' => 'file', 'path' => null]]];";
        file_put_contents($this->base . '/config/cache.php', $config);
        CacheFactory::setDefaultContext($this->context());
        self::assertSame($this->base . '/storage/cache/', $this->directoryOf(CacheFactory::create('file')));
    }

    private function context(): ApplicationContext
    {
        $context = new ApplicationContext(basePath: $this->base, environment: 'testing');
        $context->setConfigLoader(new ConfigurationLoader($this->base, 'testing', $this->base . '/config'));
        return $context;
    }

    private function directoryOf(object $driver): string
    {
        self::assertInstanceOf(FileCacheDriver::class, $driver);
        $p = new \ReflectionProperty(FileCacheDriver::class, 'directory');
        return rtrim((string) $p->getValue($driver), '/') . '/';
    }
}
