<?php

$root = dirname(__DIR__);

/**
 * Image Processing Configuration
 *
 * Configuration for Intervention Image integration with Glueful Framework.
 * Covers driver selection, optimization settings, security policies, and performance limits.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Image Processing Driver
    |--------------------------------------------------------------------------
    |
    | Choose the image processing driver. Options:
    | - 'gd'      : GD extension (lighter, good performance)
    | - 'imagick' : ImageMagick extension (more features, better quality)
    |
    */
    'driver' => env('IMAGE_DRIVER', 'gd'),

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for processed image caching.
    |
    */
    'cache' => [
        'enabled' => env('IMAGE_CACHE_ENABLED', true),
        'ttl' => (int) env('IMAGE_CACHE_TTL', 86400), // 24 hours
        'prefix' => env('IMAGE_CACHE_PREFIX', 'image_'),
        'driver' => env('IMAGE_CACHE_DRIVER', null), // null = use default cache driver
        'tags' => ['images', 'processed'], // Cache tags for organized invalidation
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Optimization Settings
    |--------------------------------------------------------------------------
    |
    | Default quality and optimization settings for different formats.
    |
    */
    'optimization' => [
        'jpeg_quality' => (int) env('IMAGE_JPEG_QUALITY', 85),
        'png_compression' => (int) env('IMAGE_PNG_COMPRESSION', 6), // 0-9, 6 is good balance
        'webp_quality' => (int) env('IMAGE_WEBP_QUALITY', 80),
        'gif_quality' => (int) env('IMAGE_GIF_QUALITY', 85),
        'auto_optimize' => env('IMAGE_AUTO_OPTIMIZE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Limits
    |--------------------------------------------------------------------------
    |
    | Limits to prevent abuse and control resource usage.
    |
    */
    'limits' => [
        'max_width' => (int) env('IMAGE_MAX_WIDTH', 2048),
        'max_height' => (int) env('IMAGE_MAX_HEIGHT', 2048),
        'max_filesize' => env('IMAGE_MAX_FILESIZE', '10M'), // Max input file size
        'max_memory' => env('IMAGE_MAX_MEMORY', '256M'), // Memory limit for processing
        'processing_timeout' => (int) env('IMAGE_PROCESSING_TIMEOUT', 30), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security policies for image processing operations.
    |
    */
    'security' => [
        'allowed_domains' => explode(',', env('IMAGE_ALLOWED_DOMAINS', '*')), // '*' allows all
        'allowed_formats' => [
            'jpeg', 'jpg', 'png', 'gif', 'webp'
        ],
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ],
        'validate_mime' => env('IMAGE_VALIDATE_MIME', true),
        'validate_extension' => env('IMAGE_VALIDATE_EXTENSION', true),
        'check_image_integrity' => env('IMAGE_CHECK_INTEGRITY', true),
        'disable_external_urls' => env('IMAGE_DISABLE_EXTERNAL_URLS', false),
        'user_agent' => env('IMAGE_USER_AGENT', 'Glueful-ImageProcessor/1.0'),
        'timeout' => (int) env('IMAGE_HTTP_TIMEOUT', 10), // HTTP timeout for remote images
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Transformations
    |--------------------------------------------------------------------------
    |
    | Default settings for common transformations.
    |
    */
    'defaults' => [
        'maintain_aspect_ratio' => true,
        'crop_position' => 'center', // center, top, bottom, left, right
        'background_color' => 'ffffff', // Default background for rotations/crops
        'watermark_opacity' => 50, // 0-100
        'watermark_position' => 'bottom-right',
    ],

    /*
    |--------------------------------------------------------------------------
    | Format-Specific Settings
    |--------------------------------------------------------------------------
    |
    | Settings specific to each image format.
    |
    */
    'formats' => [
        'jpeg' => [
            'quality' => (int) env('IMAGE_JPEG_QUALITY', 85),
            'progressive' => env('IMAGE_JPEG_PROGRESSIVE', false),
            'optimize' => env('IMAGE_JPEG_OPTIMIZE', true),
        ],
        'png' => [
            'compression' => (int) env('IMAGE_PNG_COMPRESSION', 6),
            'optimize' => env('IMAGE_PNG_OPTIMIZE', true),
        ],
        'webp' => [
            'quality' => (int) env('IMAGE_WEBP_QUALITY', 80),
            'lossless' => env('IMAGE_WEBP_LOSSLESS', false),
        ],
        'gif' => [
            'optimize' => env('IMAGE_GIF_OPTIMIZE', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Performance optimization settings.
    |
    */
    'performance' => [
        'memory_limit_multiplier' => 1.5, // Multiply image size by this for memory estimation
        'enable_lazy_loading' => env('IMAGE_LAZY_LOADING', true),
        'parallel_processing' => env('IMAGE_PARALLEL_PROCESSING', false),
        'chunk_size' => (int) env('IMAGE_CHUNK_SIZE', 1024), // For streaming operations
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable/disable specific features.
    |
    */
    'features' => [
        'watermarking' => env('IMAGE_ENABLE_WATERMARKS', true),
        'format_conversion' => env('IMAGE_ENABLE_FORMAT_CONVERSION', true),
        'remote_images' => env('IMAGE_ENABLE_REMOTE_IMAGES', true),
        'auto_rotation' => env('IMAGE_ENABLE_AUTO_ROTATION', true), // Based on EXIF
        'preserve_metadata' => env('IMAGE_PRESERVE_METADATA', false),
        'advanced_filters' => env('IMAGE_ENABLE_ADVANCED_FILTERS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths and Directories
    |--------------------------------------------------------------------------
    |
    | File system paths for image processing operations.
    |
    */
    'paths' => [
        'cache_dir' => env('IMAGE_CACHE_DIR', $root . '/storage/cache/images'),
        'temp_dir' => env('IMAGE_TEMP_DIR', sys_get_temp_dir()),
        'watermark_dir' => env('IMAGE_WATERMARK_DIR', $root . '/resources/images/watermarks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Logging
    |--------------------------------------------------------------------------
    |
    | Configuration for monitoring and logging image processing operations.
    |
    */
    'monitoring' => [
        'log_processing_time' => env('IMAGE_LOG_PROCESSING_TIME', false),
        'log_memory_usage' => env('IMAGE_LOG_MEMORY_USAGE', false),
        'log_cache_hits' => env('IMAGE_LOG_CACHE_HITS', false),
        'performance_threshold_ms' => (int) env('IMAGE_PERFORMANCE_THRESHOLD', 1000),
        'alert_on_failure' => env('IMAGE_ALERT_ON_FAILURE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Settings
    |--------------------------------------------------------------------------
    |
    | Settings that are useful during development.
    |
    */
    'development' => [
        'debug_mode' => env('IMAGE_DEBUG', env('APP_DEBUG', false)),
        'save_debug_images' => env('IMAGE_SAVE_DEBUG', false),
        'debug_output_dir' => env('IMAGE_DEBUG_DIR', $root . '/storage/debug/images'),
        'benchmark_operations' => env('IMAGE_BENCHMARK', false),
    ],
];
