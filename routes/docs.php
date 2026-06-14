<?php

use Glueful\Routing\Router;
use Glueful\Controllers\DocsController;

/**
 * @var \Glueful\Routing\Router $router Router instance injected by RouteManifest::load()
 */

// Documentation routes - serves OpenAPI spec and interactive UI
$router->group(['prefix' => '/docs'], function (Router $router) {
    $router->get('/', [DocsController::class, 'index']);

    $router->get('/openapi.json', [DocsController::class, 'openapi']);
});
