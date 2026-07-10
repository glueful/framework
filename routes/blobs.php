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
use Glueful\Uploader\Contracts\BlobRouteAction;
use Glueful\Uploader\Contracts\BlobRouteMiddlewareProvider;

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

/** @var BlobRouteMiddlewareProvider|null $provider */
$provider = has_service($context, BlobRouteMiddlewareProvider::class)
    ? container($context)->get(BlobRouteMiddlewareProvider::class)
    : null;
$contributed = static fn (BlobRouteAction $action): array => $provider?->middlewareFor($action) ?? [];

$uploadsPerMin = (int) config($context, 'uploads.rate_limits.uploads_per_minute', 30);
$retrievalPerMin = (int) config($context, 'uploads.rate_limits.retrieval_per_minute', 200);

$postMw = array_merge(
    $requireAuthAll || $uploadOnlyAuth ? ['auth'] : [],
    $contributed(BlobRouteAction::UPLOAD),
    ["rate_limit:{$uploadsPerMin},60"],
);
$viewMw = array_merge(
    ['auth:optional'],
    $contributed(BlobRouteAction::VIEW),
    ["rate_limit:{$retrievalPerMin},60"],
);
$infoMw = array_merge(
    $requireAuthAll ? ['auth'] : [],
    $contributed(BlobRouteAction::INFO),
    ["rate_limit:{$retrievalPerMin},60"],
);
$deleteMw = array_merge(
    $requireAuthAll || $uploadOnlyAuth ? ['auth'] : [],
    $contributed(BlobRouteAction::DELETE),
    ['rate_limit:20,60'],
);
$signMw = array_merge(['auth'], $contributed(BlobRouteAction::SIGN));

$router->group(['prefix' => '/blobs'], function (Router $router) use (
    $postMw,
    $viewMw,
    $infoMw,
    $deleteMw,
    $signMw,
) {
    $router->post('', [UploadController::class, 'upload'])
        ->middleware($postMw);

    $router->get('/{uuid}', [UploadController::class, 'show'])
        ->middleware($viewMw);

    $router->get('/{uuid}/info', [UploadController::class, 'info'])
        ->middleware($infoMw);

    $router->delete('/{uuid}', [UploadController::class, 'delete'])
        ->middleware($deleteMw);

    $router->post('/{uuid}/signed-url', [UploadController::class, 'signedUrl'])
        ->middleware($signMw);
});
