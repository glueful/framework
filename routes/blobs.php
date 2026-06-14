<?php

/**
 * Blob routes - loaded via RouteManifest::requireRouteFile()
 *
 * Handles file upload, retrieval, and management operations.
 * Supports multipart uploads, base64 encoding, on-demand image resizing,
 * per-blob visibility controls, and signed URLs for temporary access.
 *
 * @var \Glueful\Routing\Router $router
 * @var \Glueful\Bootstrap\ApplicationContext $context
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
    $router->post('', [UploadController::class, 'upload'])
        ->middleware($postMw);

    $router->get('/{uuid}', [UploadController::class, 'show'])
        ->middleware($getMw);

    $router->get('/{uuid}/info', [UploadController::class, 'info'])
        ->middleware($getMw);

    $router->delete('/{uuid}', [UploadController::class, 'delete'])
        ->middleware($deleteMw);

    $router->post('/{uuid}/signed-url', [UploadController::class, 'signedUrl'])
        ->middleware(['auth']);
});
