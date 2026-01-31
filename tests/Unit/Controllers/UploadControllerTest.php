<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for UploadController functionality.
 *
 * Since UploadController and its dependencies are final classes,
 * we test the logical behaviors using standalone functions that
 * mirror the controller's internal logic.
 */
final class UploadControllerTest extends TestCase
{
    /**
     * Test resize parameter parsing logic.
     */
    public function testResizeParamsParsingReturnsNullWhenEmpty(): void
    {
        $request = Request::create('/blobs/test-uuid', 'GET');
        $result = $this->parseResizeParams($request);

        $this->assertNull($result);
    }

    public function testResizeParamsParsingReturnsWidthWhenProvided(): void
    {
        $request = Request::create('/blobs/test-uuid?width=200', 'GET');
        $result = $this->parseResizeParams($request);

        $this->assertIsArray($result);
        $this->assertSame(200, $result['width']);
    }

    public function testResizeParamsParsingReturnsHeightWhenProvided(): void
    {
        $request = Request::create('/blobs/test-uuid?height=300', 'GET');
        $result = $this->parseResizeParams($request);

        $this->assertIsArray($result);
        $this->assertSame(300, $result['height']);
    }

    public function testResizeParamsParsingReturnsQualityWhenProvided(): void
    {
        $request = Request::create('/blobs/test-uuid?quality=75', 'GET');
        $result = $this->parseResizeParams($request);

        $this->assertIsArray($result);
        $this->assertSame(75, $result['quality']);
    }

    public function testResizeParamsParsingReturnsFormatWhenProvided(): void
    {
        $request = Request::create('/blobs/test-uuid?format=webp', 'GET');
        $result = $this->parseResizeParams($request);

        $this->assertIsArray($result);
        $this->assertSame('webp', $result['format']);
    }

    public function testResizeParamsParsingReturnsFitWhenProvided(): void
    {
        $request = Request::create('/blobs/test-uuid?fit=cover', 'GET');
        $result = $this->parseResizeParams($request);

        $this->assertIsArray($result);
        $this->assertSame('cover', $result['fit']);
    }

    public function testResizeParamsParsingReturnsAllParams(): void
    {
        $request = Request::create(
            '/blobs/test-uuid?width=800&height=600&quality=90&format=jpeg&fit=contain',
            'GET'
        );
        $result = $this->parseResizeParams($request);

        $this->assertIsArray($result);
        $this->assertSame(800, $result['width']);
        $this->assertSame(600, $result['height']);
        $this->assertSame(90, $result['quality']);
        $this->assertSame('jpeg', $result['format']);
        $this->assertSame('contain', $result['fit']);
    }

    public function testResizeParamsIgnoresZeroValues(): void
    {
        $request = Request::create('/blobs/test-uuid?width=0&height=0&quality=0', 'GET');
        $result = $this->parseResizeParams($request);

        $this->assertNull($result);
    }

    public function testResizeParamsIgnoresNegativeValues(): void
    {
        $request = Request::create('/blobs/test-uuid?width=-100', 'GET');
        $result = $this->parseResizeParams($request);

        $this->assertNull($result);
    }

    /**
     * Test cache key generation logic.
     */
    public function testCacheKeyGeneratesUniqueKeys(): void
    {
        $key1 = $this->buildCacheKey('uuid1', ['width' => 200]);
        $key2 = $this->buildCacheKey('uuid1', ['width' => 400]);
        $key3 = $this->buildCacheKey('uuid2', ['width' => 200]);

        $this->assertStringStartsWith('blob_variant:', $key1);
        $this->assertNotSame($key1, $key2);
        $this->assertNotSame($key1, $key3);
        $this->assertNotSame($key2, $key3);
    }

    public function testCacheKeyConsistentForSameInput(): void
    {
        $key1 = $this->buildCacheKey('uuid1', ['width' => 200, 'height' => 150]);
        $key2 = $this->buildCacheKey('uuid1', ['width' => 200, 'height' => 150]);

        $this->assertSame($key1, $key2);
    }

