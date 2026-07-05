<?php

use Glueful\Routing\Router;
use Glueful\Controllers\ExtensionsController;

/**
 * @var \Glueful\Routing\Router $router Router instance injected by RouteManifest::load()
 *
 * In-admin extensions manager. Mounted under the API prefix (e.g. /api/v1/extensions).
 * Authorization is enforced in the controller (system.config.view / .edit); `auth`
 * middleware guarantees an authenticated principal first.
 */
$router->group(['prefix' => '/extensions'], function (Router $router) {
    $router->get('/', [ExtensionsController::class, 'index'])
        ->middleware(['auth', 'rate_limit:60,60']);

    $router->get('/catalog', [ExtensionsController::class, 'catalog'])
        ->middleware(['auth', 'rate_limit:30,60']);

    $router->post('/install', [ExtensionsController::class, 'install'])
        ->middleware(['auth', 'rate_limit:10,60']);

    $router->get('/install/{jobId}', [ExtensionsController::class, 'installStatus'])
        ->middleware(['auth', 'rate_limit:120,60']);

    $router->post('/enable', [ExtensionsController::class, 'enable'])
        ->middleware(['auth', 'rate_limit:20,60']);

    $router->post('/disable', [ExtensionsController::class, 'disable'])
        ->middleware(['auth', 'rate_limit:20,60']);
});
