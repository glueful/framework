<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth\Session;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Auth\Session\SessionLogout;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SessionLogoutTest extends TestCase
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

    private function logout(AuthenticationService $auth): SessionLogout
    {
        $config = $this->config();

        return new SessionLogout($auth, new SessionCookieIssuer($config), $config);
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

    public function testLogoutRevokesTheSessionAndClearsBothCookiesTogether(): void
    {
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::once())->method('terminateSession')->with('access-abc')->willReturn(true);

        $request = Request::create('/auth/session/logout', 'POST');
        $request->cookies->set('gf_session', 'access-abc');

        $result = $this->logout($auth)->logout($request, new Response());
        $cookies = $this->cookies($result->response);

        self::assertTrue($result->revoked);
        self::assertLessThan(time(), $cookies['gf_session']->getExpiresTime());
        self::assertLessThan(time(), $cookies['gf_refresh']->getExpiresTime());
    }

    public function testFailedRevocationClearsCookiesButIsReportedAsAFailure(): void
    {
        // Both halves matter. Leaving a credential in the browser would let the visitor keep
        // browsing as themselves after clicking sign out; reporting success would hide that
        // the server session is still live and a copied token still works.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->method('terminateSession')->willReturn(false);

        $request = Request::create('/auth/session/logout', 'POST');
        $request->cookies->set('gf_session', 'access-abc');

        $result = $this->logout($auth)->logout($request, new Response());
        $cookies = $this->cookies($result->response);

        self::assertFalse($result->revoked);
        self::assertLessThan(time(), $cookies['gf_session']->getExpiresTime());
        self::assertLessThan(time(), $cookies['gf_refresh']->getExpiresTime());
    }

    public function testLogoutWithoutACookieClearsAndCountsAsRevoked(): void
    {
        // Nothing to revoke is not a failure — there was no session of ours to begin with.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('terminateSession');

        $result = $this->logout($auth)->logout(Request::create('/auth/session/logout', 'POST'), new Response());

        self::assertTrue($result->revoked);
        self::assertCount(2, $this->cookies($result->response));
    }

    public function testABearerCredentialIsIgnoredBecauseThisEndpointIsCookieOnly(): void
    {
        // Bearer clients use POST /auth/logout. Honouring both here would mean silently
        // choosing one when both are present.
        $auth = $this->createMock(AuthenticationService::class);
        $auth->expects(self::never())->method('terminateSession');

        $request = Request::create('/auth/session/logout', 'POST');
        $request->headers->set('Authorization', 'Bearer bearer-token');

        $result = $this->logout($auth)->logout($request, new Response());

        self::assertCount(2, $this->cookies($result->response), 'cookies are still cleared');
    }
}
