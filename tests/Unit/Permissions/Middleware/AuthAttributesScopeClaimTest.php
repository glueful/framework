<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Middleware;

use Glueful\Auth\UserIdentity;
use Glueful\Http\RequestUserContext;
use Glueful\Permissions\Middleware\AuthToRequestAttributesMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * OAuth tokens carry `scope` as ONE space-delimited string (RFC 6749 §3.3). Passing it raw
 * into UserIdentity's array parameter threw a TypeError that the enricher's catch-all
 * swallowed, so auth.user was never set from this path.
 */
final class AuthAttributesScopeClaimTest extends TestCase
{
    /** @param array<string, mixed> $claims */
    private function enrich(array $claims): Request
    {
        $ctx = $this->createMock(RequestUserContext::class);
        $ctx->method('initialize')->willReturnSelf();
        $ctx->method('getUserUuid')->willReturn('user-1');
        $ctx->method('getUserRoles')->willReturn([]);
        $ctx->method('getUser')->willReturn(new UserIdentity('user-1', email: 'u@example.test'));
        $ctx->method('getSessionData')->willReturn(['jwt_payload' => $claims]);
        $ctx->method('getUserPermissions')->willReturn([]);
        $ctx->method('getRequestMetadata')->willReturn([]);

        $request = Request::create('/x');
        (new AuthToRequestAttributesMiddleware($ctx))->enrichRequest($request);

        return $request;
    }

    public function testASpaceDelimitedScopeStringBecomesAList(): void
    {
        $identity = $this->enrich(['scope' => 'read:posts  write:posts'])->attributes->get('auth.user');

        self::assertInstanceOf(UserIdentity::class, $identity);
        self::assertSame(['read:posts', 'write:posts'], $identity->scopes());
    }

    public function testAScopesArrayIsKept(): void
    {
        $identity = $this->enrich(['scopes' => ['read:posts', 'read:posts', 7]])->attributes->get('auth.user');

        self::assertInstanceOf(UserIdentity::class, $identity);
        self::assertSame(['read:posts'], $identity->scopes());
    }

    public function testTheSharedNormaliserHandlesEveryShape(): void
    {
        self::assertSame(['a', 'b'], UserIdentity::normalizeScopes(' a b '));
        self::assertSame(['a'], UserIdentity::normalizeScopes(['a', 'a', null]));
        self::assertSame([], UserIdentity::normalizeScopes(null));
        self::assertSame([], UserIdentity::normalizeScopes(''));
    }
}
