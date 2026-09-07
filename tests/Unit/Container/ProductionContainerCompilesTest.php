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

        self::assertSame(
            'Glueful\\Container\\Compiled\\CompiledContainer',
            get_class($container),
            'prod=true must yield the compiled container, not the runtime fallback',
        );
        self::assertSame($context, $container->get(ApplicationContext::class));
        self::assertSame($container, $container->get(ContainerInterface::class));
        self::assertFileExists(
            $this->base . '/storage/cache/container/CompiledContainer.runtime.php',
            'the compiled artifact is written under the APP storage/cache',
        );
    }
}
