<?php

/**
 * Documentation Generation Configuration
 *
 * Centralizes all settings for OpenAPI/Swagger documentation generation.
 * Used by TableDefinitionGenerator, DocGenerator, CommentsDocGenerator, and OpenApiGenerator.
 */

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
    */
    'openapi_version' => env('OPENAPI_VERSION', '3.0.0'),

    /*
    |--------------------------------------------------------------------------
    | API Information
    |--------------------------------------------------------------------------
    |
    | Metadata about your API that appears in the generated documentation.
    | These values populate the "info" section of the OpenAPI spec.
    |
    */
    'info' => [
        'title' => env('API_TITLE', env('APP_NAME', 'API Documentation')),
        'description' => env('API_DESCRIPTION', 'Auto-generated API documentation'),
        'version' => env('API_VERSION', '1.0.0'),
        'contact' => [
            'name' => env('API_CONTACT_NAME', ''),
            'email' => env('API_CONTACT_EMAIL', ''),
            'url' => env('API_CONTACT_URL', ''),
        ],
        'license' => [
            'name' => env('API_LICENSE_NAME', ''),
            'url' => env('API_LICENSE_URL', ''),
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
            'url' => env('API_SERVER_URL', env('APP_URL', 'http://localhost') . '/api'),
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
        'output' => base_path('docs'),

        // Final swagger.json file location
        'swagger' => base_path('docs/swagger.json'),

        // JSON definitions for database tables
        'database_definitions' => base_path('docs/json-definitions/database'),

        // JSON definitions for route-based documentation
        'route_definitions' => base_path('docs/json-definitions/routes'),

        // JSON definitions for extension documentation
        'extension_definitions' => base_path('docs/json-definitions/extensions'),
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
        // Directory containing route files
        'routes' => base_path('routes'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    |
    | Default security schemes to include in the generated documentation.
    | These define authentication methods for your API.
    |
    */
    'security_schemes' => [
        'BearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => 'JWT authentication token',
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
        // Include database table schemas in documentation
        'include_tables' => true,

        // Include route-based documentation from PHPDoc comments
        'include_routes' => true,

        // Include extension documentation
        'include_extensions' => true,

        // Pretty print JSON output
        'pretty_print' => true,
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
            'hide_models' => false,
            'default_open_all_tags' => false,
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
];
