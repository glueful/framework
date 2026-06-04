<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\Permission;
use PHPUnit\Framework\TestCase;

final class PermissionTest extends TestCase
{
    public function test_define_defaults_label_to_slug(): void
    {
        $p = Permission::define('blog.posts.publish');
        self::assertSame('blog.posts.publish', $p->slug());
        self::assertSame('blog.posts.publish', $p->toArray()['name']);
    }

    public function test_fluent_setters_populate_to_array(): void
    {
        $p = Permission::define('blog.posts.publish')
            ->label('Publish Posts')
            ->description('Publish and unpublish posts')
            ->category('blog')
            ->resource('posts')
            ->managedBy('vendor/blog');

        self::assertSame([
            'slug' => 'blog.posts.publish',
            'name' => 'Publish Posts',
            'description' => 'Publish and unpublish posts',
            'category' => 'blog',
            'resource_type' => 'posts',
            'managed_by' => 'vendor/blog',
        ], $p->toArray());
        self::assertSame('vendor/blog', $p->getManagedBy());
    }
}
