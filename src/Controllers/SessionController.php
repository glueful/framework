<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Auth\AuthenticationService;
use Glueful\Auth\Session\AuthenticatedSession;
use Glueful\Auth\Session\SameOriginGuard;
use Glueful\Auth\Session\SessionCookieConfig;
use Glueful\Auth\Session\SessionCookieIssuer;
use Glueful\Auth\Session\SessionLogout;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse as ApiResponseAttribute;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie-transport session endpoints.
 *
 * Separate from AuthController because these speak only cookies: they read no credential from
 * the request body and return none in the response body. The bearer equivalents
 * (`/auth/refresh-token`, `/auth/logout`) are untouched and remain the API client's path.
 */
final class SessionController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly SessionCookieIssuer $issuer,
        private readonly SessionCookieConfig $config,
        private readonly SameOriginGuard $origin,
        private readonly SessionLogout $sessionLogout,
    ) {
    }

    #[ApiOperation(
        summary: 'Refresh the browser session',
        description: 'Rotates the session cookie pair using the path-scoped refresh cookie. '
            . 'Returns no tokens. Same-origin requests only.',
        tags: ['Authentication'],
    )]
    #[ApiResponseAttribute(200, description: 'Session refreshed')]
    #[ApiResponseAttribute(401, description: 'Missing or expired refresh cookie')]
    #[ApiResponseAttribute(403, description: 'Request is not same-origin')]
    public function refresh(Request $request): Response
    {
        if (!$this->config->enabled) {
            return $this->disabled();
        }

        if (!$this->origin->isSameOrigin($request)) {
            return new JsonResponse(['success' => false, 'message' => 'Request rejected.'], 403);
        }

        // The cookie is the ONLY accepted source — never a body field.
        $cookie = $request->cookies->get($this->config->refreshName);
        $refreshToken = is_string($cookie) ? $cookie : '';
        if ($refreshToken === '') {
            return new JsonResponse(['success' => false, 'message' => 'Session expired.'], 401);
        }

        $rotated = $this->auth->refreshTokens($refreshToken);
        if ($rotated === null) {
            // A dead refresh credential leaves nothing worth keeping in the browser.
            return $this->issuer->clear(
                new JsonResponse(['success' => false, 'message' => 'Session expired.'], 401)
            );
        }

        return $this->issuer->issue(
            new JsonResponse(['success' => true, 'message' => 'Session refreshed'], 200),
            AuthenticatedSession::fromSessionArray($rotated),
        );
    }

    #[ApiOperation(
        summary: 'End the browser session',
        description: 'Revokes the server-side session and expires both session cookies. '
            . 'Cookie-only; bearer clients use POST /auth/logout. Same-origin requests only.',
        tags: ['Authentication'],
    )]
    #[ApiResponseAttribute(200, description: 'Logged out')]
    #[ApiResponseAttribute(403, description: 'Request is not same-origin')]
    #[ApiResponseAttribute(500, description: 'Cookies cleared but server-side revocation failed')]
    public function logout(Request $request): Response
    {
        if (!$this->config->enabled) {
            return $this->disabled();
        }

        if (!$this->origin->isSameOrigin($request)) {
            return new JsonResponse(['success' => false, 'message' => 'Request rejected.'], 403);
        }

        $result = $this->sessionLogout->logout(
            $request,
            new JsonResponse(['success' => true, 'message' => 'Logged out successfully'], 200)
        );

        if (!$result->revoked) {
            // Cookies are cleared, but the server session may still be live and a copied token
            // still usable — that is not a successful logout and must not be reported as one.
            error_log('Session logout: server-side revocation failed; cookies cleared.');

            return $this->issuer->clear(
                new JsonResponse(['success' => false, 'message' => 'Logout incomplete.'], 500)
            );
        }

        return $result->response;
    }

    /**
     * The transport is off: behave as though these routes do not exist. The route file also
     * refuses to register them while disabled; this is the second layer, and it fails closed
     * BEFORE any credential is read, so a disabled install does no authentication work here.
     */
    private function disabled(): Response
    {
        return new JsonResponse(['success' => false, 'message' => 'Not found.'], 404);
    }
}
