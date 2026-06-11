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
}
