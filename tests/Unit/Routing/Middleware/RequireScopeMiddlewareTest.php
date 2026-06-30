<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing\Middleware;

use Glueful\Auth\ApiKey\Exceptions\InsufficientScopeException;
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
}
