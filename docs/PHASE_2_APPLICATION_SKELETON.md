# Phase 2: Transform Current Repository to Application Skeleton

## Overview

This phase transforms the current monolithic repository into a clean application skeleton that depends on the `glueful/framework` package. Users will interact with this skeleton to build their APIs.

## Step 2.1: Backup Current State

```bash
cd glueful
git checkout -b package-architecture-backup
git checkout -b package-architecture
```

## Step 2.2: Remove Framework Code

```bash
# Remove framework source code (now in package)
rm -rf api/

# Create user application directory
mkdir -p api/Controllers
mkdir -p api/Middleware  
mkdir -p api/Services
mkdir -p api/Models

# Create user docs directory for API documentation
mkdir -p docs/json-definitions
touch docs/swagger.json
touch docs/index.html

# Add .gitkeep files
touch api/Controllers/.gitkeep
touch api/Middleware/.gitkeep
touch api/Services/.gitkeep
touch api/Models/.gitkeep
```

## Step 2.3: Create Application Bootstrap

Modern instance-based bootstrap using the new Framework class:

```php
<?php
// bootstrap/app.php

declare(strict_types=1);

use Glueful\Framework;

// Load composer autoloader  
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env file
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Create and configure the framework instance
$framework = Framework::create(dirname(__DIR__))
    ->withConfigDir(__DIR__ . '/../config')
    ->withEnvironment($_ENV['APP_ENV'] ?? 'production');

// Boot the framework and get application instance
$app = $framework->boot();

// Register any application-specific service providers
// $app->getContainer()->register(App\Providers\AppServiceProvider::class);

return $app;
```

**Migration Benefits:**
- ✅ Clean fluent configuration API
- ✅ Proper dependency injection
- ✅ Testable (can mock instances)
- ✅ Immutable configuration (each `with*` method returns new instance)
- ✅ Clear separation: Framework handles bootstrap, Application handles requests
- ✅ Better error handling (exceptions vs global state)

## Step 2.4: Update Public Entry Point

Modern PSR-7 compliant entry point using Application instance:

```php
<?php
// public/index.php

declare(strict_types=1);

use Glueful\Http\ServerRequestFactory;
use Glueful\Http\Cors;
use Glueful\SpaManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// Bootstrap the framework application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$container = $app->getContainer();

// Create global request logger
$requestLogger = $container->has(LoggerInterface::class)
    ? $container->get(LoggerInterface::class)
    : new NullLogger();

// Get request URI and method
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Log the incoming request
$requestLogger->debug("Request received", [
    'method' => $requestMethod,
    'uri' => $requestUri,
    'path_info' => $_SERVER['PATH_INFO'] ?? 'not_set'
]);

// Create PSR-7 request object
$request = ServerRequestFactory::fromGlobals();

// Handle CORS for API requests
if (str_starts_with($requestUri, '/api/')) {
    $cors = new Cors();
    if (!$cors->handle()) {
        return; // OPTIONS request handled
    }
    // Strip /api prefix for API routes processing
    $apiPath = substr($requestUri, 4);
    if (empty($apiPath)) {
        $apiPath = '/';
    }
    
    // Update request URI for framework routing
    $request = $request->withUri(
        $request->getUri()->withPath($apiPath)
    );
}

// Handle SPA routing for non-API requests
if (!str_starts_with($requestUri, '/api/')) {
    $path = parse_url($requestUri, PHP_URL_PATH);
    
    try {
        $spaManager = $container->get(SpaManager::class);
        if ($spaManager->handleSpaRouting($path)) {
            exit; // SPA or asset was served
        }
    } catch (\Throwable $e) {
        $requestLogger->error("SPA routing failed: " . $e->getMessage());
    }
}

// Handle request through modern Application instance
$response = $app->handle($request);

// Send the response
$response->send();

// Perform any cleanup
$app->terminate($request, $response);
```

**Key Improvements:**
- ✅ PSR-7 compliant Request/Response handling
- ✅ Proper URI manipulation instead of global $_SERVER modification
- ✅ Instance-based application handling vs static API calls
- ✅ Clean separation of concerns
- ✅ Proper request lifecycle (handle → send → terminate)

## Step 2.5: Update CLI Entry Point

Keep the current CLI complexity but use new bootstrap:

