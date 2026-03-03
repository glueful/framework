<?php

$root = dirname(__DIR__);
$appEnv = (string) env('APP_ENV', 'development');
$defaultProfile = match ($appEnv) {
    'production' => 'production',
    'staging' => 'staging',
    'testing' => 'testing',
    default => 'development',
};
$selectedProfile = (string) env('LOG_PROFILE', $defaultProfile);

$profiles = [
    'development' => [
        'framework_level' => 'info',
        'app_level' => 'debug',
        'log_to_file' => true,
        'log_to_db' => false,
        'retention_default' => 30,
        'retention' => [
            'debug' => 7,
            'api' => 14,
            'app' => 30,
            'framework' => 30,
            'auth' => 180,
            'security' => 180,
            'error' => 180,
        ],
    ],
    'staging' => [
        'framework_level' => 'info',
        'app_level' => 'info',
        'log_to_file' => true,
        'log_to_db' => false,
        'retention_default' => 60,
        'retention' => [
            'debug' => 7,
            'api' => 30,
            'app' => 60,
            'framework' => 60,
            'auth' => 365,
            'security' => 365,
            'error' => 365,
        ],
    ],
    'production' => [
        'framework_level' => 'warning',
        'app_level' => 'warning',
        'log_to_file' => true,
        'log_to_db' => false,
        'retention_default' => 90,
        'retention' => [
            'debug' => 7,
            'api' => 30,
            'app' => 90,
            'framework' => 90,
            'auth' => 365,
            'security' => 365,
            'error' => 365,
        ],
    ],
    'testing' => [
        'framework_level' => 'warning',
        'app_level' => 'warning',
        'log_to_file' => false,
        'log_to_db' => false,
        'retention_default' => 7,
        'retention' => [
            'debug' => 1,
            'api' => 1,
            'app' => 1,
            'framework' => 1,
            'auth' => 7,
            'security' => 7,
            'error' => 7,
        ],
    ],
];

$profile = $profiles[$selectedProfile] ?? $profiles[$defaultProfile];
$logDirectory = rtrim((string) env('LOG_FILE_PATH', $root . '/storage/logs/'), '/') . '/';

/**
 * Logging Configuration
 *
 * Framework vs Application logging boundaries:
 * - Framework logs: HTTP protocol, exceptions, lifecycle, performance
 * - Application logs: Business logic, user actions, custom events
 */
return [
    'profile' => $selectedProfile,
    'default_profile' => $defaultProfile,
    'profiles' => $profiles,

    // Framework-level logging configuration
    'framework' => [
        'enabled' => env('FRAMEWORK_LOGGING_ENABLED', true),
        'level' => env('FRAMEWORK_LOG_LEVEL', $profile['framework_level']),
        'channel' => env('FRAMEWORK_LOG_CHANNEL', 'framework'),

        // Feature-specific toggles
        'log_exceptions' => env('LOG_FRAMEWORK_EXCEPTIONS', true),
        'log_deprecations' => env('LOG_FRAMEWORK_DEPRECATIONS', true),
        'log_lifecycle' => env('LOG_FRAMEWORK_LIFECYCLE', true),
        'log_protocol_errors' => env('LOG_FRAMEWORK_PROTOCOL_ERRORS', true),

        // Performance monitoring (optional framework features)
        'slow_requests' => [
            'enabled' => env('LOG_SLOW_REQUESTS', true),
            'threshold_ms' => env('SLOW_REQUEST_THRESHOLD', 1000),
            'log_level' => 'warning'
        ],

        'slow_queries' => [
            'enabled' => env('LOG_SLOW_QUERIES', true),
            'threshold_ms' => env('SLOW_QUERY_THRESHOLD', 200),
            'log_level' => 'warning'
        ],

        'http_client' => [
            'log_failures' => env('LOG_HTTP_CLIENT_FAILURES', true),
            'log_level' => 'error',
            'slow_threshold_ms' => env('HTTP_CLIENT_SLOW_THRESHOLD', 5000)
        ]
    ],

    // Application-level logging (developers configure)
    'application' => [
        'default_channel' => env('LOG_CHANNEL', 'app'),
        'level' => env('LOG_LEVEL', $profile['app_level']),
        'log_to_file' => env('LOG_TO_FILE', $profile['log_to_file']),
        'log_to_db' => env('LOG_TO_DB', $profile['log_to_db']),
    ],

    // File paths and rotation settings
    'paths' => [
        'log_directory' => $logDirectory,
        'api_log_file' => env('API_LOG_FILE', 'api_debug_') . date('Y-m-d') . '.log',
    ],

    'rotation' => [
        'days' => env('LOG_ROTATION_DAYS', 30),
        'strategy' => env('LOG_ROTATION_STRATEGY', 'daily'), // daily, weekly, monthly, size
        'max_size' => env('LOG_MAX_SIZE', '100M'), // For size-based rotation
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Retention Settings (Database)
    |--------------------------------------------------------------------------
    |
    | Configure how long logs are retained in the database per channel.
    | Channels with longer retention (auth, security, error) are kept for
    | compliance and auditing purposes. File rotation is separate (see above).
    |
    */
    'retention' => [
        'default' => env('LOG_RETENTION_DAYS', $profile['retention_default']),
        'channels' => [
            'debug' => env('LOG_RETENTION_DEBUG_DAYS', $profile['retention']['debug']),
            'api' => env('LOG_RETENTION_API_DAYS', $profile['retention']['api']),
            'app' => env('LOG_RETENTION_APP_DAYS', $profile['retention']['app']),
            'framework' => env('LOG_RETENTION_FRAMEWORK_DAYS', $profile['retention']['framework']),
            'auth' => env('LOG_RETENTION_AUTH_DAYS', $profile['retention']['auth']),
            'security' => env('LOG_RETENTION_SECURITY_DAYS', $profile['retention']['security']),
            'error' => env('LOG_RETENTION_ERROR_DAYS', $profile['retention']['error']),
        ],
    ],

    // Channels configuration
    'channels' => [
        'framework' => [
            'driver' => 'daily',
            'path' => $logDirectory . 'framework.log',
            'level' => env('FRAMEWORK_LOG_LEVEL', $profile['framework_level']),
            'days' => env('LOG_ROTATION_DAYS', 30),
        ],
        'app' => [
            'driver' => 'daily',
            'path' => $logDirectory . 'app.log',
            'level' => env('LOG_LEVEL', $profile['app_level']),
            'days' => env('LOG_ROTATION_DAYS', 30),
        ],
        'api' => [
            'driver' => 'daily',
            'path' => $logDirectory . 'api.log',
            'level' => env('LOG_LEVEL', $profile['app_level']),
            'days' => env('LOG_ROTATION_DAYS', 30),
        ],
        'error' => [
            'driver' => 'daily',
            'path' => $logDirectory . 'error.log',
            'level' => 'error',
            'days' => env('LOG_ROTATION_DAYS', 30),
        ],
        'debug' => [
            'driver' => 'daily',
            'path' => $logDirectory . 'debug.log',
            'level' => 'debug',
            'days' => env('LOG_ROTATION_DAYS', 30),
        ]
    ]
];
