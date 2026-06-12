<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Middleware;

use Glueful\Auth\UserIdentity;
use Glueful\Permissions\Middleware\GateAttributeMiddleware;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\{Request, Response};
use PHPUnit\Framework\TestCase;

final class GateAttributeMiddlewareTest extends TestCase
{
    private function requestFor(string $class, string $method): Request
    {
        $r = new Request();
        $r->attributes->set('handler_meta', ['class' => $class, 'method' => $method]);
        $r->attributes->set('auth.user', new UserIdentity('u1', ['blog.editor']));
        return $r;
    }

    public function test_calls_can_with_system_resource_default(): void
    {
        $manager = $this->createMock(PermissionManager::class);
        $manager->expects(self::once())
            ->method('can')
            ->with('u1', 'blog.publish', 'system', self::anything())
            ->willReturn(true);

        $mw = new GateAttributeMiddleware($manager);
        $resp = $mw->handle(
            $this->requestFor(FixtureController::class, 'publish'),
            fn(Request $req) => new Response('ok')
        );
        self::assertSame('ok', $resp->getContent());
    }

    public function test_denies_with_403_when_can_returns_false(): void
    {
        $manager = $this->createMock(PermissionManager::class);
        $manager->method('can')->willReturn(false);

        $mw = new GateAttributeMiddleware($manager);
        $resp = $mw->handle(
            $this->requestFor(FixtureController::class, 'publish'),
            fn(Request $req) => new Response('ok')
        );
        self::assertSame(403, $resp->getStatusCode());
    }

    public function test_role_attribute_maps_to_role_dot_name(): void
    {
        $manager = $this->createMock(PermissionManager::class);
        $manager->expects(self::once())
            ->method('can')
            ->with('u1', 'role.editor', 'system', self::anything())
            ->willReturn(true);

        $mw = new GateAttributeMiddleware($manager);
        $mw->handle(
            $this->requestFor(RoleFixtureController::class, 'index'),
            fn(Request $req) => new Response('ok')
        );
    }

    public function test_dotted_role_attribute_passes_through_unchanged(): void
    {
        // Regression: a dotted role value must NOT be re-prefixed to "role.role.admin".
        $manager = $this->createMock(PermissionManager::class);
        $manager->expects(self::once())
            ->method('can')
            ->with('u1', 'role.admin', 'system', self::anything())
            ->willReturn(true);

        $mw = new GateAttributeMiddleware($manager);
        $mw->handle(
            $this->requestFor(DottedRoleFixtureController::class, 'index'),
            fn(Request $req) => new Response('ok')
        );
    }

    public function test_parent_class_permission_attribute_is_enforced(): void
    {
        $manager = $this->createMock(PermissionManager::class);
        $manager->expects(self::once())
            ->method('can')
            ->with('u1', 'parent.manage', 'system', self::anything())
            ->willReturn(false);

        $mw = new GateAttributeMiddleware($manager);
        $resp = $mw->handle(
            $this->requestFor(ChildPermissionFixtureController::class, 'index'),
            fn(Request $req) => new Response('ok')
        );

        self::assertSame(403, $resp->getStatusCode());
    }
}

#[\Glueful\Auth\Attributes\RequiresPermission('blog.publish')]
final class FixtureController
{
    public function publish(): void
    {
    }
}

final class RoleFixtureController
{
    #[\Glueful\Auth\Attributes\RequiresRole('editor')]
    public function index(): void
    {
    }
}

final class DottedRoleFixtureController
{
    #[\Glueful\Auth\Attributes\RequiresRole('role.admin')]
    public function index(): void
    {
    }
}

#[\Glueful\Auth\Attributes\RequiresPermission('parent.manage')]
abstract class ParentPermissionFixtureController
{
}

final class ChildPermissionFixtureController extends ParentPermissionFixtureController
{
    public function index(): void
    {
    }
}
