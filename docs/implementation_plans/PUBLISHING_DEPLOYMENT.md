# Publishing and Deployment

## Overview

This phase handles the publishing of packages to Packagist, setting up distribution workflows, and establishing maintenance procedures for the new package architecture.

## Step 1: Framework Package Publishing

Prepare and publish the framework package to Packagist:

**Verify and Tag Release:**
```bash
# In the framework repository
composer install

# Run full test suite and quality gates
composer test
composer run phpcs || true
composer run phpstan || true

# Tag a release
git tag -a v1.0.0 -m "Glueful Framework 1.0.0 — split from monolith"
git push origin main --tags
```

**Packagist Submission:**
1. Visit https://packagist.org/packages/submit
2. Submit repository: `https://github.com/glueful/framework`
3. Enable auto-updates via GitHub webhook
4. Verify package appears at https://packagist.org/packages/glueful/framework

**Package Validation (sanity check):**
```bash
# In a throwaway directory
mkdir -p /tmp/glueful-fw-test && cd /tmp/glueful-fw-test
composer init --no-interaction --name tmp/app --require glueful/framework:^1.0
composer install
php -r "require 'vendor/autoload.php'; echo class_exists('Glueful\\Framework') ? 'ok'.PHP_EOL : 'missing'.PHP_EOL;"
```

## Step 2: Application Skeleton Publishing

Publish the application skeleton from a dedicated skeleton repository:

**Skeleton Setup (in skeleton repo):**
```bash
# Create application structure
mkdir -p api/Controllers api/Middleware api/Services api/Models
touch api/Controllers/.gitkeep api/Middleware/.gitkeep api/Services/.gitkeep api/Models/.gitkeep

# Write composer.json for skeleton
cat > composer.json << 'EOF'
{
    "name": "glueful/api-skeleton",
    "description": "Glueful API Application Skeleton - Create high-performance APIs",
    "type": "project",
    "keywords": ["api", "skeleton", "glueful", "rest", "php", "framework"],
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
EOF

# Create application bootstrap
mkdir -p bootstrap
cat > bootstrap/app.php << 'EOF'
<?php

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

return $app;
EOF

# Update public/index.php
cat > public/index.php << 'EOF'
<?php

declare(strict_types=1);

use Glueful\Http\ServerRequestFactory;

// Bootstrap the framework application
$app = require_once __DIR__ . '/../bootstrap/app.php';

// Create PSR-7 request object
$request = ServerRequestFactory::fromGlobals();

// Handle request through application
$response = $app->handleRequest($request);

// Send the response
$response->send();

// Perform any cleanup
$app->terminate($request, $response);
EOF

# Create example controller
cat > api/Controllers/WelcomeController.php << 'EOF'
<?php

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
EOF

# Create example routes
cat > routes/api.php << 'EOF'
<?php

use App\Controllers\WelcomeController;
use Glueful\Routing\Router;

$router = container()->get(Router::class);

// Welcome routes
$router->get('/', [WelcomeController::class, 'index']);
$router->get('/health', [WelcomeController::class, 'health']);

// Protected route examples
$router->group(['middleware' => ['auth']], function (Router $router) {
    $router->get('/user', function () {
        return response()->json([
            'user' => auth()->user()
        ]);
    });
});
EOF

# Update README for skeleton
cat > README.md << 'EOF'
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

## Creating Your First API Endpoint

1. Create a controller:
```php
<?php
namespace App\Controllers;
use Glueful\Http\Controller;

class UserController extends Controller
{
    public function index()
    {
        return $this->json(['users' => []]);
    }
}
```

2. Add a route:
```php
<?php
// routes/api.php
Router::get('/users', [UserController::class, 'index']);
```

3. Test your endpoint:
```bash
curl http://localhost:8000/users
```
EOF

# Install framework dependency and test
composer install
php glueful system:check

# Commit skeleton transformation
git add .
git commit -m "Transform repository to application skeleton

- Remove framework source code (now distributed as package)
- Add glueful/framework dependency
- Create modern bootstrap architecture
- Add example controller and routes
- Update documentation for application development

🤖 Generated with Claude Code"

git push origin package-architecture-implementation
```

## Step 6.3: Release Process Automation

Create automated release workflows:

