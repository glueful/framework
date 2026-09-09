<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Bootstrap\ContainerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * With prod=true the factory must actually hand back the COMPILED container — every core
 * provider definition compiles (static factories included) and the live ApplicationContext is
 * hydrated into it — instead of logging a compile failure and falling back on every boot.
 * The compiled artifact belongs to the app's storage/cache, not the framework package dir or
 * a host-shared temp file.
 */
final class ProductionContainerCompilesTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-prod-container-' . uniqid('', true);
        mkdir($this->base . '/storage/cache', 0755, true);
    }

    protected function tearDown(): void
    {
        $dir = $this->base . '/storage/cache/container';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        @rmdir($this->base . '/storage/cache');
        @rmdir($this->base . '/storage');
        @rmdir($this->base);
    }

    public function testProductionReturnsACompiledContainerHydratedWithTheContext(): void
    {
        $context = new ApplicationContext($this->base, 'production');

        $container = ContainerFactory::create($context, true);

        self::assertStringStartsWith(
            'Glueful\\Container\\Compiled\\CompiledContainer_',
            get_class($container),
            'prod=true must yield the compiled container, not the runtime fallback',
        );
        self::assertSame($context, $container->get(ApplicationContext::class));
        self::assertSame($container, $container->get(ContainerInterface::class));
        self::assertCount(1, $this->artifacts(), 'one signed compiled artifact under the APP storage/cache');
        self::assertFileDoesNotExist($this->base . '/storage/cache/container/CompiledContainer.runtime.php');
    }

    /**
     * Every PHP-FPM worker used to compile and rewrite the artifact on ITS OWN boot and require
     * the same path: 783 KB written per request, and a worker requiring a file another worker
     * was mid-writing ("Unclosed '{' on line 9557") fell back to the runtime container. The
     * artifact is now written once, atomically, under a name signed by the definitions; later
     * boots require the existing file and write nothing.
     */
    public function testALaterBootReusesTheSignedArtifactWithoutRewritingIt(): void
    {
        $context = new ApplicationContext($this->base, 'production');
        ContainerFactory::create($context, true);
        [$artifact] = $this->artifacts();
        $stamp = time() - 3600;
        touch($artifact, $stamp);
        clearstatcache();

        $again = ContainerFactory::create(new ApplicationContext($this->base, 'production'), true);

        self::assertStringStartsWith('Glueful\\Container\\Compiled\\CompiledContainer_', get_class($again));
        self::assertSame([$artifact], $this->artifacts(), 'no second artifact');
        clearstatcache();
        self::assertSame($stamp, filemtime($artifact), 'the existing artifact was not rewritten');
    }

    public function testAStaleArtifactFromOtherDefinitionsIsReplacedNotReused(): void
    {
        $dir = $this->base . '/storage/cache/container';
        mkdir($dir, 0755, true);
        $stale = $dir . '/CompiledContainer_0000000000000000.php';
        file_put_contents($stale, "<?php // stale artifact from an earlier release\n");

        ContainerFactory::create(new ApplicationContext($this->base, 'production'), true);

        self::assertFileDoesNotExist($stale, 'artifacts for other definition sets are pruned');
        self::assertCount(1, $this->artifacts());
    }

    public function testAPrecompiledContainerWithoutAMatchingSignatureIsIgnored(): void
    {
        $dir = $this->base . '/storage/cache/container';
        mkdir($dir, 0755, true);
        // What `di:container:compile` from an earlier release leaves behind: no signature at all.
        file_put_contents($dir . '/CompiledContainer.php', "<?php namespace Glueful\\Container\\Compiled; final class CompiledContainer { public function boom(): never { throw new \\RuntimeException('stale precompiled container used'); } }\n");

        $container = ContainerFactory::create(new ApplicationContext($this->base, 'production'), true);

        self::assertStringStartsWith('Glueful\\Container\\Compiled\\CompiledContainer_', get_class($container));
        self::assertSame($container, $container->get(ContainerInterface::class));
    }

    /** @return list<string> */
    private function artifacts(): array
    {
        $files = glob($this->base . '/storage/cache/container/CompiledContainer_*.php') ?: [];
        sort($files);
        return $files;
    }
}
