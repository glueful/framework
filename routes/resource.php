<?php

use Glueful\Routing\Router;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\ResourceController;

/**
 * @var \Glueful\Routing\Router $router Router instance injected by RouteManifest::load()
 * @var \Glueful\Bootstrap\ApplicationContext $context
 */

/** @var ApplicationContext|null $context */
$context = (isset($context) && $context instanceof ApplicationContext)
    ? $context
    : $router->getContext();

// RESTful Resource CRUD Routes
// All routes are prefixed with /data to avoid conflicts with custom application routes
// Final URLs: /api/v1/data/{table}, /api/v1/data/{table}/{uuid}, etc.
$router->group(['prefix' => '/data'], function (Router $router) {
    $router->get('/{table}', [ResourceController::class, 'index'])
        ->setFieldsConfig([
            'strict' => false,
            'maxDepth' => 6,
            'maxFields' => 200,
            'maxItems' => 1000,
        ])
        ->middleware(['auth', 'field_selection', 'rate_limit:100,60']); // 100 requests per minute for reads

    $router->get('/{table}/{uuid}', [ResourceController::class, 'show'])
        ->setFieldsConfig([
            'strict' => false,
            'maxDepth' => 6,
            'maxFields' => 200,
            'maxItems' => 1000,
        ])
        ->middleware(['auth', 'field_selection', 'rate_limit:200,60']); // Higher limit for single resource reads

    $router->post('/{table}', [ResourceController::class, 'store'])
        ->middleware(['auth', 'rate_limit:50,60']); // 50 creates per minute

    $router->put('/{table}/{uuid}', [ResourceController::class, 'update'])
        ->middleware(['auth', 'rate_limit:30,60']); // 30 updates per minute

    $router->delete('/{table}/{uuid}', [ResourceController::class, 'destroy'])
        ->middleware(['auth', 'rate_limit:20,60']); // 20 deletes per minute
});

// Bulk operation routes (only if enabled in configuration)
if (config($context, 'resource.security.bulk_operations', false)) {
    $router->group(['prefix' => '/data'], function (Router $router) {
        $router->delete('/{table}/bulk', [ResourceController::class, 'destroyBulk'])
            ->middleware(['auth', 'rate_limit:5,60']); // Very strict: 5 bulk deletes per minute

        $router->put('/{table}/bulk', [ResourceController::class, 'updateBulk'])
            ->middleware(['auth', 'rate_limit:10,60']); // 10 bulk updates per minute
    });
}
