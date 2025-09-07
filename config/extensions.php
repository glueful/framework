<?php

return [
     /*
    |--------------------------------------------------------------------------
    | Exclusive Allow-List Mode
    |--------------------------------------------------------------------------
    |
    | When 'only' is set, ONLY these providers load. Nothing else.
    | This overrides all other discovery methods for maximum security.
    |
    */
    // 'only' => [
    //     // Core business logic
    //     'App\\Banking\\CoreBankingProvider',
    //     'App\\Banking\\TransactionProvider',
    //     'App\\Banking\\ComplianceProvider',

    //     // Approved third-party
    //     'Vendor\\Audit\\AuditProvider',
    //     'Vendor\\Encryption\\FIPSProvider',

    //     // NOTHING else can load - no auto-discovery
    // ],

    // Note: 'enabled', 'disabled', 'dev_only' are ignored when 'only' is set
    /*
    |--------------------------------------------------------------------------
    | Enabled Extensions
    |--------------------------------------------------------------------------
    |
    | List of extension service providers to load. These are loaded
    | in the order specified. Overall discovery order:
    | 1) enabled, 2) dev_only (non-prod), 3) local dev, 4) composer.
    |
    */
    'enabled' => [
        // Core extensions
        // Glueful\Blog\BlogServiceProvider::class,
        // Glueful\Shop\ShopServiceProvider::class,

        // Third-party
        // Vendor\Analytics\AnalyticsServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Extensions
    |--------------------------------------------------------------------------
    |
    | Extensions only loaded in non-production environments.
    |
    */
    'dev_only' => [
        // Glueful\Debug\DebugServiceProvider::class,
        // Glueful\Profiler\ProfilerServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Disabled Extensions (Blacklist)
    |--------------------------------------------------------------------------
    |
    | Block specific extensions even if discovered. Useful for temporarily
    | disabling problematic extensions during development.
    |
    */
    'disabled' => [
        // Example: Temporarily disable broken extension
        // Vendor\Broken\BrokenServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Extensions Path
    |--------------------------------------------------------------------------
    |
    | Path to scan for local extensions during development.
    | Set to null to disable local extension scanning.
    |
    */
    'local_path' => env('APP_ENV') === 'production' ? null : 'extensions',

    /*
    |--------------------------------------------------------------------------
    | Composer Package Scanning
    |--------------------------------------------------------------------------
    |
    | Whether to scan Composer packages for glueful-extension types.
    | Set to false to disable Composer scanning (for troubleshooting).
    |
    */
    'scan_composer' => true,
];
