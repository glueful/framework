<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container;

use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Container\Exception\ContainerException;
use PHPUnit\Framework\TestCase;

/**
 * An extension provider whose services()/defs()/tags() throws must NOT be silently
 * swallowed. Outside production it fails loud (so a broken extension is caught at boot,
 * not as a mysteriously-missing service -- or a silently-served core default -- at
 * runtime); in production it is recorded so partial boot is detectable.
 */
final class ProviderLoadFailureTest extends TestCase
{
    private function handle(string $provider, string $phase, \Throwable $e, bool $prod): void
    {
        $method = new \ReflectionMethod(ContainerFactory::class, 'handleProviderLoadFailure');
        $method->setAccessible(true);
        $method->invoke(null, $provider, $phase, $e, $prod);
    }

    public function test_non_production_rethrows_naming_the_provider(): void
    {
        ContainerFactory::clearFailedProviders();

        try {
            $this->handle('App\\Broken\\Provider', 'services()', new \RuntimeException('boom'), false);
            self::fail('expected a ContainerException to be thrown outside production');
        } catch (ContainerException $e) {
            self::assertStringContainsString('App\\Broken\\Provider', $e->getMessage());
            self::assertStringContainsString('boom', $e->getMessage());
        }
    }

    public function test_production_records_and_does_not_throw(): void
    {
        ContainerFactory::clearFailedProviders();

        $this->handle('App\\Broken\\Provider', 'services()', new \RuntimeException('boom'), true);

        $failed = ContainerFactory::failedProviders();
        self::assertCount(1, $failed);
        self::assertSame('App\\Broken\\Provider', $failed[0]['provider']);
        self::assertSame('services()', $failed[0]['phase']);
    }

    public function test_reserved_keys_surface_is_explicit(): void
    {
        $reserved = ContainerFactory::reservedKeys();

        self::assertContains(\Glueful\Bootstrap\ApplicationContext::class, $reserved);
        self::assertContains('param.bag', $reserved);
        self::assertContains(\Psr\Container\ContainerInterface::class, $reserved);
    }
}
