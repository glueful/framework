<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions;

use PHPUnit\Framework\TestCase;
use Glueful\Permissions\Middleware\GateAttributeMiddleware;
use Glueful\Permissions\PermissionManager;
use Glueful\Auth\UserIdentity;
use Glueful\Auth\Attributes\RequiresPermission;
use Glueful\Auth\Attributes\RequiresRole;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Test that RequiresPermission and RequiresRole attributes work with GateAttributeMiddleware
 */
class AttributeMiddlewareTest extends TestCase
{
    public function testAttributesExist(): void
    {
        $this->assertTrue(class_exists(RequiresPermission::class));
        $this->assertTrue(class_exists(RequiresRole::class));
    }

    public function testAttributesCanBeInstantiated(): void
    {
        $permission = new RequiresPermission('posts.create', 'posts');
        $this->assertEquals('posts.create', $permission->name);
        $this->assertEquals('posts', $permission->resource);

        $role = new RequiresRole('admin');
        $this->assertEquals('admin', $role->name);
    }

    public function testAttributesAreRepeatable(): void
    {
        $attrs = (new \ReflectionClass(RequiresPermission::class))->getAttributes();
        $attrInstance = $attrs[0]->newInstance();
        $this->assertTrue(($attrInstance->flags & \Attribute::IS_REPEATABLE) !== 0);

        $attrs = (new \ReflectionClass(RequiresRole::class))->getAttributes();
        $attrInstance = $attrs[0]->newInstance();
        $this->assertTrue(($attrInstance->flags & \Attribute::IS_REPEATABLE) !== 0);
    }

    public function testMiddlewareChecksAttributes(): void
    {
        // Enforcement now routes through PermissionManager::can() (single entry point).
        $manager = $this->createMock(PermissionManager::class);
        $manager->method('can')->willReturn(true);

        $middleware = new GateAttributeMiddleware($manager);

        $request = Request::create('/test');
        $request->attributes->set('handler_meta', [
            'class' => TestControllerWithAttributes::class,
            'method' => 'store'
        ]);
        $request->attributes->set('auth.user', new UserIdentity('user123', ['editor']));

        $next = fn() => new JsonResponse(['success' => true]);

        $response = $middleware->handle($request, $next);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success'] ?? false);
    }
}

// Controller moved to its own PSR-4 compliant file under tests/Unit/Permissions/TestControllerWithAttributes.php
