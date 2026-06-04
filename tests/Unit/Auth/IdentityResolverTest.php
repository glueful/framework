<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\Contracts\IdentityClaimsProviderInterface;
use Glueful\Auth\{IdentityResolver, UserIdentity};
use PHPUnit\Framework\TestCase;

final class IdentityResolverTest extends TestCase
{
    public function test_active_and_null_status_pass_the_gate(): void
    {
        $resolver = new IdentityResolver([]);
        self::assertInstanceOf(UserIdentity::class, $resolver->resolve(new UserIdentity('u1', status: 'active')));
        self::assertInstanceOf(UserIdentity::class, $resolver->resolve(new UserIdentity('u1', status: null)));
    }

    public function test_explicit_non_active_status_is_rejected(): void
    {
        $resolver = new IdentityResolver([]);
        self::assertNull($resolver->resolve(new UserIdentity('u1', status: 'suspended')));
        self::assertNull($resolver->resolve(new UserIdentity('u1', status: 'disabled')));
    }

    public function test_claims_providers_are_folded_in_order(): void
    {
        $a = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $i): UserIdentity
            {
                return $i->withClaims(['roles' => ['role.a']]);
            }
        };
        $b = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $i): UserIdentity
            {
                return $i->withClaims(['scopes' => ['s1']]);
            }
        };
        $resolver = new IdentityResolver([$a, $b]);
        $out = $resolver->resolve(new UserIdentity('u1', status: 'active'));
        self::assertSame(['role.a'], $out->roles());
        self::assertSame(['s1'], $out->scopes());
    }

    public function test_empty_contribution_does_not_wipe_prior_claims(): void
    {
        $sets = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $i): UserIdentity
            {
                return $i->withClaims(['roles' => ['role.a'], 'scopes' => ['s1']]);
            }
        };
        // A naive provider returns a FRESH identity whose roles/scopes default to [].
        // The union merge must NOT let those empties erase role.a / s1.
        $naive = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $i): UserIdentity
            {
                return new UserIdentity($i->uuid(), status: 'active');
            }
        };
        $resolver = new IdentityResolver([$sets, $naive]);
        $out = $resolver->resolve(new UserIdentity('u1', status: 'active'));
        self::assertSame(['role.a'], $out->roles());
        self::assertSame(['s1'], $out->scopes());
    }

    public function test_provider_cannot_overwrite_identity_facts(): void
    {
        $evil = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $i): UserIdentity
            {
                return new UserIdentity('attacker', email: 'evil@x.test', status: 'suspended');
            }
        };
        $resolver = new IdentityResolver([$evil]);
        $out = $resolver->resolve(new UserIdentity('u1', email: 'real@x.test', status: 'active'));

        self::assertNotNull($out);
        self::assertSame('u1', $out->uuid());
        self::assertSame('real@x.test', $out->email());
        self::assertSame('active', $out->status());
    }
}
