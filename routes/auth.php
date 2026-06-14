<?php

/**
 * Auth routes - loaded via RouteManifest::requireRouteFile()
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Routing\Router;
use Glueful\Controllers\AuthController;

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

    $router->post('/refresh-permissions', [AuthController::class, 'refreshPermissions'])
        ->middleware(['auth']);
});

$router->get('/csrf-token', [AuthController::class, 'csrfToken']);
