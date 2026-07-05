<?php

/**
 * Extensions
 *
 * Composer discovers installed `glueful-extension` packages (see their
 * extra.glueful.provider). This file is the single activation allow-list:
 * an installed extension does nothing until its provider FQCN appears below.
 *
 * - Entries are plain string FQCNs (no ::class) so `php glueful extensions:enable|disable`
 *   can edit this list safely. Do not use conditionals/function calls here.
 * - Order is preserved; dependencies are reordered automatically.
 * - Empty = nothing loads. To kill everything fast, set `enabled => []`.
 *
 * Manage with: php glueful extensions:list | enable <name> | disable <name> | cache
 */

return [
    'enabled' => [
        // 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
    ],

    /**
     * In-admin extension installer (composer require via the /extensions API).
     * Off in production unless EXTENSIONS_INSTALL_ENABLED is explicitly set.
     * Keep env() reads here — NOT inside `enabled` above (that must stay a
     * literal list the enable/disable writer can edit).
     */
    'install' => [
        'enabled'    => env('EXTENSIONS_INSTALL_ENABLED', env('APP_ENV') !== 'production'),
        'timeout'    => (int) env('EXTENSIONS_INSTALL_TIMEOUT', 600),
        'vendor'     => 'glueful/',
        // Absolute path to a CLI php used to run composer. Leave null to auto-detect
        // (PhpExecutableFinder, then PHP_BINARY). Set it when the web SAPI's php is not
        // a usable CLI interpreter (Apache module / php-cgi / nginx+FPM).
        'php_binary' => env('EXTENSIONS_INSTALL_PHP_BINARY') ?: null,
    ],
];
