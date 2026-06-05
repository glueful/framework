<?php

declare(strict_types=1);

namespace Glueful\Auth;

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
        // CSRF — same logic previously in AuthController::login.
        if (env('CSRF_PROTECTION_ENABLED', true) === true) {
            try {
                $csrf = new CSRFMiddleware();
                $token = $csrf->generateToken($request);
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
}
