<?php

use Glueful\Routing\Router;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\DocsController;
use Symfony\Component\HttpFoundation\Request;

/**
 * @var Router $router Router instance injected by RouteManifest::load()
 * @var ApplicationContext $context
 */

/** @var ApplicationContext|null $context */
$context = (isset($context) && $context instanceof ApplicationContext)
    ? $context
    : $router->getContext();

// Documentation routes - serves OpenAPI spec and interactive UI
$router->group(['prefix' => '/docs'], function (Router $router) use ($context) {
    /**
     * @route GET /docs
     * @summary API Documentation UI
     * @description Interactive API documentation interface (Scalar, Swagger UI, or Redoc)
     * @tag Documentation
     * @response 200 text/html "Documentation UI HTML page"
     * @response 404 application/json "Documentation not generated or disabled" {
     *   success:boolean="false",
     *   message:string="Documentation not generated. Run: php glueful generate:openapi --ui"
     * }
     */
    $router->get('/', function (Request $request) use ($context) {
        $controller = container($context)->get(DocsController::class);
        return $controller->index($request);
    });

    /**
     * @route GET /docs/openapi.json
     * @summary OpenAPI Specification
     * @description Returns the OpenAPI/Swagger specification in JSON format
     * @tag Documentation
     * @response 200 application/json "OpenAPI specification" {
     *   openapi:string="3.1.0",
     *   info:object="API information",
     *   paths:object="API endpoints",
     *   components:object="Reusable components"
     * }
     * @response 404 application/json "Specification not generated or disabled" {
     *   success:boolean="false",
     *   message:string="OpenAPI specification not generated. Run: php glueful generate:openapi"
     * }
     */
    $router->get('/openapi.json', function (Request $request) use ($context) {
        $controller = container($context)->get(DocsController::class);
        return $controller->openapi($request);
    });
});
