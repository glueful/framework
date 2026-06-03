<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Voters;

use Glueful\Auth\UserIdentity;
use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role};
use Glueful\Permissions\{Context, Vote};
use Glueful\Permissions\Voters\RegistryRoleVoter;
use PHPUnit\Framework\TestCase;

final class RegistryRoleVoterTest extends TestCase
{
    private function registry(): PermissionRegistry
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.publish'), 'vendor/blog');
        $r->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');
        return $r;
    }

    public function test_grants_when_user_has_declared_role(): void
    {
        $voter = new RegistryRoleVoter($this->registry());
        $user = new UserIdentity('u1', ['blog.editor']);
        $vote = $voter->vote($user, 'blog.publish', null, new Context());
        self::assertSame(Vote::GRANT, $vote->result);
    }

    public function test_abstains_when_user_has_no_roles(): void
    {
        $voter = new RegistryRoleVoter($this->registry());
        $user = new UserIdentity('u1', []);
        $vote = $voter->vote($user, 'blog.publish', null, new Context());
        self::assertSame(Vote::ABSTAIN, $vote->result, 'must not fabricate role membership');
    }

    public function test_abstains_when_role_does_not_grant_permission(): void
    {
        $voter = new RegistryRoleVoter($this->registry());
        $user = new UserIdentity('u1', ['blog.editor']);
        $vote = $voter->vote($user, 'blog.delete', null, new Context());
        self::assertSame(Vote::ABSTAIN, $vote->result);
    }
}
