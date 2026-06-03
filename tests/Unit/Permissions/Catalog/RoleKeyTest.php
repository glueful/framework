<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\RoleKey;
use PHPUnit\Framework\TestCase;

final class RoleKeyTest extends TestCase
{
    public function test_bare_role_is_prefixed(): void
    {
        self::assertSame('role.admin', RoleKey::canonical('admin'));
    }

    public function test_dotted_role_passes_through(): void
    {
        self::assertSame('role.admin', RoleKey::canonical('role.admin'));
        self::assertSame('blog.editor', RoleKey::canonical('blog.editor'));
    }

    public function test_declared_slug_and_enforced_value_share_canonical_form(): void
    {
        // A declared Role slug 'admin' and an enforced #[RequiresRole('admin')] resolve to the
        // same canonical key, so diff never reports a false drift between them.
        self::assertSame(RoleKey::canonical('admin'), RoleKey::canonical('admin'));
        self::assertSame('role.admin', RoleKey::canonical('admin'));
    }
}
