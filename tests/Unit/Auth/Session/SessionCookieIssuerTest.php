<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\Session\AuthenticatedSession;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Auth\Session\SessionCookieIssuer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class SessionCookieIssuerTest extends TestCase
{
    private function config(): SessionCookieConfig
    {
        return new SessionCookieConfig(
            enabled: true,
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

    private function session(): AuthenticatedSession
    {
        return AuthenticatedSession::fromSessionArray([
            'access_token' => 'access-abc',
            'refresh_token' => 'refresh-xyz',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'user' => ['uuid' => 'user00000001'],
        ]);
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

    public function testIssueSetsBothCookiesWithTheMandatedAttributes(): void
    {
        $response = (new SessionCookieIssuer($this->config()))->issue(new Response(), $this->session());
        $cookies = $this->cookies($response);

        self::assertArrayHasKey('gf_session', $cookies);
        self::assertArrayHasKey('gf_refresh', $cookies);

        foreach ($cookies as $cookie) {
            self::assertTrue($cookie->isHttpOnly(), 'session cookies must be HttpOnly');
            self::assertTrue($cookie->isSecure(), 'session cookies must be Secure');
            self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
            self::assertNull($cookie->getDomain(), 'no domain by default — host-only cookies');
        }
    }

    public function testTheAccessCookieCarriesTheAccessTokenAtRootPath(): void
    {
        $response = (new SessionCookieIssuer($this->config()))->issue(new Response(), $this->session());
        $access = $this->cookies($response)['gf_session'];

        self::assertSame('access-abc', $access->getValue());
        self::assertSame('/', $access->getPath());
    }

    public function testTheRefreshCookieIsPathScopedSoItIsNotSentOnOrdinaryRequests(): void
    {
        // The narrow path is the whole point: the refresh credential travels only to the
        // session routes, so it is never attached to an ordinary page or API request.
        $response = (new SessionCookieIssuer($this->config()))->issue(new Response(), $this->session());
        $refresh = $this->cookies($response)['gf_refresh'];

        self::assertSame('refresh-xyz', $refresh->getValue());
        self::assertSame('/auth/session', $refresh->getPath());
    }

    public function testAccessCookieExpiryFollowsTheSessionTtl(): void
    {
        $before = time();
        $response = (new SessionCookieIssuer($this->config()))->issue(new Response(), $this->session());
        $access = $this->cookies($response)['gf_session'];

        self::assertGreaterThanOrEqual($before + 3600, $access->getExpiresTime());
        self::assertLessThanOrEqual(time() + 3600, $access->getExpiresTime());
    }

    public function testClearExpiresBothCookiesOnTheirOwnPaths(): void
    {
        // A clear on the wrong path leaves the cookie alive in the browser — logout would
        // appear to succeed and the next request would still carry a credential.
        $response = (new SessionCookieIssuer($this->config()))->clear(new Response());
        $cookies = $this->cookies($response);

        self::assertSame('/', $cookies['gf_session']->getPath());
        self::assertSame('/auth/session', $cookies['gf_refresh']->getPath());
        foreach ($cookies as $cookie) {
            self::assertLessThan(time(), $cookie->getExpiresTime(), 'cleared cookies must be expired');
        }
    }

    public function testHostConfigurableNamesAreHonoured(): void
    {
        $config = new SessionCookieConfig(
            enabled: true,
            accessName: 'app_a_session',
            refreshName: 'app_a_refresh',
            refreshTtl: 600,
            path: '/',
            refreshPath: '/auth/session',
            domain: null,
            secure: true,
            sameSite: Cookie::SAMESITE_LAX,
        );

        $cookies = $this->cookies((new SessionCookieIssuer($config))->issue(new Response(), $this->session()));

        self::assertArrayHasKey('app_a_session', $cookies);
        self::assertArrayHasKey('app_a_refresh', $cookies);
    }

    public function testIssueAcceptsOnlyACompletedSession(): void
    {
        // Structural guarantee: there is no overload taking an array or a LoginOutcome, so
        // "issue cookies for a login still awaiting two-factor verification" is unwritable.
        $parameter = (new \ReflectionMethod(SessionCookieIssuer::class, 'issue'))->getParameters()[1];

        self::assertSame(AuthenticatedSession::class, (string) $parameter->getType());
    }
}
