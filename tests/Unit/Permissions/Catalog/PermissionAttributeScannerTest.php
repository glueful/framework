<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\PermissionAttributeScanner;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;

final class PermissionAttributeScannerTest extends TestCase
{
    public function test_scans_enforced_permissions_and_roles_from_handlers(): void
    {
        $router = $this->createMock(Router::class);
        $router->method('getAllRoutes')->willReturn([
            ['method' => 'POST', 'path' => '/posts', 'handler' => [ScanFixtureController::class, 'publish'], 'middleware' => [], 'name' => null, 'type' => 'static'],
            ['method' => 'GET', 'path' => '/admin', 'handler' => [ScanFixtureController::class, 'adminOnly'], 'middleware' => [], 'name' => null, 'type' => 'static'],
            ['method' => 'GET', 'path' => '/closure', 'handler' => fn() => null, 'middleware' => [], 'name' => null, 'type' => 'static'],
        ]);

        $result = (new PermissionAttributeScanner($router))->scan();

        self::assertEqualsCanonicalizing(['blog.publish'], $result['permissions']);
        self::assertEqualsCanonicalizing(['admin'], $result['roles']);
    }
}

final class ScanFixtureController
{
    #[\Glueful\Auth\Attributes\RequiresPermission('blog.publish')]
    public function publish(): void
    {
    }

    #[\Glueful\Auth\Attributes\RequiresRole('admin')]
    public function adminOnly(): void
    {
    }
}
