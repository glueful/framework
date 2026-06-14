<?php

use Glueful\Routing\Router;
use Glueful\Controllers\HealthController;

/**
 * @var \Glueful\Routing\Router $router Router instance injected by RouteManifest::load()
 */

// Health check routes - organized by access level
$router->group(['prefix' => '/health'], function (Router $router) {
    $router->get('/', [HealthController::class, 'index'])
        ->middleware('rate_limit:60,60'); // 60 requests per minute - high limit for monitoring

    $router->get('/database', [HealthController::class, 'database'])
        ->middleware('rate_limit:30,60'); // 30 requests per minute

    $router->get('/cache', [HealthController::class, 'cache'])
        ->middleware('rate_limit:30,60'); // 30 requests per minute

    $router->get('/detailed', [HealthController::class, 'detailed'])
        ->middleware(['auth', 'rate_limit:10,60']); // Authenticated, 10 requests per minute

    $router->get('/middleware', [HealthController::class, 'middleware'])
        ->middleware(['auth', 'rate_limit:20,60']); // Authenticated, 20 requests per minute

    $router->get('/response-api', [HealthController::class, 'responseApi'])
        ->middleware(['auth', 'rate_limit:15,60']); // Authenticated, 15 requests per minute

    $router->get('/queue', [HealthController::class, 'queue'])
        ->middleware('rate_limit:20,60');

    // Kubernetes-conventional liveness probe — returns 200 if the process is alive.
    // Equivalent to /healthz; provided so k8s/ECS/Nomad Pod specs can use /health/live.
    $router->get('/live', [HealthController::class, 'liveness'])
        ->middleware('rate_limit:60,60');

    // Kubernetes-conventional readiness probe — returns 200 when the service can serve
    // traffic (database, cache, and config checks all pass), 503 otherwise.
    // No authentication required — k8s probes run from the cluster network.
    $router->get('/ready', [HealthController::class, 'readiness'])
        ->middleware('rate_limit:30,60');

    // Kubernetes-conventional startup probe — returns 200 once the framework has finished
    // initializing. Gives slow-booting containers a grace period before liveness kicks in.
    $router->get('/startup', [HealthController::class, 'startup'])
        ->middleware('rate_limit:60,60');
});

$router->get('/healthz', [HealthController::class, 'liveness'])
    ->middleware('rate_limit:60,60');

$router->get('/ready', [HealthController::class, 'readiness'])
    ->middleware(['rate_limit:30,60', 'allow_ip']);
