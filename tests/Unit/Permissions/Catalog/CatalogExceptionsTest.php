<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\{DanglingGrantException, DuplicatePermissionException, PermissionCatalogException};
use PHPUnit\Framework\TestCase;

final class CatalogExceptionsTest extends TestCase
{
    public function test_duplicate_names_both_sources(): void
    {
        $e = new DuplicatePermissionException('blog.posts.publish', 'vendor/a', 'vendor/b');
        self::assertInstanceOf(PermissionCatalogException::class, $e);
        self::assertStringContainsString('blog.posts.publish', $e->getMessage());
        self::assertStringContainsString('vendor/a', $e->getMessage());
        self::assertStringContainsString('vendor/b', $e->getMessage());
    }

    public function test_dangling_grant_names_role_and_permission(): void
    {
        $e = new DanglingGrantException('blog.editor', 'blog.posts.missing');
        self::assertInstanceOf(PermissionCatalogException::class, $e);
        self::assertStringContainsString('blog.editor', $e->getMessage());
        self::assertStringContainsString('blog.posts.missing', $e->getMessage());
    }
}
