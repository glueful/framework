<?php

/**
 * Storage Configuration
 *
 * Configure filesystem disks for file storage. Uses Flysystem under the hood.
 *
 * Included adapters:
 *   - local: Local filesystem (included)
 *   - memory: In-memory storage for testing (included)
 *
 * Optional adapters (install via Composer):
 *   - S3/MinIO/DigitalOcean Spaces/Wasabi:
 *       composer require league/flysystem-aws-s3-v3
 *
 *   - Google Cloud Storage:
 *       composer require league/flysystem-google-cloud-storage
 *
 *   - Azure Blob Storage:
 *       composer require league/flysystem-azure-blob-storage
 *
 *   - SFTP:
 *       composer require league/flysystem-sftp-v3
 *
 *   - FTP:
 *       composer require league/flysystem-ftp
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

        // Optional S3-compatible disk
        's3' => [
            'driver' => 's3',
            'key' => env('S3_ACCESS_KEY_ID'),
            'secret' => env('S3_SECRET_ACCESS_KEY'),
            'region' => env('S3_REGION', 'us-east-1'),
            'bucket' => env('S3_BUCKET'),
            'endpoint' => env('S3_ENDPOINT'),
            'use_path_style_endpoint' => true,

            // Optional behavior hints
            'acl' => env('S3_ACL', 'private'),
            'signed_urls' => env('S3_SIGNED_URLS', true),
            'signed_ttl' => (int) env('S3_SIGNED_URL_TTL', 3600),
            'cdn_base_url' => env('S3_CDN_BASE_URL'),
        ],
    ],
];
