<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Controllers\DTOs\BlobDeletedData;
use Glueful\Controllers\DTOs\BlobInfoData;
use Glueful\Controllers\DTOs\SignedUrlData;
use Glueful\Controllers\DTOs\UploadResultData;
use Glueful\Helpers\RequestHelper;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Glueful\Http\Exceptions\Domain\BusinessLogicException;
use Glueful\Repository\BlobRepository;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\QueryParam;
use Glueful\Services\ImageSecurityValidator;
use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;
use Glueful\Uploader\Contracts\MediaProcessorInterface;
use Glueful\Uploader\Contracts\BlobAccessContext;
use Glueful\Uploader\Contracts\BlobAction;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobCreatedHook;
use Glueful\Uploader\Contracts\BlobPublicUrlProvider;
use Glueful\Uploader\Contracts\NullBlobAccessPolicy;
use Glueful\Uploader\Contracts\NullBlobCreatedHook;
use Glueful\Uploader\FileUploader;
use Glueful\Uploader\UploadException;
use Glueful\Validation\ValidationException;
use Glueful\Support\SignedUrl;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class UploadController extends BaseController
{
    private FileUploader $uploader;
    private BlobRepository $blobs;
    private StorageManager $storage;
    private UrlGenerator $urls;
    /** @var CacheStore<mixed>|null */
    private ?CacheStore $cache;
    private BlobCreatedHook $createdHook;
    private BlobAccessPolicy $accessPolicy;
    private LoggerInterface $logger;

    public function __construct(
        ApplicationContext $context,
        FileUploader $uploader,
        BlobRepository $blobRepository,
        StorageManager $storage,
        UrlGenerator $urls,
        private readonly ?MediaProcessorInterface $media = null,
        private readonly ?ImageSecurityValidator $imageSecurity = null,
        ?BlobCreatedHook $createdHook = null,
        ?BlobAccessPolicy $accessPolicy = null,
        ?LoggerInterface $logger = null,
        private readonly ?BlobPublicUrlProvider $publicUrlProvider = null,
    ) {
        parent::__construct($context);
        $this->uploader = $uploader;
        $this->blobs = $blobRepository;
        $this->storage = $storage;
        $this->urls = $urls;
        $this->createdHook = $createdHook ?? new NullBlobCreatedHook();
        $this->accessPolicy = $accessPolicy ?? new NullBlobAccessPolicy();
        $this->logger = $logger ?? new NullLogger();
        $this->cache = $this->resolveCache();
    }

    #[ApiOperation(
        summary: 'Upload File',
        description: 'Upload a file via multipart form data or base64 encoding.',
        tags: ['Blobs'],
    )]
    #[ApiRequestBody(
        contentType: 'multipart/form-data',
        inlineSchema: [
            'type' => 'object',
            'properties' => [
                'file' => ['type' => 'string', 'format' => 'binary'],
                'path_prefix' => ['type' => 'string'],
                'visibility' => ['type' => 'string', 'enum' => ['public', 'private']],
            ],
            'required' => ['file'],
        ],
    )]
    #[ApiResponse(201, UploadResultData::class, description: 'Upload successful')]
    #[ApiResponse(400, description: 'Missing file upload or invalid base64 data')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(413, description: 'File too large')]
    #[ApiResponse(415, description: 'Unsupported file type')]
    public function upload(Request $request): Response
    {
        if (!(bool) $this->getConfig('uploads.enabled', true)) {
            return Response::notFound('Uploads are disabled');
        }

        if ($this->requiresAuthFor('upload') && Utils::getUser() === null) {
            return Response::unauthorized('Authentication required');
        }

        $payload = RequestHelper::getRequestData($request);
        $fileInput = $request->files->get('file');
        $tempFile = null;

        try {
            if ($fileInput === null) {
                if (($payload['type'] ?? '') !== 'base64') {
                    return Response::error('Missing file upload', Response::HTTP_BAD_REQUEST);
                }

                $base64 = (string) ($payload['data'] ?? '');
                if ($base64 === '') {
                    return Response::error('Missing base64 data', Response::HTTP_BAD_REQUEST);
                }

                $tempFile = $this->uploader->handleBase64Upload($base64);
                $mime = (string) ($payload['mime_type'] ?? $this->detectMime($tempFile));
                $filename = (string) ($payload['filename'] ?? ('upload.' . $this->extensionFromMime($mime)));
                $size = filesize($tempFile) ?: 0;

                $fileInput = [
                    'name' => $filename,
                    'type' => $mime,
                    'tmp_name' => $tempFile,
                    'error' => UPLOAD_ERR_OK,
                    'size' => $size,
                ];
            }

            $pathPrefix = $this->buildPathPrefix(
                is_string($payload['path_prefix'] ?? null) ? $payload['path_prefix'] : null
            );

            $disk = (string) $this->getConfig('uploads.disk', 'uploads');
            $defaultDisk = (string) $this->getConfig('storage.default', 'uploads');
            $uploader = $disk !== '' && $disk !== $defaultDisk
                ? new FileUploader(storageDriver: $disk, context: $this->getContext(), media: $this->media)
                : $this->uploader;

            // Determine visibility
            $visibility = $payload['visibility'] ?? null;
            if (!is_string($visibility) || !in_array($visibility, ['public', 'private'], true)) {
                $visibility = (string) $this->getConfig('uploads.default_visibility', 'private');
            }

            $thumbCfg = (array) $this->getConfig('uploads.thumbnails', []);
            $result = $uploader->uploadMedia($fileInput, $pathPrefix, [
                'generate_thumbnail' => false,
                'thumbnail_width' => $thumbCfg['width'] ?? null,
                'thumbnail_height' => $thumbCfg['height'] ?? null,
                'thumbnail_quality' => $thumbCfg['quality'] ?? null,
                'save_to_blobs' => true,
                'visibility' => $visibility,
            ]);

            $blobUuid = (string) ($result['blob_uuid'] ?? '');
            try {
                $this->createdHook->onBlobCreated($blobUuid, $this->authenticatedUserUuid());
            } catch (\Throwable $exception) {
                $this->compensateOwnerlessBlob($uploader, $blobUuid, (string) ($result['path'] ?? ''));
                $this->logger->error('upload.tenant_attribution_failed', [
                    'blob_uuid' => $blobUuid,
                    'error' => $exception->getMessage(),
                ]);

                return Response::error(
                    'Upload could not be attributed to a tenant',
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                );
            }

            if ((bool) ($thumbCfg['enabled'] ?? true)) {
                try {
                    $result['thumb_url'] = $uploader->generateThumbnailFor(
                        $fileInput,
                        $pathPrefix,
                        (string) ($result['filename'] ?? ''),
                        (string) ($result['mime_type'] ?? ''),
                        [
                            'thumbnail_width' => $thumbCfg['width'] ?? null,
                            'thumbnail_height' => $thumbCfg['height'] ?? null,
                            'thumbnail_quality' => $thumbCfg['quality'] ?? null,
                        ],
                    );
                } catch (\Throwable $exception) {
                    $this->logger->warning('upload.thumbnail.deferred_failed', [
                        'blob_uuid' => $blobUuid,
                        'error' => $exception->getMessage(),
                    ]);
                    $result['thumb_url'] = null;
                }
            }

            // Add visibility to response
            $result['visibility'] = $visibility;

            return Response::created($result, 'Upload successful');
        } catch (ValidationException $e) {
            return Response::error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (UploadException $e) {
            return Response::error($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        } finally {
            if ($tempFile !== null && is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    #[ApiOperation(
        summary: 'Blob Metadata',
        description: 'Retrieve blob metadata without downloading the file content',
        tags: ['Blobs'],
    )]
    #[ApiResponse(200, BlobInfoData::class, description: 'Blob metadata retrieved')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(404, description: 'Blob not found')]
    public function info(Request $request, string $uuid): BlobInfoData|Response
    {
        if ($this->requiresAuthFor('info') && Utils::getUser() === null) {
            return Response::unauthorized('Authentication required');
        }

        $blob = $this->blobs->findByUuidWithDeleteFilter($uuid);
        if ($blob === null) {
            return Response::notFound('Blob not found');
        }

        $allowed = $this->accessPolicy->authorizeAccess(
            $blob,
            new BlobAccessContext(BlobAction::INFO, $this->authenticatedUserUuid(), false),
        );
        if (!$allowed) {
            return Response::notFound('Blob not found');
        }

        $rawPath = (string) ($blob['url'] ?? '');
        $blob['url'] = $this->resolveBlobUrl($blob);

        $native = $this->maybeNativeUrl($blob, $rawPath);
        if ($native !== null) {
            $blob['native_url'] = $native;
        }

        return new BlobInfoData($blob);
    }

    /**
     * @return Response|BinaryFileResponse|StreamedResponse|\Symfony\Component\HttpFoundation\Response
     */
    #[ApiOperation(
        summary: 'Retrieve Blob',
        description: 'Retrieve blob file content with optional image resizing.',
        tags: ['Blobs'],
    )]
    #[QueryParam('width', 'integer', 'Resize target width in pixels (images only)')]
    #[QueryParam('height', 'integer', 'Resize target height in pixels (images only)')]
    #[QueryParam('quality', 'integer', 'Output quality 1-100 (images only)')]
    #[QueryParam('format', 'string', 'Output format for conversion (images only)')]
    #[QueryParam('fit', 'string', 'Resize fit mode (images only)')]
    #[ApiResponse(
        200,
        contentType: 'application/octet-stream',
        body: 'binary',
        description: 'File content with appropriate Content-Type header',
    )]
    #[ApiResponse(401, description: 'Authentication required for private blob')]
    #[ApiResponse(404, description: 'Blob not found')]
    public function show(
        Request $request,
        string $uuid
    ): Response|BinaryFileResponse|StreamedResponse|\Symfony\Component\HttpFoundation\Response {
        $blob = $this->blobs->findByUuidWithDeleteFilter($uuid);
        if ($blob === null) {
            return Response::notFound('Blob not found');
        }

        // Check access based on visibility and auth
        $signatureValid = $this->hasValidSignature($request);
        $accessDenied = $this->checkBlobAccess($request, $blob, $signatureValid);
        if ($accessDenied !== null) {
            return $accessDenied;
        }
        $allowed = $this->accessPolicy->authorizeAccess(
            $blob,
            new BlobAccessContext(BlobAction::VIEW, $this->authenticatedUserUuid(), $signatureValid),
        );
        if (!$allowed) {
            return Response::notFound('Blob not found');
        }

        $disk = $this->resolveDisk($blob);
        $path = (string) ($blob['url'] ?? '');
        if ($path === '') {
            return Response::notFound('Blob file not found');
        }

        $isImage = str_starts_with((string) ($blob['mime_type'] ?? ''), 'image/');
        $mime = (string) ($blob['mime_type'] ?? 'application/octet-stream');
        $resize = $this->getResizeParams($request);

        if (
            $isImage
            && $resize !== null
            && $this->media !== null
            && (bool) $this->getConfig('uploads.image_processing.enabled', true)
        ) {
            return $this->serveResizedImage($request, $uuid, $disk, $path, $resize, $mime);
        }

        // No media processor bound: width/height/quality fall through to serving
        // the ORIGINAL. An explicit format conversion, however, cannot be honored
        // without a processor — fail loudly rather than serve a differently-typed
        // original.
        if ($isImage && $resize !== null && $this->media === null && ($resize['format'] ?? null) !== null) {
            return Response::error('Image format conversion is not available', 415);
        }

        $size = isset($blob['size']) ? (int) $blob['size'] : null;

        return $this->serveFile($request, $disk, $path, $mime, $size);
    }

    /**
     * Generate a signed URL for temporary access to a private blob.
     */
    #[ApiOperation(
        summary: 'Generate Signed URL',
        description: 'Generate a temporary signed URL for accessing a private blob.',
        tags: ['Blobs'],
    )]
    #[QueryParam('ttl', 'integer', 'URL lifetime in seconds (default: 3600, max: 604800)')]
    #[ApiResponse(200, SignedUrlData::class, description: 'Signed URL generated')]
    #[ApiResponse(400, description: 'Signed URLs are disabled')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(404, description: 'Blob not found')]
    public function signedUrl(Request $request, string $uuid): SignedUrlData|Response
    {
        if (Utils::getUser() === null) {
            return Response::unauthorized('Authentication required');
        }

        if (!(bool) $this->getConfig('uploads.signed_urls.enabled', true)) {
            return Response::error('Signed URLs are disabled', Response::HTTP_BAD_REQUEST);
        }

        $blob = $this->blobs->findByUuidWithDeleteFilter($uuid);
        if ($blob === null) {
            return Response::notFound('Blob not found');
        }

        $allowed = $this->accessPolicy->authorizeAccess(
            $blob,
            new BlobAccessContext(BlobAction::SIGN, $this->authenticatedUserUuid(), false),
        );
        if (!$allowed) {
            return Response::notFound('Blob not found');
        }

        $ttl = $request->query->getInt('ttl', 0);
        if ($ttl <= 0) {
            $ttl = (int) $this->getConfig('uploads.signed_urls.ttl', 3600);
        }

        // Cap TTL at 7 days
        $maxTtl = 7 * 24 * 60 * 60;
        if ($ttl > $maxTtl) {
            $ttl = $maxTtl;
        }

        $base = $this->publicUrlProvider?->publicBaseUrl($blob) ?? $request->getSchemeAndHttpHost();
        $baseUrl = rtrim($base, '/') . '/blobs/' . $uuid;
        $signedUrl = SignedUrl::make($this->getContext())->generate($baseUrl, $ttl);

        $rawPath = (string) ($blob['url'] ?? '');
        $native = $this->maybeNativeUrl($blob, $rawPath);

        return (new SignedUrlData(
            uuid: $uuid,
            signed_url: $signedUrl,
            expires_in: $ttl,
            expires_at: date('Y-m-d H:i:s', time() + $ttl),
        ))->withNativeUrl($native);
    }

    #[ApiOperation(
        summary: 'Delete Blob',
        description: 'Soft-delete a blob and remove its underlying file from storage',
        tags: ['Blobs'],
    )]
    #[ApiResponse(200, BlobDeletedData::class, description: 'Blob deleted')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(404, description: 'Blob not found')]
    public function delete(Request $request, string $uuid): BlobDeletedData|Response
    {
        if ($this->requiresAuthFor('delete') && Utils::getUser() === null) {
            return Response::unauthorized('Authentication required');
        }

        $blob = $this->blobs->findByUuidWithDeleteFilter($uuid, true);
        if ($blob === null) {
            return Response::notFound('Blob not found');
        }

        $allowed = $this->accessPolicy->authorizeAccess(
            $blob,
            new BlobAccessContext(BlobAction::DELETE, $this->authenticatedUserUuid(), false),
        );
        if (!$allowed) {
            return Response::notFound('Blob not found');
        }

        $disk = $this->resolveDisk($blob);
        $path = (string) ($blob['url'] ?? '');
        if ($path !== '') {
            $this->storage->disk($disk)->delete($path);
        }

        $this->blobs->updateStatus($uuid, 'deleted');

        return new BlobDeletedData($uuid);
    }

    /**
     * @return array{width?: int, height?: int, quality?: int, format?: string, fit?: string}|null
     */
    private function getResizeParams(Request $request): ?array
    {
        $width = $request->query->getInt('width', 0);
        $height = $request->query->getInt('height', 0);
        $quality = $request->query->getInt('quality', 0);
        $format = $request->query->get('format');
        $fit = $request->query->get('fit');

        if ($width <= 0 && $height <= 0 && $quality <= 0 && $format === null && $fit === null) {
            return null;
        }

        return [
            'width' => $width > 0 ? $width : null,
            'height' => $height > 0 ? $height : null,
            'quality' => $quality > 0 ? $quality : null,
            'format' => is_string($format) && $format !== '' ? $format : null,
            'fit' => is_string($fit) && $fit !== '' ? $fit : null,
        ];
    }

    /**
     * @param array<string, int|string|null> $resize
     */
    private function serveResizedImage(
        Request $request,
        string $uuid,
        string $disk,
        string $path,
        array $resize,
        string $sourceMime
    ): Response|\Symfony\Component\HttpFoundation\Response {
        $cacheEnabled = (bool) $this->getConfig('uploads.image_processing.cache_enabled', true);
        $cacheTtl = (int) $this->getConfig('uploads.image_processing.cache_ttl', 604800);
        $cacheKey = $this->buildCacheKey($uuid, $resize);
        $allowedFormats = (array) $this->getConfig('uploads.image_processing.allowed_formats', []);
        $etagEnabled = (bool) $this->getConfig('uploads.response.enable_etag', true);
        $maxVariantBytes = (int) $this->getConfig('uploads.image_processing.max_variant_bytes', 5 * 1024 * 1024);

        $format = $resize['format'] ?? null;
        if (
            $format !== null
            && $allowedFormats !== []
            && !in_array($format, $allowedFormats, true)
        ) {
            return Response::error('Unsupported image format', Response::HTTP_BAD_REQUEST);
        }

        // Generate ETag from cache key
        $etag = '"' . md5($cacheKey) . '"';

        // Check If-None-Match for 304 response
        if ($etagEnabled) {
            $ifNoneMatch = $request->headers->get('If-None-Match');
            if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
                return new \Symfony\Component\HttpFoundation\Response('', 304, [
                    'ETag' => $etag,
                    'Cache-Control' => $this->getCacheControl($cacheTtl),
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        // Check server-side cache. The variant bytes are stored base64-encoded (see the set below),
        // so decode before serving; a decode failure (legacy/corrupt entry) falls through to re-render.
        if ($cacheEnabled && $this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached) && isset($cached['data'], $cached['mime']) && is_string($cached['data'])) {
                $decoded = base64_decode($cached['data'], true);
                if ($decoded !== false) {
                    return $this->binaryResponse(
                        $decoded,
                        (string) $cached['mime'],
                        $cacheTtl,
                        $etagEnabled ? $etag : null
                    );
                }
            }
        }

        $temp = $this->readToTempFile($disk, $path);
        try {
            try {
                $this->imageSecurity?->validateImageFile($temp, $this->formatFromMime($sourceMime));
            } catch (BusinessLogicException $e) {
                return Response::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // @phpstan-ignore-next-line — guarded by the `$this->media !== null`
            // check in show(); serveResizedImage() is never reached with a null seam.
            ['data' => $data, 'mime' => $mime] = $this->media->renderVariant(
                $temp,
                $this->buildVariantOptions($resize)
            );

            // Validate max_variant_bytes
            if ($maxVariantBytes > 0 && strlen($data) > $maxVariantBytes) {
                return Response::error(
                    'Resized image exceeds maximum allowed size',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            if ($cacheEnabled && $this->cache !== null) {
                // Store base64-encoded: raw image bytes aren't valid UTF-8 and break JSON-based cache
                // serializers (e.g. the Redis driver's SecureSerializer -> json_encode).
                $this->cache->set($cacheKey, ['data' => base64_encode($data), 'mime' => $mime], $cacheTtl);
            }

            return $this->binaryResponse($data, $mime, $cacheTtl, $etagEnabled ? $etag : null);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Serve a file with optional Range request support and ETag headers.
     */
    private function serveFile(
        Request $request,
        string $disk,
        string $path,
        string $mime,
        ?int $fileSize = null
    ): Response|BinaryFileResponse|StreamedResponse|\Symfony\Component\HttpFoundation\Response {
        $rangeEnabled = (bool) $this->getConfig('uploads.response.enable_range_requests', true);
        $etagEnabled = (bool) $this->getConfig('uploads.response.enable_etag', true);
        $cacheControl = $this->getCacheControl();

        $diskCfg = (array) $this->getConfig("storage.disks.{$disk}", []);

        // For local files, use BinaryFileResponse with Range support
        if (($diskCfg['driver'] ?? '') === 'local' && isset($diskCfg['root'])) {
            $fullPath = rtrim((string) $diskCfg['root'], '/') . '/' . ltrim($path, '/');
            if (is_file($fullPath)) {
                $response = new BinaryFileResponse($fullPath);
                $response->headers->set('Content-Type', $mime);
                $response->headers->set('Cache-Control', $cacheControl);
                $this->applyBlobSecurityHeaders($response, $mime, basename($path));

                if ($etagEnabled) {
                    $etag = '"' . md5($fullPath . filemtime($fullPath)) . '"';
                    $response->headers->set('ETag', $etag);

                    $ifNoneMatch = $request->headers->get('If-None-Match');
                    if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
                        return new \Symfony\Component\HttpFoundation\Response('', 304, [
                            'ETag' => $etag,
                            'Cache-Control' => $cacheControl,
                            'X-Content-Type-Options' => 'nosniff',
                        ]);
                    }
                }

                if ($rangeEnabled) {
                    $response->headers->set('Accept-Ranges', 'bytes');
                    // BinaryFileResponse handles Range requests automatically
                    $response->prepare($request);
                }

                return $response;
            }
        }

        // For non-local storage, use streaming with manual Range support
        try {
            $stream = $this->storage->disk($disk)->readStream($path);
        } catch (\Throwable) {
            return Response::notFound('Blob file not found');
        }
        if (!is_resource($stream)) {
            return Response::notFound('Blob file not found');
        }

        // Get file size if not provided
        if ($fileSize === null) {
            $stat = fstat($stream);
            $fileSize = $stat !== false ? ($stat['size'] ?? 0) : 0;
        }

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => $this->contentDispositionFor($mime, basename($path)),
        ];

        if ($etagEnabled && $fileSize > 0) {
            $etag = '"' . md5($path . $fileSize) . '"';
            $headers['ETag'] = $etag;

            $ifNoneMatch = $request->headers->get('If-None-Match');
            if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
                fclose($stream);
                return new \Symfony\Component\HttpFoundation\Response('', 304, [
                    'ETag' => $etag,
                    'Cache-Control' => $cacheControl,
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        // Handle Range requests for streaming
        if ($rangeEnabled && $fileSize > 0) {
            $headers['Accept-Ranges'] = 'bytes';
            $rangeHeader = $request->headers->get('Range');

            if ($rangeHeader !== null && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
                $start = (int) $matches[1];
                $end = $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;

                if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
                    fclose($stream);
                    return new \Symfony\Component\HttpFoundation\Response(
                        'Range Not Satisfiable',
                        416,
                        ['Content-Range' => "bytes */{$fileSize}"]
                    );
                }

                $length = $end - $start + 1;
                fseek($stream, $start);

                $response = new StreamedResponse(function () use ($stream, $length) {
                    $remaining = $length;
                    while ($remaining > 0 && !feof($stream)) {
                        $chunk = fread($stream, min(8192, $remaining));
                        if ($chunk === false) {
                            break;
                        }
                        echo $chunk;
                        $remaining -= strlen($chunk);
                    }
                    fclose($stream);
                }, 206);

                $response->headers->add($headers);
                $response->headers->set('Content-Length', (string) $length);
                $response->headers->set('Content-Range', "bytes {$start}-{$end}/{$fileSize}");

                return $response;
            }
        }

        // Full file response
        $response = new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->add($headers);
        if ($fileSize > 0) {
            $response->headers->set('Content-Length', (string) $fileSize);
        }

        return $response;
    }

    /**
     * Assemble the option bag passed to MediaProcessorInterface::renderVariant().
     *
     * Merges the request's resize params (width/height/quality/format/fit) with
     * the core-owned `uploads.image_processing.*` clamps/defaults. The width/height
     * are clamped to max_width/max_height and quality defaults to default_quality.
     * The actual resize/encode (fit semantics, format) is performed by the bound
     * processor in the extension.
     *
     * @param array<string, int|string|null> $resize
     * @return array<string, int|string|null>
     */
    private function buildVariantOptions(array $resize): array
    {
        $maxWidth = (int) $this->getConfig('uploads.image_processing.max_width', 2048);
        $maxHeight = (int) $this->getConfig('uploads.image_processing.max_height', 2048);

        $width = $resize['width'] ?? null;
        $height = $resize['height'] ?? null;

        if (is_int($width) && $width > $maxWidth) {
            $width = $maxWidth;
        }
        if (is_int($height) && $height > $maxHeight) {
            $height = $maxHeight;
        }

        $quality = $resize['quality'] ?? null;
        if ($quality === null) {
            $quality = (int) $this->getConfig('uploads.image_processing.default_quality', 85);
        }

        return [
            'width' => $width,
            'height' => $height,
            'quality' => $quality,
            'format' => $resize['format'] ?? null,
            'fit' => $resize['fit'] ?? null,
        ];
    }

    private function binaryResponse(
        string $data,
        string $mime,
        int $cacheTtl,
        ?string $etag = null
    ): \Symfony\Component\HttpFoundation\Response {
        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => $this->getCacheControl($cacheTtl),
            'Content-Length' => (string) strlen($data),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => $this->contentDispositionFor($mime, 'variant'),
        ];

        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }

        return new \Symfony\Component\HttpFoundation\Response($data, 200, $headers);
    }

    private function applyBlobSecurityHeaders(
        \Symfony\Component\HttpFoundation\Response $response,
        string $mime,
        string $filename
    ): void {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', $this->contentDispositionFor($mime, $filename));
    }

    private function contentDispositionFor(string $mime, string $filename): string
    {
        $disposition = $this->isSafeInlineMime($mime)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        $safeFilename = $filename !== '' ? $filename : 'download';

        return (new ResponseHeaderBag())->makeDisposition($disposition, $safeFilename);
    }

    private function isSafeInlineMime(string $mime): bool
    {
        $normalized = strtolower(trim(explode(';', $mime, 2)[0]));

        return in_array($normalized, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    /**
     * Get Cache-Control header value from config or default.
     */
    private function getCacheControl(?int $maxAge = null): string
    {
        $configured = $this->getConfig('uploads.response.cache_control');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $maxAge = $maxAge ?? 86400;
        return 'public, max-age=' . $maxAge;
    }

    /**
     * @param array<string, mixed> $resize
     */
    private function buildCacheKey(string $uuid, array $resize): string
    {
        return 'blob_variant:' . sha1($uuid . '|' . json_encode($resize));
    }

    /**
     * @param array<string, mixed> $blob
     */
    private function resolveDisk(array $blob): string
    {
        $disk = (string) ($blob['storage_type'] ?? '');
        if ($disk !== '') {
            return $disk;
        }

        return (string) $this->getConfig('uploads.disk', 'uploads');
    }

    /**
     * @param array<string, mixed> $blob
     */
    private function resolveBlobUrl(array $blob): string
    {
        $path = (string) ($blob['url'] ?? '');
        $disk = $this->resolveDisk($blob);

        return $path !== '' ? $this->urls->url($path, $disk) : '';
    }

    /**
     * Decide whether to expose a native object-store URL for a blob, and mint it.
     *
     * @param array<string, mixed> $policy
     * @param callable(int): (string|null) $signer
     */
    public static function nativeUrlFor(
        array $policy,
        string $disk,
        string $visibility,
        callable $signer,
        int $defaultTtl
    ): ?string {
        /** @var array<string, array<string, mixed>> $disks */
        $disks = (array) ($policy['disks'] ?? []);
        $diskPolicy = $disks[$disk] ?? null;
        if (!is_array($diskPolicy) || ($diskPolicy['enabled'] ?? false) !== true) {
            return null;
        }

        if ($visibility === 'public') {
            if (($diskPolicy['public'] ?? false) !== true) {
                return null;
            }

            return $signer($defaultTtl);
        }

        if (($diskPolicy['private'] ?? false) !== true) {
            return null;
        }

        $maxTtl = (int) ($policy['max_private_ttl'] ?? 900);
        $ttl = (int) ($diskPolicy['private_ttl'] ?? $defaultTtl);
        if ($ttl <= 0 || $ttl > $maxTtl) {
            $ttl = $maxTtl;
        }

        return $signer($ttl);
    }

    /**
     * Resolve a disk factory and sign the raw stored object path.
     *
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $diskConfig
     */
    public static function nativeUrlViaRegistry(
        StorageDriverRegistryInterface $registry,
        array $policy,
        string $disk,
        array $diskConfig,
        string $visibility,
        string $rawPath,
        int $defaultTtl
    ): ?string {
        if ($rawPath === '') {
            return null;
        }

        $driver = (string) ($diskConfig['driver'] ?? '');
        if (!$registry->has($driver)) {
            return null;
        }

        $factory = $registry->get($driver);
        if (!$factory instanceof NativeSignedUrlProviderInterface) {
            return null;
        }

        return self::nativeUrlFor(
            $policy,
            $disk,
            $visibility,
            function (int $ttl) use ($factory, $rawPath, $diskConfig): ?string {
                try {
                    return $factory->temporaryUrl($rawPath, $ttl, $diskConfig);
                } catch (\Throwable) {
                    return null;
                }
            },
            $defaultTtl
        );
    }

    /**
     * @param array<string, mixed> $blob
     */
    private function maybeNativeUrl(array $blob, string $rawPath): ?string
    {
        /** @var array<string, mixed> $policy */
        $policy = (array) $this->getConfig('uploads.native_urls', []);
        if (($policy['disks'] ?? []) === []) {
            return null;
        }

        $disk = $this->resolveDisk($blob);

        return self::nativeUrlViaRegistry(
            $this->storage->drivers(),
            $policy,
            $disk,
            (array) $this->urls->diskConfig($disk),
            (string) ($blob['visibility'] ?? 'private'),
            $rawPath,
            (int) $this->getConfig('uploads.signed_urls.ttl', 3600)
        );
    }

    /**
     * @return CacheStore<mixed>|null
     */
    private function resolveCache(): ?CacheStore
    {
        try {
            /** @var CacheStore<mixed> $cache */
            $cache = container($this->getContext())->get(CacheStore::class);
            return $cache;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        return config($this->getContext(), $key, $default);
    }

    private function requiresAuthFor(string $action): bool
    {
        $access = $this->getConfig('uploads.access', 'private');
        $access = is_string($access) ? strtolower($access) : $access;

        // Private mode: auth required for everything
        if ($access === 'private' || $access === true || $access === 1 || $access === 'true') {
            return true;
        }

        // Upload-only mode: auth required for upload and delete, not retrieval
        if ($access === 'upload_only') {
            return in_array($action, ['upload', 'delete'], true);
        }

        // Public mode: no auth required
        return false;
    }

    private function authenticatedUserUuid(): ?string
    {
        $user = Utils::getUser();
        $uuid = is_array($user) ? ($user['uuid'] ?? null) : null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    private function compensateOwnerlessBlob(FileUploader $uploader, string $blobUuid, string $path): void
    {
        if ($path !== '' && !$uploader->getStorage()->delete($path)) {
            $this->logger->critical('upload.compensation.object_orphaned', [
                'blob_uuid' => $blobUuid,
                'path' => $path,
            ]);
        }

        if ($blobUuid !== '' && $this->blobs->forceDelete($blobUuid)) {
            return;
        }

        $updated = $blobUuid !== '' && $this->blobs->updateStatus($blobUuid, 'deleted');
        $row = $blobUuid !== ''
            ? $this->blobs->findByUuidWithDeleteFilter($blobUuid, includeDeleted: true)
            : null;
        $quarantined = $updated && $row !== null && ($row['status'] ?? null) === 'deleted';

        $this->logger->critical('upload.compensation.blob_quarantined', [
            'blob_uuid' => $blobUuid,
            'quarantined' => $quarantined,
        ]);
    }

    /**
     * Check blob access based on visibility, auth, and signed URL.
     *
     * @param array<string, mixed> $blob
     * @return Response|null Null if access allowed, Response if denied
     */
    private function checkBlobAccess(Request $request, array $blob, bool $signatureValid): ?Response
    {
        $visibility = (string) ($blob['visibility'] ?? 'private');
        // Public blobs are accessible only when retrieval is not globally auth-gated.
        if ($visibility === 'public' && !$this->requiresAuthFor('retrieve')) {
            return null;
        }

        // Check if user is authenticated
        if (Utils::getUser() !== null) {
            return null;
        }

        // Check for valid signed URL
        if ($signatureValid) {
            return null;
        }

        // For private visibility or private global access, auth is required
        if ($visibility === 'private' || $this->requiresAuthFor('retrieve')) {
            return Response::unauthorized('Authentication required');
        }

        return null;
    }

    /**
     * Check if the request has a valid signed URL signature.
     */
    private function hasValidSignature(Request $request): bool
    {
        if (!(bool) $this->getConfig('uploads.signed_urls.enabled', true)) {
            return false;
        }

        $expires = $request->query->get('expires');
        $signature = $request->query->get('signature');

        if ($expires === null || $signature === null) {
            return false;
        }

        $path = $request->getPathInfo();
        $params = $request->query->all();

        return SignedUrl::make($this->getContext())->validateParams($path, $params);
    }

    private function buildPathPrefix(?string $override): string
    {
        $base = (string) $this->getConfig('uploads.path_prefix', 'uploads');
        $base = trim($base, '/');

        if ($override === null || $override === '') {
            return $base;
        }

        $segment = preg_replace('/[^a-zA-Z0-9_\\/-]+/', '', $override) ?? '';
        $segment = trim($segment, '/');

        return $segment !== '' ? $base . '/' . $segment : $base;
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/wav' => 'wav',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function formatToMime(?string $format): string
    {
        $format = $format !== null ? strtolower($format) : '';
        return match ($format) {
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function formatFromMime(string $mime): ?string
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }

    private function readToTempFile(string $disk, string $path): string
    {
        $stream = $this->storage->disk($disk)->readStream($path);
        if (!is_resource($stream)) {
            throw new \RuntimeException('File not found');
        }

        $temp = tempnam(sys_get_temp_dir(), 'blob_');
        if ($temp === false) {
            throw new \RuntimeException('Failed to create temp file');
        }

        $out = fopen($temp, 'w');
        if ($out === false) {
            throw new \RuntimeException('Failed to write temp file');
        }

        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return $temp;
    }
}