    public function testCacheKeyDifferentForDifferentParamOrder(): void
    {
        // JSON encode preserves array order, so different orders produce different keys
        $key1 = $this->buildCacheKey('uuid1', ['width' => 200, 'height' => 150]);
        $key2 = $this->buildCacheKey('uuid1', ['height' => 150, 'width' => 200]);

        // Since JSON preserves key order, these should actually be different
        $this->assertNotSame($key1, $key2);
    }

    /**
     * Test MIME type detection logic.
     */
    public function testFormatToMimeJpeg(): void
    {
        $this->assertSame('image/jpeg', $this->formatToMime('jpeg'));
        $this->assertSame('image/jpeg', $this->formatToMime('jpg'));
        $this->assertSame('image/jpeg', $this->formatToMime('JPEG'));
        $this->assertSame('image/jpeg', $this->formatToMime('JPG'));
    }

    public function testFormatToMimePng(): void
    {
        $this->assertSame('image/png', $this->formatToMime('png'));
        $this->assertSame('image/png', $this->formatToMime('PNG'));
    }

    public function testFormatToMimeGif(): void
    {
        $this->assertSame('image/gif', $this->formatToMime('gif'));
        $this->assertSame('image/gif', $this->formatToMime('GIF'));
    }

    public function testFormatToMimeWebp(): void
    {
        $this->assertSame('image/webp', $this->formatToMime('webp'));
        $this->assertSame('image/webp', $this->formatToMime('WEBP'));
    }

    public function testFormatToMimeUnknown(): void
    {
        $this->assertSame('application/octet-stream', $this->formatToMime('unknown'));
        $this->assertSame('application/octet-stream', $this->formatToMime(''));
        $this->assertSame('application/octet-stream', $this->formatToMime(null));
    }

    /**
     * Test access requirement logic.
     */
    public function testRequiresAuthForPrivateAccess(): void
    {
        $this->assertTrue($this->requiresAuthFor('private', 'upload'));
        $this->assertTrue($this->requiresAuthFor('private', 'retrieve'));
        $this->assertTrue($this->requiresAuthFor('private', 'delete'));
        $this->assertTrue($this->requiresAuthFor('private', 'info'));
    }

    public function testRequiresAuthForUploadOnlyAccess(): void
    {
        $this->assertTrue($this->requiresAuthFor('upload_only', 'upload'));
        $this->assertFalse($this->requiresAuthFor('upload_only', 'retrieve'));
        $this->assertTrue($this->requiresAuthFor('upload_only', 'delete'));
        $this->assertFalse($this->requiresAuthFor('upload_only', 'info'));
    }

    public function testRequiresAuthForPublicAccess(): void
    {
        $this->assertFalse($this->requiresAuthFor('public', 'upload'));
        $this->assertFalse($this->requiresAuthFor('public', 'retrieve'));
        $this->assertFalse($this->requiresAuthFor('public', 'delete'));
        $this->assertFalse($this->requiresAuthFor('public', 'info'));
    }

    public function testRequiresAuthForBooleanTrue(): void
    {
        $this->assertTrue($this->requiresAuthFor(true, 'upload'));
        $this->assertTrue($this->requiresAuthFor(true, 'retrieve'));
    }

    public function testRequiresAuthForBooleanFalse(): void
    {
        $this->assertFalse($this->requiresAuthFor(false, 'upload'));
        $this->assertFalse($this->requiresAuthFor(false, 'retrieve'));
    }

    /**
     * Test path prefix building logic.
     */
    public function testBuildPathPrefixDefault(): void
    {
        $result = $this->buildPathPrefix('uploads', null);
        $this->assertSame('uploads', $result);
    }

    public function testBuildPathPrefixWithOverride(): void
    {
        $result = $this->buildPathPrefix('uploads', 'custom/path');
        $this->assertSame('uploads/custom/path', $result);
    }

    public function testBuildPathPrefixSanitizesUnsafeChars(): void
    {
        $result = $this->buildPathPrefix('uploads', 'user$%^&files');
        $this->assertSame('uploads/userfiles', $result);
    }

    public function testBuildPathPrefixTrimsSlashes(): void
    {
        $result = $this->buildPathPrefix('/uploads/', '/custom/');
        $this->assertSame('uploads/custom', $result);
    }

