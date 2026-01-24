<?php

/**
 * Security Configuration
 *
 * Enhanced security settings with production-ready defaults.
 * Controls permission checks, session validation, and rate limiting.
 */

return [
    // Authentication policy
    'auth' => [
        // Statuses that are permitted to authenticate/login.
        // Adjust as needed (e.g., include 'verified', 'onboarding').
        'allowed_login_statuses' => ['active'],
    ],

    // Security level definitions
    'levels' => [
        'flexible' => 1,    // Basic token validation only
        'moderate' => 2,    // Token + IP address validation
        'strict' => 3,      // Token + IP + User Agent validation
    ],

    // Health endpoint security
    'health_ip_allowlist' => array_filter(explode(',', env('HEALTH_IP_ALLOWLIST', ''))),
    'health_auth_required' => env('HEALTH_AUTH_REQUIRED', false),

    // Smart environment-aware security level (stricter for production, flexible for development)
    'default_level' => env('DEFAULT_SECURITY_LEVEL', match (env('APP_ENV')) {
        'production' => 2,  // Moderate security for production
        'staging' => 2,     // Moderate security for staging
        default => 1        // Flexible security for development
    }),

    // Security settings
    'nanoid_length' => env('NANOID_LENGTH', 12),

    // Sensitive configuration files
    // These files require additional permissions for viewing/editing
    'sensitive_config_files' => [
        'security',  // Security configurations, API keys
        'database',  // Database credentials
        'app',       // Application secrets and keys
        'auth',      // Authentication providers and secrets
        'services',  // Third-party service credentials
        'mail',      // SMTP credentials
    ],

    // CORS Configuration
    'cors' => [
        // Smart CORS defaults: permissive in development, secure guidance in production
        'allowed_origins' => env(
            'CORS_ALLOWED_ORIGINS',
            env('APP_ENV') === 'development' ? '*' : null  // null means must be explicitly set
        ),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        'expose_headers' => ['X-Total-Count', 'X-Page-Count'],
        'max_age' => 86400,
        'supports_credentials' => true,
    ],

    // CSRF Protection Configuration
    'csrf' => [
        'enabled' => env('CSRF_PROTECTION_ENABLED', true),
        'tokenLifetime' => env('CSRF_TOKEN_LIFETIME', 3600),
        'useDoubleSubmit' => env('CSRF_DOUBLE_SUBMIT', false),
        'exemptRoutes' => [
            'auth/login',
            'auth/register',
            'auth/forgot-password',
            'auth/reset-password',
            'auth/verify-email',
            'auth/verify-otp',
            'webhooks/*',
            'public/*',
            'csrf-token',
        ],
    ],

    // Security Headers
    'headers' => [
        'x_frame_options' => env('X_FRAME_OPTIONS', 'DENY'),
        'x_content_type_options' => env('X_CONTENT_TYPE_OPTIONS', 'nosniff'),
        'x_xss_protection' => env('X_XSS_PROTECTION', '1; mode=block'),
        'strict_transport_security' => env(
            'HSTS_HEADER',
            env('APP_ENV') === 'production' ? 'max-age=31536000; includeSubDomains' : null
        ),
        'content_security_policy' => env('CSP_HEADER'),
    ],

    // Password Security
    'password' => [
        'min_length' => env('PASSWORD_MIN_LENGTH', 8),
        'require_mixed_case' => env('PASSWORD_REQUIRE_MIXED_CASE', true),
        'require_numbers' => env('PASSWORD_REQUIRE_NUMBERS', true),
        'require_symbols' => env('PASSWORD_REQUIRE_SYMBOLS', env('APP_ENV') === 'production'),
        'max_age_days' => env('PASSWORD_MAX_AGE_DAYS', env('APP_ENV') === 'production' ? 90 : null),
    ],

    // Request Validation
    'request_validation' => [
        'allowed_content_types' => [
            'application/json',
            'application/x-www-form-urlencoded',
            'multipart/form-data'
        ],
        'max_request_size' => env('MAX_REQUEST_SIZE', '10MB'),
        'require_user_agent' => env('REQUIRE_USER_AGENT', false),
        'block_suspicious_ua' => env('BLOCK_SUSPICIOUS_UA', false),
        'suspicious_ua_patterns' => [
            '/bot/i',
            '/crawler/i',
            '/spider/i',
            '/scraper/i'
        ]
    ],

    // Job Execution Security
    'jobs' => [
        'allowed_names' => [
            // Core system jobs that can be executed via API
            'cache_maintenance',
            'database_backup',
            'log_cleaner',
            'notification_retry_processor',
            'session_cleaner',
            'archive_cleanup',
            'metrics_aggregation',
            'security_scan',
            'health_check',
            'queue_maintenance'
        ],

        // Whether to auto-allow all jobs from schedule.php
        'auto_allow_scheduled_jobs' => env('AUTO_ALLOW_SCHEDULED_JOBS', false),

        // Additional validation settings
        'job_name_pattern' => '/^[a-z][a-z0-9_]*[a-z0-9]$/',
        'max_job_data_size' => 65536, // 64KB
    ],
];
