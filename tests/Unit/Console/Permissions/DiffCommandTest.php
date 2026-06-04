<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Permissions;

use Glueful\Console\Commands\Permissions\DiffCommand;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests DiffCommand::classify() — the pure drift-classification core — without a container.
 */
final class DiffCommandTest extends TestCase
{
    public function test_classifies_permission_and_role_drift(): void
    {
        $sections = DiffCommand::classify(
            declaredPerms: ['blog.publish', 'blog.orphan'],
            enforcedPerms: ['blog.publish', 'blog.ghost'],     // ghost = enforced, undeclared
            declaredRoles: ['blog.editor'],
            enforcedRoles: ['admin'],                          // admin = enforced, undeclared role
            managedPerms: ['blog.publish' => 'vendor/blog', 'blog.stale' => 'vendor/blog'],
            persistedAllPerms: ['blog.publish' => '', 'blog.stale' => '', 'adhoc.keep' => ''],
            managedRoles: ['blog.editor' => 'vendor/blog', 'blog.staleRole' => 'vendor/blog'],
        );

        // Permissions
        self::assertSame(['blog.ghost'], $sections['perm_enforced_undeclared']);
        self::assertSame(['blog.orphan'], $sections['perm_declared_unenforced']);
        self::assertSame(['blog.stale'], $sections['perm_stale_managed']);
        self::assertSame(['adhoc.keep'], $sections['perm_unmanaged_persisted']); // hand-created, informational

        // Roles — enforced 'admin' canonicalizes to 'role.admin'; declared 'blog.editor' stays dotted.
        self::assertSame(['role.admin'], $sections['role_enforced_undeclared']);
        self::assertSame(['blog.editor'], $sections['role_declared_unenforced']);
        self::assertSame(['blog.staleRole'], $sections['role_stale_managed']);
    }

    public function test_canonical_role_match_is_not_drift(): void
    {
        // Declared bare 'admin' and enforced 'admin' share canonical form → no drift either way.
        $sections = DiffCommand::classify(
            declaredPerms: [],
            enforcedPerms: [],
            declaredRoles: ['admin'],
            enforcedRoles: ['admin'],
            managedPerms: [],
            persistedAllPerms: [],
            managedRoles: [],
        );

        self::assertSame([], $sections['role_enforced_undeclared']);
        self::assertSame([], $sections['role_declared_unenforced']);
    }
}
