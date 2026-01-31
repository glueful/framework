# Upload Endpoints Implementation Plan

**Version:** 1.22.0+
**Status:** Proposed
**Author:** Framework Team
**Date:** 2026-01-30

---

## Overview

Add built-in file upload and retrieval endpoints to Glueful's core framework routes. This provides users with secure, configurable file handling out of the box, including on-demand image resizing during retrieval.

> **Opt-in Feature:** These endpoints are **disabled by default**. Enable with `UPLOADS_ENABLED=true` in your `.env` or `'enabled' => true` in `config/uploads.php`.

### Goals

1. **Opt-in, explicit activation** - Disabled by default, requires explicit config to enable
2. **Configurable** - Users can customize allowed types, size limits, auth requirements
3. **On-demand image resizing** - Resize images at retrieval time with caching
4. **Secure by default** - Content-based MIME validation, decompression bomb protection, EXIF stripping
5. **Leverage existing infrastructure** - Use `FileUploader`, `ImageProcessor`, `StorageManager`
6. **Performance-ready** - HTTP Range for streaming, ETag/Cache headers, variant size limits

---

## API Design

### Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| `POST` | `/uploads` | Upload a file | Configurable |
| `GET` | `/uploads/{uuid}` | Retrieve a file (with optional resize) | Configurable |
| `DELETE` | `/uploads/{uuid}` | Delete an uploaded file | Required |
| `GET` | `/uploads/{uuid}/info` | Get file metadata | Configurable |

**Why UUID instead of path?**
- Consistent with Glueful's REST pattern (`/data/{table}/{uuid}`)
- Hides internal storage structure (security)
- Enables permission checks via blob record
- Cleaner URLs, no wildcard path matching needed

### Access Control

Retrieval visibility is controlled by the `access` config:

| Mode | Upload | `GET /uploads/{uuid}` | Direct URL (`data.url`) |
|------|--------|----------------------|-------------------------|
| `private` | Auth required | Auth required | N/A (use endpoint) |
| `upload_only` | Auth required | Public | Public |
| `public` | Public | Public | Public |

**Default is `private`** - both upload and retrieval require authentication. The blob record stores `created_by` for ownership checks on delete.

### Upload Request

**Option 1: Multipart Form (type=file)**

```http
POST /api/v1/uploads
Content-Type: multipart/form-data
Authorization: Bearer <token>

type: file
file: <binary>
path_prefix: "avatars" (optional)
```

**Option 2: Base64 Encoded (type=base64)**

```http
POST /api/v1/uploads
Content-Type: application/json
Authorization: Bearer <token>

{
  "type": "base64",
  "data": "iVBORw0KGgoAAAANSUhEUgAA...",
  "filename": "photo.jpg",
  "mime_type": "image/jpeg",
  "path_prefix": "avatars"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | `file` or `base64` |
| `file` | binary | If type=file | The uploaded file |
| `data` | string | If type=base64 | Base64 encoded file content |
| `filename` | string | If type=base64 | Original filename with extension |
| `mime_type` | string | No | MIME type (auto-detected if not provided) |
| `path_prefix` | string | No | Override default storage path prefix |

### Upload Response

```json
{
  "success": true,
  "data": {
    "uuid": "abc123-def456",
    "url": "https://cdn.example.com/uploads/2024/01/abc123.jpg",
    "thumb_url": null,
    "filename": "abc123_original.jpg",
    "original_name": "profile-photo.jpg",
    "mime_type": "image/jpeg",
    "size": 245678,
    "width": 1920,
    "height": 1080
  }
}
```

> **Note:** `thumb_url` is `null` by default. Enable `thumbnails.enabled` to auto-generate thumbnails on upload, or use on-demand resize: `GET /uploads/{uuid}?width=400&height=400`

### Retrieval with Resize

```http
GET /api/v1/uploads/abc123-def456?width=300&height=200&quality=80&format=webp
```

| Parameter | Type | Description | Default |
|-----------|------|-------------|---------|
| `width` | int | Target width (max configurable, default 2048) | Original |
| `height` | int | Target height (max configurable, default 2048) | Original |
| `quality` | int | JPEG/WebP quality (1-100) | 85 |
| `format` | string | Output format - must be in `allowed_formats` | Original |
| `fit` | string | Resize mode (see below) | `contain` |

**Fit modes:**
- `contain` - Scale to fit within bounds, maintain aspect ratio (may have letterboxing)
- `cover` - Scale to cover bounds, maintain aspect ratio, crop overflow
- `fill` - Stretch to exact dimensions (may distort)

**Unknown values rejected** - Invalid `format` or `fit` values return `422 VALIDATION_FAILED`

---

## Configuration

### config/uploads.php

```php
<?php

