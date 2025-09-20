<?php

return [
    'default' => env('STORAGE_DEFAULT_DISK', env('STORAGE_DRIVER', 'uploads')),

    'disks' => [
        // Local uploads disk
        'uploads' => [
            'driver' => 'local',
            'root' => config('app.paths.uploads'),
            'visibility' => 'private',
            // Used by UrlGenerator for public URLs
            'base_url' => config('app.urls.cdn'),
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
