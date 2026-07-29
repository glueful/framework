<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Middleware\SessionCookieMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SessionCookieMiddlewareTest extends TestCase
{
    private function config(bool $enabled = true): SessionCookieConfig
    {
        return new SessionCookieConfig(
            enabled: $enabled,
            accessName: 'gf_session',
            refreshName: 'gf_refresh',
            refreshTtl: 2592000,
            path: '/',
            refreshPath: '/auth/session',
            domain: null,
            secure: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    /** @param array<string,array<string,mixed>> $sessionsByToken */
    private function middleware(array $sessionsByToken = [], bool $enabled = true): SessionCookieMiddleware
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('validateAccessToken')->willReturnCallback(
            static fn (string $token): ?array => $sessionsByToken[$token] ?? null
        );

        return new SessionCookieMiddleware(
            $this->config($enabled),
            $auth,
            ApplicationContext::forTesting(dirname(__DIR__, 3)),
        );
    }

    private static function echoTransport(): callable
    {
        return static fn (Request $r): Response => new Response(
            (string) $r->attributes->get('auth_transport', 'none')
            . '|' . (string) $r->headers->get('Authorization', '')
        );
    }

    public function testDisabledConfigIsAPassThrough(): void
    {
        $request = Request::create('/anything');
        $request->cookies->set('gf_session', 'access-abc');

        $response = $this->middleware(['access-abc' => ['uuid' => 'u1']], false)
            ->handle($request, self::echoTransport());

        self::assertSame('none|', $response->getContent());
    }

    public function testNoCredentialsPassesThroughUntouched(): void
    {
        $response = $this->middleware()->handle(Request::create('/anything'), self::echoTransport());

        self::assertSame('none|', $response->getContent());
    }

    public function testCookieIsInjectedAsABearerHeaderAndMarkedAsCookieTransport(): void
    {
        $request = Request::create('/account');
        $request->cookies->set('gf_session', 'access-abc');

        $response = $this->middleware(['access-abc' => ['uuid' => 'u1']])
            ->handle($request, self::echoTransport());

        self::assertSame('cookie|Bearer access-abc', $response->getContent());
    }

    public function testAnInvalidCookieIsStillPassedOnInRequiredModeSoAuthRejectsItNormally(): void
    {
        $request = Request::create('/account');
        $request->cookies->set('gf_session', 'expired');

        $response = $this->middleware([])->handle($request, self::echoTransport());

        self::assertSame('cookie|Bearer expired', $response->getContent());
    }

    public function testAnInvalidCookieDegradesToAnonymousInOptionalMode(): void
    {
        // A public, cacheable page must not 401 because a visitor's session lapsed.
        $request = Request::create('/public');
        $request->cookies->set('gf_session', 'expired');

        $response = $this->middleware([])->handle($request, self::echoTransport(), 'optional');

        self::assertSame('none|', $response->getContent());
    }

    public function testAValidCookieIsInjectedInOptionalMode(): void
    {
        $request = Request::create('/public');
        $request->cookies->set('gf_session', 'access-abc');

        $response = $this->middleware(['access-abc' => ['uuid' => 'u1']])
            ->handle($request, self::echoTransport(), 'optional');

        self::assertSame('cookie|Bearer access-abc', $response->getContent());
    }

    public function testAnExplicitBearerIsLeftAloneAndMarkedAsBearerTransport(): void
    {
        $request = Request::create('/api');
        $request->headers->set('Authorization', 'Bearer api-token');

        $response = $this->middleware(['api-token' => ['uuid' => 'u1']])
            ->handle($request, self::echoTransport());

        self::assertSame('bearer|Bearer api-token', $response->getContent());
    }

    public function testMatchingBearerAndCookieResolveToBearerTransport(): void
    {
        // Proving possession of an explicit bearer credential must not drag the request into
        // cookie CSRF obligations.
        $request = Request::create('/api');
        $request->headers->set('Authorization', 'Bearer api-token');
        $request->cookies->set('gf_session', 'access-abc');

        $response = $this->middleware([
            'api-token' => ['uuid' => 'u1'],
            'access-abc' => ['uuid' => 'u1'],
        ])->handle($request, self::echoTransport());

        self::assertSame('bearer|Bearer api-token', $response->getContent());
    }

    public function testMismatchedBearerAndCookieAreRejected(): void
    {
        $request = Request::create('/api');
        $request->headers->set('Authorization', 'Bearer api-token');
        $request->cookies->set('gf_session', 'access-abc');

        $response = $this->middleware([
            'api-token' => ['uuid' => 'u1'],
            'access-abc' => ['uuid' => 'u2'],
        ])->handle($request, static fn (): Response => new Response('next'));

        self::assertSame(401, $response->getStatusCode());
        self::assertStringNotContainsString('u1', (string) $response->getContent());
        self::assertStringNotContainsString('u2', (string) $response->getContent());
    }

    public function testAnInvalidCookieAlongsideAValidBearerIsRejected(): void
    {
        $request = Request::create('/api');
        $request->headers->set('Authorization', 'Bearer api-token');
        $request->cookies->set('gf_session', 'expired');

        $response = $this->middleware(['api-token' => ['uuid' => 'u1']])
            ->handle($request, static fn (): Response => new Response('next'));

        self::assertSame(401, $response->getStatusCode());
    }
}
