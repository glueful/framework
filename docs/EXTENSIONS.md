# Glueful Extensions System Documentation

## Overview

The Glueful Extensions System provides a modern, Laravel/Symfony-inspired architecture for extending the framework's functionality. Built on the industry-standard ServiceProvider pattern, it delivers high performance with minimal complexity - just ~400 lines of core code.

## Table of Contents

- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [Creating Extensions](#creating-extensions)
- [ServiceProvider Pattern](#serviceprovider-pattern)
- [Configuration](#configuration)
- [Command Reference](#command-reference)
- [Real-World Example](#real-world-example)
- [Migration Guide](#migration-guide)
- [Performance](#performance)
- [Troubleshooting](#troubleshooting)

## Quick Start

### 1. Check System Status

```bash
# List all extensions with status (shows enabled/disabled)
php glueful extensions:list

# Show detailed extension information
php glueful extensions:info blog

# Explain why a provider was included or excluded
php glueful extensions:why BlogProvider

# Show system summary with performance metrics
php glueful extensions:summary
```

### 2. Create Your First Extension

```bash
# Create new local extension
php glueful create:extension my-extension

# The extension will be created in extensions/my-extension/
# with a basic ServiceProvider and composer.json
```

### 3. Install a Composer Extension

```bash
# Install via Composer (recommended)
composer require vendor/my-extension

# Extension will be auto-discovered if type is "glueful-extension"
# Rebuild cache after adding new packages
php glueful extensions:cache
```

## Architecture

### Core Principles

1. **Composer-native discovery** - Leverages existing package manager
2. **ServiceProvider pattern** - Familiar to all PHP developers
3. **PHP config files** - Type-safe and IDE-friendly
4. **Standard interfaces** - Reuses Symfony/PSR interfaces
5. **Convention over configuration** - Minimal boilerplate

### Extension Types

#### Composer Package (Recommended)

```json
// composer.json
{
    "name": "vendor/my-extension",
    "type": "glueful-extension",
    "autoload": {
        "psr-4": {
            "Vendor\\MyExtension\\": "src/"
        }
    },
    "extra": {
        "glueful": {
            "provider": "Vendor\\MyExtension\\MyExtensionServiceProvider"
        }
    }
}
```

#### Local Development Extension

```
extensions/
└── my-extension/
    ├── composer.json
    ├── src/
    │   └── MyExtensionServiceProvider.php
    ├── routes/
    │   └── routes.php
    ├── config/
    │   └── my-extension.php
    └── database/
        └── migrations/
```

## ServiceProvider Pattern

Each extension has a single ServiceProvider that registers all functionality:

```php
<?php

namespace MyExtension;

use Glueful\Extensions\ServiceProvider;

class MyExtensionServiceProvider extends ServiceProvider
{
    /**
     * Define services for container compilation (production-ready)
     */
    public static function services(): array
    {
        return [
            MyService::class => [
                'class' => MyService::class,
                'shared' => true,
                'arguments' => ['@db']
            ]
        ];
    }
    
    /**
     * Register runtime services and config defaults
     */
    public function register(): void
    {
        // Note: Service registration happens via static services() method
        // This method is for configuration merging and other runtime setup
        
        // Merge default configuration
        $this->mergeConfig('my-extension', require __DIR__.'/../config/config.php');
    }
    
    /**
     * Boot after all providers are registered
     */
    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/routes.php');
        
        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        
        // Register console commands
        if ($this->runningInConsole()) {
            $this->commands([
                Commands\MyCommand::class,
            ]);
        }
        
        // Register extension metadata (if container has ExtensionManager)
        if ($this->app->has(\Glueful\Extensions\ExtensionManager::class)) {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'my-extension',
                'name' => 'My Extension',
                'version' => '1.0.0',
                'description' => 'My awesome extension',
            ]);
        }
    }
}
```

### Available Helper Methods

- `loadRoutesFrom(string $path)` - Load route definitions
- `loadMigrationsFrom(string $dir)` - Register migration directory  
- `mergeConfig(string $key, array $defaults)` - Merge default configuration
- `loadMessageCatalogs(string $dir, string $domain = 'messages')` - Load translation catalogs
- `mountStatic(string $mount, string $dir)` - Serve static assets (dev/admin UIs)
- `commands(array $commands)` - Register console commands
- `runningInConsole()` - Check if running in CLI

### Optional Interfaces

#### OrderedProvider

Control provider boot order:

```php
use Glueful\Extensions\OrderedProvider;

class MyServiceProvider extends ServiceProvider implements OrderedProvider
{
    public function priority(): int
    {
        return 10; // Lower number boots first
    }
    
    public function bootAfter(): array
    {
        return [
            DatabaseServiceProvider::class,
            CacheServiceProvider::class,
        ];
    }
}
```

#### DeferrableProvider

Declare provided services (for future lazy loading):

```php
use Glueful\Extensions\DeferrableProvider;

class MyServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function provides(): array
    {
        return [
            MyService::class,
            MyRepository::class,
        ];
    }
}
```

## Configuration

### config/extensions.php

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Enabled Extensions
    |--------------------------------------------------------------------------
    |
    | List of extension service providers to load. Discovery order:
    | 1) enabled, 2) dev_only (non-prod), 3) local dev, 4) composer.
    |
    */
    'enabled' => [
        // Core extensions
        Glueful\Blog\BlogServiceProvider::class,
        Glueful\Shop\ShopServiceProvider::class,
        
        // Third-party
        Vendor\Analytics\AnalyticsServiceProvider::class,
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
        Glueful\Debug\DebugServiceProvider::class,
        Glueful\Profiler\ProfilerServiceProvider::class,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Disabled Extensions (Blacklist)
    |--------------------------------------------------------------------------
    |
    | Block specific extensions even if discovered. Useful for temporarily
    | disabling problematic extensions or blocking transitive dependencies.
    |
    */
    'disabled' => [
        // Example: Block debug tools in production
        // Barryvdh\Debugbar\ServiceProvider::class,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Exclusive Allow-List Mode (Ultra-Secure)
    |--------------------------------------------------------------------------
    |
    | When 'only' is set, ONLY these providers load. Nothing else.
    | This overrides all other discovery methods for maximum security.
    |
    */
    // 'only' => [
    //     'App\\Core\\CoreProvider',
    //     'Vendor\\Approved\\ApprovedProvider',
    // ],
    
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
```

## Command Reference

### Discovery & Information

```bash
php glueful extensions:list              # List all discovered extensions with status
php glueful extensions:info <slug>       # Show detailed extension information
php glueful extensions:why <provider>    # Explain why a provider was included/excluded
php glueful extensions:summary           # Show system summary with performance metrics
```

### Cache Management

```bash
php glueful extensions:cache             # Build extensions cache for production
php glueful extensions:clear             # Clear extensions cache
```

### Development Tools

```bash
php glueful create:extension <name>      # Create new local extension scaffold
php glueful extensions:enable <name>     # Enable extension (dev only)
php glueful extensions:disable <name>    # Disable extension (dev only)
```

**Note**: `enable` and `disable` commands modify your local `config/extensions.php` file for development convenience.

### Example CLI Output

```bash
$ php glueful extensions:list
✓ App\Extensions\BlogProvider (enabled, composer)
✓ App\Extensions\ShopProvider (enabled, local scan)
✗ App\Extensions\TestProvider (disabled in config)
✓ VendorPackage\CmsProvider (enabled, composer)

$ php glueful extensions:why BlogProvider
✓ Found: App\Extensions\BlogProvider
✓ Source: composer scan (vendor/myapp/blog-extension)
✓ Status: included in final provider list
✓ Load order: priority 100, no dependencies
✓ Boot phase: registered 3 services, mounted /blog static assets

$ php glueful extensions:summary
Extensions: 3 loaded, 1 disabled, 2 deferred
Cache: enabled (built 2 hours ago)
Boot time: 45ms (12ms discovery, 33ms registration)
```

## Real-World Example

### Blog Extension

#### composer.json

```json
{
    "name": "glueful/blog",
    "description": "Blog functionality for Glueful",
    "type": "glueful-extension",
    "require": {
        "php": "^8.2",
        "glueful/framework": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Glueful\\Blog\\": "src/"
        }
    },
    "extra": {
        "glueful": {
            "provider": "Glueful\\Blog\\BlogServiceProvider",
            "minVersion": "1.0.0"
        }
    }
}
```

#### BlogServiceProvider.php

```php
<?php

namespace Glueful\Blog;

use Glueful\Extensions\ServiceProvider;
use Glueful\Blog\Services\BlogService;
use Glueful\Blog\Controllers\BlogController;

class BlogServiceProvider extends ServiceProvider
{
    public static function services(): array
    {
        return [
            BlogService::class => [
                'class' => BlogService::class,
                'shared' => true,
                'arguments' => ['@db', '@cache']
            ]
        ];
    }
    
    public function register(): void
    {
        // Note: Service registration happens via static services() method
        // This method handles configuration merging and other setup
        
        // Merge default config
        $this->mergeConfig('blog', require __DIR__.'/../config/blog.php');
    }
    
    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/blog.php');
        
        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        
        // Register middleware
        $this->app->get('middleware.registry')->register([
            'blog.auth' => Middleware\BlogAuthMiddleware::class,
        ]);
        
        // Register console commands
        if ($this->runningInConsole()) {
            $this->commands([
                Commands\BlogInstallCommand::class,
            ]);
        }
        
        // Register metadata (if container has ExtensionManager)
        if ($this->app->has(\Glueful\Extensions\ExtensionManager::class)) {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'blog',
                'name' => 'Blog Extension',
                'version' => '1.0.0',
                'description' => 'Blogging functionality for Glueful',
            ]);
        }
    }
}
```

#### routes/blog.php

```php
<?php

use Glueful\Blog\Controllers\BlogController;
use Glueful\Routing\Router;

// $router is available from the container
$router->group(['prefix' => 'blog'], function (Router $router) {
    // Public routes
    $router->get('/', [BlogController::class, 'index'])->name('blog.index');
    $router->get('/posts/{slug}', [BlogController::class, 'show'])
           ->where('slug', '[a-z0-9\-]+')
           ->name('blog.show');
    
    // Admin routes
    $router->group(['middleware' => ['auth:api', 'role:admin']], function (Router $router) {
        $router->post('/posts', [BlogController::class, 'store'])->name('blog.store');
        $router->put('/posts/{id}', [BlogController::class, 'update'])->name('blog.update');
        $router->delete('/posts/{id}', [BlogController::class, 'destroy'])->name('blog.destroy');
    });
});
```

## Migration Guide

### From Old Extension System

If migrating from the old manifest.json-based system:

| Old System | New System |
|------------|------------|
| `manifest.json` | `composer.json` with `extra.glueful.provider` |
| `BaseExtension` class | `ServiceProvider` class |
| `Extension.php` | `ServiceProvider.php` |
| Routes in manifest | `loadRoutesFrom()` in provider |
| Migrations in manifest | `loadMigrationsFrom()` in provider |
| Config publishing | `mergeConfig()` in register() |
| Runtime service registration | Static `services()` method + runtime fallback |

### Migration Steps

1. Create a `composer.json` with type `glueful-extension`
2. Convert your main extension class to extend `ServiceProvider`
3. **Add static `services()` method for production compilation**
4. Move initialization logic to `register()` and `boot()` methods
5. Update route and migration loading to use helper methods
6. Remove old `manifest.json` file

### Service Registration Patterns

**Extensions use static services definition for container compilation:**

```php
// Static services definition for container compilation
public static function services(): array 
{
    return [
        MyService::class => [
            'class' => MyService::class,
            'shared' => true,
            'arguments' => ['@db']
        ]
    ];
}

// Runtime configuration and setup
public function register(): void 
{
    // Handle configuration merging and other non-service setup
    $this->mergeConfig('my-extension', require __DIR__.'/../config/config.php');
}

public function boot(): void
{
    // Load routes, migrations, commands, etc.
    $this->loadRoutesFrom(__DIR__.'/../routes/routes.php');
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
}
```

## Performance

### Metrics

| Aspect | Performance |
|--------|------------|
| **Discovery Time** | < 5ms (cached) |
| **Boot Time** | < 10ms for 10 extensions |
| **Memory Usage** | ~400KB per extension |
| **Cache Hit** | 100% in production |

### Optimization

#### Production Deployment

For optimal production performance, follow this deployment sequence:

```bash
# 1. Clear any existing cache
php glueful extensions:clear

# 2. Build extensions cache
php glueful extensions:cache

# 3. Compile DI container with extension services
php glueful di:container:compile

# 4. Clear application cache if needed
php glueful cache:clear
```

#### Production Caching

```bash
# Build cache for production
php glueful extensions:cache

# Cache location: bootstrap/cache/extensions.php
# Cache persists until explicitly cleared
```

#### Development Mode

- Cache expires after 5 seconds by default (configurable via `EXTENSIONS_CACHE_TTL_DEV`)
- Local extensions scanned on each request if `extensions.local_path` is set
- Full discovery mode enabled including composer packages
- Supports development commands for enabling/disabling extensions

## Troubleshooting

### Common Issues

#### Extension Not Found

```
[Extensions] Extension provider not found ['provider' => 'MyExtension\\Provider']
```

**Solution**: Ensure the provider class exists and is properly autoloaded.

#### Invalid composer.json

```
[Extensions] Invalid composer.json in /path/to/extension/composer.json: Syntax error
```

**Solution**: Validate JSON syntax and ensure file is valid.

#### Provider Boot Failure

```
[Extensions] Provider failed during boot() ['provider' => 'MyExtension\\Provider', 'error' => '...']
```

**Solution**: Check provider's boot() method for errors. System continues with other providers.

#### Circular Dependencies

```
[Extensions] Circular dependency detected in provider bootAfter(), using priority fallback
```

**Solution**: Review `bootAfter()` dependencies to remove cycles.

### Debug Commands

```bash
# Show all registered providers
php glueful extensions:list

# Check specific extension
php glueful extensions:info my-extension

# View system summary
php glueful extensions:summary

# Clear cache if having issues
php glueful extensions:clear
```

## API-First Philosophy

Glueful follows an API-first approach:

- **No runtime views**: Extensions don't use view templating
- **No asset publishing**: No file copying into the app
- **Prebuilt frontends**: SPAs are compiled and served as static assets
- **Configuration via files**: No in-app configuration UIs

For frontend assets, use `mountStatic()` in development or serve via CDN in production.

## Security

The extension system includes multiple security features:

- **Path traversal protection** in `mountStatic()`
- **File size limits** for local extension scanning (100KB max)
- **Symlink detection** to prevent traversal
- **Graceful error handling** - failures don't crash the system
- **PSR-3 logging** for security events

## Summary

The Glueful Extensions System provides:

- **Simple architecture** - Just 3 core files, ~400 lines
- **Familiar patterns** - ServiceProvider from Laravel/Symfony
- **Modern tooling** - Composer packages, PSR-4 autoloading
- **High performance** - Production caching, lazy loading
- **Developer friendly** - Clear structure, good IDE support
- **Production ready** - Error handling, logging, security

For more examples and advanced usage, see the framework documentation.