<?php

/**
 * Blob routes - loaded via RouteManifest::requireRouteFile()
 *
 * Handles file upload, retrieval, and management operations.
 * Supports multipart uploads, base64 encoding, on-demand image resizing,
 * per-blob visibility controls, and signed URLs for temporary access.
 *
 * Variables provided in scope by the loader:
 * @var Router $router
 * @var ApplicationContext $context
 */

use Glueful\Routing\Router;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\UploadController;
use Symfony\Component\HttpFoundation\Request;

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

$router->group(['prefix' => '/blobs'], function (Router $router) use ($context, $requireAuthAll, $uploadOnlyAuth) {
    $postMiddleware = $requireAuthAll || $uploadOnlyAuth ? ['auth'] : [];
    $getMiddleware = $requireAuthAll ? ['auth'] : [];
    $deleteMiddleware = $requireAuthAll || $uploadOnlyAuth ? ['auth'] : [];

    $postMiddleware[] = 'rate_limit:' . (int) config($context, 'uploads.rate_limits.uploads_per_minute', 30) . ',60';
    $getMiddleware[] = 'rate_limit:' . (int) config($context, 'uploads.rate_limits.retrieval_per_minute', 200) . ',60';
    $deleteMiddleware[] = 'rate_limit:20,60';

    /**
     * @route POST /blobs
     * @summary Upload File
     * @description Upload a file via multipart form data or base64 encoding. Supports optional visibility setting.
     * @tag Blobs
     * @requestBody file:file="File to upload (multipart)" type:string="Upload type (base64 for encoded uploads)"
     * data:string="Base64-encoded file data (when type=base64)" filename:string="Original filename (for base64)"
     * mime_type:string="MIME type (for base64)" visibility:string="Blob visibility (public|private)"
     * path_prefix:string="Custom storage path prefix"
     * @response 201 application/json "Upload successful" {
     *   success:boolean="true",
     *   message:string="Upload successful",
     *   data:{
     *     uuid:string="Unique blob identifier",
     *     name:string="Original filename",
     *     mime_type:string="File MIME type",
     *     size:integer="File size in bytes",
     *     url:string="Storage path",
     *     visibility:string="Blob visibility (public|private)",
     *     thumbnail_uuid:string="Thumbnail blob UUID (if generated)"
     *   }
     * }
     * @response 400 "Missing file upload or invalid base64 data"
     * @response 401 "Authentication required"
     * @response 413 "File too large"
     * @response 415 "Unsupported file type"
     */
    $router->post('', function (Request $request) use ($context) {
        $controller = container($context)->get(UploadController::class);
        return $controller->upload($request);
    })->middleware($postMiddleware);

    /**
     * @route GET /blobs/{uuid}
     * @summary Retrieve Blob
     * @description Retrieve blob file content. For images, supports on-demand resizing via query parameters.
     * Supports HTTP Range requests for video/audio streaming. Private blobs require auth or valid signed URL.
     * @tag Blobs
     * @param uuid:string="Blob UUID" {required=true}
     * @queryParam width:integer="Resize width in pixels (images only)"
     * @queryParam height:integer="Resize height in pixels (images only)"
     * @queryParam quality:integer="Image quality 1-100 (images only)"
     * @queryParam format:string="Output format: jpeg, png, webp, gif (images only)"
     * @queryParam fit:string="Resize fit mode: contain, cover, fill (images only)"
     * @queryParam expires:integer="Signed URL expiration timestamp"
     * @queryParam signature:string="Signed URL HMAC signature"
     * @response 200 "File content with appropriate Content-Type header"
     * @response 206 "Partial content (Range request for streaming)"
     * @response 304 "Not Modified (ETag match)"
     * @response 401 "Authentication required for private blob"
     * @response 404 "Blob not found"
     * @response 416 "Range not satisfiable"
     * @response 422 "Resized image exceeds maximum allowed size"
     */
    $router->get('/{uuid}', function (Request $request) use ($context) {
        $controller = container($context)->get(UploadController::class);
        return $controller->show($request, (string) $request->attributes->get('uuid'));
    })->middleware($getMiddleware);

    /**
     * @route GET /blobs/{uuid}/info
     * @summary Blob Metadata
     * @description Retrieve blob metadata without downloading the file content
     * @tag Blobs
     * @param uuid:string="Blob UUID" {required=true}
     * @response 200 application/json "Blob metadata retrieved" {
     *   success:boolean="true",
     *   message:string="Blob metadata",
     *   data:{
     *     uuid:string="Unique blob identifier",
     *     name:string="Original filename",
     *     description:string="Blob description",
     *     mime_type:string="File MIME type",
     *     size:integer="File size in bytes",
     *     url:string="Public URL or storage path",
     *     status:string="Blob status (active|deleted)",
     *     visibility:string="Blob visibility (public|private)",
     *     storage_type:string="Storage disk name",
     *     created_by:string="Creator user UUID",
     *     created_at:string="Creation timestamp",
     *     updated_at:string="Last update timestamp"
     *   }
     * }
     * @response 401 "Authentication required"
     * @response 404 "Blob not found"
     */
    $router->get('/{uuid}/info', function (Request $request) use ($context) {
        $controller = container($context)->get(UploadController::class);
        return $controller->info($request, (string) $request->attributes->get('uuid'));
    })->middleware($getMiddleware);

    /**
     * @route DELETE /blobs/{uuid}
     * @summary Delete Blob
     * @description Soft-delete a blob and remove its underlying file from storage
     * @tag Blobs
     * @requiresAuth true
     * @param uuid:string="Blob UUID" {required=true}
     * @response 200 application/json "Blob deleted" {
     *   success:boolean="true",
     *   message:string="Blob deleted",
     *   data:{
     *     uuid:string="Deleted blob UUID"
     *   }
     * }
     * @response 401 "Authentication required"
     * @response 404 "Blob not found"
     */
    $router->delete('/{uuid}', function (Request $request) use ($context) {
        $controller = container($context)->get(UploadController::class);
        return $controller->delete($request, (string) $request->attributes->get('uuid'));
    })->middleware($deleteMiddleware);

    /**
     * @route POST /blobs/{uuid}/signed-url
     * @summary Generate Signed URL
     * @description Generate a temporary signed URL for accessing a private blob without authentication.
     * Useful for sharing private files or embedding in emails/external systems.
     * @tag Blobs
     * @requiresAuth true
     * @param uuid:string="Blob UUID" {required=true}
     * @queryParam ttl:integer="URL lifetime in seconds (default: 3600, max: 604800)"
     * @response 200 application/json "Signed URL generated" {
     *   success:boolean="true",
     *   message:string="Signed URL generated",
     *   data:{
     *     uuid:string="Blob UUID",
     *     signed_url:string="Temporary access URL with signature",
     *     expires_in:integer="Seconds until expiration",
     *     expires_at:string="Expiration timestamp (Y-m-d H:i:s)"
     *   }
     * }
     * @response 400 "Signed URLs are disabled"
     * @response 401 "Authentication required"
     * @response 404 "Blob not found"
     */
    $router->post('/{uuid}/signed-url', function (Request $request) use ($context) {
        $controller = container($context)->get(UploadController::class);
        return $controller->signedUrl($request, (string) $request->attributes->get('uuid'));
    })->middleware(['auth']);
});
