<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\Contracts\{UserProviderInterface, IdentityClaimsProviderInterface};
use Glueful\Auth\UserIdentity;
use PHPUnit\Framework\TestCase;

final class ContractsExistTest extends TestCase
{
    public function test_user_provider_contract_shape(): void
    {
        $p = new class implements UserProviderInterface {
            public function findByUuid(string $uuid): ?UserIdentity
            {
                return $uuid === 'u1' ? new UserIdentity('u1') : null;
            }
            public function findByLogin(string $identifier): ?UserIdentity
            {
                return null;
            }
            public function verifyCredentials(string $identifier, string $password): ?UserIdentity
            {
                return null;
            }
        };
        self::assertInstanceOf(UserIdentity::class, $p->findByUuid('u1'));
        self::assertNull($p->findByUuid('nope'));
    }

    public function test_claims_provider_contract_shape(): void
    {
        $c = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $identity): UserIdentity
            {
                return $identity->withClaims(['roles' => ['role.admin']]);
            }
        };
        self::assertSame(['role.admin'], $c->enrich(new UserIdentity('u1'))->roles());
    }
}
