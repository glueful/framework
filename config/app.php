<?php

/**
 * Application Configuration
 *
 * Core application settings, paths, performance, and pagination configurations.
 * Values can be overridden using environment variables.
 */

$basePath = dirname(__DIR__);

return [
    // Application Environment (development, staging, production)
    'env' => env('APP_ENV', 'development'),

    // Smart environment-aware debug default (false in production, true otherwise)
    'debug' => (bool) env('APP_DEBUG', env('APP_ENV') !== 'production'),

    // Smart environment-aware API documentation (disabled in production for security)
    'api_docs_enabled' => env('API_DOCS_ENABLED', env('APP_ENV') !== 'production'),

    // Smart environment-aware development mode
    'dev_mode' => env('DEV_MODE', env('APP_ENV') === 'development'),

    // Warn when app routes don't use api_prefix() (dev only)
    'warn_unprefixed_routes' => env('WARN_UNPREFIXED_ROUTES', false),

    // Smart environment-aware HTTPS enforcement
    'force_https' => env('FORCE_HTTPS', env('APP_ENV') === 'production'),

    // Application Encryption Key
    'key' => env('APP_KEY'),

    // API Information
    'name' => env('APP_NAME', 'Glueful'),
    'api_version' => env('API_VERSION', '1'),

    // API Versioning Configuration
    'versioning' => [
        'strategy' => env('API_VERSION_STRATEGY', 'url'), // url, header, both
        'current' => env('API_VERSION', '1'),
        'supported' => explode(',', env('API_SUPPORTED_VERSIONS', '1')),
        'default' => env('API_VERSION', '1'),
    ],

    // Application Paths (filesystem only)
    'paths' => [
        'base' => $basePath,
        'api_base_directory' => $basePath . '/api',
        'api_docs' => $basePath . '/docs',
        'uploads' => $basePath . '/storage/cdn',
        'logs' => $basePath . '/storage/logs',
        'cache' => $basePath . '/storage/cache',
        'backups' => $basePath . '/storage/backups',
        'storage' => $basePath . '/storage',
        'database_json_definitions' => $basePath . '/docs/json-definitions/database',
        'project_extensions' => $basePath . '/extensions',
        'archives' => $basePath . '/storage/archives',
        'migrations' => $basePath . '/database/migrations',
        'app_events' => $basePath . '/app/Events',
        'app_listeners' => $basePath . '/app/Events/Listeners',
    ],

    // Application URLs (web addresses grouped here)
    // BASE_URL is your deployment URL (e.g., https://api.example.com)
    // For API URLs, use the api_url() helper function instead of config
    'urls' => [
        'base' => env('BASE_URL', 'http://localhost'),
        'cdn' => rtrim(env('BASE_URL', 'http://localhost'), '/') . '/storage/cdn/',
        'docs' => rtrim(env('BASE_URL', 'http://localhost'), '/') . '/docs/',
    ],

    // Performance Settings
    'performance' => [
        'memory' => [
            'monitoring' => [
                'enabled' => env('MEMORY_MONITORING_ENABLED', true),
                'alert_threshold' => env('MEMORY_ALERT_THRESHOLD', 0.8),
                'critical_threshold' => env('MEMORY_CRITICAL_THRESHOLD', 0.9),
                'log_level' => env('MEMORY_LOG_LEVEL', 'warning'),
                'sample_rate' => env('MEMORY_SAMPLE_RATE', 0.01)
            ],
            'limits' => [
                'query_cache' => env('MEMORY_LIMIT_QUERY_CACHE', 1000),
                'object_pool' => env('MEMORY_LIMIT_OBJECT_POOL', 500),
                'result_limit' => env('MEMORY_LIMIT_RESULTS', 10000)
            ],
            'gc' => [
                'auto_trigger' => env('MEMORY_AUTO_GC', true),
                'threshold' => env('MEMORY_GC_THRESHOLD', 0.85)
            ]
        ]
    ],

];