return [
    // Enable/disable upload routes (opt-in, disabled by default)
    'enabled' => env('UPLOADS_ENABLED', false),

    // Allowed file types (wildcards supported)
    // null = use framework defaults
    'allowed_types' => [
        'image/*',
        'video/*',
        'audio/*',
        'application/pdf',
    ],

    // Maximum file size in bytes (10MB default)
    'max_size' => env('UPLOADS_MAX_SIZE', 10 * 1024 * 1024),

    // Storage path prefix
    'path_prefix' => env('UPLOADS_PATH_PREFIX', 'uploads'),

    // Access control mode:
    // - 'private': Auth required for upload AND retrieval
    // - 'public': No auth required (not recommended)
    // - 'upload_only': Auth for uploads, public retrieval
    'access' => env('UPLOADS_ACCESS', 'private'),

    // Storage disk (from config/storage.php)
    'disk' => env('UPLOADS_DISK', 'uploads'),

    // Image processing settings
    'image_processing' => [
        'enabled' => true,
        'max_width' => 2048,
        'max_height' => 2048,
        'max_pixels' => 25000000, // 25MP - decompression bomb protection
        'default_quality' => 85,
        'default_fit' => 'contain', // contain, cover, fill
        'allowed_formats' => ['jpeg', 'jpg', 'png', 'webp', 'gif'],
        'max_variant_bytes' => 5 * 1024 * 1024, // 5MB max for generated variants
        'cache_enabled' => true,
        'cache_ttl' => 604800, // 7 days
    ],

    // Auto-generate thumbnails on upload (disabled by default - use on-demand resize instead)
    'thumbnails' => [
        'enabled' => env('UPLOADS_THUMBNAILS', false),
        'width' => 400,
        'height' => 400,
        'quality' => 80,
    ],

    // File organization
    'organization' => [
        'structure' => 'month', // year, month, day, none
        'unique_names' => true,
        'preserve_original_name' => true,
    ],

    // Rate limiting
    'rate_limits' => [
        'uploads_per_minute' => 30,
        'retrieval_per_minute' => 200,
    ],

    // Security (hardened defaults)
    'security' => [
        'scan_uploads' => true,
        'validate_mime_by_content' => true, // Inspect file bytes, not headers
        'strip_exif' => true, // Strip by default for privacy
        'max_filename_length' => 255,
    ],

    // HTTP response settings
    'response' => [
        'enable_range_requests' => true, // HTTP Range for video/audio streaming
        'enable_etag' => true,           // ETag headers for caching
        'cache_control' => 'public, max-age=86400', // 1 day browser cache
    ],
];
```

---

## Storage & URL Generation

The `disk` config determines where files are stored and how URLs are generated.

### Storage Drivers

| Driver | Config | URL Generation |
|--------|--------|----------------|
| `local` | `storage.disks.uploads.root` | `{base_url}/{path}` from disk config |
| `s3` | `storage.disks.uploads.bucket` | S3 URL or CDN if `cdn_base_url` configured |

### How URLs Work

```php
// config/storage.php
'disks' => [
    'uploads' => [
        'driver' => 'local',
        'root' => storage_path('uploads'),
        'base_url' => env('UPLOADS_URL', '/storage/uploads'),
    ],
    // OR for S3:
    'uploads' => [
        'driver' => 's3',
        'bucket' => env('AWS_BUCKET'),
        'cdn_base_url' => env('CDN_URL'), // Optional CDN
    ],
],
```

The `url` returned in upload responses uses `StorageManager->url($path)`, which resolves based on the configured disk driver. For S3 with CDN, set `cdn_base_url` to return CDN URLs instead of direct S3 URLs.

### Retrieval URL vs Direct URL

| Endpoint | Use Case |
|----------|----------|
| `GET /uploads/{uuid}` | Framework-mediated retrieval with auth, resize, caching |
| `data.url` (from upload response) | Direct storage URL (local path or S3/CDN) for public assets |

For `access: 'private'` mode, clients should use the `/uploads/{uuid}` endpoint. For `access: 'upload_only'` or `'public'`, the direct `data.url` can be used for better performance (bypasses framework).

---

## Files to Create/Modify

### New Files

| File | Purpose |
|------|---------|
| `config/uploads.php` | Upload configuration |
| `src/Controllers/UploadController.php` | Handle upload/retrieval logic |
| `routes/uploads.php` | Define upload routes |

### Modified Files

| File | Changes |
|------|---------|
| `src/Routing/RouteManifest.php` | Include uploads.php in route loading |

---

## Implementation Details

### 1. UploadController

```php
<?php

