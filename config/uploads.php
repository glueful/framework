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

    // Default visibility for uploaded blobs: 'public' or 'private'
    // Public blobs can be accessed without auth (if access mode allows)
    // Private blobs require auth or a valid signed URL
    'default_visibility' => env('UPLOADS_DEFAULT_VISIBILITY', 'private'),

    // Signed URLs for temporary access to private blobs
    'signed_urls' => [
        'enabled' => env('UPLOADS_SIGNED_URLS', true),
        'secret' => env('UPLOADS_SIGNED_SECRET'), // Falls back to APP_KEY if not set
        'ttl' => (int) env('UPLOADS_SIGNED_TTL', 3600), // 1 hour default
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
        'cache_enabled' => env('UPLOADS_CACHE_ENABLED', true),
        'cache_ttl' => (int) env('UPLOADS_CACHE_TTL', 604800), // 7 days
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
        'scan_uploads' => true,
        'validate_mime_by_content' => true, // Inspect file bytes, not client headers
        'strip_exif' => env('UPLOADS_STRIP_EXIF', true), // Strip by default for privacy
        'max_filename_length' => 255,
    ],

    // HTTP response settings
    'response' => [
        'enable_range_requests' => env('UPLOADS_ENABLE_RANGE', true), // HTTP Range for video/audio
        'enable_etag' => env('UPLOADS_ENABLE_ETAG', true), // ETag headers for caching
        'cache_control' => env('UPLOADS_CACHE_CONTROL', 'public, max-age=86400'), // 1 day browser cache
    ],
];