**GitHub Actions for Framework Releases:**
```yaml
# glueful-framework/.github/workflows/release.yml
name: Release

on:
  push:
    tags:
      - 'v*'

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php-version: [8.2, 8.3]
        
    steps:
    - uses: actions/checkout@v3
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ matrix.php-version }}
        extensions: mbstring, xml, ctype, json, tokenizer, sqlite
        
    - name: Install dependencies
      run: composer install --prefer-dist --no-dev
      
    - name: Run tests
      run: composer test
      
    - name: Code style check
      run: composer run phpcs
      
    - name: Static analysis
      run: composer run phpstan
      
  release:
    needs: test
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Get version
      id: version
      run: echo "version=${GITHUB_REF#refs/tags/}" >> $GITHUB_OUTPUT
      
    - name: Create Release
      uses: actions/create-release@v1
      env:
        GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
      with:
        tag_name: ${{ steps.version.outputs.version }}
        release_name: Glueful Framework ${{ steps.version.outputs.version }}
        body: |
          ## What's Changed
          
          See the [CHANGELOG.md](CHANGELOG.md) for detailed changes.
          
          ## Installation
          
          ```bash
          composer require glueful/framework:${{ steps.version.outputs.version }}
          ```
          
        draft: false
        prerelease: false
        
  notify:
    needs: release
    runs-on: ubuntu-latest
    
    steps:
    - name: Notify Discord
      if: always()
      uses: sarisia/actions-status-discord@v1
      with:
        webhook: ${{ secrets.DISCORD_WEBHOOK }}
        title: "Glueful Framework Release"
        description: "Version ${{ steps.version.outputs.version }} released"
```

**Semantic Release Configuration:**
```json
// glueful-framework/.releaserc.json
{
  "branches": ["main"],
  "plugins": [
    "@semantic-release/commit-analyzer",
    "@semantic-release/release-notes-generator",
    "@semantic-release/changelog",
    [
      "@semantic-release/git",
      {
        "assets": ["CHANGELOG.md"],
        "message": "chore(release): ${nextRelease.version} [skip ci]\n\n${nextRelease.notes}"
      }
    ],
    "@semantic-release/github"
  ]
}
```

## Step 6.4: Documentation and Distribution

Set up comprehensive documentation and distribution:

**Documentation Site Structure:**
```
docs/
├── getting-started/
│   ├── installation.md
│   ├── configuration.md
│   └── first-api.md
├── framework/
│   ├── architecture.md
│   ├── dependency-injection.md
│   ├── routing.md
│   ├── middleware.md
│   ├── database.md
│   ├── caching.md
│   └── extensions.md
├── application/
│   ├── controllers.md
│   ├── models.md
│   ├── services.md
│   └── testing.md
└── deployment/
    ├── production.md
    ├── docker.md
    └── scaling.md
```

**Package Distribution Channels:**

1. **Packagist (Primary):**
   - https://packagist.org/packages/glueful/framework
   - https://packagist.org/packages/glueful/api-skeleton

2. **GitHub Packages (Mirror):**
```yaml
# .github/workflows/publish-packages.yml
name: Publish Packages

on:
  release:
    types: [published]

jobs:
  publish-github-packages:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: 8.2
        
    - name: Configure Composer for GitHub Packages
      run: |
        composer config repositories.github composer https://composer.pkg.github.com/glueful/
        composer config github-oauth.github.com ${{ secrets.GITHUB_TOKEN }}
        
    - name: Publish to GitHub Packages
      run: composer config --global --auth github-oauth.github.com ${{ secrets.GITHUB_TOKEN }}
```

3. **Docker Registry:**
```dockerfile
# Dockerfile for development environment
FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    && docker-php-ext-install pdo pdo_mysql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

EXPOSE 8000

CMD ["php", "glueful", "serve", "--host=0.0.0.0"]
```

## Step 3: Maintenance and Update Workflows

Establish maintenance procedures:

**Framework Updates (script example):**
```bash
#!/bin/bash
# scripts/update-framework.sh

set -e

echo "Updating Glueful Framework..."

## 1. Update dependencies
composer update glueful/framework

## 2. Review CHANGELOG and docs for breaking changes
echo "Please review CHANGELOG.md and ROADMAP.md before proceeding."

# 3. Run tests
composer test

## 4. Apply any needed app updates manually (config keys, routes, middleware)

echo "Framework updated successfully!"
```

**Version Compatibility Matrix (illustrative):**
```php
// src/Compatibility/VersionMatrix.php
<?php

return [
    'framework_versions' => [
        '1.0.0' => [
            'php' => '^8.2',
            'skeleton_versions' => ['^1.0'],
            'breaking_changes' => [],
            'deprecated_features' => [],
        ],
        '1.1.0' => [
            'php' => '^8.2',
            'skeleton_versions' => ['^1.0', '^1.1'],
            'breaking_changes' => [],
            'deprecated_features' => ['old_config_format'],
        ],
        '2.0.0' => [
            'php' => '^8.3',
            'skeleton_versions' => ['^2.0'],
            'breaking_changes' => ['config_format_change', 'routing_changes'],
            'deprecated_features' => [],
        ],
    ],
];
```