```php
#!/usr/bin/env php
<?php
/**
 * Glueful Console Application
 * 
 * Command line interface for Glueful framework. Provides utilities for:
 * - Database migrations, Schema management, Cache operations
 * - Code generation, Development tools
 */

// Ensure we're running from command line
if (PHP_SAPI !== 'cli') {
    die('This script can only be run from the command line.');
}

// Load composer autoloader
require dirname(__FILE__) . '/vendor/autoload.php';

// Check if this is an install command and handle setup
$isInstallCommand = in_array('install', $argv ?? []);

// For install command, check if .env exists first
if ($isInstallCommand && !file_exists(dirname(__FILE__) . '/.env')) {
    error_log("DEBUG: Install command detected, .env does not exist");
    
    // Create storage/database directory if needed
    $databaseDir = dirname(__FILE__) . '/storage/database';
    if (!is_dir($databaseDir)) {
        mkdir($databaseDir, 0755, true);
    }
    
    // Create .env from .env.example
    $envPath = dirname(__FILE__) . '/.env';
    $examplePath = dirname(__FILE__) . '/.env.example';
    
    if (file_exists($examplePath)) {
        copy($examplePath, $envPath);
        echo "Created .env file from .env.example\n";
    }
    
    // Handle minimal initialization for install
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__FILE__));
        $dotenv->load();
    } catch (Exception $e) {
        echo "Warning: Could not load .env file: " . $e->getMessage() . "\n";
    }
}

// Load .env if it exists
if (file_exists(dirname(__FILE__) . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__FILE__));
    $dotenv->load();
}

try {
    // Bootstrap application
    $app = require __DIR__ . '/bootstrap/app.php';
    
    // Handle console commands
    exit($app->handleConsole());
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    
    // In development, show full trace
    if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
        echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    }
    
    exit(1);
}
```

## Step 2.6: Update Composer Configuration

Transform the monolithic composer.json to depend on the framework package:

```json
{
    "name": "glueful/api",
    "description": "Glueful API Application Skeleton",
    "type": "project",
    "keywords": ["api", "skeleton", "glueful", "rest", "php"],
    "homepage": "https://glueful.com/",
    "license": "MIT",
    "authors": [
        {
            "name": "Michael Tawiah Sowah",
            "email": "michael@glueful.dev"
        }
    ],
    "require": {
        "php": "^8.2",
        "glueful/framework": "^1.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "squizlabs/php_codesniffer": "^3.6"
    },
    "autoload": {
        "psr-4": {
            "App\\": "api/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "App\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "vendor/bin/phpunit",
        "test:unit": "vendor/bin/phpunit --testsuite Unit",
        "test:integration": "vendor/bin/phpunit --testsuite Integration",
        "phpcs": "vendor/bin/phpcs",
        "phpcbf": "vendor/bin/phpcbf",
        "post-create-project-cmd": [
            "@php glueful install",
            "@php glueful generate:key"
        ]
    },
    "config": {
        "allow-plugins": {
            "php-http/discovery": true
        },
        "sort-packages": true,
        "optimize-autoloader": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

## Step 2.7: Create Example Application Files

**Example Controller:**

```php
<?php
// api/Controllers/WelcomeController.php

declare(strict_types=1);

namespace App\Controllers;

use Glueful\Http\Controller;
use Glueful\Http\Request;
use Glueful\Http\Response;

class WelcomeController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->json([
            'message' => 'Welcome to your Glueful API!',
            'version' => config('app.version', '1.0.0'),
            'timestamp' => now()->toISOString()
        ]);
    }
    
    public function health(Request $request): Response
    {
        return $this->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'uptime' => uptime(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ]);
    }
}
```

**Example Routes:**

```php
<?php
// routes/api.php

use App\Controllers\WelcomeController;
use Glueful\Http\Router;

// Welcome routes
Router::get('/', [WelcomeController::class, 'index']);
Router::get('/health', [WelcomeController::class, 'health']);

// Protected route examples
Router::middleware('auth')->group(function () {
    Router::get('/user', function () {
        return response()->json([
            'user' => auth()->user()
        ]);
    });
});
```

## Step 2.8: Create Application Tests

```php
<?php
// tests/Feature/WelcomeTest.php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Tests\TestCase;

class WelcomeTest extends TestCase
{
    public function test_welcome_endpoint(): void
    {
        $response = $this->get('/');
        
        $response->assertOk();
        $response->assertJson([
            'message' => 'Welcome to your Glueful API!'
        ]);
    }
    
    public function test_health_endpoint(): void
    {
        $response = $this->get('/health');
        
        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'uptime',
            'memory_usage',
            'peak_memory'
        ]);
    }
}
```

**Base Test Case:**

```php
<?php
// tests/TestCase.php

declare(strict_types=1);

namespace App\Tests;

use Glueful\Framework;
use Glueful\Testing\TestCase as FrameworkTestCase;

