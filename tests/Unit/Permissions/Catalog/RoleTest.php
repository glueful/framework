<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\Role;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function test_builds_role_with_grants(): void
    {
        $r = Role::define('blog.editor')
            ->label('Blog Editor')
            ->description('Edits blog content')
            ->grants(['blog.posts.publish', 'blog.posts.delete'])
            ->level(40)
            ->parent('blog.author')
            ->managedBy('vendor/blog');

        self::assertSame('blog.editor', $r->slug());
        self::assertSame(['blog.posts.publish', 'blog.posts.delete'], $r->grantedPermissions());
        self::assertSame('vendor/blog', $r->getManagedBy());

        $arr = $r->toArray();
        self::assertSame('blog.editor', $arr['slug']);
        self::assertSame('Blog Editor', $arr['name']);
        self::assertSame(40, $arr['level']);
        self::assertSame('blog.author', $arr['parent']);
        self::assertSame(['blog.posts.publish', 'blog.posts.delete'], $arr['grants']);
    }

    public function test_defaults(): void
    {
        $r = Role::define('blog.viewer');
        self::assertSame('blog.viewer', $r->toArray()['name']);
        self::assertSame(0, $r->toArray()['level']);
        self::assertSame([], $r->grantedPermissions());
    }
}