**Security Update Process (workflow example):**
```yaml
# .github/workflows/security-updates.yml
name: Security Updates

on:
  schedule:
    - cron: '0 2 * * 1' # Weekly Monday 2 AM
  workflow_dispatch:

jobs:
  security-audit:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v4
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.2'
        
    - name: Install dependencies
      run: composer install --prefer-dist --no-progress
      
    - name: Security audit
      run: composer audit || true
      
    - name: Update security dependencies
      run: |
        composer update --dry-run | grep -i security
        composer update --prefer-stable
        
    - name: Create PR for security updates
      if: success()
      uses: peter-evans/create-pull-request@v5
      with:
        title: "Security Updates"
        body: "Automated security dependency updates"
        branch: security-updates
        commit-message: "Security updates"
```

## Step 4: Monitoring and Analytics

Set up monitoring for package usage:

**Download Analytics:**
```php
// scripts/analytics/package-stats.php
<?php

$packages = [
    'glueful/framework',
    'glueful/api-skeleton'
];

foreach ($packages as $package) {
    $url = "https://packagist.org/packages/{$package}.json";
    $data = json_decode(file_get_contents($url), true);
    
    $downloads = $data['package']['downloads'];
    
    echo "Package: {$package}\n";
    echo "  Daily: " . number_format($downloads['daily']) . "\n";
    echo "  Monthly: " . number_format($downloads['monthly']) . "\n";
    echo "  Total: " . number_format($downloads['total']) . "\n";
    echo "\n";
}
```

**Health Monitoring:**
```php
// src/Monitoring/PackageHealthMonitor.php
<?php

declare(strict_types=1);

namespace Glueful\Monitoring;

class PackageHealthMonitor
{
    public function checkFrameworkHealth(): array
    {
        return [
            'packagist_status' => $this->checkPackagistStatus(),
            'github_status' => $this->checkGitHubStatus(),
            'ci_status' => $this->checkCiStatus(),
            'security_advisories' => $this->checkSecurityAdvisories(),
        ];
    }
    
    private function checkPackagistStatus(): array
    {
        $url = 'https://packagist.org/packages/glueful/framework.json';
        
        try {
            $data = json_decode(file_get_contents($url), true);
            return [
                'status' => 'healthy',
                'last_update' => $data['package']['time'],
                'latest_version' => $data['package']['version'],
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    private function checkGitHubStatus(): array
    {
        // Implementation for GitHub API checks
        return ['status' => 'healthy'];
    }
    
    private function checkCiStatus(): array
    {
        // Implementation for CI status checks
        return ['status' => 'healthy'];
    }
    
    private function checkSecurityAdvisories(): array
    {
        // Implementation for security advisory checks
        return ['advisories' => []];
    }
}
```

## Success Criteria

- [ ] Framework package successfully published to Packagist
- [ ] Application skeleton package available for `composer create-project`
- [ ] Automated release workflows operational
- [ ] Documentation complete and accessible
- [ ] Package installation tested across multiple environments
- [ ] Security update process established
- [ ] Monitoring and analytics systems operational
- [ ] Version compatibility matrix maintained
- [ ] Community can successfully create new projects
- [ ] Migration path from monolithic structure documented and tested

## Post-Launch Activities

1. **Community Engagement:**
   - Announce launch on relevant PHP communities
   - Create tutorials and screencasts
   - Respond to community feedback and issues

2. **Continuous Improvement:**
   - Monitor package usage analytics
   - Collect feedback from early adopters
   - Plan feature roadmap based on community needs

3. **Documentation Maintenance:**
   - Keep documentation up-to-date with releases
   - Add community-contributed examples
   - Maintain FAQ and troubleshooting guides

4. **Ecosystem Development:**
   - Encourage community extensions
   - Create extension development guidelines
   - Maintain extension registry

## Completion

Upon successful completion of all phases, Glueful will have transformed from a monolithic framework into a modern, package-based architecture that provides:

- Clean separation between framework core and user applications
- Professional distribution through Composer/Packagist
- Extensible architecture supporting community contributions
- Comprehensive testing and quality assurance
- Automated maintenance and security update processes

The new architecture positions Glueful for sustainable growth and community adoption while maintaining the high-performance characteristics and developer experience that make it unique.
