<?php

declare(strict_types=1);

namespace Glueful\Routing\Middleware;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adapts an HttpOnly session cookie into the `Authorization` header that {@see AuthMiddleware}
 * already understands, so browser clients authenticate through the SAME path as API clients
 * without the bearer extraction being modified.
 *
 * Runs BEFORE `auth`. It authenticates nothing itself; it decides which credential the request
 * is making, and records that decision in the `auth_transport` request attribute. Callers need
 * that provenance because cookie-authenticated unsafe requests are CSRF-exposed while bearer
 * requests are not — a distinction that is lost if a cookie is silently treated as a bearer.
 *
 * Identity attributes stay `auth`'s job: AuthMiddleware populates `user` and `auth.user`
 * immediately afterwards, and a second identity written here would be a divergent source.
 *
 * Deliberately does NOT refresh: the refresh cookie is path-scoped to the session routes and is
 * not sent on ordinary requests, so a transparent refresh here is impossible by construction.
 * Clients call the refresh endpoint explicitly.
 *
 * Params: `optional` — an invalid or expired cookie degrades to anonymous instead of being
 * passed on for rejection, so public pages survive a lapsed session.
 */
final class SessionCookieMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly SessionCookieConfig $config,
        private readonly AuthenticationService $auth,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        if (!$this->config->enabled) {
            return $next($request);
        }

        $cookieValue = $request->cookies->get($this->config->accessName);
        $cookie = is_string($cookieValue) ? $cookieValue : '';
        $bearer = $this->bearerToken($request);

        if ($bearer !== null && $cookie !== '') {
            if (!$this->sameIdentity($bearer, $cookie)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Conflicting credentials.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Both credentials, one identity: the explicit bearer wins, so a request that
            // proved possession of an API credential keeps bearer's CSRF exemption.
            $request->attributes->set('auth_transport', 'bearer');

            return $next($request);
        }

        if ($bearer !== null) {
            $request->attributes->set('auth_transport', 'bearer');

            return $next($request);
        }

        if ($cookie === '') {
            return $next($request);
        }

        if (in_array('optional', $params, true) && $this->identity($cookie) === null) {
            return $next($request);
        }

        $request->headers->set('Authorization', 'Bearer ' . $cookie);
        $request->attributes->set('auth_transport', 'cookie');

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (!is_string($header) || preg_match('/Bearer\s+(.+)/i', $header, $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }

    private function sameIdentity(string $bearer, string $cookie): bool
    {
        $bearerIdentity = $this->identity($bearer);
        $cookieIdentity = $this->identity($cookie);

        return $bearerIdentity !== null && $cookieIdentity !== null && $bearerIdentity === $cookieIdentity;
    }

    private function identity(string $token): ?string
    {
        $session = $this->auth->validateAccessToken($token, $this->context);
        if ($session === null) {
            return null;
        }

        $user = $session['user'] ?? null;
        $uuid = is_array($user) ? ($user['uuid'] ?? null) : ($session['uuid'] ?? null);

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
