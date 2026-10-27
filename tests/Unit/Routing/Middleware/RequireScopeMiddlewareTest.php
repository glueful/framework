<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Auth\ApiKey\Exceptions\InsufficientScopeException;
use Glueful\Auth\UserIdentity;
use Glueful\Routing\Middleware\RequireScopeMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireScopeMiddlewareTest extends TestCase
{
    /**
     * @param array<int, array<int, string>> $scopeConfig
     * @param array<int, string>|null $grantedScopes  null = the api_key_scopes attribute is absent
     */
    private function dispatch(array $scopeConfig, ?array $grantedScopes): Response
    {
        $request = Request::create('/x');
        $route = new class ($scopeConfig) {
            /** @param array<int, array<int, string>> $cfg */
            public function __construct(private array $cfg)
            {
            }
            /** @return array<int, array<int, string>> */
            public function getRequireScopeConfig(): array
            {
                return $this->cfg;
            }
        };
        $request->attributes->set('_route', $route);
        if ($grantedScopes !== null) {
            $request->attributes->set('api_key_scopes', $grantedScopes);
        }

        return (new RequireScopeMiddleware())->handle($request, static fn(Request $r): Response => new Response('OK'));
    }

    /**
     * The bypass: a scoped route with NO api_key_scopes attribute (e.g. a JWT request,
     * which never sets it) must be denied -- not treated as "no scopes = full access".
     */
    public function test_scoped_route_denies_when_scopes_attribute_absent(): void
    {
        $this->expectException(InsufficientScopeException::class);
        $this->dispatch([['admin:posts']], null);
    }

    /**
     * An API key that explicitly carries an empty scope set is unrestricted (the attribute
     * is present but empty) -- it should still pass.
     */
    public function test_scoped_route_allows_unrestricted_key_with_present_empty_scopes(): void
    {
        $response = $this->dispatch([['admin:posts']], []);
        self::assertSame('OK', $response->getContent());
    }

    public function test_scoped_route_allows_matching_scope(): void
    {
        $response = $this->dispatch([['admin:posts']], ['admin:posts']);
        self::assertSame('OK', $response->getContent());
    }

    public function test_scoped_route_denies_insufficient_scope(): void
    {
        $this->expectException(InsufficientScopeException::class);
        $this->dispatch([['admin:posts']], ['read:posts']);
    }

    public function test_unscoped_route_always_passes(): void
    {
        $response = $this->dispatch([], null);
        self::assertSame('OK', $response->getContent());
    }

    /**
     * Dispatch a file-defined route (EMPTY #[RequireScope] attribute config) that declares its
     * required scope(s) as middleware params, so the middleware falls back to the params.
     *
     * @param array<int, string>|null $grantedScopes  null = the api_key_scopes attribute is absent
     */
    private function dispatchWithParams(?array $grantedScopes, string ...$params): Response
    {
        $request = Request::create('/x');
        $route = new class {
            /** @return array<int, array<int, string>> */
            public function getRequireScopeConfig(): array
            {
                return [];
            }
        };
        $request->attributes->set('_route', $route);
        if ($grantedScopes !== null) {
            $request->attributes->set('api_key_scopes', $grantedScopes);
        }

        return (new RequireScopeMiddleware())
            ->handle($request, static fn(Request $r): Response => new Response('OK'), ...$params);
    }

    public function test_param_scope_denies_when_scopes_attribute_absent(): void
    {
        // File-defined route requires a scope via param, but the request carries no scopes -> deny.
        $this->expectException(InsufficientScopeException::class);
        $this->dispatchWithParams(null, 'read:content');
    }

    public function test_param_scope_allows_matching_grant(): void
    {
        $response = $this->dispatchWithParams(['read:content'], 'read:content');
        self::assertSame('OK', $response->getContent());
    }

    public function test_param_scope_denies_insufficient_grant(): void
    {
        $this->expectException(InsufficientScopeException::class);
        $this->dispatchWithParams(['read:other'], 'read:content');
    }

    /**
     * Dispatch a JWT-authenticated request: AuthMiddleware sets `auth.user` (a UserIdentity) but
     * never `api_key_scopes`.
     *
     * @param array<int, array<int, string>> $scopeConfig
     * @param array<int, string> $identityScopes
     */
    private function dispatchJwt(array $scopeConfig, array $identityScopes, string ...$params): Response
    {
        $request = Request::create('/x');
        $route = new class ($scopeConfig) {
            /** @param array<int, array<int, string>> $cfg */
            public function __construct(private array $cfg)
            {
            }
            /** @return array<int, array<int, string>> */
            public function getRequireScopeConfig(): array
            {
                return $this->cfg;
            }
        };
        $request->attributes->set('_route', $route);
        $request->attributes->set('auth.user', new UserIdentity('user00000001', [], $identityScopes));

        return (new RequireScopeMiddleware())
            ->handle($request, static fn(Request $r): Response => new Response('OK'), ...$params);
    }

    public function test_jwt_identity_with_required_scope_passes(): void
    {
        $response = $this->dispatchJwt([['write:posts']], ['read:posts', 'write:posts']);
        self::assertSame('OK', $response->getContent());
    }

    public function test_jwt_identity_any_of_within_one_attribute(): void
    {
        $response = $this->dispatchJwt([['admin:posts', 'write:posts']], ['write:posts']);
        self::assertSame('OK', $response->getContent());
    }

    public function test_jwt_identity_all_of_across_stacked_attributes_denies_when_one_missing(): void
    {
        $this->expectException(InsufficientScopeException::class);
        $this->dispatchJwt([['read:posts'], ['write:posts']], ['read:posts']);
    }

    public function test_jwt_identity_all_of_across_stacked_attributes_passes_when_all_held(): void
    {
        $response = $this->dispatchJwt([['read:posts'], ['write:posts']], ['read:posts', 'write:posts']);
        self::assertSame('OK', $response->getContent());
    }

    public function test_jwt_identity_wildcard_scope_uses_the_api_key_matcher(): void
    {
        $response = $this->dispatchJwt([['write:posts']], ['write:*']);
        self::assertSame('OK', $response->getContent());
    }

    public function test_jwt_identity_with_insufficient_scope_is_denied(): void
    {
        $this->expectException(InsufficientScopeException::class);
        $this->dispatchJwt([['write:posts']], ['read:posts']);
    }

    /**
     * Unlike an API key, an empty JWT scope set is NOT "unrestricted": it grants nothing.
     */
    public function test_jwt_identity_without_scopes_is_denied(): void
    {
        $this->expectException(InsufficientScopeException::class);
        $this->dispatchJwt([['write:posts']], []);
    }

    public function test_jwt_identity_param_scope_passes_with_matching_grant(): void
    {
        $response = $this->dispatchJwt([], ['read:content'], 'read:content');
        self::assertSame('OK', $response->getContent());
    }

    /**
     * API-key requests also carry a synthesised auth.user; api_key_scopes stays authoritative.
     */
    public function test_api_key_scopes_take_precedence_over_identity_scopes(): void
    {
        $request = Request::create('/x');
        $request->attributes->set('auth.user', new UserIdentity('user00000001', [], ['write:posts']));
        $request->attributes->set('api_key_scopes', ['read:posts']);

        $this->expectException(InsufficientScopeException::class);
        (new RequireScopeMiddleware())
            ->handle($request, static fn(Request $r): Response => new Response('OK'), 'write:posts');
    }
}
