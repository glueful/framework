<?php

/**
 * Auth routes - loaded via RouteManifest::requireRouteFile()
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Routing\Router;
use Glueful\Controllers\AuthController;
use Glueful\Controllers\SessionController;

// Auth routes
$router->group(['prefix' => '/auth'], function (Router $router) {
    $router->post('/login', [AuthController::class, 'login'])
        ->middleware('rate_limit:5,60'); // 5 attempts per minute

    // Account-lifecycle routes (verify-email, verify-otp, resend-otp, forgot-password,
    // reset-password) moved to the glueful/users extension (AccountController).

    $router->post('/validate-token', [AuthController::class, 'validateToken'])
        ->middleware(['auth']);

    $router->post('/refresh-token', [AuthController::class, 'refreshToken']);

    $router->post('/logout', [AuthController::class, 'logout'])
        ->middleware(['auth']);

    // Cookie-transport session routes. The refresh cookie is path-scoped to `/auth/session`,
    // so it is sent here and nowhere else. Registered ONLY when the transport is enabled:
    // "off by default" has to mean the endpoints are absent, not merely that they decline —
    // with default cookie names they would otherwise stay fully operational while disabled.
    if ((bool) config($router->getContext(), 'auth.session_cookie.enabled', false)) {
        $router->group(['prefix' => '/session'], function (Router $router) {
            $router->post('/refresh', [SessionController::class, 'refresh'])
                ->middleware('rate_limit:30,60');
            $router->post('/logout', [SessionController::class, 'logout']);
        });
    }

    $router->post('/refresh-permissions', [AuthController::class, 'refreshPermissions'])
        ->middleware(['auth']);
});

$router->get('/csrf-token', [AuthController::class, 'csrfToken']);
