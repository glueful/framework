<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\UserIdentity;
use PHPUnit\Framework\TestCase;

final class UserIdentityTest extends TestCase
{
    public function test_legacy_4arg_constructor_still_works(): void
    {
        $id = new UserIdentity('u1', ['role.editor'], ['read'], ['k' => 'v']);

        self::assertSame('u1', $id->id());      // legacy accessor
        self::assertSame('u1', $id->uuid());    // new accessor
        self::assertSame(['role.editor'], $id->roles());
        self::assertSame(['read'], $id->scopes());
        self::assertSame('v', $id->attr('k'));  // legacy bag accessor
        self::assertSame('v', $id->claim('k')); // new claim accessor
    }

    public function test_identity_facts_and_runtime_fields(): void
    {
        $id = new UserIdentity('u1', email: 'a@b.test', username: 'amy', status: 'active');
        self::assertSame('a@b.test', $id->email());
        self::assertSame('amy', $id->username());
        self::assertSame('active', $id->status());
        self::assertNull($id->sessionUuid());
        self::assertNull($id->provider());
    }

    public function test_roles_and_scopes_are_backed_by_claims_bag(): void
    {
        $id = new UserIdentity('u1', ['role.a'], ['s1']);
        self::assertSame(['role.a'], $id->claim('roles'));
        self::assertSame(['s1'], $id->claim('scopes'));
    }

    public function test_with_claims_is_immutable_and_preserves_identity_facts(): void
    {
        $id = new UserIdentity('u1', email: 'a@b.test', status: 'active');
        $enriched = $id->withClaims(['roles' => ['role.admin'], 'x' => 1]);

        self::assertSame([], $id->roles());
        self::assertSame(['role.admin'], $enriched->roles());
        self::assertSame(1, $enriched->claim('x'));
        self::assertSame('a@b.test', $enriched->email());
        self::assertSame('active', $enriched->status());
        self::assertNotSame($id, $enriched);
    }

    public function test_with_session_sets_runtime_fields_immutably(): void
    {
        $id = new UserIdentity('u1');
        $live = $id->withSession('sess-1', 'jwt');
        self::assertNull($id->sessionUuid());
        self::assertSame('sess-1', $live->sessionUuid());
        self::assertSame('jwt', $live->provider());
    }
}
