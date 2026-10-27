<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Session\AuthenticatedSession;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\Auth\LoginResponseBuildingEvent;
use Glueful\Events\Auth\LoginResponseBuiltEvent;
use Glueful\Events\EventService;
use Glueful\Http\Response;
use Glueful\Routing\Middleware\CSRFMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shapes the successful-login response: adds a CSRF token (if enabled) and
 * dispatches the LoginResponseBuilding / LoginResponseBuilt events.
 *
 * Used by both the normal AuthController::login path and the /2fa/verify
 * login-purpose path so the on-the-wire response is identical regardless of
 * which path the user took. Mirrors the logic previously inline in
 * AuthController::login (CSRF block + event-dispatch block).
 */
final class LoginResponseShaper
{
    public function __construct(private ApplicationContext $context)
    {
    }

    /**
     * @param array<string, mixed> $session The OIDC session payload from
     *                                       issueSession() / createUserSession()
     */
    public function shape(Request $request, array $session): Response
    {
        $session = $this->withCsrfToken($request, $session);

        // Login events — same logic previously in AuthController::login.
        $tokens = [
            'access_token' => $session['access_token'] ?? null,
            'refresh_token' => $session['refresh_token'] ?? null,
            'expires_in' => $session['expires_in'] ?? null,
            'token_type' => $session['token_type'] ?? 'Bearer',
        ];
        /** @var array<string, mixed> $user */
        $user = $session['user'] ?? [];
        try {
            $events = app($this->context, EventService::class);
            $building = new LoginResponseBuildingEvent($tokens, $user, $session);
            $events->dispatch($building);
            // Read back any fields listeners added/merged into the response map
            // (setResponse()/mergeResponse()); otherwise their changes are discarded.
            $session = $building->getResponse();
            $events->dispatch(new LoginResponseBuiltEvent($session));
        } catch (\Throwable $e) {
            error_log('Login response events failed: ' . $e->getMessage());
        }

        return Response::success($session, 'Login successful');
    }

    /**
     * Cookie-transport login (`auth_transport: "cookie"`): sets the session cookies via the
     * issuer and returns a body WITHOUT the access/refresh tokens — only the user, expiry,
     * session id and a session-bound CSRF token. Accepts only an {@see AuthenticatedSession},
     * so a login still awaiting its second factor cannot reach it.
     *
     * Deliberately dispatches no LoginResponseBuilding/Built events: those shape the bearer
     * token body, and a listener there must not be able to put tokens back into this one.
     */
    public function shapeForCookies(
        Request $request,
        AuthenticatedSession $session,
        SessionCookieIssuer $issuer
    ): Response {
        $body = $session->toSessionArray();
        unset($body['access_token'], $body['refresh_token'], $body['token_type']);
        $body = $this->withCsrfToken($request, $body);

        $response = Response::success($body, 'Login successful');
        $issuer->issue($response, $session);

        return $response;
    }

    /**
     * Adds a `csrf_token` block (when CSRF protection is enabled) bound to the issued session.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function withCsrfToken(Request $request, array $session): array
    {
        // CSRF — same logic previously in AuthController::login.
        if (env('CSRF_PROTECTION_ENABLED', true) === true) {
            try {
                $csrf = $this->csrf();

                // Bind the token to the session just issued. Login runs before any
                // authenticated identity is attached to the request, so without an explicit
                // binding the token would key on the anonymous fingerprint while every later
                // authenticated request keys on the session — and the first write would fail.
                $sessionId = '';
                foreach (['sid', 'session_uuid', 'session_id'] as $key) {
                    $candidate = $session[$key] ?? null;
                    if (!is_string($candidate) || $candidate === '') {
                        $user = $session['user'] ?? null;
                        $candidate = is_array($user) ? ($user[$key] ?? null) : null;
                    }
                    if (is_string($candidate) && $candidate !== '') {
                        $sessionId = $candidate;
                        break;
                    }
                }

                $token = $sessionId !== ''
                    ? $csrf->generateTokenForSession($request, $sessionId)
                    : $csrf->generateToken($request);
                $session['csrf_token'] = [
                    'token' => $token,
                    'header' => 'X-CSRF-Token',
                    'field' => '_token',
                    'expires_at' => time() + (int) env('CSRF_TOKEN_LIFETIME', 3600),
                ];
            } catch (\Throwable $e) {
                error_log('Failed to generate CSRF token during login: ' . $e->getMessage());
            }
        }

        return $session;
    }

    /** The CSRF middleware sharing the route middleware's token store. */
    private function csrf(): CSRFMiddleware
    {
        return CSRFMiddleware::forContext($this->context);
    }
}
