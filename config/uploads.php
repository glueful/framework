<?php

return [
    // Enable/disable upload routes
    'enabled' => env('UPLOADS_ENABLED', true),

    // Allowed file types (wildcards supported)
    'allowed_types' => [
        'image/*',
        'video/*',
        'audio/*',
        'application/pdf',
    ],

    // Maximum file size in bytes (10MB default)
    'max_size' => env('UPLOADS_MAX_SIZE', 10 * 1024 * 1024),

    // Storage path prefix
    'path_prefix' => env('UPLOADS_PATH_PREFIX', ''),

    // Access control mode:
    // - true/'private': Auth required for upload AND retrieval
    // - false/'public': No auth required (not recommended)
    // - 'upload_only': Auth for uploads, public retrieval
    'access' => env('UPLOADS_ACCESS', 'private'),

    // Storage disk (from config/storage.php)
    'disk' => env('UPLOADS_DISK', 'uploads'),

    // A deleted blob keeps its file this many days, then `blobs:purge` (and the scheduled
    // blob_purge job) removes the file and the row.
    'purge_deleted_after_days' => (int) env('UPLOADS_PURGE_DELETED_AFTER_DAYS', 30),

    // Default visibility for uploaded blobs: 'public' or 'private'
    // Public blobs can be accessed without auth (if access mode allows)
    // Private blobs require auth or a valid signed URL
    'default_visibility' => env('UPLOADS_DEFAULT_VISIBILITY', 'private'),

    // Signed URLs for temporary access to private blobs
    // Signed URLs are HMAC'd over the path + query only (not the host), so a signature is
    // valid on ANY host that shares the signing secret. Use a DISTINCT signing secret per
    // environment (set UPLOADS_SIGNED_SECRET, or a per-environment APP_KEY) -- reusing one
    // APP_KEY across staging/prod lets a URL minted in one environment be replayed in another.
    'signed_urls' => [
        'enabled' => env('UPLOADS_SIGNED_URLS', true),
        'secret' => env('UPLOADS_SIGNED_SECRET'), // Falls back to APP_KEY if not set; use a per-env value
        'ttl' => (int) env('UPLOADS_SIGNED_TTL', 3600), // 1 hour default
    ],

    // Native object-store URLs (opt-in, per-disk, visibility-scoped).
    //
    // When enabled for a disk whose storage factory implements
    // NativeSignedUrlProviderInterface, blob metadata/signed-url responses may
    // include an additive `native_url` field that points straight at the bucket
    // or CDN. This is an addition, never a replacement: the app-signed
    // /blobs/{uuid} path stays the always-available, access-controlled URL.
    //
    // Security: a native presigned URL is a time-boxed bearer token that bypasses
    // app-side access control and revocation. Public blobs may return a direct
    // URL for bandwidth offload. Private blobs may only opt in with a bounded TTL.
    'native_urls' => [
        // Map of disk name => policy, keyed under 'disks'. Absent disk = disabled.
        'disks' => [
            // 'media' => ['enabled' => true, 'public' => true, 'private' => false, 'private_ttl' => 300],
        ],
        // Hard ceiling on the TTL handed to a native private signer (seconds).
        'max_private_ttl' => (int) env('UPLOADS_NATIVE_MAX_PRIVATE_TTL', 900),
    ],

    // Image processing settings
    'image_processing' => [
        'enabled' => env('UPLOADS_IMAGE_PROCESSING', true),
        'max_width' => (int) env('UPLOADS_MAX_WIDTH', 2048),
        'max_height' => (int) env('UPLOADS_MAX_HEIGHT', 2048),
        'max_pixels' => (int) env('UPLOADS_MAX_PIXELS', 25000000), // 25MP - decompression bomb protection
        'default_quality' => (int) env('UPLOADS_DEFAULT_QUALITY', 85),
        'default_fit' => env('UPLOADS_DEFAULT_FIT', 'contain'), // contain, cover, fill
        'allowed_formats' => ['jpeg', 'jpg', 'png', 'webp', 'gif'],
        'max_variant_bytes' => (int) env('UPLOADS_MAX_VARIANT_BYTES', 5 * 1024 * 1024), // 5MB max for resized
        // Store each resized copy as a file and serve it from there, kept until its image changes
        // (BlobVariantStore). Off: every copy is rendered on every request.
        'cache_enabled' => env('UPLOADS_CACHE_ENABLED', true),
        // max-age for a copy asked for without `?v=` (a URL naming the version is immutable).
        'cache_ttl' => (int) env('UPLOADS_CACHE_TTL', 604800), // 7 days
        // Where copies are stored: null beside each image, on its own disk (local, S3, R2…); or
        // one disk for all of them, e.g. a local disk while the originals are in a bucket.
        'variant_disk' => env('UPLOADS_VARIANT_DISK'),
        // The folder on that disk: <prefix>/<uuid>/<version>/<settings>.<ext>
        'variant_prefix' => '_variants',
        // Copies kept per image version; a size asked for past this is served, not stored, so
        // requests for arbitrary sizes cannot fill the disk.
        'max_stored_variants' => (int) env('UPLOADS_MAX_STORED_VARIANTS', 32),
        // Seconds a redirect to a stored copy lasts. Above 0, a copy of a public image on a disk
        // that signs URLs (every cloud driver: S3 and R2/MinIO/Spaces/Wasabi, GCS, Azure) is a
        // 302 to a signed URL, fetched from the store instead of through the app; caches keep the
        // redirect for half this. Keep the driver's max_signed_ttl at or above it. 0: streamed.
        'variant_redirect_ttl' => (int) env('UPLOADS_VARIANT_REDIRECT_TTL', 0),
        // Make copies when an image is uploaded, on the queue, so no visitor waits for one.
        // Each width in each format (null = the image's own), for images of these types.
        'pregenerate' => [
            'enabled' => env('UPLOADS_PREGENERATE_VARIANTS', false),
            'queue' => env('UPLOADS_PREGENERATE_QUEUE', 'default'),
            'widths' => [],
            'formats' => [null],
            'mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        ],
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
        'uploads_per_minute' => (int) env('UPLOADS_RATE_LIMIT', 30),
        'retrieval_per_minute' => (int) env('UPLOADS_RETRIEVAL_RATE_LIMIT', 200),
    ],

    // Security (hardened defaults)
    'security' => [
        // Refuse a file whose first 64 KB contain PHP (<?php, <?=) or a <script> tag.
        'scan_uploads' => true,
        // Remove GPS position, camera and other embedded metadata from JPEG, PNG and WebP
        // images before they are stored. A JPEG keeps its orientation.
        'strip_exif' => env('UPLOADS_STRIP_EXIF', true),
    ],

    // HTTP response settings
    'response' => [
        'enable_range_requests' => env('UPLOADS_ENABLE_RANGE', true), // HTTP Range for video/audio
        'enable_etag' => env('UPLOADS_ENABLE_ETAG', true), // ETag headers for caching
        'cache_control' => env('UPLOADS_CACHE_CONTROL', 'public, max-age=86400'), // 1 day browser cache
        // A public blob asked for at its current version (?v=, see Glueful\Uploader\BlobVersion):
        // that URL never answers different bytes, so browsers and CDNs may keep it for a year.
        'immutable_cache_control' => env('UPLOADS_IMMUTABLE_CACHE_CONTROL', 'public, max-age=31536000, immutable'),
        // A blob served because the request is signed in or signed: never stored by a shared
        // cache, and revalidated by the browser so a lost permission applies on the next view.
        'private_cache_control' => env('UPLOADS_PRIVATE_CACHE_CONTROL', 'private, no-cache'),
    ],
];