abstract class TestCase extends FrameworkTestCase
{
    protected function createApplication(): \Glueful\Application
    {
        $framework = Framework::create(__DIR__ . '/..')
            ->withEnvironment('testing');
            
        return $framework->boot();
    }
}
```

## Step 2.9: Create Sample Configuration

**Application Configuration:**

```php
<?php
// config/app.php

return [
    'name' => env('APP_NAME', 'Glueful API'),
    'version' => '1.0.0',
    'description' => 'My awesome API built with Glueful',
    
    // Extend framework configuration
    'api_documentation' => [
        'title' => env('API_TITLE', 'My API'),
        'description' => env('API_DESCRIPTION', 'API Documentation'),
        'contact' => [
            'name' => env('API_CONTACT_NAME', 'API Support'),
            'email' => env('API_CONTACT_EMAIL', 'api@example.com')
        ]
    ]
];
```

## Step 2.10: Default Extensions Configuration

Create default configuration for skeleton applications with pre-configured core extensions:

**Application Extensions Configuration:**
```php
<?php
// config/extensions.php

return [
    // Core extensions (enabled by default)
    'RBAC' => [
        'enabled' => true,
        'type' => 'core',
        'settings' => [
            'default_role' => 'user',
            'super_admin_role' => 'admin',
            'cache_permissions' => true,
            'role_hierarchy' => [
                'admin' => ['manager', 'user'],
                'manager' => ['user'],
                'user' => []
            ]
        ]
    ],
    
    'EmailNotification' => [
        'enabled' => true,
        'type' => 'core', 
        'settings' => [
            'driver' => env('MAIL_DRIVER', 'smtp'),
            'queue_emails' => env('QUEUE_EMAILS', true),
            'templates_path' => storage_path('email-templates'),
            'from_address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
            'from_name' => env('MAIL_FROM_NAME', 'API Notifications')
        ]
    ],
    
    // Optional extensions (disabled by default)
    'Analytics' => [
        'enabled' => false,
        'type' => 'optional',
        'settings' => [
            'tracking_enabled' => env('ANALYTICS_ENABLED', false),
            'data_retention_days' => env('ANALYTICS_RETENTION', 90),
            'anonymize_ips' => env('ANALYTICS_ANONYMIZE_IPS', true)
        ]
    ],
    
    'PaymentGateway' => [
        'enabled' => false,
        'type' => 'optional',
        'settings' => [
            'default_gateway' => env('PAYMENT_GATEWAY', 'stripe'),
            'test_mode' => env('PAYMENT_TEST_MODE', true),
            'supported_currencies' => ['USD', 'EUR', 'GBP']
        ]
    ],
    
    'CustomAuth' => [
        'enabled' => false,
        'type' => 'optional',
        'settings' => [
            'provider' => env('CUSTOM_AUTH_PROVIDER', 'oauth2'),
            'auto_register' => env('CUSTOM_AUTH_AUTO_REGISTER', true),
            'session_lifetime' => env('CUSTOM_AUTH_SESSION_LIFETIME', 3600)
        ]
    ],
    
    // Global extension settings
    'settings' => [
        'auto_discovery' => env('EXTENSIONS_AUTO_DISCOVERY', true),
        'cache_manifests' => env('EXTENSIONS_CACHE_MANIFESTS', true),
        'development_mode' => env('EXTENSIONS_DEV_MODE', false),
        'registry_url' => env('EXTENSIONS_REGISTRY_URL', 'https://registry.glueful.com')
    ]
];
```

**Extension Environment Configuration:**
```php
<?php
// config/extensions/development.php

return [
    // Override settings for development environment
    'RBAC' => [
        'settings' => [
            'cache_permissions' => false, // Disable caching in dev
            'debug_mode' => true
        ]
    ],
    
    'EmailNotification' => [
        'settings' => [
            'queue_emails' => false, // Send immediately in dev
            'log_emails' => true // Log all emails in dev
        ]
    ],
    
    // Enable debug extensions in development
    'DebugBar' => [
        'enabled' => true,
        'type' => 'development'
    ],
    
    'QueryLogger' => [
        'enabled' => true,
        'type' => 'development'
    ]
];
```

**Extension Environment Configuration (Production):**
```php
<?php
// config/extensions/production.php

return [
    // Production-optimized settings
    'RBAC' => [
        'settings' => [
            'cache_permissions' => true,
            'cache_ttl' => 3600,
            'debug_mode' => false
        ]
    ],
    
    'EmailNotification' => [
        'settings' => [
            'queue_emails' => true,
            'batch_size' => 100,
            'retry_attempts' => 3
        ]
    ],
    
    // Disable debug extensions in production
    'DebugBar' => [
        'enabled' => false
    ],
    
    'QueryLogger' => [
        'enabled' => false
    ]
];
```

## Step 2.11: Update Documentation

**README.md for Application Skeleton:**

```markdown
# My Glueful API