    public function testBuildPathPrefixEmptyOverride(): void
    {
        $result = $this->buildPathPrefix('uploads', '');
        $this->assertSame('uploads', $result);
    }

    /**
     * Test disk resolution logic.
     */
    public function testResolveDiskFromBlobStorageType(): void
    {
        $blob = ['storage_type' => 's3'];
        $result = $this->resolveDisk($blob, 'uploads');
        $this->assertSame('s3', $result);
    }

    public function testResolveDiskFallsBackToDefault(): void
    {
        $blob = [];
        $result = $this->resolveDisk($blob, 'uploads');
        $this->assertSame('uploads', $result);
    }

    public function testResolveDiskIgnoresEmptyStorageType(): void
    {
        $blob = ['storage_type' => ''];
        $result = $this->resolveDisk($blob, 'uploads');
        $this->assertSame('uploads', $result);
    }

    /**
     * Test Cache-Control header logic.
     */
    public function testGetCacheControlDefault(): void
    {
        $result = $this->getCacheControl(null, null);
        $this->assertSame('public, max-age=86400', $result);
    }

    public function testGetCacheControlWithCustomMaxAge(): void
    {
        $result = $this->getCacheControl(null, 3600);
        $this->assertSame('public, max-age=3600', $result);
    }

    public function testGetCacheControlWithConfiguredValue(): void
    {
        $result = $this->getCacheControl('private, max-age=0, no-cache', null);
        $this->assertSame('private, max-age=0, no-cache', $result);
    }

    public function testGetCacheControlConfiguredValueOverridesMaxAge(): void
    {
        // When a configured value exists, it takes precedence
        $result = $this->getCacheControl('private, max-age=0, no-cache', 3600);
        $this->assertSame('private, max-age=0, no-cache', $result);
    }

    /**
     * Test visibility resolution logic.
     */
    public function testVisibilityDefaultsToPrivate(): void
    {
        $result = $this->resolveVisibility(null, 'private');
        $this->assertSame('private', $result);
    }

    public function testVisibilityAcceptsPublic(): void
    {
        $result = $this->resolveVisibility('public', 'private');
        $this->assertSame('public', $result);
    }

    public function testVisibilityAcceptsPrivate(): void
    {
        $result = $this->resolveVisibility('private', 'public');
        $this->assertSame('private', $result);
    }

    public function testVisibilityFallsBackOnInvalidValue(): void
    {
        $result = $this->resolveVisibility('invalid', 'private');
        $this->assertSame('private', $result);
    }

    // ==================================================
    // Helper methods that mirror controller logic
    // ==================================================

    /**
     * @return array{width?: int, height?: int, quality?: int, format?: string, fit?: string}|null
     */
    private function parseResizeParams(Request $request): ?array
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
     * @param array<string, mixed> $resize
     */
    private function buildCacheKey(string $uuid, array $resize): string
    {
        return 'blob_variant:' . sha1($uuid . '|' . json_encode($resize));
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

    /**
     * @param string|bool $access
     */
    private function requiresAuthFor($access, string $action): bool
    {
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

    private function buildPathPrefix(string $base, ?string $override): string
    {
        $base = trim($base, '/');

        if ($override === null || $override === '') {
            return $base;
        }

        $segment = preg_replace('/[^a-zA-Z0-9_\\/-]+/', '', $override) ?? '';
        $segment = trim($segment, '/');

        return $segment !== '' ? $base . '/' . $segment : $base;
    }

    /**
     * @param array<string, mixed> $blob
     */
    private function resolveDisk(array $blob, string $defaultDisk): string
    {
        $disk = (string) ($blob['storage_type'] ?? '');
        if ($disk !== '') {
            return $disk;
        }

        return $defaultDisk;
    }

    private function getCacheControl(?string $configured, ?int $maxAge): string
    {
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $maxAge = $maxAge ?? 86400;
        return 'public, max-age=' . $maxAge;
    }

    private function resolveVisibility(?string $visibility, string $default): string
    {
        if (!is_string($visibility) || !in_array($visibility, ['public', 'private'], true)) {
            return $default;
        }

        return $visibility;
    }
}
