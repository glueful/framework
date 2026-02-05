<?php

/**
 * Blob routes - loaded via RouteManifest::requireRouteFile()
 *
 * Handles file upload, retrieval, and management operations.
 * Supports multipart uploads, base64 encoding, on-demand image resizing,
 * per-blob visibility controls, and signed URLs for temporary access.
 *
 * @var Router $router
 * @var ApplicationContext $context
 */

use Glueful\Routing\Router;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\UploadController;

/** @var ApplicationContext|null $context */
$context = (isset($context) && $context instanceof ApplicationContext)
    ? $context
    : $router->getContext();

if (!(bool) config($context, 'uploads.enabled', true)) {
    return;
}

// Access control: 'private' | 'public' | 'upload_only' | true | false
$access = config($context, 'uploads.access', 'private');
$requireAuthAll = $access === 'private' || $access === true || $access === 'true' || $access === 1;
$uploadOnlyAuth = $access === 'upload_only';

$postMw = $requireAuthAll || $uploadOnlyAuth ? ['auth'] : [];
$getMw = $requireAuthAll ? ['auth'] : [];
$deleteMw = $requireAuthAll || $uploadOnlyAuth ? ['auth'] : [];

$uploadsPerMin = (int) config($context, 'uploads.rate_limits.uploads_per_minute', 30);
$retrievalPerMin = (int) config($context, 'uploads.rate_limits.retrieval_per_minute', 200);
$postMw[] = "rate_limit:{$uploadsPerMin},60";
$getMw[] = "rate_limit:{$retrievalPerMin},60";
$deleteMw[] = 'rate_limit:20,60';

$router->group(['prefix' => '/blobs'], function (Router $router) use ($postMw, $getMw, $deleteMw) {
    /**
     * @route POST /blobs
     * @summary Upload File
     * @description Upload a file via multipart form data or base64 encoding.
     * @tag Blobs
     * @response 201 application/json "Upload successful"
     * @response 400 "Missing file upload or invalid base64 data"
     * @response 401 "Authentication required"
     * @response 413 "File too large"
     * @response 415 "Unsupported file type"
     */
    $router->post('', [UploadController::class, 'upload'])
        ->middleware($postMw);

    /**
     * @route GET /blobs/{uuid}
     * @summary Retrieve Blob
     * @description Retrieve blob file content with optional image resizing.
     * @tag Blobs
     * @param uuid:string="Blob UUID" {required=true}
     * @response 200 "File content with appropriate Content-Type header"
     * @response 401 "Authentication required for private blob"
     * @response 404 "Blob not found"
     */
    $router->get('/{uuid}', [UploadController::class, 'show'])
        ->middleware($getMw);

    /**
     * @route GET /blobs/{uuid}/info
     * @summary Blob Metadata
     * @description Retrieve blob metadata without downloading the file content
     * @tag Blobs
     * @param uuid:string="Blob UUID" {required=true}
     * @response 200 application/json "Blob metadata retrieved"
     * @response 401 "Authentication required"
     * @response 404 "Blob not found"
     */
    $router->get('/{uuid}/info', [UploadController::class, 'info'])
        ->middleware($getMw);

    /**
     * @route DELETE /blobs/{uuid}
     * @summary Delete Blob
     * @description Soft-delete a blob and remove its underlying file from storage
     * @tag Blobs
     * @requiresAuth true
     * @param uuid:string="Blob UUID" {required=true}
     * @response 200 application/json "Blob deleted"
     * @response 401 "Authentication required"
     * @response 404 "Blob not found"
     */
    $router->delete('/{uuid}', [UploadController::class, 'delete'])
        ->middleware($deleteMw);

    /**
     * @route POST /blobs/{uuid}/signed-url
     * @summary Generate Signed URL
     * @description Generate a temporary signed URL for accessing a private blob.
     * @tag Blobs
     * @requiresAuth true
     * @param uuid:string="Blob UUID" {required=true}
     * @queryParam ttl:integer="URL lifetime in seconds (default: 3600, max: 604800)"
     * @response 200 application/json "Signed URL generated"
     * @response 400 "Signed URLs are disabled"
     * @response 401 "Authentication required"
     * @response 404 "Blob not found"
     */
    $router->post('/{uuid}/signed-url', [UploadController::class, 'signedUrl'])
        ->middleware(['auth']);
});