namespace Glueful\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Uploader\FileUploader;
use Glueful\Services\ImageProcessor;
use Glueful\Repository\BlobRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UploadController extends BaseController
{
    private FileUploader $uploader;
    private BlobRepository $blobRepository;

    public function __construct(
        ApplicationContext $context,
        FileUploader $uploader,
        BlobRepository $blobRepository
    ) {
        parent::__construct($context);
        $this->uploader = $uploader;
        $this->blobRepository = $blobRepository;
    }

    /**
     * Handle file upload (multipart or base64)
     */
    public function upload(Request $request): Response
    {
        // 1. Determine upload type from request
        $type = $request->get('type', 'file');

        // 2. Process based on type
        if ($type === 'base64') {
            // a. Decode base64 data
            // b. Save to temp file
            // c. Validate MIME type matches declared
        } else {
            // a. Get file from multipart request
        }

        // 3. Validate against allowed_types config
        // 4. Validate size against max_size
        // 5. Use FileUploader->uploadMedia()
        // 6. Return structured response with UUID, URL, metadata
    }

    /**
     * Retrieve file with optional resize
     */
    public function retrieve(Request $request, string $uuid): Response
    {
        // 1. Look up blob record by UUID
        // 2. Resolve file path from blob.url
        // 3. If image + resize params: use ImageProcessor
        // 4. Cache resized version
        // 5. Return file with proper headers
    }

    /**
     * Delete uploaded file
     */
    public function delete(Request $request, string $uuid): Response
    {
        // 1. Find blob by UUID
        // 2. Verify ownership/permissions
        // 3. Delete from storage
        // 4. Delete blob record
    }

    /**
     * Get file metadata
     */
    public function info(Request $request, string $uuid): Response
    {
        // Return blob metadata without file content
    }
}
```

### 2. Upload Flow

```
POST /uploads with type=file OR type=base64

┌─────────────────────────────────────────────────────────────┐
│                    UploadController::upload()                │
├─────────────────────────────────────────────────────────────┤
│ 1. Determine type from request (default: 'file')            │
│                                                             │
│ IF type = 'file':                                           │
│    ├─ Get UploadedFile from $request->files->get('file')   │
│    └─ Pass to FileUploader                                  │
│                                                             │
│ IF type = 'base64':                                         │
│    ├─ Extract 'data', 'filename', 'mime_type' from JSON    │
│    ├─ Decode base64 → temp file                            │
│    ├─ Validate MIME matches declared (if provided)         │
│    └─ Pass temp file to FileUploader                        │
│                                                             │
│ 2. Validate against config:                                 │
│    ├─ allowed_types (with wildcard matching)               │
│    └─ max_size                                              │
│                                                             │
│ 3. FileUploader->uploadMedia() handles:                     │
│    ├─ Security scanning                                     │
│    ├─ Unique filename generation                            │
│    ├─ Storage (local/S3/etc)                               │
│    ├─ Thumbnail generation (if image)                       │
│    └─ Blob record creation                                  │
│                                                             │
│ 4. Return JSON response with uuid, url, metadata            │
└─────────────────────────────────────────────────────────────┘
```

### 3. Image Resize Flow

```
Request: GET /uploads/abc123-def456?width=300&height=200&fit=cover

