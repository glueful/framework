<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Glueful\Auth\AuthenticationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a browser session: revokes it server-side AND clears both cookies.
 *
 * Both halves live here rather than in a controller because the guarantee is the PAIR.
 * Revocation without clearing leaves a dead cookie that still looks like a credential;
 * clearing without revocation leaves a live session that a copied token can still use.
 * Testing the two operations separately cannot catch a caller that performs only one.
 *
 * Cookies are cleared even when revocation fails — a visitor who clicked "sign out" must not
 * be left holding a working credential — but the failure is REPORTED rather than swallowed,
 * because a live server session after a logout is a security-relevant outcome, not a detail.
 *
 * Cookie-only by design: bearer clients use the existing POST /auth/logout. Reading either
 * credential here would mean silently choosing one when both are present.
 */
final class SessionLogout
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly SessionCookieIssuer $issuer,
        private readonly SessionCookieConfig $config,
    ) {
    }

    public function logout(Request $request, Response $response): SessionLogoutResult
    {
        $cookie = $request->cookies->get($this->config->accessName);
        $token = is_string($cookie) ? $cookie : '';

        // No cookie means there was no session of ours to revoke — not a failure.
        $revoked = $token === '' ? true : $this->auth->terminateSession($token);

        return new SessionLogoutResult($revoked, $this->issuer->clear($response));
    }
}
