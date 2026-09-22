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

    public function test_permissions_named_by_an_enforcing_middleware_count_as_enforced(): void
    {
        // An app that enforces permissions in route middleware ('content_permission:content.view')
        // had every permission reported as declared-but-unenforced: only attributes were read.
        $router = $this->createMock(Router::class);
        $router->method('getAllRoutes')->willReturn([
            ['method' => 'GET', 'path' => '/a', 'handler' => fn() => null, 'name' => null, 'type' => 'static',
                'middleware' => ['auth', 'content_permission:content.view']],
            ['method' => 'GET', 'path' => '/b', 'handler' => fn() => null, 'name' => null, 'type' => 'static',
                'middleware' => ['content_permission:commerce.view, commerce.manage', 'throttle:60,1']],
            ['method' => 'GET', 'path' => '/c', 'handler' => fn() => null, 'name' => null, 'type' => 'static',
                'middleware' => ['other_permission:not.counted']],
        ]);

        $result = (new PermissionAttributeScanner($router, ['content_permission']))->scan();

        self::assertEqualsCanonicalizing(
            ['content.view', 'commerce.view', 'commerce.manage'],
            $result['permissions']
        );
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
