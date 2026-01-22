# API Versioning Strategy Implementation Plan

> A comprehensive plan for implementing flexible API versioning with support for multiple strategies, deprecation handling, and version negotiation in Glueful Framework.

---

## ✅ Implementation Status: COMPLETE

**Implemented:** January 2026
**Version:** v1.16.0
**Tests:** 86 unit tests (all passing)

### Implemented Components

| Component | File | Status |
|-----------|------|--------|
| ApiVersion value object | `src/Api/Versioning/ApiVersion.php` | ✅ |
| VersionResolverInterface | `src/Api/Versioning/Contracts/VersionResolverInterface.php` | ✅ |
| VersionNegotiatorInterface | `src/Api/Versioning/Contracts/VersionNegotiatorInterface.php` | ✅ |
| DeprecatableInterface | `src/Api/Versioning/Contracts/DeprecatableInterface.php` | ✅ |
| UrlPrefixResolver | `src/Api/Versioning/Resolvers/UrlPrefixResolver.php` | ✅ |
| HeaderResolver | `src/Api/Versioning/Resolvers/HeaderResolver.php` | ✅ |
| QueryParameterResolver | `src/Api/Versioning/Resolvers/QueryParameterResolver.php` | ✅ |
| AcceptHeaderResolver | `src/Api/Versioning/Resolvers/AcceptHeaderResolver.php` | ✅ |
| Version attribute | `src/Api/Versioning/Attributes/Version.php` | ✅ |
| Deprecated attribute | `src/Api/Versioning/Attributes/Deprecated.php` | ✅ |
| Sunset attribute | `src/Api/Versioning/Attributes/Sunset.php` | ✅ |
| VersionManager | `src/Api/Versioning/VersionManager.php` | ✅ |
| VersionNegotiationMiddleware | `src/Api/Versioning/Middleware/VersionNegotiationMiddleware.php` | ✅ |
| ApiVersioningProvider | `src/Container/Providers/ApiVersioningProvider.php` | ✅ |
| VersionListCommand | `src/Console/Commands/Api/VersionListCommand.php` | ✅ |
| VersionDeprecateCommand | `src/Console/Commands/Api/VersionDeprecateCommand.php` | ✅ |
| Router extension | `src/Routing/Router.php` (apiVersion method) | ✅ |
| Route extension | `src/Routing/Route.php` (version method) | ✅ |
| Configuration | `config/api.php` (versioning section) | ✅ |

### Implementation Notes

1. **Attribute Naming**: The version attribute is named `Version` (not `ApiVersion`) to avoid naming conflict with the `ApiVersion` value object class.

2. **Resolver Priority**: Higher priority resolvers are checked first. Default priorities:
   - UrlPrefixResolver: 100
   - HeaderResolver: 90
   - AcceptHeaderResolver: 80
   - QueryParameterResolver: 70

3. **Middleware Alias**: Registered as `api_version` in the container.

