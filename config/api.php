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
        'default' => env('API_VERSION', '1'),

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
        | Set to empty string for subdomain-based APIs (e.g., api.example.com).
        |
        */
        'prefix' => env('API_PREFIX', '/api'),

        /*
        |--------------------------------------------------------------------------
        | Apply Prefix to Routes
        |--------------------------------------------------------------------------
        |
        | Whether to automatically apply the API prefix to framework routes.
        | Set to false for subdomain-based APIs where prefix is not needed.
        |
        | Examples:
        | - true + prefix=/api → routes at /api/v1/auth/login
        | - false → routes at /v1/auth/login (for api.example.com subdomain)
        |
        */
        'apply_prefix_to_routes' => env('API_USE_PREFIX', true),

        /*
        |--------------------------------------------------------------------------
        | Version in URL Path
        |--------------------------------------------------------------------------
        |
        | Whether to include the version number in the URL path.
        | Set to false for unversioned APIs.
        |
        | Examples:
        | - true → /api/v1/auth/login
        | - false → /api/auth/login
        |
        */
        'version_in_path' => env('API_VERSION_IN_PATH', true),

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

    /*
    |--------------------------------------------------------------------------
    | Enhanced Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure tiered rate limiting with multiple algorithms and IETF-compliant
    | headers. Works alongside the existing security.rate_limiter config.
    |
    */
    'rate_limiting' => [
        /*
        |--------------------------------------------------------------------------
        | Enable Enhanced Rate Limiting
        |--------------------------------------------------------------------------
        |
        | When enabled, the enhanced_rate_limit middleware will be available
        | for attribute-based rate limiting with tier support.
        |
        */
        'enabled' => env('API_RATE_LIMITING_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Default Algorithm
        |--------------------------------------------------------------------------
        |
        | The default rate limiting algorithm to use:
        | - sliding: Sliding window (recommended, smooth distribution)
        | - fixed: Fixed window (simple, may have boundary spikes)
        | - bucket: Token bucket (allows bursts while maintaining average)
        |
        */
        'algorithm' => env('API_RATE_LIMIT_ALGORITHM', 'sliding'),

        /*
        |--------------------------------------------------------------------------
        | Default Tier
        |--------------------------------------------------------------------------
        |
        | The tier assigned to requests that don't match any tier criteria.
        |
        */
        'default_tier' => env('API_RATE_LIMIT_DEFAULT_TIER', 'anonymous'),

        /*
        |--------------------------------------------------------------------------
        | Tier Definitions
        |--------------------------------------------------------------------------
        |
        | Define rate limits for each user tier. Set a limit to null for unlimited.
        |
        | Tiers are resolved from user attributes in order:
        | 1. user.tier, user.plan, user.subscription fields
        | 2. Role mapping (admin -> enterprise, pro/premium -> pro)
        | 3. Default tier for authenticated users: 'free'
        | 4. Anonymous users: 'anonymous'
        |
        */
        'tiers' => [
            'anonymous' => [
                'requests_per_minute' => 30,
                'requests_per_hour' => 500,
                'requests_per_day' => 5000,
            ],
            'free' => [
                'requests_per_minute' => 60,
                'requests_per_hour' => 1000,
                'requests_per_day' => 10000,
            ],
            'pro' => [
                'requests_per_minute' => 300,
                'requests_per_hour' => 10000,
                'requests_per_day' => 100000,
            ],
            'enterprise' => [
                'requests_per_minute' => null,  // Unlimited
                'requests_per_hour' => null,     // Unlimited
                'requests_per_day' => null,      // Unlimited
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Default Limits
        |--------------------------------------------------------------------------
        |
        | Default limits when no route-specific limits are defined and no
        | tier-specific limits apply.
        |
        */
        'defaults' => [
            'ip' => [
                'max_attempts' => 60,
                'window_seconds' => 60,
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Response Headers
        |--------------------------------------------------------------------------
        |
        | Configure which rate limit headers to include in responses.
        |
        */
        'headers' => [
            'enabled' => true,
            'include_legacy' => true,   // X-RateLimit-* headers
            'include_ietf' => true,     // RateLimit-* headers (IETF draft)
        ],

        /*
        |--------------------------------------------------------------------------
        | Bypass IPs
        |--------------------------------------------------------------------------
        |
        | IP addresses that should bypass rate limiting entirely.
        | Comma-separated string or array of IPs.
        |
        */
        'bypass_ips' => env('API_RATE_LIMIT_BYPASS_IPS', '127.0.0.1,::1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks Configuration
    |--------------------------------------------------------------------------
    |
    | Configure webhook delivery, retry logic, and security settings.
    | Tables are auto-created on first use (webhook_subscriptions, webhook_deliveries).
    |
    */
    'webhooks' => [
        /*
        |--------------------------------------------------------------------------
        | Enable Webhooks
        |--------------------------------------------------------------------------
        |
        | Master switch for webhook functionality.
        |
        */
        'enabled' => env('WEBHOOKS_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Queue Configuration
        |--------------------------------------------------------------------------
        |
        | Queue settings for webhook delivery jobs.
        |
        */
        'queue' => env('WEBHOOKS_QUEUE', 'webhooks'),
        'connection' => env('WEBHOOKS_QUEUE_CONNECTION', null),

        /*
        |--------------------------------------------------------------------------
        | Signature Settings
        |--------------------------------------------------------------------------
        |
        | Settings for HMAC signature generation and verification.
        |
        */
        'signature_header' => 'X-Webhook-Signature',
        'signature_algorithm' => 'sha256',

        /*
        |--------------------------------------------------------------------------
        | HTTP Request Settings
        |--------------------------------------------------------------------------
        |
        | Settings for outgoing webhook HTTP requests.
        |
        */
        'timeout' => env('WEBHOOKS_TIMEOUT', 30),
        'user_agent' => 'Glueful-Webhooks/1.0',

        /*
        |--------------------------------------------------------------------------
        | Retry Configuration
        |--------------------------------------------------------------------------
        |
        | Exponential backoff retry settings for failed deliveries.
        | Default backoff: 1m, 5m, 30m, 2h, 12h
        |
        */
        'retry' => [
            'max_attempts' => env('WEBHOOKS_MAX_ATTEMPTS', 5),
            'backoff' => [60, 300, 1800, 7200, 43200],
        ],

        /*
        |--------------------------------------------------------------------------
        | Security
        |--------------------------------------------------------------------------
        |
        | Security settings for webhook endpoints.
        |
        */
        'require_https' => env('WEBHOOKS_REQUIRE_HTTPS', true),

        /*
        |--------------------------------------------------------------------------
        | Cleanup
        |--------------------------------------------------------------------------
        |
        | Automatic cleanup of old delivery records.
        |
        */
        'cleanup' => [
            'keep_successful_days' => 7,
            'keep_failed_days' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search & Filtering DSL Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the filtering, sorting, and search query parameter DSL.
    | This enables standardized URL query parameter syntax for data access.
    |
    */
    'filtering' => [
        /*
        |--------------------------------------------------------------------------
        | Enable Filtering DSL
        |--------------------------------------------------------------------------
        |
        | When enabled, the filter middleware will parse filter, sort, and search
        | parameters from requests.
        |
        */
        'enabled' => env('API_FILTERING_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Query Parameters
        |--------------------------------------------------------------------------
        |
        | Configure the query parameter names used for filtering.
        |
        */
        'filter_param' => 'filter',
        'sort_param' => 'sort',
        'search_param' => 'search',
        'search_fields_param' => 'search_fields',

        /*
        |--------------------------------------------------------------------------
        | Limits
        |--------------------------------------------------------------------------
        |
        | Security limits to prevent abuse.
        |
        */
        'max_filters' => env('API_MAX_FILTERS', 20),
        'max_depth' => env('API_FILTER_MAX_DEPTH', 3),
        'max_sort_fields' => env('API_MAX_SORT_FIELDS', 5),

        /*
        |--------------------------------------------------------------------------
        | Allowed Operators
        |--------------------------------------------------------------------------
        |
        | List of operators that can be used in filters.
        | Remove operators from this list to disable them globally.
        |
        */
        'allowed_operators' => [
            'eq', 'ne', 'gt', 'gte', 'lt', 'lte',
            'contains', 'starts', 'ends',
            'in', 'nin', 'between',
            'null', 'not_null',
        ],

        /*
        |--------------------------------------------------------------------------
        | Search Driver
        |--------------------------------------------------------------------------
        |
        | The search driver to use for full-text search:
        | - database: Use SQL LIKE queries (default, no setup required)
        | - elasticsearch: Use Elasticsearch (requires elasticsearch/elasticsearch)
        | - meilisearch: Use Meilisearch (requires meilisearch/meilisearch-php)
        |
        */
        'search_driver' => env('API_SEARCH_DRIVER', 'database'),

        /*
        |--------------------------------------------------------------------------
        | Search Configuration
        |--------------------------------------------------------------------------
        |
        | Configuration for search engine integrations.
        |
        | Available drivers:
        | - database: Uses SQL LIKE queries (default, no setup required)
        | - elasticsearch: Requires elasticsearch/elasticsearch package
        |   Installation: composer require elasticsearch/elasticsearch:^8.0
        | - meilisearch: Requires meilisearch/meilisearch-php package
        |   Installation: composer require meilisearch/meilisearch-php:^1.0
        |
        */
        'search' => [
            'driver' => env('API_SEARCH_DRIVER', 'database'),
            'index_prefix' => env('SEARCH_INDEX_PREFIX', ''),
            'auto_index' => env('SEARCH_AUTO_INDEX', false),
            'elasticsearch' => [
                'hosts' => [env('ELASTICSEARCH_HOST', 'localhost:9200')],
            ],
            'meilisearch' => [
                'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
                'key' => env('MEILISEARCH_KEY'),
            ],
        ],
    ],
];
