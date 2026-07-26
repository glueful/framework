<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\OrderedProvider;
use Glueful\Extensions\ProviderClassResolver;
use Glueful\Extensions\ServiceProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;

final class ParityEarlyExtensionProvider extends ServiceProvider
{
}

final class ParityLateAppProvider extends ServiceProvider implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [ParityEarlyExtensionProvider::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

/**
 * The cross-phase parity contract (modules-not-extensions spec §8.1): a declarative edge that
 * INVERTS the raw app-first merge (the app provider declares loadAfter an extension) must hold
 * in every observed order. ContainerFactory compilation and extensions:diagnose both consume
 * `ProviderClassResolver`/`resolveProviderClasses()` directly, so the resolver assertion below
 * covers those phases by construction; the cache and discovery phases are asserted explicitly.
 */
final class ProviderOrderParityTest extends TestCase
{
    private string $base;
    private ApplicationContext $context;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-parity-' . uniqid('', true);
        mkdir($this->base . '/config', 0777, true);
        mkdir($this->base . '/vendor/composer', 0777, true);

        // App provider list: the LATE provider — app providers precede extensions in the raw merge.
        file_put_contents($this->base . '/config/serviceproviders.php', "<?php\nreturn " . var_export([
            'enabled' => [ParityLateAppProvider::class],
        ], true) . ";\n");
        // Extension activation list: the EARLY provider.
        file_put_contents($this->base . '/config/extensions.php', "<?php\nreturn " . var_export([
            'enabled' => [ParityEarlyExtensionProvider::class],
        ], true) . ";\n");
        // Composer-discovered candidate backing EARLY.
        file_put_contents($this->base . '/vendor/composer/installed.json', (string) json_encode([
            'packages' => [[
                'name' => 'acme/early',
                'version' => '1.0.0',
                'type' => 'glueful-extension',
                'extra' => ['glueful' => ['provider' => ParityEarlyExtensionProvider::class]],
            ]],
        ]));

        $this->context = new ApplicationContext($this->base, 'testing');
        $this->context->setConfigLoader(
            new ConfigurationLoader($this->base, 'testing', $this->base . '/config')
        );

        $this->container = $this->createMock(ContainerInterface::class);
        $context = $this->context;
        $this->container->method('get')->willReturnCallback(
            static function (string $id) use ($context): mixed {
                if ($id === ApplicationContext::class) {
                    return $context;
                }
                throw new \RuntimeException("Unexpected service: {$id}");
            }
        );
        $this->container->method('has')->willReturn(false);
        $this->context->setContainer($this->container);
    }

    protected function tearDown(): void
    {
        $cache = $this->base . '/bootstrap/cache/extensions.php';
        if (is_file($cache)) {
            unlink($cache);
        }
        foreach (
            [
                '/bootstrap/cache',
                '/bootstrap',
                '/config/serviceproviders.php',
                '/config/extensions.php',
                '/config',
                '/vendor/composer/installed.json',
                '/vendor/composer',
                '/vendor',
                '',
            ] as $path
        ) {
            $full = $this->base . $path;
            if (is_file($full)) {
                unlink($full);
            } elseif (is_dir($full)) {
                @rmdir($full);
            }
        }
    }

    /** @param list<class-string> $classes */
    private static function assertEarlyBeforeLate(array $classes, string $phase): void
    {
        $early = array_search(ParityEarlyExtensionProvider::class, $classes, true);
        $late = array_search(ParityLateAppProvider::class, $classes, true);
        self::assertNotFalse($early, "{$phase}: early provider missing");
        self::assertNotFalse($late, "{$phase}: late provider missing");
        self::assertLessThan($late, $early, "{$phase}: declarative order violated");
    }

    public function testResolverOrdersTheDeclarativeEdgeAcrossTheMerge(): void
    {
        $providers = (new ProviderClassResolver())->resolve($this->context)->providers;
        self::assertEarlyBeforeLate($providers, 'resolver');
    }

    public function testImplicitAndExplicitCacheWritesShareTheOrder(): void
    {
        $manager = new ExtensionManager($this->container);
        $manager->writeCacheNow();
        $cache = $this->base . '/bootstrap/cache/extensions.php';
        self::assertEarlyBeforeLate(require $cache, 'implicit cache write');

        // An explicit list arriving in the WRONG order is ordered before persistence.
        $manager->writeCacheNow([ParityLateAppProvider::class, ParityEarlyExtensionProvider::class]);
        self::assertEarlyBeforeLate(require $cache, 'explicit cache write');
    }

    public function testCachedAndUncachedDiscoveryPreserveTheOrder(): void
    {
        (new ExtensionManager($this->container))->writeCacheNow();

        // Cached path: discover() must return the cached (already canonical) order untouched.
        $cachedManager = new ExtensionManager($this->container);
        $cachedManager->discover();
        self::assertEarlyBeforeLate(array_keys($this->providersOf($cachedManager)), 'cached discovery');

        // Uncached development path: live resolve + registerProviders + sortProviders.
        unlink($this->base . '/bootstrap/cache/extensions.php');
        $liveManager = new ExtensionManager($this->container);
        $liveManager->discover();
        self::assertEarlyBeforeLate(array_keys($this->providersOf($liveManager)), 'uncached discovery');
    }

    public function testRecompileAfterAStateWriteSeesTheNewFileState(): void
    {
        // The read→write→recompile sequence every activation surface runs (extensions:enable,
        // the admin toggles): reading the enabled list primes the context config cache, then
        // ExtensionStateWriter mutates the file, then writeCacheNow() recompiles. The recompile
        // must observe the JUST-WRITTEN state, not the primed cache — a stale recompile writes
        // a cache missing the provider that was just enabled.
        file_put_contents($this->base . '/config/extensions.php', "<?php\nreturn [\n    'enabled' => [\n    ],\n];\n");
        self::assertSame([], EnabledProviders::from($this->context)); // primes the config cache

        (new ExtensionStateWriter())->enable(
            $this->base . '/config/extensions.php',
            ParityEarlyExtensionProvider::class,
        );
        (new ExtensionManager($this->container))->writeCacheNow();

        self::assertContains(
            ParityEarlyExtensionProvider::class,
            require $this->base . '/bootstrap/cache/extensions.php',
            'writeCacheNow() must recompile from current file state, not the primed config cache',
        );
    }

    public function testLegacyCycleFallbackKeepsTheDeclarativeOrder(): void
    {
        // A legacy OrderedProvider cycle forces the fallback branch; the declarative edge
        // (Late loadAfter Early) must survive it — the fallback re-applies the contract.
        $legacyA = new class ($this->container) extends ServiceProvider implements OrderedProvider {
            public function priority(): int
            {
                return 0;
            }

            public function bootAfter(): array
            {
                return ['Legacy\\B'];
            }
        };
        $legacyB = new class ($this->container) extends ServiceProvider implements OrderedProvider {
            public function priority(): int
            {
                return 0;
            }

            public function bootAfter(): array
            {
                return ['Legacy\\A'];
            }
        };

        $manager = new ExtensionManager($this->container);
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('providers');
        $property->setAccessible(true);
        $property->setValue($manager, [
            // Seeded in the WRONG order — the declarative edge must correct it even in fallback.
            ParityLateAppProvider::class => new ParityLateAppProvider($this->container),
            'Legacy\\A' => $legacyA,
            'Legacy\\B' => $legacyB,
            ParityEarlyExtensionProvider::class => new ParityEarlyExtensionProvider($this->container),
        ]);

        $method = $reflection->getMethod('sortProviders');
        $method->setAccessible(true);
        $method->invoke($manager);

        self::assertEarlyBeforeLate(array_keys($property->getValue($manager)), 'legacy-cycle fallback');
    }

    /** @return array<class-string, object> */
    private function providersOf(ExtensionManager $manager): array
    {
        $property = (new \ReflectionClass($manager))->getProperty('providers');
        $property->setAccessible(true);

        return $property->getValue($manager);
    }
}
