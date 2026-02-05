<?php

use Glueful\Routing\Router;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\ResourceController;

/**
 * @var Router $router Router instance injected by RouteManifest::load()
 * @var ApplicationContext $context
 */

/** @var ApplicationContext|null $context */
$context = (isset($context) && $context instanceof ApplicationContext)
    ? $context
    : $router->getContext();

// RESTful Resource CRUD Routes
// All routes are prefixed with /data to avoid conflicts with custom application routes
// Final URLs: /api/v1/data/{table}, /api/v1/data/{table}/{uuid}, etc.
$router->group(['prefix' => '/data'], function (Router $router) {
    /**
     * @route GET /data/{table}
     * @summary List Resources
     * @description Retrieves a paginated list of resources from the specified table
     * @tag Data
     * @parameter table:string="Table/resource name" {required}
     * @parameter page:integer="Page number for pagination"
     * @parameter limit:integer="Number of items per page (max 100)"
     * @parameter sort:string="Field to sort by"
     * @parameter order:string="Sort order (asc|desc)"
     * @response 200 application/json "Resources retrieved successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:array="Array of resource objects",
     *   pagination:{
     *     current_page:integer="Current page number",
     *     per_page:integer="Items per page",
     *     total:integer="Total number of items"
     *   }
     * }
     * @response 403 "Insufficient permissions for resource access"
     * @response 404 "Resource table not found"
     * @requiresAuth true
     */
    $router->get('/{table}', [ResourceController::class, 'index'])
        ->setFieldsConfig([
            'strict' => false,
            'maxDepth' => 6,
            'maxFields' => 200,
            'maxItems' => 1000,
        ])
        ->middleware(['auth', 'field_selection', 'rate_limit:100,60']); // 100 requests per minute for reads

    /**
     * @route GET /data/{table}/{uuid}
     * @summary Get Single Resource
     * @description Retrieves a single resource by its UUID
     * @tag Data
     * @parameter table:string="Table/resource name" {required}
     * @parameter uuid:string="Resource UUID" {required}
     * @response 200 application/json "Resource retrieved successfully"
     * @response 404 "Resource not found"
     * @response 403 "Insufficient permissions"
     * @requiresAuth true
     */
    $router->get('/{table}/{uuid}', [ResourceController::class, 'show'])
        ->setFieldsConfig([
            'strict' => false,
            'maxDepth' => 6,
            'maxFields' => 200,
            'maxItems' => 1000,
        ])
        ->middleware(['auth', 'field_selection', 'rate_limit:200,60']); // Higher limit for single resource reads

    /**
     * @route POST /data/{table}
     * @summary Create Resource
     * @description Creates a new resource in the specified table
     * @tag Data
     * @parameter table:string="Table/resource name" {required}
     * @requestBody data:object="Resource data to create" {required}
     * @response 201 application/json "Resource created successfully"
     * @response 400 "Invalid input data"
     * @response 403 "Insufficient permissions"
     * @requiresAuth true
     */
    $router->post('/{table}', [ResourceController::class, 'store'])
        ->middleware(['auth', 'rate_limit:50,60']); // 50 creates per minute

    /**
     * @route PUT /data/{table}/{uuid}
     * @summary Update Resource
     * @description Updates an existing resource by UUID
     * @tag Data
     * @parameter table:string="Table/resource name" {required}
     * @parameter uuid:string="Resource UUID to update" {required}
     * @requestBody data:object="Updated resource data" {required}
     * @response 200 application/json "Resource updated successfully"
     * @response 404 "Resource not found"
     * @response 400 "Invalid input data"
     * @response 403 "Insufficient permissions"
     * @requiresAuth true
     */
    $router->put('/{table}/{uuid}', [ResourceController::class, 'update'])
        ->middleware(['auth', 'rate_limit:30,60']); // 30 updates per minute

    /**
     * @route DELETE /data/{table}/{uuid}
     * @summary Delete Resource
     * @description Deletes a resource by UUID
     * @tag Data
     * @parameter table:string="Table/resource name" {required}
     * @parameter uuid:string="Resource UUID to delete" {required}
     * @response 200 application/json "Resource deleted successfully"
     * @response 404 "Resource not found"
     * @response 403 "Insufficient permissions"
     * @requiresAuth true
     */
    $router->delete('/{table}/{uuid}', [ResourceController::class, 'destroy'])
        ->middleware(['auth', 'rate_limit:20,60']); // 20 deletes per minute
});

// Bulk operation routes (only if enabled in configuration)
if (config($context, 'resource.security.bulk_operations', false)) {
    $router->group(['prefix' => '/data'], function (Router $router) {
        /**
         * @route DELETE /data/{table}/bulk
         * @summary Bulk Delete Resources
         * @description Deletes multiple resources by UUIDs
         * @tag Data
         * @parameter table:string="Table/resource name" {required}
         * @requestBody uuids:array="Array of UUIDs to delete" {required}
         * @response 200 application/json "Resources deleted successfully"
         * @response 400 "Invalid input data"
         * @response 403 "Insufficient permissions or bulk operations disabled"
         * @requiresAuth true
         */
        $router->delete('/{table}/bulk', [ResourceController::class, 'destroyBulk'])
            ->middleware(['auth', 'rate_limit:5,60']); // Very strict: 5 bulk deletes per minute

        /**
         * @route PUT /data/{table}/bulk
         * @summary Bulk Update Resources
         * @description Updates multiple resources with provided data
         * @tag Data
         * @parameter table:string="Table/resource name" {required}
         * @requestBody data:object="Bulk update data with UUIDs and fields" {required}
         * @response 200 application/json "Resources updated successfully"
         * @response 400 "Invalid input data"
         * @response 403 "Insufficient permissions or bulk operations disabled"
         * @requiresAuth true
         */
        $router->put('/{table}/bulk', [ResourceController::class, 'updateBulk'])
            ->middleware(['auth', 'rate_limit:10,60']); // 10 bulk updates per minute
    });
}
