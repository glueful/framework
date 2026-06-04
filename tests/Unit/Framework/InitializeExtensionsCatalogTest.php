<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Framework;

use Glueful\Framework;
use Glueful\Permissions\Catalog\DuplicatePermissionException;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class InitializeExtensionsCatalogTest extends TestCase
{
    private function frameworkWithContainer(object $extensionManagerStub): Framework
    {
        $container = new class ($extensionManagerStub) implements ContainerInterface {
            public function __construct(private object $mgr)
            {
            }
            public function get(string $id): mixed
            {
                return $this->mgr;
            }
            public function has(string $id): bool
            {
                return true;
            }
        };

        $framework = (new \ReflectionClass(Framework::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(Framework::class, 'container');
        $prop->setAccessible(true);
        $prop->setValue($framework, $container);
        return $framework;
    }

    private function invokeInitialize(Framework $framework): void
    {
        $m = new \ReflectionMethod(Framework::class, 'initializeExtensions');
        $m->setAccessible(true);
        $m->invoke($framework);
    }

    public function test_catalog_exception_propagates(): void
    {
        $stub = new class {
            public function discover(): void
            {
            }
            public function aggregatePermissionCatalog(): void
            {
                throw new DuplicatePermissionException('blog.x', 'vendor/a', 'vendor/b');
            }
            public function registerProviderGateExtensions(): void
            {
            }
            public function boot(): void
            {
            }
        };

        $this->expectException(DuplicatePermissionException::class);
        $this->invokeInitialize($this->frameworkWithContainer($stub));
    }

    public function test_discovery_failure_is_swallowed_and_catalog_still_runs(): void
    {
        $stub = new class {
            public bool $catalogRan = false;
            public function discover(): void
            {
                throw new \RuntimeException('discover boom');
            }
            public function aggregatePermissionCatalog(): void
            {
                $this->catalogRan = true;
            }
            public function registerProviderGateExtensions(): void
            {
            }
            public function boot(): void
            {
            }
        };

        // Must NOT throw — discovery errors are logged, not fatal.
        $this->invokeInitialize($this->frameworkWithContainer($stub));
        self::assertTrue($stub->catalogRan, 'catalog build runs even after discovery failure');
    }
}
