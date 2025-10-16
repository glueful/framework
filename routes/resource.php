<?php

use Glueful\Routing\Router;
use Glueful\Controllers\ResourceController;
use Glueful\Helpers\RequestHelper;
use Symfony\Component\HttpFoundation\Request;

/** @var Router $router Router instance injected by RouteManifest::load() */

// RESTful Resource CRUD Routes
/**
 * @route GET /{resource}
 * @summary List Resources
 * @description Retrieves a paginated list of resources from the specified table
 * @tag Resources
 * @parameter resource:string="Table/resource name" {required}
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
$router->get('/{resource}', function (Request $request) {
    $resourceController = container()->get(ResourceController::class);
    // Extract resource from path parameters
    $pathInfo = $request->getPathInfo();
    $segments = explode('/', trim($pathInfo, '/'));
    $params = ['resource' => $segments[0]];
    $queryParams = $request->query->all();
    return $resourceController->get($params, $queryParams);
})
    ->setFieldsConfig([
        'strict' => false,
        'maxDepth' => 6,
        'maxFields' => 200,
        'maxItems' => 1000,
    ])
    ->middleware(['auth', 'field_selection', 'rate_limit:100,60']); // 100 requests per minute for reads

/**
 * @route GET /{resource}/{uuid}
 * @summary Get Single Resource
 * @description Retrieves a single resource by its UUID
 * @tag Resources
 * @parameter resource:string="Table/resource name" {required}
 * @parameter uuid:string="Resource UUID" {required}
 * @response 200 application/json "Resource retrieved successfully"
 * @response 404 "Resource not found"
 * @response 403 "Insufficient permissions"
 * @requiresAuth true
 */
$router->get('/{resource}/{uuid}', function (Request $request) {
    $resourceController = container()->get(ResourceController::class);
    // Extract resource and UUID from path
    $pathInfo = $request->getPathInfo();
    $segments = explode('/', trim($pathInfo, '/'));
    $params = ['resource' => $segments[0], 'uuid' => $segments[1]];
    $queryParams = $request->query->all();
    return $resourceController->getSingle($params, $queryParams);
})
    ->setFieldsConfig([
        'strict' => false,
        'maxDepth' => 6,
        'maxFields' => 200,
        'maxItems' => 1000,
    ])
    ->middleware(['auth', 'field_selection', 'rate_limit:200,60']); // Higher limit for single resource reads

/**
 * @route POST /{resource}
 * @summary Create Resource
 * @description Creates a new resource in the specified table
 * @tag Resources
 * @parameter resource:string="Table/resource name" {required}
 * @requestBody data:object="Resource data to create" {required}
 * @response 201 application/json "Resource created successfully"
 * @response 400 "Invalid input data"
 * @response 403 "Insufficient permissions"
 * @requiresAuth true
 */
$router->post('/{resource}', function (Request $request) {
    $resourceController = container()->get(ResourceController::class);
    // Extract resource from path
    $pathInfo = $request->getPathInfo();
    $segments = explode('/', trim($pathInfo, '/'));
    $params = ['resource' => $segments[0]];
    $postData = RequestHelper::getRequestData();
    return $resourceController->post($params, $postData);
})->middleware(['auth', 'rate_limit:50,60']); // 50 creates per minute

/**
 * @route PUT /{resource}/{uuid}
 * @summary Update Resource
 * @description Updates an existing resource by UUID
 * @tag Resources
 * @parameter resource:string="Table/resource name" {required}
 * @parameter uuid:string="Resource UUID to update" {required}
 * @requestBody data:object="Updated resource data" {required}
 * @response 200 application/json "Resource updated successfully"
 * @response 404 "Resource not found"
 * @response 400 "Invalid input data"
 * @response 403 "Insufficient permissions"
 * @requiresAuth true
 */
$router->put('/{resource}/{uuid}', function (Request $request) {
    $resourceController = container()->get(ResourceController::class);
    // Extract resource and UUID from path
    $pathInfo = $request->getPathInfo();
    $segments = explode('/', trim($pathInfo, '/'));
    $params = ['resource' => $segments[0], 'uuid' => $segments[1]];
    $putData = RequestHelper::getPutData();
    $putData['uuid'] = $params['uuid'];
    return $resourceController->put($params, $putData);
})->middleware(['auth', 'rate_limit:30,60']); // 30 updates per minute

/**
 * @route DELETE /{resource}/{uuid}
 * @summary Delete Resource
 * @description Deletes a resource by UUID
 * @tag Resources
 * @parameter resource:string="Table/resource name" {required}
 * @parameter uuid:string="Resource UUID to delete" {required}
 * @response 200 application/json "Resource deleted successfully"
 * @response 404 "Resource not found"
 * @response 403 "Insufficient permissions"
 * @requiresAuth true
 */
$router->delete('/{resource}/{uuid}', function (Request $request) {
    $resourceController = container()->get(ResourceController::class);
    // Extract resource and UUID from path
    $pathInfo = $request->getPathInfo();
    $segments = explode('/', trim($pathInfo, '/'));
    $params = ['resource' => $segments[0], 'uuid' => $segments[1]];
    return $resourceController->delete($params);
})->middleware(['auth', 'rate_limit:20,60']); // 20 deletes per minute

// Bulk operation routes (only if enabled in configuration)
if (config('resource.security.bulk_operations', false)) {
    /**
     * @route DELETE /{resource}/bulk
     * @summary Bulk Delete Resources
     * @description Deletes multiple resources by UUIDs
     * @tag Resources
     * @parameter resource:string="Table/resource name" {required}
     * @requestBody uuids:array="Array of UUIDs to delete" {required}
     * @response 200 application/json "Resources deleted successfully"
     * @response 400 "Invalid input data"
     * @response 403 "Insufficient permissions or bulk operations disabled"
     * @requiresAuth true
     */
    $router->delete('/{resource}/bulk', function (Request $request) {
        $resourceController = container()->get(ResourceController::class);
        // Extract resource from path
        $pathInfo = $request->getPathInfo();
        $segments = explode('/', trim($pathInfo, '/'));
        $params = ['resource' => $segments[0]];
        $deleteData = RequestHelper::getRequestData();
        return $resourceController->bulkDelete($params, $deleteData);
    })->middleware(['auth', 'rate_limit:5,60']); // Very strict: 5 bulk deletes per minute

    /**
     * @route PUT /{resource}/bulk
     * @summary Bulk Update Resources
     * @description Updates multiple resources with provided data
     * @tag Resources
     * @parameter resource:string="Table/resource name" {required}
     * @requestBody data:object="Bulk update data with UUIDs and fields" {required}
     * @response 200 application/json "Resources updated successfully"
     * @response 400 "Invalid input data"
     * @response 403 "Insufficient permissions or bulk operations disabled"
     * @requiresAuth true
     */
    $router->put('/{resource}/bulk', function (Request $request) {
        $resourceController = container()->get(ResourceController::class);
        // Extract resource from path
        $pathInfo = $request->getPathInfo();
        $segments = explode('/', trim($pathInfo, '/'));
        $params = ['resource' => $segments[0]];
        $updateData = RequestHelper::getRequestData();
        return $resourceController->bulkUpdate($params, $updateData);
    })->middleware(['auth', 'rate_limit:10,60']); // 10 bulk updates per minute
}
