<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Exclusive Allow-List Mode (App Providers)
    |--------------------------------------------------------------------------
    |
    | When 'only' is set, ONLY these application service providers load.
    | This does not include vendor extensions; set this to strictly control
    | which first-party providers are allowed to load.
    |
    */
    // 'only' => [
    //     App\Core\CoreProvider::class,
    //     App\Features\Payments\PaymentsProvider::class,
    // ],

    /*
    |--------------------------------------------------------------------------
    | Enabled App Providers
    |--------------------------------------------------------------------------
    |
    | First-party providers loaded in all environments (order preserved).
    |
    */
    'enabled' => [
        // App\Providers\AppServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Development-Only App Providers
    |--------------------------------------------------------------------------
    |
    | App providers loaded only in non-production environments.
    |
    */
    'dev_only' => [
        // App\Providers\DevToolsProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Disabled App Providers
    |--------------------------------------------------------------------------
    |
    | Block specific application providers from being loaded.
    |
    */
    'disabled' => [
        // App\Legacy\OldProvider::class,
    ],
];
