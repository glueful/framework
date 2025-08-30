<?php

/**
 * Extensions Configuration
 *
 * Configuration for the extension system including local directory access,
 * Composer package discovery, and environment-specific settings.
 */

return [
    
    // Extension Discovery Settings
    'discovery' => [
        // Allow local extensions directory scanning
        // - Production: false (Composer packages only for security)
        // - Development: true (allows local extensions for development)
        'allow_local' => env('ALLOW_LOCAL_EXTENSIONS', env('APP_ENV') !== 'production'),
        
        // Cache discovery results to improve performance
        'cache_enabled' => env('EXTENSION_CACHE_ENABLED', true),
        
        // Cache TTL in seconds (1 hour default)
        'cache_ttl' => env('EXTENSION_CACHE_TTL', 3600),
    ],
    
    // Local Extensions Security
    'local' => [
        // Disallow symlinks in extensions directory for security
        'disallow_symlinks' => env('EXTENSIONS_DISALLOW_SYMLINKS', true),
        
        // Validate file ownership and permissions
        'validate_ownership' => env('EXTENSIONS_VALIDATE_OWNERSHIP', false),
        
        // Required file permissions (octal)
        'required_permissions' => env('EXTENSIONS_REQUIRED_PERMS', 0644),
    ],
    
    // Composer Extensions
    'composer' => [
        // Package type to discover
        'package_type' => 'glueful-extension',
        
        // Required extra section in composer.json
        'required_extra' => 'glueful.extension-class',
        
        // Enable PSR-4 autoloading registration
        'autoload_enabled' => env('EXTENSIONS_AUTOLOAD_ENABLED', true),
    ],
    
    // Extension Precedence
    'precedence' => [
        // When conflicts occur between Composer and local extensions
        // 'composer' = prefer Composer packages
        // 'local' = prefer local extensions  
        // 'error' = throw error on conflicts
        'conflict_resolution' => env('EXTENSION_CONFLICT_RESOLUTION', 'composer'),
        
        // Log conflicts for debugging
        'log_conflicts' => env('EXTENSION_LOG_CONFLICTS', true),
    ],
    
    // Extension Loading
    'loading' => [
        // Enable debug mode for extension loading
        'debug' => env('EXTENSION_DEBUG', env('APP_DEBUG', false)),
        
        // Automatically enable extensions found in Composer
        'auto_enable_composer' => env('EXTENSION_AUTO_ENABLE_COMPOSER', false),
        
        // Automatically enable local extensions in development
        'auto_enable_local' => env('EXTENSION_AUTO_ENABLE_LOCAL', env('APP_ENV') === 'development'),
    ],
    
    // Extension Paths
    'paths' => [
        // Local extensions directory
        'local_directory' => dirname(__DIR__) . '/extensions',
        
        // Extensions configuration file
        'config_file' => dirname(__DIR__) . '/extensions/extensions.json',
        
        // Extension manifests directory
        'manifests' => dirname(__DIR__) . '/extensions/manifests',
    ],
];