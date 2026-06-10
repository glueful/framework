<?php

/**
 * Storage Configuration
 *
 * Configure filesystem disks for file storage. Uses Flysystem under the hood.
 *
 * Included drivers (core):
 *   - local:  Local filesystem
 *   - memory: In-memory storage for testing
 *
 * Provider drivers ship as first-party packs (install the one you use):
 *   - S3 / R2 / MinIO / Spaces / Wasabi:  composer require glueful/storage-s3
 *   - Google Cloud Storage:               composer require glueful/storage-gcs
 *   - Azure Blob Storage:                 composer require glueful/storage-azure
 *
 * Without the matching pack, a disk using that driver fails fast with a pointed
 * "composer require glueful/storage-*" error (UnsupportedStorageDriverException).
 */

$root = dirname(__DIR__);

return [
    'default' => env('STORAGE_DEFAULT_DISK', env('STORAGE_DRIVER', 'uploads')),

    'disks' => [
        // Local uploads disk
        'uploads' => [
            'driver' => 'local',
            'root' => $root . '/storage/uploads',
            'visibility' => 'private',
            // Used by UrlGenerator for public URLs
            'base_url' => env('CDN_URL'),
        ],

        // Optional S3-compatible disk.
        // Requires glueful/storage-s3: composer require glueful/storage-s3
        // Core no longer ships the s3 driver; uncomment after installing the pack.
        // 's3' => [
        //     'driver' => 's3',
        //     'key' => env('S3_ACCESS_KEY_ID'),
        //     'secret' => env('S3_SECRET_ACCESS_KEY'),
        //     'region' => env('S3_REGION', 'us-east-1'),
        //     'bucket' => env('S3_BUCKET'),
        //     'endpoint' => env('S3_ENDPOINT'),
        //     'use_path_style_endpoint' => true,
        //
        //     // Optional behavior hints
        //     'acl' => env('S3_ACL', 'private'),
        //     'signed_urls' => env('S3_SIGNED_URLS', true),
        //     'signed_ttl' => (int) env('S3_SIGNED_URL_TTL', 3600),
        //     'cdn_base_url' => env('S3_CDN_BASE_URL'),
        // ],
    ],
];
