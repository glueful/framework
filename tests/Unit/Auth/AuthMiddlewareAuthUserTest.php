<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AuthMiddleware synthesises a basic auth.user {@see UserIdentity} from the authenticated user
 * array, so auth.user is never null after auth even when the optional enricher is not applied.
 */
final class AuthMiddlewareAuthUserTest extends TestCase
{
    /**
     * @param array<string, mixed> $user
     */
    private function build(array $user): ?UserIdentity
    {
        $middleware = new AuthMiddleware(context: ApplicationContext::forTesting(dirname(__DIR__, 3)));
        $method = new ReflectionMethod($middleware, 'identityFromUserArray');
        $method->setAccessible(true);

        $identity = $method->invoke($middleware, $user);

        return $identity instanceof UserIdentity ? $identity : null;
    }

    public function test_builds_identity_from_user_array(): void
    {
        $identity = $this->build([
            'uuid' => 'u-123',
            'email' => 'amy@example.test',
            'username' => 'amy',
            'status' => 'active',
            'roles' => ['admin', 'editor'],
            'claims' => ['scopes' => ['read', 'write']],
            'permissions' => ['posts.edit'],
        ]);

        self::assertInstanceOf(UserIdentity::class, $identity);
        self::assertSame('u-123', $identity->uuid());
        self::assertSame('amy@example.test', $identity->email());
        self::assertSame('amy', $identity->username());
        self::assertSame('active', $identity->status());
        self::assertSame(['admin', 'editor'], $identity->roles());
        self::assertSame(['read', 'write'], $identity->scopes());
    }

    /**
     * OAuth-style tokens carry `scope` as one space-delimited string (RFC 6749 §3.3). Only arrays
     * were read, so such a token's scopes were dropped and every #[RequireScope] route denied it.
     */
    public function test_space_delimited_scope_string_becomes_a_scope_list(): void
    {
        $identity = $this->build([
            'uuid' => 'u-1',
            'claims' => ['scope' => 'read:posts  write:posts write:posts'],
        ]);

        self::assertNotNull($identity);
        self::assertSame(['read:posts', 'write:posts'], $identity->scopes());
    }

    public function test_blank_scope_string_yields_no_scopes(): void
    {
        $identity = $this->build(['uuid' => 'u-1', 'claims' => ['scope' => '   ']]);

        self::assertNotNull($identity);
        self::assertSame([], $identity->scopes());
    }

    public function test_returns_null_without_a_uuid(): void
    {
        self::assertNull($this->build(['email' => 'no@uuid.test']));
    }

    public function test_tolerates_minimal_array(): void
    {
        $identity = $this->build(['uuid' => 'u-9']);

        self::assertInstanceOf(UserIdentity::class, $identity);
        self::assertSame('u-9', $identity->uuid());
        self::assertSame([], $identity->roles());
        self::assertNull($identity->email());
    }
}