┌─────────────────────────────────────────────────────────────┐
│                    UploadController::retrieve()              │
├─────────────────────────────────────────────────────────────┤
│ 1. Look up blob record by UUID (404 if not found)           │
│ 2. Parse & validate resize params:                          │
│    ├─ width/height: must be <= max_width/max_height         │
│    ├─ quality: 1-100, default 85                            │
│    ├─ format: must be in allowed_formats (reject unknown)   │
│    └─ fit: contain|cover|fill, default 'contain'            │
│ 3. Generate cache key: image_{uuid}_{w}x{h}_q{q}_{fmt}_{fit}│
│ 4. Check ETag header (If-None-Match) → 304 if match         │
│ 5. Check server cache for resized version                   │
│    ├─ HIT: Return cached image with headers                 │
│    └─ MISS: Continue to step 6                              │
│ 6. Load original from storage using blob.url (path)         │
│ 7. Validate dimensions (max_pixels check for bomb protect)  │
│ 8. Use ImageProcessor to resize:                            │
│    ImageProcessor::make($storagePath)                       │
│        ->fit($width, $height, $fit) // or ->resize()        │
│        ->quality($quality)                                  │
│        ->format($format)                                    │
│        ->getImageData()                                     │
│ 9. Validate output size <= max_variant_bytes                │
│10. Cache resized image                                      │
│11. Return with headers:                                     │
│    ├─ Content-Type: image/{format}                          │
│    ├─ ETag: md5(cache_key)                                  │
│    ├─ Cache-Control: public, max-age=86400                  │
│    └─ Content-Length: {size}                                │
└─────────────────────────────────────────────────────────────┘
```

### 4. Routes Definition

```php
<?php
// routes/uploads.php

use Glueful\Routing\Router;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\UploadController;
use Symfony\Component\HttpFoundation\Request;

/** @var Router $router */
/** @var ApplicationContext $context */

// Only register if uploads are enabled (opt-in)
if (!config($context, 'uploads.enabled', false)) {
    return;
}

// Access control: 'private' | 'public' | 'upload_only'
$access = config($context, 'uploads.access', 'private');
$uploadMiddleware = $access !== 'public' ? ['auth'] : [];
$retrieveMiddleware = $access === 'private' ? ['auth'] : [];

// Rate limits from config
$uploadRate = config($context, 'uploads.rate_limits.uploads_per_minute', 30);
$retrieveRate = config($context, 'uploads.rate_limits.retrieval_per_minute', 200);

