<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\{DanglingGrantException, DuplicatePermissionException, Permission, PermissionRegistry, Role};
use PHPUnit\Framework\TestCase;

final class PermissionRegistryTest extends TestCase
{
    public function test_register_and_lookup(): void
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.posts.publish'), 'vendor/blog');
        self::assertTrue($r->has('blog.posts.publish'));
        self::assertCount(1, $r->permissions());
        self::assertSame('vendor/blog', $r->permissions()[0]->getManagedBy());
    }

    public function test_same_slug_same_source_is_idempotent(): void
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.x'), 'vendor/blog');
        $r->register(Permission::define('blog.x'), 'vendor/blog');
        self::assertCount(1, $r->permissions());
    }

    public function test_cross_source_collision_throws(): void
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.x'), 'vendor/a');
        $this->expectException(DuplicatePermissionException::class);
        $r->register(Permission::define('blog.x'), 'vendor/b');
    }

    public function test_role_permission_map(): void
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.publish'), 'vendor/blog');
        $r->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');
        self::assertSame(['blog.editor' => ['blog.publish']], $r->rolePermissionMap());
    }

    public function test_validate_rejects_dangling_grant(): void
    {
        $r = new PermissionRegistry();
        $r->registerRole(Role::define('blog.editor')->grants(['blog.missing']), 'vendor/blog');
        $this->expectException(DanglingGrantException::class);
        $r->validate();
    }

    public function test_validate_allows_wildcard_grant(): void
    {
        $r = new PermissionRegistry();
        $r->registerRole(Role::define('admin')->grants(['*']), 'app');
        $r->validate();
        $this->expectNotToPerformAssertions();
    }
}