4. **Console Commands**: Available as `api:version:list` and `api:version:deprecate`.

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [Versioning Strategies](#versioning-strategies)
6. [Deprecation System](#deprecation-system)
7. [Implementation Phases](#implementation-phases)
8. [Testing Strategy](#testing-strategy)
9. [API Reference](#api-reference)
10. [Migration Guide](#migration-guide)

---

## Executive Summary

This document outlines the implementation of a comprehensive API versioning system for Glueful Framework. The system will support multiple versioning strategies (URL prefix, header, query parameter, Accept header), automatic version negotiation, deprecation warnings, and sunset headers for graceful API evolution.

### Key Features

- **Multiple Versioning Strategies**: URL prefix (`/v1/users`), custom header (`X-API-Version`), query parameter (`?api_version=1`), Accept header (`application/vnd.api+json; version=1`)
- **Version Negotiation Middleware**: Automatic version detection and routing
- **Deprecation Support**: Sunset headers, deprecation warnings, and replacement suggestions
- **Version-Specific Rate Limits**: Different rate limits per API version
- **OpenAPI Integration**: Automatic version documentation generation

---

## Goals and Non-Goals

### Goals

- ✅ Support multiple versioning strategies with easy switching
- ✅ Automatic version detection and request routing
- ✅ Deprecation warnings with RFC 8594 Sunset header
- ✅ Version-specific middleware and rate limits
- ✅ Attribute-based version configuration
- ✅ Version groups for route organization
- ✅ Backward compatibility with existing routes
- ✅ OpenAPI/Swagger version documentation

### Non-Goals

- ❌ Automatic code migration between versions
- ❌ Database schema versioning (handled separately)
- ❌ Version-specific authentication (use middleware)
- ❌ GraphQL versioning (GraphQL handles this differently)

---

## Current State Analysis

### Existing Versioning Support

Currently, Glueful supports basic URL prefix versioning through route groups:

```php
// Current approach - manual route groups
$router->group(['prefix' => '/v1'], function ($router) {
    $router->get('/users', [UserControllerV1::class, 'index']);
});

$router->group(['prefix' => '/v2'], function ($router) {
    $router->get('/users', [UserControllerV2::class, 'index']);
});
```

### Limitations

| Limitation | Impact |
|------------|--------|
| No automatic version detection | Requires explicit URL paths |
| No header-based versioning | Mobile/SPA apps prefer headers |
| No deprecation support | Clients unaware of sunset dates |
| No version negotiation | Can't fallback to compatible versions |
| No version-specific limits | All versions share rate limits |

---

## Architecture Design

### Component Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        Request                                   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              VersionNegotiationMiddleware                        │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                  VersionManager                          │   │
│  │  ┌──────────────┐ ┌──────────────┐ ┌──────────────────┐ │   │
│  │  │ URL Resolver │ │Header Resolver│ │ Accept Resolver │ │   │
│  │  └──────────────┘ └──────────────┘ └──────────────────┘ │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                   Version-Specific Router                        │
│  ┌────────────┐    ┌────────────┐    ┌────────────────────┐    │
│  │ V1 Routes  │    │ V2 Routes  │    │ V3 Routes          │    │
│  │ (Active)   │    │ (Active)   │    │ (Deprecated)       │    │
│  └────────────┘    └────────────┘    └────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                        Response                                  │
│  Headers: X-API-Version, Sunset, Deprecation, Link             │
└─────────────────────────────────────────────────────────────────┘
```

### Directory Structure (Implemented)

```
src/Api/Versioning/
├── Contracts/
│   ├── VersionResolverInterface.php      # Strategy contract ✅
│   ├── VersionNegotiatorInterface.php    # Negotiation contract ✅
│   └── DeprecatableInterface.php         # Deprecation contract ✅
├── Resolvers/
│   ├── UrlPrefixResolver.php             # /v1/users ✅
│   ├── HeaderResolver.php                # X-Api-Version: 1 ✅
│   ├── QueryParameterResolver.php        # ?api-version=1 ✅
│   └── AcceptHeaderResolver.php          # Accept: application/vnd.glueful.v1+json ✅
├── Attributes/
│   ├── Version.php                       # Route version attribute ✅ (named Version to avoid conflict)
│   ├── Deprecated.php                    # Mark as deprecated ✅
│   └── Sunset.php                        # Set sunset date ✅
├── Middleware/
│   └── VersionNegotiationMiddleware.php  # Request processing ✅
├── VersionManager.php                    # Central management ✅
└── ApiVersion.php                        # Version value object ✅

src/Container/Providers/
└── ApiVersioningProvider.php             # Service provider ✅

src/Console/Commands/Api/
├── VersionListCommand.php                # api:version:list ✅
└── VersionDeprecateCommand.php           # api:version:deprecate ✅

src/Routing/
├── Router.php                            # Added apiVersion() method ✅
└── Route.php                             # Added version() fluent method ✅

config/
└── api.php                               # Added versioning configuration ✅
```

---

## Versioning Strategies

### 1. URL Prefix Versioning (Default)

The most explicit and cache-friendly approach.

```php
// Route definition
$router->apiVersion('v1', function (Router $router) {
    $router->get('/users', [UserControllerV1::class, 'index']);
    $router->get('/users/{id}', [UserControllerV1::class, 'show']);
});

$router->apiVersion('v2', function (Router $router) {
    $router->get('/users', [UserControllerV2::class, 'index']);
    $router->get('/users/{id}', [UserControllerV2::class, 'show']);
});

// Request
GET /v1/users HTTP/1.1
Host: api.example.com

// Response
HTTP/1.1 200 OK
X-API-Version: v1
```

### 2. Custom Header Versioning

Preferred by mobile apps and SPAs for cleaner URLs.

```php
// Configuration
'versioning' => [
    'strategy' => 'header',
    'header' => 'X-API-Version',
    'default' => 'v1',
]

// Request
GET /users HTTP/1.1
Host: api.example.com
X-API-Version: v2

// Response
HTTP/1.1 200 OK
X-API-Version: v2
```

### 3. Query Parameter Versioning

Simple approach, useful for testing.

```php
// Configuration
'versioning' => [
    'strategy' => 'query',
    'query_param' => 'api_version',
    'default' => 'v1',
]

// Request
GET /users?api_version=v2 HTTP/1.1
Host: api.example.com

// Response
HTTP/1.1 200 OK
X-API-Version: v2
```

### 4. Accept Header Versioning (Content Negotiation)

RESTful purist approach using media types.

```php
// Configuration
'versioning' => [
    'strategy' => 'accept',
    'vendor' => 'glueful',
    'default' => 'v1',
]

// Request
GET /users HTTP/1.1
Host: api.example.com
Accept: application/vnd.glueful.v2+json

// Response
HTTP/1.1 200 OK
Content-Type: application/vnd.glueful.v2+json
X-API-Version: v2
```

### 5. Composite Strategy

Combine multiple strategies with priority.

```php
// Configuration
'versioning' => [
    'strategy' => 'composite',
    'strategies' => ['url', 'header', 'query', 'accept'],
    'default' => 'v1',
]
```

---

## Deprecation System

### Deprecation Attributes

```php
use Glueful\Api\Versioning\Attributes\Deprecated;
use Glueful\Api\Versioning\Attributes\Sunset;

class UserControllerV1
{
    #[Deprecated(
        version: 'v1',
        since: '2026-01-01',
        replacement: '/v2/users',
        message: 'Use v2 API for improved performance'
    )]
    #[Sunset(date: '2026-12-31')]
    public function index(): Response
    {
        // Still functional but deprecated
        return UserResource::collection(User::all());
    }
}
```

### Deprecation Headers (RFC 8594)

```http
HTTP/1.1 200 OK
Deprecation: @1735689600
Sunset: Tue, 31 Dec 2026 23:59:59 GMT
Link: </v2/users>; rel="successor-version"
X-Deprecation-Notice: This endpoint is deprecated. Use /v2/users instead.
```

### Deprecation Response Structure

When a deprecated endpoint is called:

```json
{
    "data": [...],
    "meta": {
        "api_version": "v1",
        "deprecation": {
            "deprecated": true,
            "since": "2026-01-01",
            "sunset": "2026-12-31",
            "replacement": "/v2/users",
            "message": "Use v2 API for improved performance"
        }
    }
}
```

---

## API Specification

### VersionManager

```php
<?php

namespace Glueful\Api\Versioning;

use Glueful\Api\Versioning\Contracts\VersionResolverInterface;

/**
 * Central API version management
 */
class VersionManager
{
    /** @var array<string, VersionResolverInterface> */
    private array $resolvers = [];

    /** @var array<string, array> */
    private array $versions = [];

    private string $defaultVersion;
    private string $currentVersion;

    /**
     * Register a version with its configuration
     *
     * @param string $version Version identifier (e.g., 'v1', 'v2')
     * @param array $config Version configuration
     */
    public function register(string $version, array $config = []): self
    {
        $this->versions[$version] = array_merge([
            'deprecated' => false,
            'sunset' => null,
            'replacement' => null,
            'rate_limit' => null,
        ], $config);

        return $this;
    }

    /**
     * Resolve version from request
     *
     * @param Request $request
     * @return string Resolved version
     */
    public function resolve(Request $request): string
    {
        foreach ($this->resolvers as $resolver) {
            $version = $resolver->resolve($request);
            if ($version !== null && $this->isValid($version)) {
                return $version;
            }
        }

        return $this->defaultVersion;
    }

    /**
     * Check if version is deprecated
     */
    public function isDeprecated(string $version): bool
    {
        return $this->versions[$version]['deprecated'] ?? false;
    }

    /**
     * Get sunset date for version
     */
    public function getSunset(string $version): ?\DateTimeInterface
    {
        $sunset = $this->versions[$version]['sunset'] ?? null;
        return $sunset ? new \DateTimeImmutable($sunset) : null;
    }

    /**
     * Get replacement version/endpoint
     */
    public function getReplacement(string $version): ?string
    {
        return $this->versions[$version]['replacement'] ?? null;
    }

    /**
     * Get all registered versions
     *
     * @return array<string, array>
     */
    public function all(): array
    {
        return $this->versions;
    }

    /**
     * Get active (non-deprecated) versions
     *
     * @return array<string, array>
     */
    public function active(): array
    {
        return array_filter(
            $this->versions,
            fn($config) => !($config['deprecated'] ?? false)
        );
    }
}
```

### Version Resolution Interface

```php
<?php

namespace Glueful\Api\Versioning\Contracts;

use Symfony\Component\HttpFoundation\Request;

interface VersionResolverInterface
{
    /**
     * Resolve API version from request
     *
     * @param Request $request
     * @return string|null Version string or null if not found
     */
    public function resolve(Request $request): ?string;

    /**
     * Get resolver priority (higher = checked first)
     */
    public function priority(): int;

    /**
     * Get resolver name for debugging
     */
    public function name(): string;
}
```

### URL Prefix Resolver

```php
<?php

namespace Glueful\Api\Versioning\Resolvers;

use Glueful\Api\Versioning\Contracts\VersionResolverInterface;
use Symfony\Component\HttpFoundation\Request;

class UrlPrefixResolver implements VersionResolverInterface
{
    private string $pattern;

    public function __construct(string $pattern = '/^\/?(v\d+)\//')
    {
        $this->pattern = $pattern;
    }

    public function resolve(Request $request): ?string
    {
        $path = $request->getPathInfo();

        if (preg_match($this->pattern, $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function priority(): int
    {
        return 100;
    }

    public function name(): string
    {
        return 'url_prefix';
    }
}
```

### Header Resolver

```php
<?php

namespace Glueful\Api\Versioning\Resolvers;

use Glueful\Api\Versioning\Contracts\VersionResolverInterface;
use Symfony\Component\HttpFoundation\Request;

class HeaderResolver implements VersionResolverInterface
{
    private string $header;

    public function __construct(string $header = 'X-API-Version')
    {
        $this->header = $header;
    }

    public function resolve(Request $request): ?string
    {
        $version = $request->headers->get($this->header);

        if ($version === null) {
            return null;
        }

        // Normalize: "1" -> "v1", "v2" -> "v2"
        return $this->normalize($version);
    }

    private function normalize(string $version): string
    {
        if (preg_match('/^v?\d+/', $version, $matches)) {
            return str_starts_with($matches[0], 'v')
                ? $matches[0]
                : 'v' . $matches[0];
        }

        return $version;
    }

    public function priority(): int
    {
        return 90;
    }

    public function name(): string
    {
        return 'header';
    }
}
```

### Version Negotiation Middleware

```php
<?php

namespace Glueful\Api\Versioning\Middleware;

use Glueful\Api\Versioning\VersionManager;
use Glueful\Routing\Middleware\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class VersionNegotiationMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly VersionManager $versionManager
    ) {}

    public function handle(Request $request, callable $next, ...$params): Response
    {
        // Resolve version from request
        $version = $this->versionManager->resolve($request);

        // Store in request attributes for later use
        $request->attributes->set('api_version', $version);

        // Process request
        $response = $next($request);

        // Add version headers to response
        $response->headers->set('X-API-Version', $version);

        // Add deprecation headers if applicable
        if ($this->versionManager->isDeprecated($version)) {
            $this->addDeprecationHeaders($response, $version);
        }

        return $response;
    }

    private function addDeprecationHeaders(Response $response, string $version): void
    {
        // RFC 8594 Deprecation header
        $response->headers->set('Deprecation', 'true');

        // Sunset header with date
        $sunset = $this->versionManager->getSunset($version);
        if ($sunset !== null) {
            $response->headers->set(
                'Sunset',
                $sunset->format(\DateTimeInterface::RFC7231)
            );
        }

        // Link to replacement
        $replacement = $this->versionManager->getReplacement($version);
        if ($replacement !== null) {
            $response->headers->set(
                'Link',
                "<{$replacement}>; rel=\"successor-version\""
            );
        }
    }
}
```

### Router Extension

```php
<?php

// In Router class - add version group support

/**
 * Create a version-specific route group
 *
 * @param string $version Version identifier
 * @param callable $routes Route definitions
 * @param array $options Additional group options
 */
public function apiVersion(string $version, callable $routes, array $options = []): void
{
    $prefix = config('api.versioning.strategy') === 'url'
        ? "/{$version}"
        : '';

    $this->group(array_merge([
        'prefix' => $prefix,
        'middleware' => ['api.version'],
        'version' => $version,
    ], $options), $routes);
}

// Usage
$router->apiVersion('v1', function (Router $router) {
    $router->get('/users', [UserControllerV1::class, 'index']);
});

$router->apiVersion('v2', function (Router $router) {
    $router->get('/users', [UserControllerV2::class, 'index']);
}, ['deprecated' => true, 'sunset' => '2026-12-31']);
```

### Attribute-Based Versioning

```php
<?php

namespace Glueful\Api\Versioning\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ApiVersion
{
    /**
     * @param string|array $versions Supported versions
     * @param string|null $deprecated Deprecation version
     * @param string|null $sunset Sunset date (ISO 8601)
     */
    public function __construct(
        public readonly string|array $versions,
        public readonly ?string $deprecated = null,
        public readonly ?string $sunset = null,
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Deprecated
{
    public function __construct(
        public readonly string $version,
        public readonly ?string $since = null,
        public readonly ?string $replacement = null,
        public readonly ?string $message = null,
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Sunset
{
    public function __construct(
        public readonly string $date, // ISO 8601 date
    ) {}
}
```

### Controller Usage Examples

```php
<?php

namespace App\Http\Controllers;

use Glueful\Api\Versioning\Attributes\ApiVersion;
use Glueful\Api\Versioning\Attributes\Deprecated;
use Glueful\Api\Versioning\Attributes\Sunset;
use Glueful\Http\Controllers\Controller;

#[ApiVersion(['v1', 'v2'])]
class UserController extends Controller
{
    /**
     * Get all users - available in v1 and v2
     */
    public function index(): Response
    {
        $version = $this->request->attributes->get('api_version');

        return match($version) {
            'v1' => $this->indexV1(),
            'v2' => $this->indexV2(),
            default => $this->indexV2(),
        };
    }

    #[Deprecated(version: 'v1', replacement: '/v2/users/{id}')]
    #[Sunset(date: '2026-12-31')]
    public function showV1(string $id): Response
    {
        // Legacy implementation
        $user = User::findOrFail($id);
        return new Response(['user' => $user->toArray()]);
    }

    #[ApiVersion('v2')]
    public function showV2(string $id): Response
    {
        // New implementation with resource transformer
        $user = User::with(['profile', 'roles'])->findOrFail($id);
        return UserResource::make($user)->toResponse();
    }
}
```

---

## Console Commands

### version:list

```bash
php glueful api:version:list

┌─────────┬────────────┬────────────────┬─────────────────┐
│ Version │ Status     │ Sunset         │ Replacement     │
├─────────┼────────────┼────────────────┼─────────────────┤
│ v1      │ Deprecated │ Dec 31, 2026   │ /v2             │
│ v2      │ Active     │ -              │ -               │
│ v3      │ Active     │ -              │ -               │
└─────────┴────────────┴────────────────┴─────────────────┘
```

### version:deprecate

```bash
php glueful api:version:deprecate v1 --sunset=2026-12-31 --replacement=/v2

API version v1 has been marked as deprecated.
Sunset date: December 31, 2026
Replacement: /v2
```

---

## Implementation Phases

### Phase 1: Core Infrastructure (Week 1) ✅

**Deliverables:**
- [x] `VersionResolverInterface` contract
- [x] `UrlPrefixResolver` implementation
- [x] `HeaderResolver` implementation
- [x] `VersionManager` class
- [x] Basic configuration in `config/api.php`

**Acceptance Criteria:**
```php
$manager = new VersionManager();
$manager->register('v1')->register('v2');

$resolver = new HeaderResolver('X-API-Version');
$version = $resolver->resolve($request); // 'v1'
```

### Phase 2: Middleware & Routing (Week 1-2) ✅

**Deliverables:**
- [x] `VersionNegotiationMiddleware`
- [x] Router `apiVersion()` method
- [x] Version attributes (`#[Version]` - named to avoid conflict with ApiVersion value object)
- [x] Request attribute injection

**Acceptance Criteria:**
```php
$router->apiVersion('v1', function ($router) {
    $router->get('/users', [UserController::class, 'index']);
});

// Request to /v1/users returns version in headers
```

### Phase 3: Deprecation System (Week 2) ✅

**Deliverables:**
- [x] `#[Deprecated]` attribute
- [x] `#[Sunset]` attribute
- [x] RFC 8594 headers
- [x] Deprecation response metadata

**Acceptance Criteria:**
```php
#[Deprecated(version: 'v1', sunset: '2026-12-31')]
public function index(): Response { }

// Response includes:
// Deprecation: true
// Sunset: Tue, 31 Dec 2026 23:59:59 GMT
```

### Phase 4: Advanced Features (Week 3) ✅

**Deliverables:**
- [x] Query parameter resolver
- [x] Accept header resolver
- [x] Composite strategy (via resolver priority system)
- [x] Version-specific rate limits (configurable via version config)
- [x] Console commands (`api:version:list`, `api:version:deprecate`)

**Acceptance Criteria:**
```bash
php glueful api:version:list
php glueful api:version:deprecate v1 --sunset=2026-12-31
```

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Api\Versioning;

use PHPUnit\Framework\TestCase;
use Glueful\Api\Versioning\Resolvers\HeaderResolver;
use Symfony\Component\HttpFoundation\Request;

class HeaderResolverTest extends TestCase
{
    public function testResolvesVersionFromHeader(): void
    {
        $resolver = new HeaderResolver('X-API-Version');
        $request = Request::create('/users');
        $request->headers->set('X-API-Version', 'v2');

        $this->assertEquals('v2', $resolver->resolve($request));
    }

    public function testNormalizesNumericVersion(): void
    {
        $resolver = new HeaderResolver('X-API-Version');
        $request = Request::create('/users');
        $request->headers->set('X-API-Version', '2');

        $this->assertEquals('v2', $resolver->resolve($request));
    }

    public function testReturnsNullWhenHeaderMissing(): void
    {
        $resolver = new HeaderResolver('X-API-Version');
        $request = Request::create('/users');

        $this->assertNull($resolver->resolve($request));
    }
}
```

### Integration Tests

```php
<?php

namespace Glueful\Tests\Integration\Api\Versioning;

use Glueful\Testing\TestCase;

class VersioningIntegrationTest extends TestCase
{
    public function testUrlPrefixVersioning(): void
    {
        $response = $this->get('/v1/users');

        $response->assertStatus(200);
        $response->assertHeader('X-API-Version', 'v1');
    }

    public function testHeaderVersioning(): void
    {
        $response = $this->withHeader('X-API-Version', 'v2')
            ->get('/users');

        $response->assertStatus(200);
        $response->assertHeader('X-API-Version', 'v2');
    }

    public function testDeprecatedVersionIncludesSunsetHeader(): void
    {
        $response = $this->get('/v1/users');

        $response->assertHeader('Deprecation', 'true');
        $response->assertHeaderContains('Sunset', 'Dec 2026');
    }
}
```

---

## API Reference

### Configuration

```php
// config/api.php
return [
    'versioning' => [
        'enabled' => true,
        'default' => 'v1',
        'strategy' => 'url', // url, header, query, accept, composite

        // Header strategy options
        'header' => 'X-API-Version',

        // Query strategy options
        'query_param' => 'api_version',

        // Accept header strategy options
        'vendor' => 'glueful',

        // Composite strategy priority
        'strategies' => ['url', 'header', 'query'],

        // Deprecation settings
        'deprecation' => [
            'sunset_header' => true,
            'warning_header' => true,
            'link_header' => true,
        ],

        // Registered versions
        'versions' => [
            'v1' => [
                'deprecated' => true,
                'sunset' => '2026-12-31',
                'replacement' => '/v2',
            ],
            'v2' => [
                'deprecated' => false,
            ],
        ],
    ],
];
```

### Response Headers

| Header | Description | Example |
|--------|-------------|---------|
| `X-API-Version` | Current API version | `v2` |
| `Deprecation` | Indicates deprecation status | `true` or Unix timestamp |
| `Sunset` | When API will be removed (RFC 7231) | `Tue, 31 Dec 2026 23:59:59 GMT` |
| `Link` | Successor version relation | `</v2/users>; rel="successor-version"` |

---

## Migration Guide

### From Manual Versioning

**Before:**
```php
$router->group(['prefix' => '/v1'], function ($router) {
    $router->get('/users', [UserControllerV1::class, 'index']);
});
```

**After:**
```php
$router->apiVersion('v1', function ($router) {
    $router->get('/users', [UserControllerV1::class, 'index']);
});
```

### Accessing Version in Controller

```php
public function index(Request $request): Response
{
    $version = $request->attributes->get('api_version');
    // or
    $version = api_version(); // Helper function
}
```

---

## Security Considerations

1. **Version Enumeration**: Don't expose all available versions in error messages
2. **Version Bypass**: Ensure deprecated versions still require authentication
3. **Rate Limiting**: Apply appropriate limits per version
4. **Input Validation**: Validate version format before processing

---

## Performance Considerations

1. **Lazy Loading**: Only load version-specific code when needed
2. **Caching**: Cache version resolution for same request patterns
3. **Minimal Overhead**: Version resolution adds ~1ms to request
4. **Route Caching**: Versioned routes fully compatible with route cache
