<?php

use Glueful\Routing\Router;
use Glueful\Controllers\DocsController;
use Glueful\Support\Documentation\ApiDocsPath;

/**
 * @var \Glueful\Routing\Router $router Router instance injected by RouteManifest::load()
 */

// Documentation routes - the OpenAPI spec and its interactive UI, mounted at the configured
// API-docs path (`documentation.route_prefix` / API_DOCS_PATH, default /api-docs) so an
// application keeps /docs for its own documentation.
$router->group(['prefix' => ApiDocsPath::resolve($router->getContext())], function (Router $router) {
    $router->get('/', [DocsController::class, 'index']);

    $router->get('/openapi.json', [DocsController::class, 'openapi']);
});
