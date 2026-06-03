<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ServiceProvider;
use Glueful\Permissions\Catalog\{DanglingGrantException, Permission, PermissionRegistry, Role};
use Glueful\Interfaces\Permission\PermissionStandards;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class AggregatePermissionCatalogTest extends TestCase
{
    /** @param array<string, ServiceProvider> $providers */
    private function managerWith(PermissionRegistry $registry, array $providers): ExtensionManager
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use ($registry) {
            if ($id === PermissionRegistry::class) {
                return $registry;
            }
            if ($id === ApplicationContext::class) {
                // Real (final) context over a temp dir → PackageManifest finds no candidates → 'app'.
                return ApplicationContext::forTesting(sys_get_temp_dir());
            }
            throw new \RuntimeException("unexpected get($id)");
        });

        $manager = new ExtensionManager($container);
        $ref = new \ReflectionProperty(ExtensionManager::class, 'providers');
        $ref->setAccessible(true);
        $ref->setValue($manager, $providers);
        return $manager;
    }

    public function test_seeds_core_permissions_and_provider_declarations(): void
    {
        $registry = new PermissionRegistry();
        $container = $this->createMock(ContainerInterface::class);
        $provider = new class ($container) extends ServiceProvider {
            public function permissions(): array
            {
                return [Permission::define('blog.publish')];
            }
            public function roles(): array
            {
                return [Role::define('blog.editor')->grants(['blog.publish'])];
            }
        };

        $manager = $this->managerWith($registry, ['BlogProvider' => $provider]);
        $manager->aggregatePermissionCatalog();

        self::assertTrue($registry->has(PermissionStandards::PERMISSION_SYSTEM_ACCESS));
        $core = null;
        foreach ($registry->permissions() as $p) {
            if ($p->slug() === PermissionStandards::PERMISSION_SYSTEM_ACCESS) {
                $core = $p;
            }
        }
        self::assertNotNull($core);
        self::assertSame('glueful/framework', $core->getManagedBy());

        self::assertTrue($registry->has('blog.publish'));
        self::assertSame(['blog.editor' => ['blog.publish']], $registry->rolePermissionMap());
    }

    public function test_dangling_grant_is_fatal(): void
    {
        $registry = new PermissionRegistry();
        $container = $this->createMock(ContainerInterface::class);
        $provider = new class ($container) extends ServiceProvider {
            public function roles(): array
            {
                return [Role::define('blog.editor')->grants(['blog.missing'])];
            }
        };

        $manager = $this->managerWith($registry, ['BlogProvider' => $provider]);
        $this->expectException(DanglingGrantException::class);
        $manager->aggregatePermissionCatalog();
    }
}
