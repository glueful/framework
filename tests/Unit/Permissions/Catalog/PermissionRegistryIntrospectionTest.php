<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role};
use PHPUnit\Framework\TestCase;

final class PermissionRegistryIntrospectionTest extends TestCase
{
    private function registry(): PermissionRegistry
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.publish')->category('blog'), 'vendor/blog');
        $r->register(Permission::define('blog.delete')->category('blog'), 'vendor/blog');
        $r->register(Permission::define('shop.refund')->category('shop'), 'vendor/shop');
        $r->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');
        return $r;
    }

    public function test_source_of_returns_declaring_package(): void
    {
        self::assertSame('vendor/blog', $this->registry()->sourceOf('blog.publish'));
        self::assertNull($this->registry()->sourceOf('does.not.exist'));
    }

    public function test_permission_slugs_and_role_slugs(): void
    {
        $r = $this->registry();
        self::assertEqualsCanonicalizing(['blog.publish', 'blog.delete', 'shop.refund'], $r->permissionSlugs());
        self::assertSame(['blog.editor'], $r->roleSlugs());
    }

    public function test_group_permissions_by_category(): void
    {
        $grouped = $this->registry()->permissionsByCategory();
        self::assertEqualsCanonicalizing(
            ['blog.publish', 'blog.delete'],
            array_map(fn($p) => $p->slug(), $grouped['blog'])
        );
        self::assertCount(1, $grouped['shop']);
    }
}
