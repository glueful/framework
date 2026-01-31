<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Helpers\RequestHelper;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Glueful\Repository\BlobRepository;
use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;
use Glueful\Uploader\FileUploader;
use Glueful\Uploader\UploadException;
use Glueful\Validation\ValidationException;
use Glueful\Services\ImageProcessor;
use Glueful\Support\SignedUrl;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UploadController extends BaseController
{
    private FileUploader $uploader;
    private BlobRepository $blobs;
    private StorageManager $storage;
    private UrlGenerator $urls;
    /** @var CacheStore<mixed>|null */
    private ?CacheStore $cache;

    public function __construct(
        ApplicationContext $context,
        FileUploader $uploader,
        BlobRepository $blobRepository,
        StorageManager $storage,
        UrlGenerator $urls
    ) {
        parent::__construct($context);
        $this->uploader = $uploader;
        $this->blobs = $blobRepository;
        $this->storage = $storage;
        $this->urls = $urls;
        $this->cache = $this->resolveCache();
    }

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
                $filename = (string) ($payload['filename'] ?? 'upload.bin');
                $mime = (string) ($payload['mime_type'] ?? $this->detectMime($tempFile));
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
                ? new FileUploader(storageDriver: $disk, context: $this->getContext())
                : $this->uploader;

            // Determine visibility
            $visibility = $payload['visibility'] ?? null;
            if (!is_string($visibility) || !in_array($visibility, ['public', 'private'], true)) {
                $visibility = (string) $this->getConfig('uploads.default_visibility', 'private');
            }

            $thumbCfg = (array) $this->getConfig('uploads.thumbnails', []);
            $result = $uploader->uploadMedia($fileInput, $pathPrefix, [
                'generate_thumbnail' => (bool) ($thumbCfg['enabled'] ?? true),
                'thumbnail_width' => $thumbCfg['width'] ?? null,
                'thumbnail_height' => $thumbCfg['height'] ?? null,
                'thumbnail_quality' => $thumbCfg['quality'] ?? null,
                'save_to_blobs' => true,
                'visibility' => $visibility,
            ]);

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

    public function info(Request $request, string $uuid): Response
    {
        if ($this->requiresAuthFor('info') && Utils::getUser() === null) {
            return Response::unauthorized('Authentication required');
        }

        $blob = $this->blobs->findByUuidWithDeleteFilter($uuid);
        if ($blob === null) {
            return Response::notFound('Blob not found');
        }

        $blob['url'] = $this->resolveBlobUrl($blob);

        return Response::success($blob, 'Blob metadata');
    }

    /**
     * @return Response|BinaryFileResponse|StreamedResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function show(
        Request $request,
        string $uuid
    ): Response|BinaryFileResponse|StreamedResponse|\Symfony\Component\HttpFoundation\Response {
        $blob = $this->blobs->findByUuidWithDeleteFilter($uuid);
        if ($blob === null) {
            return Response::notFound('Blob not found');
        }

        // Check access based on visibility and auth
        $accessDenied = $this->checkBlobAccess($request, $blob);
        if ($accessDenied !== null) {
            return $accessDenied;
        }

        $disk = $this->resolveDisk($blob);
        $path = (string) ($blob['url'] ?? '');
        if ($path === '') {
            return Response::notFound('Blob file not found');
        }

        $isImage = str_starts_with((string) ($blob['mime_type'] ?? ''), 'image/');
        $resize = $this->getResizeParams($request);

        if ($isImage && $resize !== null && (bool) $this->getConfig('uploads.image_processing.enabled', true)) {
            return $this->serveResizedImage($request, $uuid, $disk, $path, $resize);
        }

        $mime = (string) ($blob['mime_type'] ?? 'application/octet-stream');
        $size = isset($blob['size']) ? (int) $blob['size'] : null;

        return $this->serveFile($request, $disk, $path, $mime, $size);
    }

    /**
     * Generate a signed URL for temporary access to a private blob.
     */
    public function signedUrl(Request $request, string $uuid): Response
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

        $ttl = $request->query->getInt('ttl', 0);
        if ($ttl <= 0) {
            $ttl = (int) $this->getConfig('uploads.signed_urls.ttl', 3600);
        }

        // Cap TTL at 7 days
        $maxTtl = 7 * 24 * 60 * 60;
        if ($ttl > $maxTtl) {
            $ttl = $maxTtl;
        }

        $baseUrl = $request->getSchemeAndHttpHost() . '/blobs/' . $uuid;
        $signedUrl = SignedUrl::make($this->getContext())->generate($baseUrl, $ttl);

        return Response::success([
            'uuid' => $uuid,
            'signed_url' => $signedUrl,
            'expires_in' => $ttl,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ], 'Signed URL generated');
    }

    public function delete(Request $request, string $uuid): Response
    {
        if ($this->requiresAuthFor('delete') && Utils::getUser() === null) {
            return Response::unauthorized('Authentication required');
        }

        $blob = $this->blobs->findByUuidWithDeleteFilter($uuid, true);
        if ($blob === null) {
            return Response::notFound('Blob not found');
        }

        $disk = $this->resolveDisk($blob);
        $path = (string) ($blob['url'] ?? '');
        if ($path !== '') {
            $this->storage->disk($disk)->delete($path);
        }

        $this->blobs->updateStatus($uuid, 'deleted');

        return Response::success(['uuid' => $uuid], 'Blob deleted');
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
        array $resize
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
                ]);
            }
        }

        // Check server-side cache
        if ($cacheEnabled && $this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached) && isset($cached['data'], $cached['mime']) && is_string($cached['data'])) {
                return $this->binaryResponse(
                    $cached['data'],
                    (string) $cached['mime'],
                    $cacheTtl,
                    $etagEnabled ? $etag : null
                );
            }
        }

        $temp = $this->readToTempFile($disk, $path);
        try {
            $processor = ImageProcessor::make($temp, $this->getContext());
            $this->applyResizeOptions($processor, $resize);
            $data = $processor->getImageData($resize['format'] ?? null);
            $mime = $processor->getMimeType();

            // Validate max_variant_bytes
            if ($maxVariantBytes > 0 && strlen($data) > $maxVariantBytes) {
                return Response::error(
                    'Resized image exceeds maximum allowed size',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            if ($cacheEnabled && $this->cache !== null) {
                $this->cache->set($cacheKey, ['data' => $data, 'mime' => $mime], $cacheTtl);
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

                if ($etagEnabled) {
                    $etag = '"' . md5($fullPath . filemtime($fullPath)) . '"';
                    $response->headers->set('ETag', $etag);

                    $ifNoneMatch = $request->headers->get('If-None-Match');
                    if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
                        return new \Symfony\Component\HttpFoundation\Response('', 304, [
                            'ETag' => $etag,
                            'Cache-Control' => $cacheControl,
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
     * @param array<string, int|string|null> $resize
     */
    private function applyResizeOptions(ImageProcessor $processor, array $resize): void
    {
        $maxWidth = (int) $this->getConfig('uploads.image_processing.max_width', 2048);
        $maxHeight = (int) $this->getConfig('uploads.image_processing.max_height', 2048);

        $width = $resize['width'] ?? null;
        $height = $resize['height'] ?? null;

        if ($width !== null && $width > $maxWidth) {
            $width = $maxWidth;
        }
        if ($height !== null && $height > $maxHeight) {
            $height = $maxHeight;
        }

        $fit = $resize['fit'] ?? 'contain';
        if ($fit === 'cover' && $width !== null && $height !== null) {
            $processor->fit($width, $height);
        } elseif ($fit === 'fill' && ($width !== null || $height !== null)) {
            $processor->resize($width, $height, false);
        } elseif ($width !== null || $height !== null) {
            $processor->resize($width, $height, true);
        }

        $quality = $resize['quality'] ?? null;
        if ($quality !== null) {
            $processor->quality($quality);
        } else {
            $processor->quality((int) $this->getConfig('uploads.image_processing.default_quality', 85));
        }

        $format = $resize['format'] ?? null;
        if ($format !== null) {
            $processor->format($format);
        }
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
        ];

        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }

        return new \Symfony\Component\HttpFoundation\Response($data, 200, $headers);
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

    /**
     * Check blob access based on visibility, auth, and signed URL.
     *
     * @param array<string, mixed> $blob
     * @return Response|null Null if access allowed, Response if denied
     */
    private function checkBlobAccess(Request $request, array $blob): ?Response
    {
        $visibility = (string) ($blob['visibility'] ?? 'private');
        $globalAccess = $this->getConfig('uploads.access', 'private');

        // Public blobs are always accessible (unless global access is private)
        if ($visibility === 'public' && $globalAccess !== 'private') {
            return null;
        }

        // Check if user is authenticated
        if (Utils::getUser() !== null) {
            return null;
        }

        // Check for valid signed URL
        if ($this->hasValidSignature($request)) {
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
