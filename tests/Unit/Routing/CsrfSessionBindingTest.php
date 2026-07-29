<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Http\Exceptions\Domain\SecurityException;
use Glueful\Routing\Middleware\CSRFMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF tokens must be bound to the SESSION, not to a request fingerprint.
 *
 * Before this binding existed, `getSessionId()` looked only for `user['session_id']` — a key
 * no provider emits (JWT returns `sid` / `session_uuid`) — so every authenticated request fell
 * through to fingerprinting on IP + User-Agent. Two visitors behind one NAT using the same
 * browser therefore shared a CSRF identity, which is exactly what CSRF protection must prevent.
 */
final class CsrfSessionBindingTest extends TestCase
{
    /** A POST that would require a valid CSRF token. */
    private function unsafeRequest(?string $token = null): Request
    {
        $request = Request::create('/account/profile', 'POST');
        $request->server->set('REMOTE_ADDR', '203.0.113.10');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (shared browser)');
        if ($token !== null) {
            $request->headers->set('X-CSRF-Token', $token);
        }

        return $request;
    }

    private function issuingRequest(): Request
    {
        $request = Request::create('/account', 'GET');
        $request->server->set('REMOTE_ADDR', '203.0.113.10');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (shared browser)');

        return $request;
    }

    /**
     * A middleware with a real in-memory token store. Without one, generateToken() has nowhere
     * to persist and EVERY validation fails — which would make the cross-session test pass for
     * the wrong reason.
     */
    private function middleware(): CSRFMiddleware
    {
        return new CSRFMiddleware(cache: new ArrayCacheDriver());
    }

    /** Runs the middleware with double-submit and origin checks off, isolating token binding. */
    private function passes(CSRFMiddleware $csrf, Request $request): bool
    {
        try {
            $response = $csrf->handle(
                $request,
                static fn (): Response => new Response('next'),
                3600,
                false,
                false,
            );
        } catch (SecurityException) {
            return false;
        }

        return $response instanceof Response && $response->getContent() === 'next';
    }

    public function testTokensBindToTheSessionUuidWhenAuthenticated(): void
    {
        $csrf = $this->middleware();

        $issuing = $this->issuingRequest();
        $issuing->attributes->set('user', ['uuid' => 'u1', 'sid' => 'session-A']);
        $token = $csrf->generateToken($issuing);

        // Same session, a later request: the token must still validate.
        $later = $this->unsafeRequest($token);
        $later->attributes->set('user', ['uuid' => 'u1', 'sid' => 'session-A']);

        self::assertTrue($this->passes($csrf, $later));
    }

    public function testADifferentSessionCannotReuseTheToken(): void
    {
        // Identical fingerprints (same IP, same User-Agent), different sessions. Under
        // fingerprint binding this passes — that is the hole this task closes.
        $csrf = $this->middleware();

        $issuing = $this->issuingRequest();
        $issuing->attributes->set('user', ['uuid' => 'u1', 'sid' => 'session-A']);
        $token = $csrf->generateToken($issuing);

        $other = $this->unsafeRequest($token);
        $other->attributes->set('user', ['uuid' => 'u2', 'sid' => 'session-B']);

        self::assertFalse($this->passes($csrf, $other));
    }

    public function testSessionUuidIsAcceptedAsWellAsSid(): void
    {
        $csrf = $this->middleware();

        $issuing = $this->issuingRequest();
        $issuing->attributes->set('user', ['uuid' => 'u1', 'session_uuid' => 'session-C']);
        $token = $csrf->generateToken($issuing);

        $later = $this->unsafeRequest($token);
        $later->attributes->set('user', ['uuid' => 'u1', 'sid' => 'session-C']);

        self::assertTrue($this->passes($csrf, $later), 'sid and session_uuid name one thing');
    }

    public function testAnExplicitSessionIdCanBeBoundBeforeIdentityIsAttached(): void
    {
        // Login shaping runs before any authenticated identity is on the request, so it must
        // be able to bind the token to the session it just issued.
        $csrf = $this->middleware();

        $token = $csrf->generateTokenForSession($this->issuingRequest(), 'session-D');

        $later = $this->unsafeRequest($token);
        $later->attributes->set('user', ['uuid' => 'u1', 'sid' => 'session-D']);

        self::assertTrue($this->passes($csrf, $later));
    }

    public function testAnonymousRequestsStillFallBackToFingerprinting(): void
    {
        // Unauthenticated forms must keep working exactly as before.
        $csrf = $this->middleware();
        $token = $csrf->generateToken($this->issuingRequest());

        self::assertTrue($this->passes($csrf, $this->unsafeRequest($token)));
    }
}
