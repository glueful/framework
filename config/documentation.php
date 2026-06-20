<?php

/**
 * Documentation Generation Configuration
 *
 * Centralizes all settings for OpenAPI/Swagger documentation generation.
 * Used by ResourceRouteExpander, DocGenerator, RouteReflectionDocGenerator,
 * and OpenApiGenerator.
 */

$root = dirname(__DIR__);

return [
    /*
    |--------------------------------------------------------------------------
    | Documentation Enabled
    |--------------------------------------------------------------------------
    |
    | Controls whether API documentation is enabled. Automatically disabled
    | in production for security unless explicitly enabled.
    |
    */
    'enabled' => env('API_DOCS_ENABLED', env('APP_ENV') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | OpenAPI Specification Version
    |--------------------------------------------------------------------------
    |
    | The OpenAPI specification version to use for generated documentation.
    | Supported: "3.0.0", "3.0.3", "3.1.0"
    |
    | Key differences in 3.1.0:
    | - Uses JSON Schema draft 2020-12 (full alignment)
    | - Nullable types use array syntax: type: ["string", "null"]
    | - License supports SPDX identifier field
    | - $ref can have sibling keywords
    |
    */
    'openapi_version' => env('OPENAPI_VERSION', '3.1.0'),

    /*
    |--------------------------------------------------------------------------
    | API Information
    |--------------------------------------------------------------------------
    |
    | Metadata about your API that appears in the generated documentation.
    | These values populate the "info" section of the OpenAPI spec.
    |
    | TIP: After adding or modifying endpoints in your code, regenerate docs:
    |   php glueful generate:openapi -f -u
    |
    | If changes don't appear, try:
    |   1. Hard refresh the browser (Cmd+Shift+R / Ctrl+Shift+R)
    |   2. Clear browser cache
    |   3. Run with --clean flag: php glueful generate:openapi -f -u --clean
    |
    */
    'info' => [
        'title' => env('API_TITLE', env('APP_NAME', 'API Documentation')),
        'description' => env('API_DESCRIPTION', 'Auto-generated API documentation'),
        'version' => env('API_VERSION', '1') . '.0.0',
        'contact' => [
            'name' => env('API_CONTACT_NAME', ''),
            'email' => env('API_CONTACT_EMAIL', ''),
            'url' => env('API_CONTACT_URL', ''),
        ],
        'license' => [
            'name' => env('API_LICENSE_NAME', ''),
            'url' => env('API_LICENSE_URL', ''),
            // SPDX identifier for OpenAPI 3.1+ (e.g., 'MIT', 'Apache-2.0')
            'identifier' => env('API_LICENSE_IDENTIFIER', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Configuration
    |--------------------------------------------------------------------------
    |
    | Define the server URLs that appear in the generated documentation.
    | Multiple servers can be defined for different environments.
    |
    */
    'servers' => [
        [
            // Use base URL - route paths include their own prefixes
            'url' => env('API_SERVER_URL', env('APP_URL', 'http://localhost')),
            'description' => env('API_SERVER_DESCRIPTION', 'API Server'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Paths
    |--------------------------------------------------------------------------
    |
    | Paths where generated documentation files are stored.
    | All paths are relative to base_path() unless absolute.
    |
    */
    'paths' => [
        // Main documentation output directory
        'output' => $root . '/docs',

        // Final OpenAPI specification file location
        'openapi' => $root . '/docs/openapi.json',

        // JSON definitions for route-based documentation
        'route_definitions' => $root . '/docs/json-definitions/routes',

        // JSON definitions for extension documentation
        'extension_definitions' => $root . '/docs/json-definitions/extensions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Paths
    |--------------------------------------------------------------------------
    |
    | Paths to scan for routes when generating documentation.
    | Extensions are discovered via Composer packages through ExtensionManager.
    |
    */
    'sources' => [
        // Directory containing project route files
        'routes' => $root . '/routes',

        // Include framework routes in documentation generation
        'include_framework_routes' => env('API_DOCS_INCLUDE_FRAMEWORK_ROUTES', true),

        // Framework routes directory (auto-detected from vendor or local path)
        'framework_routes' => null, // Will be resolved at runtime

        /*
        |--------------------------------------------------------------------------
        | Route File Prefixes
        |--------------------------------------------------------------------------
        |
        | Map route files to their URL path prefixes. Routes in these files will
        | have the prefix prepended to their documented paths.
        |
        | Format: 'filename.php' => '/prefix' or '' for no prefix
        |
        */
        'route_prefixes' => [
            // App routes - versioned API
            'api.php' => '/v1',

            // Framework routes - no version prefix
            'health.php' => '',
            'docs.php' => '',

            // Framework auth routes - versioned
            'auth.php' => '/v1',
            'resource.php' => '/v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    |
    | Declared OpenAPI 3.1 security schemes. Keys are the scheme names that
    | appear in #/components/securitySchemes and in operation `security`
    | requirements. The middleware_map tells the documentation generator
    | which scheme(s) protect a route based on its declared middleware.
    |
    */
    'security_schemes' => [
        'BearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => 'JWT bearer token in the Authorization header.',
        ],
        'ApiKeyAuth' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-API-Key',
            'description' => 'API key issued via the developer console.',
        ],
    ],

    'middleware_map' => [
        'auth' => ['BearerAuth'],
        'api_key' => ['ApiKeyAuth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inferred error responses
    |--------------------------------------------------------------------------
    | Body schema + descriptions the generator attaches to auto-inferred error
    | responses (401/403 on secured routes, 429 on rate-limited routes, plus any
    | status listed in `always`). `schema: null` uses a slim inline {success,
    | message} shape; set a thin public-typed DTO class to reflect instead.
    | Do NOT point this at Glueful\DTOs\ErrorResponseDTO (the fat debug DTO).
    */
    'errors' => [
        'schema'   => env('API_DOCS_ERROR_SCHEMA', null),
        'envelope' => false,
        'always'   => [],
        'descriptions' => [
            401 => 'Unauthenticated.',
            403 => 'Forbidden.',
            429 => 'Too Many Requests.',
            500 => 'Unexpected server error.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation Options
    |--------------------------------------------------------------------------
    |
    | Options that control how documentation is generated.
    |
    */
    'options' => [
        // Include extension routes in the generated spec (read by the reflect generator)
        'include_extensions' => true,

        // Pretty print JSON output
        'pretty_print' => true,

        // Generate resource/table routes (CRUD endpoints for all database tables)
        // Set to false to disable automatic generation of table-based API endpoints
        'include_resource_routes' => env('API_DOCS_INCLUDE_RESOURCE_ROUTES', false),

        // Drop default component schemas (LoginRequest, User, Notification, ...) that
        // no path/webhook references, so the spec carries only schemas it actually uses.
        // Default false: the built-in defaults are kept for backward compatibility.
        // Explicitly-documented schemas (from route/extension fragments) are always kept.
        'prune_unreferenced_schemas' => env('API_DOCS_PRUNE_UNREFERENCED_SCHEMAS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    |
    | Tables to exclude from resource route expansion and documentation.
    | System/internal tables that shouldn't be exposed in the API.
    |
    */
    'excluded_tables' => [
        // System tables
        'migrations',
        'failed_jobs',
        'password_resets',
        'personal_access_tokens',
        'jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        // Tables with explicit routes in api.php (avoid duplicate docs)
        'notifications',
        'notification_preferences',
        'notification_templates',
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation UI
    |--------------------------------------------------------------------------
    |
    | Settings for the interactive documentation UI.
    | Supported: "scalar", "swagger-ui", "redoc"
    |
    */
    'ui' => [
        // Default UI to generate (scalar, swagger-ui, redoc)
        'default' => env('API_DOCS_UI', 'scalar'),

        // Output filename for the generated HTML
        'filename' => 'index.html',

        // Page title
        'title' => env('API_DOCS_UI_TITLE', 'API Documentation'),

        // Scalar-specific settings
        'scalar' => [
            'theme' => env('API_DOCS_THEME', 'purple'),
            'dark_mode' => env('API_DOCS_DARK_MODE', true),
            'hide_download_button' => false,
            'hide_client_button' => true,
            'hide_models' => false,
            'default_open_all_tags' => false,
            'show_developer_tools' => 'never',
            'hide_powered_badge' => true,
        ],

        // Swagger UI-specific settings
        'swagger_ui' => [
            'deep_linking' => true,
            'display_request_duration' => true,
            'filter' => true,
        ],

        // Redoc-specific settings
        'redoc' => [
            'expand_responses' => '200,201',
            'hide_download_button' => false,
            'theme' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Events this API dispatches as outbound HTTP webhooks. Each entry will
    | appear in the OpenAPI 3.1 `webhooks` object so SDK generators can
    | scaffold handler types automatically.
    |
    */
    'webhooks' => [
        // Example:
        // 'user.created' => [
        //     'summary' => 'A new user has been created.',
        //     'payload_schema' => 'User',  // References #/components/schemas/User
        // ],
    ],
];