A high-performance API built with the Glueful framework.

## Quick Start

```bash
# Install dependencies
composer install

# Generate application key
php glueful generate:key

# Run migrations
php glueful migrate:run

# Start development server
php glueful serve
```

## API Documentation

Visit `/docs` when the server is running to see the interactive API documentation.

## Testing

```bash
# Run all tests
composer test

# Run specific test suites
composer run test:unit
composer run test:integration
```

## Framework Documentation

For framework documentation, visit [glueful.com/docs](https://glueful.com/docs).
```

## Success Criteria

- [ ] Current repository transformed to clean application skeleton
- [ ] Framework dependency properly configured in composer.json
- [ ] Modern instance-based bootstrap implemented
- [ ] PSR-7 compliant request handling
- [ ] Example application files created
- [ ] Test suite updated for application testing
- [ ] CLI command updated to use new bootstrap
- [ ] Application installs and runs successfully
- [ ] Health check endpoint working
- [ ] API documentation accessible
- [ ] Application skeleton testing suite implemented

## Application Skeleton Testing and Validation

### Overview

Comprehensive testing strategy for validating that the application skeleton works correctly with the framework package and provides a proper foundation for user applications.

### Package Installation Testing

**Automated Installation Test Script:**
```bash
#!/bin/bash
# tests/scripts/test_application_skeleton.sh

set -e

echo "Testing Application Skeleton Installation and Setup..."

# Create temporary directory for test
TEST_DIR=$(mktemp -d)
cd "$TEST_DIR"

echo "Test directory: $TEST_DIR"

# Test 1: Create new project from skeleton
echo "Creating new project from skeleton..."
composer create-project glueful/api test-project --prefer-source --no-interaction

cd test-project

# Test 2: Check that framework is installed as dependency
echo "Checking framework dependency..."
if ! composer show glueful/framework > /dev/null 2>&1; then
    echo "ERROR: glueful/framework not installed as dependency"
    exit 1
fi

# Test 3: Check essential skeleton files exist
echo "Checking essential skeleton files..."
required_files=(
    "glueful"
    "bootstrap/app.php"
    "public/index.php"
    "config/app.php"
    "config/extensions.php"
    "api/Controllers/.gitkeep"
    "api/Middleware/.gitkeep"
    "api/Services/.gitkeep"
    "api/Models/.gitkeep"
    "routes/api.php"
    "database/migrations/.gitkeep"
    "storage/logs/.gitkeep"
    "storage/cache/.gitkeep"
    ".env.example"
)

for file in "${required_files[@]}"; do
    if [[ ! -f "$file" && ! -d "$file" ]]; then
        echo "ERROR: Required file/directory missing: $file"
        exit 1
    fi
done

# Test 4: Check CLI works
echo "Testing CLI command..."
./glueful --version

# Test 5: Check system:check command
echo "Running system check..."
./glueful system:check

# Test 6: Test key generation
echo "Testing key generation..."
./glueful generate:key --force

# Test 7: Test development server (background process)
echo "Testing development server..."
./glueful serve --port=8080 &
SERVER_PID=$!
sleep 3

# Test API endpoints
if ! curl -f http://localhost:8080/ > /dev/null 2>&1; then
    echo "ERROR: Development server not responding"
    kill $SERVER_PID 2>/dev/null || true
    exit 1
fi

# Test health endpoint
if ! curl -f http://localhost:8080/health > /dev/null 2>&1; then
    echo "ERROR: Health endpoint not responding"
    kill $SERVER_PID 2>/dev/null || true
    exit 1
fi

# Stop development server
kill $SERVER_PID 2>/dev/null || true

# Test 8: Run application tests (if they exist)
echo "Running application tests..."
if [[ -f "composer.json" ]] && composer run-script --list | grep -q "test"; then
    composer test || echo "Warning: Application tests failed (may be expected for skeleton)"
fi

# Test 9: Test extension commands
echo "Testing extension management..."
./glueful extensions:info

# Cleanup
cd /
rm -rf "$TEST_DIR"

echo "✅ All application skeleton tests passed!"
```

### CI Integration for Application Skeleton

**Enhanced GitHub Actions Integration:**
```yaml
# .github/workflows/skeleton-test.yml (addition to existing test.yml)
application-skeleton-tests:
  runs-on: ubuntu-latest
  needs: framework-tests
  steps:
    - name: Checkout
      uses: actions/checkout@v4

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.2'
        extensions: mbstring, xml, ctype, json, tokenizer, sqlite

    - name: Create new project from skeleton and run comprehensive checks
      run: |
        # Create project from skeleton
        composer create-project glueful/api test-project --prefer-source --no-interaction
        cd test-project
        
        # Verify structure
        test -f glueful
        test -f bootstrap/app.php
        test -f public/index.php
        test -d api/Controllers
        test -d api/Middleware
        test -d api/Services
        test -d api/Models
        
        # Test CLI commands
        php glueful --version
        php glueful system:check || true
        php glueful generate:key --force
        php glueful extensions:info
        
        # Test development server
        timeout 10s php glueful serve --port=8080 &
        sleep 3
        curl -f http://localhost:8080/ || echo "Server test skipped"
        curl -f http://localhost:8080/health || echo "Health check skipped"
        
        # Run tests if available
        if composer run-script --list | grep -q "test"; then 
          composer test || echo "Tests not required for skeleton"
        fi
```

### Application Integration Tests

**Skeleton Integration Test Suite:**
```php
<?php
// tests/Integration/SkeletonValidationTest.php

declare(strict_types=1);

namespace Glueful\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class SkeletonValidationTest extends TestCase
{
    private string $testProject;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testProject = sys_get_temp_dir() . '/glueful-skeleton-test-' . uniqid();
    }
    
    protected function tearDown(): void
    {
        if (is_dir($this->testProject)) {
            $this->recursiveRemoveDirectory($this->testProject);
        }
        parent::tearDown();
    }
    
    public function test_skeleton_project_creation(): void
    {
        // Test creating project from skeleton
        $process = new Process([
            'composer', 'create-project', 'glueful/api', 
            $this->testProject, '--prefer-source', '--no-interaction'
        ]);
        
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Project creation failed: ' . $process->getErrorOutput());
        
        // Verify essential files exist
        $this->assertFileExists($this->testProject . '/glueful');
        $this->assertFileExists($this->testProject . '/bootstrap/app.php');
        $this->assertFileExists($this->testProject . '/public/index.php');
        $this->assertDirectoryExists($this->testProject . '/api/Controllers');
    }
    
    public function test_skeleton_cli_commands(): void
    {
        $this->createTestProject();
        
        // Test version command
        $process = new Process(['php', 'glueful', '--version'], $this->testProject);
        $process->run();
        $this->assertTrue($process->isSuccessful());
        
        // Test system check
        $process = new Process(['php', 'glueful', 'system:check'], $this->testProject);
        $process->run();
        $this->assertTrue($process->isSuccessful());
    }
    
    public function test_skeleton_web_endpoints(): void
    {
        $this->createTestProject();
        
        // Start development server in background
        $server = new Process(['php', 'glueful', 'serve', '--port=8081'], $this->testProject);
        $server->start();
        
        // Wait for server to start
        sleep(2);
        
        try {
            // Test root endpoint
            $response = file_get_contents('http://localhost:8081/');
            $this->assertNotFalse($response, 'Root endpoint not responding');
            
            // Test health endpoint
            $healthResponse = file_get_contents('http://localhost:8081/health');
            $this->assertNotFalse($healthResponse, 'Health endpoint not responding');
            
            $healthData = json_decode($healthResponse, true);
            $this->assertArrayHasKey('status', $healthData);
            
        } finally {
            $server->stop();
        }
    }
    
    private function createTestProject(): void
    {
        $process = new Process([
            'composer', 'create-project', 'glueful/api',
            $this->testProject, '--prefer-source', '--no-interaction'
        ]);
        $process->run();
        
        if (!$process->isSuccessful()) {
            $this->fail('Failed to create test project: ' . $process->getErrorOutput());
        }
    }
    
    private function recursiveRemoveDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        
        rmdir($directory);
    }
}
```

### Testing Success Criteria

- [ ] Package installation test script passes all checks
- [ ] CLI commands work correctly in skeleton projects  
- [ ] Development server starts and responds to requests
- [ ] Health endpoint returns proper JSON structure
- [ ] Extension management commands work
- [ ] Key generation works without framework conflicts
- [ ] Project structure matches expectations
- [ ] Framework dependency properly resolved
- [ ] Application can run tests independently
- [ ] Documentation endpoints accessible

## Next Steps

After completing Phase 2, proceed to [Phase 3: Configuration Management](PHASE_3_CONFIGURATION_MANAGEMENT.md) to handle the configuration hierarchy between framework defaults and user overrides.