// Register under /uploads (will be prefixed by api_prefix() in RouteManifest)
$router->group(['prefix' => '/uploads'], function (Router $router) use ($context, $uploadMiddleware, $retrieveMiddleware, $uploadRate, $retrieveRate) {

    // Upload file (multipart or base64)
    $router->post('', function (Request $request) use ($context) {
        return container($context)->get(UploadController::class)->upload($request);
    })->middleware(array_merge($uploadMiddleware, ["rate_limit:{$uploadRate},60"]));

    // Get file info by UUID (must be before /{uuid} to avoid conflict)
    $router->get('/{uuid}/info', function (Request $request) use ($context) {
        $uuid = $request->attributes->get('uuid');
        return container($context)->get(UploadController::class)->info($request, $uuid);
    })->middleware($retrieveMiddleware);

    // Delete file by UUID (always requires auth)
    $router->delete('/{uuid}', function (Request $request) use ($context) {
        $uuid = $request->attributes->get('uuid');
        return container($context)->get(UploadController::class)->delete($request, $uuid);
    })->middleware(['auth']);

    // Retrieve file by UUID (with optional resize params)
    // Supports: ?width=300&height=200&quality=80&format=webp&fit=contain
    $router->get('/{uuid}', function (Request $request) use ($context) {
        $uuid = $request->attributes->get('uuid');
        return container($context)->get(UploadController::class)->retrieve($request, $uuid);
    })->middleware(array_merge($retrieveMiddleware, ["rate_limit:{$retrieveRate},60"]));
});
```

---

## Allowed Types Pattern Matching

Support wildcard patterns like `image/*`:

```php
private function isTypeAllowed(string $mimeType): bool
{
    $allowed = config($this->context, 'uploads.allowed_types');

    if ($allowed === null) {
        return $this->isDefaultAllowed($mimeType);
    }

    foreach ($allowed as $pattern) {
        // Exact match
        if ($pattern === $mimeType) {
            return true;
        }

        // Wildcard match (e.g., 'image/*')
        if (str_ends_with($pattern, '/*')) {
            $prefix = substr($pattern, 0, -1); // 'image/'
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }
    }

    return false;
}
```

---

## Security Considerations

1. **Opt-in by default** - Routes disabled until explicitly enabled
2. **Authentication** - `private` mode by default (auth for upload and retrieval)
3. **Content-based MIME Validation** - Inspect file bytes with `finfo`, not client headers
4. **Decompression Bomb Protection** - Enforce `max_pixels` (25MP default) and `max_dim` limits
5. **Size Limits** - Enforced server-side, configurable `max_size`
6. **EXIF Stripping** - Enabled by default for privacy protection
7. **Path Traversal** - UUID-based addressing eliminates path manipulation risks
8. **Malware Scanning** - Check for PHP code, script tags in uploads
9. **Rate Limiting** - Configurable limits (30 uploads/min, 200 retrievals/min default)
10. **Filename Sanitization** - Remove dangerous characters, limit length
11. **Variant Size Limits** - `max_variant_bytes` prevents abuse of resize endpoint

---

## Error Contract

Standard error response schema with explicit HTTP status codes:

### Error Response Format

```json
{
  "success": false,
  "error": {
    "code": "FILE_TOO_LARGE",
    "message": "File size exceeds maximum allowed (10MB)",
    "field": "file"
  }
}
```

### HTTP Status Codes

| Status | Code | When |
|--------|------|------|
| `400` | `INVALID_REQUEST` | Missing required fields, invalid base64, malformed request |
| `401` | `UNAUTHORIZED` | Missing or invalid authentication |
| `403` | `FORBIDDEN` | User lacks permission to access/delete this file |
| `404` | `NOT_FOUND` | UUID does not exist |
| `413` | `FILE_TOO_LARGE` | File exceeds `max_size` |
| `415` | `UNSUPPORTED_TYPE` | MIME type not in `allowed_types` |
| `422` | `VALIDATION_FAILED` | MIME mismatch, image dimensions exceed limits |
| `429` | `RATE_LIMITED` | Too many requests |
| `500` | `UPLOAD_FAILED` | Storage error, processing failure |

### Error Codes

```php
// Upload errors
'INVALID_REQUEST'      // Bad request format
'FILE_MISSING'         // No file in request
'FILE_TOO_LARGE'       // Exceeds max_size
'UNSUPPORTED_TYPE'     // MIME type not allowed
'MIME_MISMATCH'        // Content doesn't match declared MIME
'IMAGE_TOO_LARGE'      // Exceeds max_pixels or max_dim
'MALICIOUS_CONTENT'    // Failed security scan
'INVALID_BASE64'       // Base64 decode failed

// Retrieval errors
'NOT_FOUND'            // UUID doesn't exist
'INVALID_DIMENSIONS'   // Requested resize exceeds limits
'INVALID_FORMAT'       // Unknown output format requested
'VARIANT_TOO_LARGE'    // Resized image exceeds max_variant_bytes

// Auth errors
'UNAUTHORIZED'         // No/invalid token
'FORBIDDEN'            // Lacks permission
```

---

## Caching Strategy

### Server-Side Cache (Resized Images)

```
Cache Key: image_{uuid}_{width}x{height}_q{quality}_{format}_{fit}
TTL: 7 days (configurable)
Storage: Framework cache (Redis/File)
```

### HTTP Caching Headers

```http
HTTP/1.1 200 OK
Content-Type: image/jpeg
Cache-Control: public, max-age=86400
ETag: "abc123def456"
Accept-Ranges: bytes
```

- **ETag** - Hash of file content + resize params; enables `If-None-Match` 304 responses
- **Cache-Control** - Configurable via `response.cache_control`
- **Accept-Ranges** - Enables HTTP Range requests for video/audio streaming

### Range Request Support

For video/audio files, support partial content:

```http
GET /uploads/{uuid}
Range: bytes=0-1023

HTTP/1.1 206 Partial Content
Content-Range: bytes 0-1023/146515
Content-Length: 1024
```

### Cache Invalidation

- Original file deleted → Clear all cached resizes for that UUID
- Manual: `php glueful cache:clear --tag=uploads`

---

## Enabling Uploads

Routes are **disabled by default**. To enable:

```env
# .env
UPLOADS_ENABLED=true
```

Or in config:

```php
// config/uploads.php
return [
    'enabled' => true,
    // ... other settings
];
```

The `blobs` table is already part of the framework schema - no additional migration needed.

---

## Testing Plan

### Unit Tests

**Upload:**
- [ ] `testUploadValidFileMultipart`
- [ ] `testUploadValidFileBase64`
- [ ] `testUploadBase64InvalidData`
- [ ] `testUploadBase64MimeMismatch`
- [ ] `testUploadInvalidType` → 415
- [ ] `testUploadExceedsSize` → 413
- [ ] `testUploadMimeByContentValidation`
- [ ] `testUploadImageExceedsMaxPixels` → 422
- [ ] `testUploadExifStripped`
- [ ] `testTypePatternMatching` (wildcards)

**Retrieval:**
- [ ] `testRetrieveByUuid`
- [ ] `testRetrieveNotFoundUuid` → 404
- [ ] `testRetrieveWithResize`
- [ ] `testRetrieveInvalidFormat` → 422
- [ ] `testRetrieveInvalidFit` → 422
- [ ] `testRetrieveCachesResized`
- [ ] `testRetrieveEtagMatch` → 304
- [ ] `testRetrieveRangeRequest` → 206
- [ ] `testRetrieveVariantExceedsMaxBytes` → 422

**Auth & Access:**
- [ ] `testDeleteRequiresAuth` → 401
- [ ] `testPrivateModeRequiresAuthForRetrieval`
- [ ] `testUploadOnlyModeAllowsPublicRetrieval`
- [ ] `testDeleteForbiddenForNonOwner` → 403

### Integration Tests

- [ ] Full multipart upload → retrieve by UUID → delete flow
- [ ] Full base64 upload → retrieve by UUID → delete flow
- [ ] Image resize with various dimensions and fit modes
- [ ] Video retrieval with Range header (streaming)
- [ ] Rate limiting enforcement
- [ ] ETag caching flow (request → cache → 304)
- [ ] Error response format validation

---

## Timeline

| Phase | Tasks | Estimate |
|-------|-------|----------|
| 1 | Create config/uploads.php | - |
| 2 | Create UploadController | - |
| 3 | Create routes/uploads.php | - |
| 4 | Add tests | - |
| 5 | Documentation | - |

---

## Future Enhancements

1. **Chunked uploads** - Support for large file uploads in chunks (resumable)
2. **Signed URLs** - Time-limited URLs for private files
3. **Multiple files** - Batch upload endpoint
4. **Presigned upload URLs** - Direct-to-S3 uploads bypassing server

---

## Related Documentation

- [FileUploader](../../src/Uploader/FileUploader.php) - Existing upload infrastructure
- [ImageProcessor](../../src/Services/ImageProcessor.php) - Image manipulation
- [StorageManager](../../src/Storage/StorageManager.php) - File storage abstraction
- [BlobRepository](../../src/Repository/BlobRepository.php) - File metadata storage
