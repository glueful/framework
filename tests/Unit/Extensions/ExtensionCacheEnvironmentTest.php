<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * bootstrap/cache/extensions.php decides which providers a boot loads, and the enabled list
 * it is compiled from is environment-specific (config/{env}/extensions.php overrides). A cache
 * compiled under one environment must never feed a boot under another: outside production the
 * dev TTL is five seconds, so a `extensions:cache` or a provision run followed by a test run
 * handed the tests a provider list built for development. The cache now records the
 * environment it was compiled for; a boot under a different environment resolves live instead.
 * A cache without the stamp (written by an older framework, or by a test that writes the bare
 * list) is still honoured.
 */
final class ExtensionCacheEnvironmentTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-ext-cache-env-' . uniqid('', true);
        mkdir($this->base . '/bootstrap/cache', 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->base . '/bootstrap/cache/extensions.php');
        @rmdir($this->base . '/bootstrap/cache');
        @rmdir($this->base);
    }

    public function testACacheCompiledForAnotherEnvironmentIsNotUsed(): void
    {
        $this->writeCache(['environment' => 'development', 'providers' => [StampedProvider::class]]);

        $manager = $this->manager('testing');
        $manager->discover();

        self::assertFalse($manager->getCacheUsed());
        self::assertFalse($manager->hasProvider(StampedProvider::class));
    }

    public function testACacheCompiledForThisEnvironmentIsUsed(): void
    {
        $this->writeCache(['environment' => 'testing', 'providers' => [StampedProvider::class]]);

        $manager = $this->manager('testing');
        $manager->discover();

        self::assertTrue($manager->getCacheUsed());
        self::assertTrue($manager->hasProvider(StampedProvider::class));
    }

    public function testACacheWithoutAStampIsStillHonoured(): void
    {
        $this->writeCache([StampedProvider::class]);

        $manager = $this->manager('testing');
        $manager->discover();

        self::assertTrue($manager->getCacheUsed());
        self::assertTrue($manager->hasProvider(StampedProvider::class));
    }

    public function testWritingTheCacheStampsTheCompilingEnvironment(): void
    {
        $this->manager('testing')->writeCacheNow([StampedProvider::class]);

        $written = ExtensionManager::readCacheFile($this->base . '/bootstrap/cache/extensions.php');

        self::assertSame('testing', $written['environment']);
        self::assertSame([StampedProvider::class], $written['providers']);
    }

    public function testReadingALegacyCacheReportsNoEnvironment(): void
    {
        $this->writeCache([StampedProvider::class]);

        $read = ExtensionManager::readCacheFile($this->base . '/bootstrap/cache/extensions.php');

        self::assertNull($read['environment']);
        self::assertSame([StampedProvider::class], $read['providers']);
    }

    /** @param array<mixed> $contents */
    private function writeCache(array $contents): void
    {
        file_put_contents(
            $this->base . '/bootstrap/cache/extensions.php',
            "<?php\nreturn " . var_export($contents, true) . ";\n",
        );
    }

    private function manager(string $environment): ExtensionManager
    {
        $context = new ApplicationContext($this->base, $environment);
        $container = new class ($context) implements ContainerInterface {
            public function __construct(private readonly ApplicationContext $context)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->context;
                }
                throw new \LogicException("unexpected service {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }
        };

        return new ExtensionManager($container);
    }
}

final class StampedProvider extends ServiceProvider
{
}
