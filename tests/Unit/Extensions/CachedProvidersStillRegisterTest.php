<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Production boots from bootstrap/cache/extensions.php. That path used to construct the cached
 * providers and return — register() never ran, so anything a provider registers there (console
 * commands, runtime bindings) silently vanished on every cached boot, i.e. on every production
 * boot. The cache decides WHICH providers load, not whether their lifecycle runs.
 */
final class CachedProvidersStillRegisterTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-ext-cache-' . uniqid('', true);
        mkdir($this->base . '/bootstrap/cache', 0755, true);
        file_put_contents(
            $this->base . '/bootstrap/cache/extensions.php',
            "<?php\nreturn " . var_export([RecordingProvider::class], true) . ";\n",
        );
        RecordingProvider::$registered = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->base . '/bootstrap/cache/extensions.php');
        @rmdir($this->base . '/bootstrap/cache');
        @rmdir($this->base . '/bootstrap');
        @rmdir($this->base);
    }

    public function testDiscoverFromCacheCallsRegisterOnEveryCachedProvider(): void
    {
        $context = new ApplicationContext($this->base, 'testing');
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

        $manager = new ExtensionManager($container);
        $manager->discover();

        self::assertTrue($manager->hasProvider(RecordingProvider::class), 'sanity: provider came from the cache');
        self::assertSame(
            [RecordingProvider::class],
            RecordingProvider::$registered,
            'register() must run for providers loaded from the extension cache',
        );
    }
}

final class RecordingProvider extends ServiceProvider
{
    /** @var list<class-string> */
    public static array $registered = [];

    public function register(ApplicationContext $context): void
    {
        self::$registered[] = static::class;
    }
}
