<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Session\SameOriginGuard;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Auth\Session\SessionLogout;
use Glueful\Controllers\SessionController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SessionRefreshTest extends TestCase
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

    private function controller(AuthenticationService $auth, bool $enabled = true): SessionController
    {
        $config = $this->config($enabled);

        return new SessionController(
            $auth,
            new SessionCookieIssuer($config),
            $config,
            new SameOriginGuard(),
            new SessionLogout($auth, new SessionCookieIssuer($config), $config),
        );
    }

    private function request(): Request
    {
        $request = Request::create('https://app.example.test/auth/session/refresh', 'POST');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');
        $request->cookies->set('gf_refresh', 'refresh-old');

        return $request;
    }

    /** @return array<string,Cookie> */
    private function cookies(Response $response): array
    {
        $byName = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $byName[$cookie->getName()] = $cookie;
        }

        return $byName;
    }

    public function testRefreshRotatesBothCookiesAndReturnsNoTokens(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::once())->method('refreshTokens')->with('refresh-old')->willReturn([
            'access_token' => 'access-new',
            'refresh_token' => 'refresh-new',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => ['uuid' => 'u1'],
        ]);

        $response = $this->controller($auth)->refresh($this->request());
        $cookies = $this->cookies($response);
        $body = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('access-new', $cookies['gf_session']->getValue());
        self::assertSame('refresh-new', $cookies['gf_refresh']->getValue());
        self::assertStringNotContainsString('access-new', $body, 'tokens must never reach the body');
        self::assertStringNotContainsString('refresh-new', $body, 'tokens must never reach the body');
    }

    public function testRefreshIsRejectedWhenNotSameOrigin(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('refreshTokens');

        $request = $this->request();
        $request->headers->set('Sec-Fetch-Site', 'cross-site');

        self::assertSame(403, $this->controller($auth)->refresh($request)->getStatusCode());
    }

    public function testRefreshWithoutTheCookieIs401AndDoesNotCallTheService(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('refreshTokens');

        $request = $this->request();
        $request->cookies->remove('gf_refresh');

        self::assertSame(401, $this->controller($auth)->refresh($request)->getStatusCode());
    }

    public function testRefreshIgnoresARefreshTokenSuppliedInTheBody(): void
    {
        // The cookie is the only accepted source; accepting a body value would hand an
        // attacker a way to drive rotation with a token lifted from another context.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('refreshTokens');

        $request = Request::create(
            'https://app.example.test/auth/session/refresh',
            'POST',
            ['refresh_token' => 'refresh-from-body'],
        );
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        self::assertSame(401, $this->controller($auth)->refresh($request)->getStatusCode());
    }

    public function testRefreshPreservesTheSessionIdentityThatCsrfTokensBindTo(): void
    {
        // CSRF tokens bind to the session uuid, and RefreshService rotates tokens while
        // preserving that uuid. So a token issued before a refresh stays valid after it;
        // forcing new CSRF state here would invalidate tokens already embedded in rendered
        // forms and buy nothing, since the binding is unchanged.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('refreshTokens')->willReturn([
            'access_token' => 'access-new',
            'refresh_token' => 'refresh-new',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => ['uuid' => 'u1', 'sid' => 'session-1'],
        ]);

        $body = (string) $this->controller($auth)->refresh($this->request())->getContent();

        self::assertStringNotContainsString('csrf', strtolower($body), 'refresh issues no new CSRF state');
    }

    public function testADisabledTransportRefusesBeforeReadingAnyCredential(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('refreshTokens');

        $response = $this->controller($auth, false)->refresh($this->request());

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $response->headers->getCookies(), 'a disabled transport emits no cookies');
    }

    public function testAnExpiredRefreshTokenClearsBothCookies(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('refreshTokens')->willReturn(null);

        $response = $this->controller($auth)->refresh($this->request());
        $cookies = $this->cookies($response);

        self::assertSame(401, $response->getStatusCode());
        foreach (['gf_session', 'gf_refresh'] as $name) {
            self::assertLessThan(time(), $cookies[$name]->getExpiresTime(), $name . ' must be expired');
        }
    }
}
