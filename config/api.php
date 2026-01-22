<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Field Selection Configuration
    |--------------------------------------------------------------------------
    */
    'field_selection' => [
        // Global defaults
        'enabled'    => true,
        'strict'     => false,
        'maxDepth'   => 6,
        'maxFields'  => 200,
        'maxItems'   => 1000,

        // Optional named whitelists (referenced by whitelistKey)
        'whitelists' => [
            // 'user' => ['id','name','email','posts','comments','profile'],
            // 'post' => ['id','title','body','comments','author'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Versioning Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how API versioning is handled in the application.
    | Multiple resolution strategies are supported with configurable priority.
    |
    */
    'versioning' => [
        /*
        |--------------------------------------------------------------------------
        | Default API Version
        |--------------------------------------------------------------------------
        |
        | The default API version when none is specified in the request.
        |
        */
        'default' => env('API_DEFAULT_VERSION', '1'),

        /*
        |--------------------------------------------------------------------------
        | Supported Versions
        |--------------------------------------------------------------------------
        |
        | List of currently supported API versions. Leave empty to accept all.
        | Requests for unsupported versions will fail in strict mode.
        |
        */
        'supported' => [],

        /*
        |--------------------------------------------------------------------------
        | Deprecated Versions
        |--------------------------------------------------------------------------
        |
        | Versions that are deprecated and will be removed in the future.
        | Each entry can specify a sunset date and optional message.
        |
        | Example:
        | '1' => [
        |     'sunset' => '2025-06-01',
        |     'message' => 'Please migrate to API v2',
        |     'alternative' => '/v2',
        | ],
        |
        */
        'deprecated' => [],

        /*
        |--------------------------------------------------------------------------
        | Version Resolution Strategy
        |--------------------------------------------------------------------------
        |
        | Primary strategy for version resolution:
        | - url_prefix: /api/v1/resource (default, most common)
        | - header: X-Api-Version header
        | - query: ?api-version=1 query parameter
        | - accept: Accept: application/vnd.glueful.v1+json
        |
        */
        'strategy' => env('API_VERSION_STRATEGY', 'url_prefix'),

        /*
        |--------------------------------------------------------------------------
        | API Prefix
        |--------------------------------------------------------------------------
        |
        | Base prefix for versioned API routes (used with url_prefix strategy).
        |
        */
        'prefix' => env('API_PREFIX', '/api'),

        /*
        |--------------------------------------------------------------------------
        | Strict Mode
        |--------------------------------------------------------------------------
        |
        | When enabled, requests for unsupported versions will be rejected.
        | When disabled, unsupported versions fall back to default.
        |
        */
        'strict' => env('API_VERSION_STRICT', false),

        /*
        |--------------------------------------------------------------------------
        | Version Resolvers
        |--------------------------------------------------------------------------
        |
        | List of resolvers to use for version negotiation.
        | Order determines fallback priority (first match wins).
        |
        */
        'resolvers' => ['url_prefix', 'header', 'query', 'accept'],

        /*
        |--------------------------------------------------------------------------
        | Resolver Options
        |--------------------------------------------------------------------------
        |
        | Configuration for individual resolvers.
        |
        */
        'resolver_options' => [
            'url_prefix' => [
                'prefix' => env('API_PREFIX', '/api'),
                'priority' => 100,
            ],
            'header' => [
                'name' => 'X-Api-Version',
                'priority' => 80,
            ],
            'query' => [
                'name' => 'api-version',
                'priority' => 60,
            ],
            'accept' => [
                'vendor' => 'glueful',
                'priority' => 70,
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Response Headers
        |--------------------------------------------------------------------------
        |
        | Configure version-related response headers.
        |
        */
        'headers' => [
            'include_version' => true,        // Add X-Api-Version header
            'include_deprecation' => true,    // Add Deprecation header for deprecated versions
            'include_sunset' => true,         // Add Sunset header (RFC 8594)
            'include_warning' => true,        // Add Warning header for deprecations
        ],
    ],
];
