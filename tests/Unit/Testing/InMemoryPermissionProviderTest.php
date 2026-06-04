<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Testing;

use Glueful\Testing\InMemoryPermissionProvider;
use Glueful\Interfaces\Permission\PermissionStandards;
use PHPUnit\Framework\TestCase;

final class InMemoryPermissionProviderTest extends TestCase
{
    public function test_grants_are_user_scoped(): void
    {
        $p = new InMemoryPermissionProvider(['u1' => ['blog.publish']]);
        self::assertTrue($p->can('u1', 'blog.publish', 'system'));
        self::assertFalse($p->can('u1', 'blog.delete', 'system'));
        // Regression: a different user does NOT inherit u1's grants.
        self::assertFalse($p->can('u2', 'blog.publish', 'system'));
    }

    public function test_wildcard_grants_everything_to_that_user_only(): void
    {
        $p = new InMemoryPermissionProvider(['u1' => ['*']]);
        self::assertTrue($p->can('u1', 'anything.at.all', 'system'));
        self::assertFalse($p->can('u2', 'anything.at.all', 'system'));
    }

    public function test_get_user_permissions_returns_resource_keyed_shape(): void
    {
        $p = new InMemoryPermissionProvider(['u1' => ['blog.publish', '*']]);
        self::assertSame(['system' => ['blog.publish']], $p->getUserPermissions('u1'));
        self::assertSame([], $p->getUserPermissions('unknown'));
    }

    public function test_exposes_core_permissions_for_validation(): void
    {
        $available = (new InMemoryPermissionProvider([]))->getAvailablePermissions();
        foreach (PermissionStandards::CORE_PERMISSIONS as $core) {
            self::assertArrayHasKey($core, $available);
        }
    }

    public function test_batch_assign_then_revoke_clears_grant(): void
    {
        $p = new InMemoryPermissionProvider();
        $p->batchAssignPermissions('u1', [['permission' => 'blog.publish', 'resource' => 'system']]);
        self::assertTrue($p->can('u1', 'blog.publish', 'system'));

        $p->batchRevokePermissions('u1', [['permission' => 'blog.publish', 'resource' => 'system']]);
        self::assertFalse($p->can('u1', 'blog.publish', 'system'));
    }
}
