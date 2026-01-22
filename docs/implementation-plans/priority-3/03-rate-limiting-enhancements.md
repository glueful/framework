# Rate Limiting Enhancements Implementation Plan

> A comprehensive plan for implementing advanced rate limiting with per-route limits, tiered access, cost-based limiting, and standard rate limit headers in Glueful Framework.

---

## Implementation Status: ✅ COMPLETE

**Implemented in:** v1.17.0
**Branch:** `feature/rate-limiting-enhancements`
**Date Completed:** January 2026

### Summary

The Rate Limiting Enhancements feature has been fully implemented with:

- **Per-route rate limits** via `#[RateLimit]` PHP 8 attributes (IS_REPEATABLE for multi-window)
- **Tiered user access** with configurable tiers (anonymous, free, pro, enterprise)
- **Cost-based limiting** via `#[RateLimitCost]` attribute for expensive operations
- **Three algorithms**: Fixed Window, Sliding Window, Token Bucket
- **IETF-compliant headers**: Both legacy `X-RateLimit-*` and draft `RateLimit-*` headers
- **Backward compatible**: Existing `RateLimiterMiddleware` continues to work unchanged

### Files Created

| Directory | Files |
|-----------|-------|
| `src/Api/RateLimiting/Contracts/` | `RateLimiterInterface.php`, `TierResolverInterface.php`, `StorageInterface.php` |
| `src/Api/RateLimiting/` | `RateLimitResult.php`, `RateLimitManager.php`, `RateLimitHeaders.php`, `TierManager.php`, `TierResolver.php` |
| `src/Api/RateLimiting/Limiters/` | `FixedWindowLimiter.php`, `SlidingWindowLimiter.php`, `TokenBucketLimiter.php` |
| `src/Api/RateLimiting/Storage/` | `CacheStorage.php`, `MemoryStorage.php` |
| `src/Api/RateLimiting/Attributes/` | `RateLimit.php`, `RateLimitCost.php` |
| `src/Api/RateLimiting/Middleware/` | `EnhancedRateLimiterMiddleware.php` |
| `tests/Unit/Api/RateLimiting/` | Unit tests for all components (44 tests, 88 assertions) |

### Files Modified

| File | Changes |
|------|---------|
| `src/Routing/Route.php` | Added `rateLimitConfig`, `rateLimitCost` properties and fluent methods |
| `src/Routing/AttributeRouteLoader.php` | Added `processRateLimitAttributes()` method |
| `config/api.php` | Added `rate_limiting` configuration section |
| `src/Container/Providers/CoreProvider.php` | Registered all rate limiting services |

### Usage

```php
// Attribute-based configuration (recommended)
#[RateLimit(attempts: 60, perMinutes: 1)]
#[RateLimit(attempts: 1000, perHours: 1)]
public function index(): Response { }

// Tier-specific limits
#[RateLimit(tier: 'free', attempts: 100, perDays: 1)]
#[RateLimit(tier: 'pro', attempts: 10000, perDays: 1)]
#[RateLimit(tier: 'enterprise', attempts: 0)] // 0 = unlimited
public function query(): Response { }

// Cost-based limiting
#[RateLimit(attempts: 1000, perDays: 1)]
#[RateLimitCost(cost: 100, reason: 'Full data export')]
public function export(): Response { }

// Route middleware
$router->get('/users', [UserController::class, 'index'])
    ->middleware(['enhanced_rate_limit']);
```

### Quality Checks

- **PHPStan**: No errors
- **PHPCS**: All files pass
- **Unit Tests**: 44 tests, 88 assertions, all passing

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Goals and Non-Goals](#goals-and-non-goals)
3. [Current State Analysis](#current-state-analysis)
4. [Architecture Design](#architecture-design)
5. [Rate Limiting Strategies](#rate-limiting-strategies)
6. [Tiered Rate Limits](#tiered-rate-limits)
7. [Cost-Based Rate Limiting](#cost-based-rate-limiting)
8. [Implementation Phases](#implementation-phases)
9. [Testing Strategy](#testing-strategy)
10. [API Reference](#api-reference)

---

## Executive Summary

This document outlines enhancements to Glueful's rate limiting system. Building on the existing global rate limiter, we will add per-route limits, tiered user access, cost-based limiting, and standard HTTP rate limit headers for better API governance.

### Key Features

- **Per-Route Rate Limits**: Define limits on individual endpoints using attributes
- **Tiered Access**: Different limits for free, pro, and enterprise users
- **Cost-Based Limiting**: Assign costs to expensive operations
- **Standard Headers**: `X-RateLimit-*` headers for client transparency
- **Multiple Algorithms**: Fixed window, sliding window, token bucket
- **Distributed Limiting**: Redis-backed for multi-server deployments

---

## Goals and Non-Goals

### Goals

- ✅ Per-route rate limits via `#[RateLimit]` attribute
- ✅ User tier-based rate limits (free, pro, enterprise)
- ✅ Cost-based rate limiting for expensive operations
- ✅ Standard rate limit headers (RFC 6585, draft-ietf-httpapi-ratelimit-headers)
- ✅ Multiple limiting algorithms (fixed window, sliding window, token bucket)
- ✅ IP-based and user-based limiting
- ✅ Route group rate limits
- ✅ Graceful degradation when limits exceeded

### Non-Goals

- ❌ DDoS protection (use WAF/CDN)
- ❌ Request throttling/queuing (reject, don't queue)
- ❌ Geographic-based limiting
- ❌ Machine learning-based adaptive limits

---

## Current State Analysis

### Existing Rate Limiting

Glueful has basic global rate limiting:

```php
// Current middleware usage
$router->group(['middleware' => ['rate_limit']], function ($router) {
    $router->get('/users', [UserController::class, 'index']);
});

// Current configuration in config/security.php
'rate_limiting' => [
    'enabled' => true,
    'requests_per_minute' => 60,
    'driver' => 'redis',
]
```

### Limitations

| Limitation | Impact |
|------------|--------|
| Global limits only | Can't protect specific endpoints |
| No user tiers | Same limits for all users |
| No cost-based | Expensive queries consume same quota |
| No standard headers | Clients can't adapt behavior |
| Single algorithm | Fixed window only |

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
│                  RateLimitMiddleware                             │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │              RateLimitManager                            │   │
│  │  ┌──────────────┐ ┌──────────────┐ ┌──────────────────┐ │   │
│  │  │TierResolver  │ │CostCalculator│ │ LimitResolver    │ │   │
│  │  └──────────────┘ └──────────────┘ └──────────────────┘ │   │
│  └─────────────────────────────────────────────────────────┘   │
│                              │                                   │
│                              ▼                                   │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                   Rate Limiter                           │   │
│  │  ┌──────────────┐ ┌──────────────┐ ┌──────────────────┐ │   │
│  │  │FixedWindow   │ │SlidingWindow │ │  TokenBucket     │ │   │
│  │  └──────────────┘ └──────────────┘ └──────────────────┘ │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                    ┌─────────┴─────────┐
                    │                   │
                    ▼                   ▼
          ┌─────────────────┐  ┌─────────────────┐
          │    Allowed      │  │    Rejected     │
          │  + Headers      │  │   429 + Retry   │
          └─────────────────┘  └─────────────────┘
```

### Directory Structure

```
src/Api/RateLimiting/
├── Contracts/
│   ├── RateLimiterInterface.php       # Limiter contract
│   ├── TierResolverInterface.php      # User tier resolution
│   └── CostCalculatorInterface.php    # Operation cost calculation
├── Attributes/
│   ├── RateLimit.php                  # Per-route limits
│   ├── RateLimitCost.php              # Operation cost
│   └── RateLimitTier.php              # Tier override
├── Limiters/
│   ├── FixedWindowLimiter.php         # Fixed time window
│   ├── SlidingWindowLimiter.php       # Sliding window
│   └── TokenBucketLimiter.php         # Token bucket
├── Middleware/
│   └── RateLimitMiddleware.php        # Request handling
├── RateLimitManager.php               # Central management
├── RateLimitHeaders.php               # Header generation
├── TierManager.php                    # Tier configuration
├── RateLimitExceededException.php     # Exception class
└── Storage/
    ├── RedisStorage.php               # Redis backend
    ├── CacheStorage.php               # Cache backend
    └── MemoryStorage.php              # In-memory (testing)
```

---

## Rate Limiting Strategies

### 1. Fixed Window Limiter

Counts requests in fixed time windows (e.g., per minute).

```php
<?php

namespace Glueful\Api\RateLimiting\Limiters;

class FixedWindowLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
    ) {}

    public function attempt(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult
    {
        $windowKey = $key . ':' . floor(time() / $decaySeconds);

        $current = $this->storage->increment($windowKey);

        if ($current === 1) {
            $this->storage->expire($windowKey, $decaySeconds);
        }

        $remaining = max(0, $maxAttempts - $current);
        $resetAt = (floor(time() / $decaySeconds) + 1) * $decaySeconds;

        return new RateLimitResult(
            allowed: $current <= $maxAttempts,
            limit: $maxAttempts,
            remaining: $remaining,
            resetAt: $resetAt,
            retryAfter: $current > $maxAttempts ? $resetAt - time() : null,
        );
    }
}
```

### 2. Sliding Window Limiter

More accurate than fixed window, prevents burst at window boundaries.

```php
<?php

namespace Glueful\Api\RateLimiting\Limiters;

class SlidingWindowLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
    ) {}

    public function attempt(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult
    {
        $now = microtime(true);
        $windowStart = $now - $decaySeconds;

        // Remove old entries
        $this->storage->zRemRangeByScore($key, '-inf', $windowStart);

        // Count current entries
        $current = $this->storage->zCard($key);

        if ($current < $maxAttempts) {
            // Add new entry with timestamp as score
            $this->storage->zAdd($key, $now, uniqid('', true));
            $this->storage->expire($key, $decaySeconds);
            $current++;
        }

        $remaining = max(0, $maxAttempts - $current);

        // Get oldest entry for reset calculation
        $oldest = $this->storage->zRange($key, 0, 0, true);
        $resetAt = $oldest !== [] ? (int) ceil(reset($oldest) + $decaySeconds) : time() + $decaySeconds;

        return new RateLimitResult(
            allowed: $current <= $maxAttempts,
            limit: $maxAttempts,
            remaining: $remaining,
            resetAt: $resetAt,
            retryAfter: $current > $maxAttempts ? max(1, $resetAt - time()) : null,
        );
    }
}
```

### 3. Token Bucket Limiter

Allows bursts while maintaining average rate.

```php
<?php

namespace Glueful\Api\RateLimiting\Limiters;

class TokenBucketLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
    ) {}

    public function attempt(
        string $key,
        int $bucketSize,
        float $refillRate,
        int $tokensRequired = 1
    ): RateLimitResult {
        $now = microtime(true);

        // Get current bucket state
        $bucket = $this->storage->get($key);

        if ($bucket === null) {
            $tokens = $bucketSize;
            $lastRefill = $now;
        } else {
            $bucket = json_decode($bucket, true);
            $tokens = $bucket['tokens'];
            $lastRefill = $bucket['last_refill'];

            // Refill tokens based on time elapsed
            $elapsed = $now - $lastRefill;
            $tokens = min($bucketSize, $tokens + ($elapsed * $refillRate));
            $lastRefill = $now;
        }

        $allowed = $tokens >= $tokensRequired;

        if ($allowed) {
            $tokens -= $tokensRequired;
        }

        // Save bucket state
        $this->storage->set($key, json_encode([
            'tokens' => $tokens,
            'last_refill' => $lastRefill,
        ]), (int) ($bucketSize / $refillRate) + 60);

        $remaining = (int) floor($tokens);
        $timeToRefill = $allowed ? 0 : ($tokensRequired - $tokens) / $refillRate;

        return new RateLimitResult(
            allowed: $allowed,
            limit: $bucketSize,
            remaining: $remaining,
            resetAt: $allowed ? null : (int) ($now + $timeToRefill),
            retryAfter: $allowed ? null : (int) ceil($timeToRefill),
        );
    }
}
```

---

## Rate Limit Attributes

### #[RateLimit] Attribute

```php
<?php

namespace Glueful\Api\RateLimiting\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class RateLimit
{
    /**
     * @param int $attempts Maximum attempts allowed
     * @param int $perMinutes Time window in minutes (default 1)
     * @param int|null $perHours Time window in hours
     * @param int|null $perDays Time window in days
     * @param string|null $tier Apply only to specific tier
     * @param string|null $key Custom key (default: IP or user ID)
     * @param string $algorithm Limiter algorithm (fixed, sliding, bucket)
     */
    public function __construct(
        public readonly int $attempts,
        public readonly int $perMinutes = 1,
        public readonly ?int $perHours = null,
        public readonly ?int $perDays = null,
        public readonly ?string $tier = null,
        public readonly ?string $key = null,
        public readonly string $algorithm = 'sliding',
    ) {}

    /**
     * Get decay time in seconds
     */
    public function getDecaySeconds(): int
    {
        if ($this->perDays !== null) {
            return $this->perDays * 86400;
        }
        if ($this->perHours !== null) {
            return $this->perHours * 3600;
        }
        return $this->perMinutes * 60;
    }
}
```

### #[RateLimitCost] Attribute

```php
<?php

namespace Glueful\Api\RateLimiting\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class RateLimitCost
{
    /**
     * @param int $cost Number of quota units consumed
     * @param string|null $reason Description for documentation
     */
    public function __construct(
        public readonly int $cost,
        public readonly ?string $reason = null,
    ) {}
}
```

---

## Tiered Rate Limits

### Tier Configuration

```php
// config/api.php
return [
    'rate_limiting' => [
        'tiers' => [
            'anonymous' => [
                'requests_per_minute' => 30,
                'requests_per_hour' => 500,
                'requests_per_day' => 5000,
            ],
            'free' => [
                'requests_per_minute' => 60,
                'requests_per_hour' => 1000,
                'requests_per_day' => 10000,
            ],
            'pro' => [
                'requests_per_minute' => 300,
                'requests_per_hour' => 10000,
                'requests_per_day' => 100000,
            ],
            'enterprise' => [
                'requests_per_minute' => null, // unlimited
                'requests_per_hour' => null,
                'requests_per_day' => null,
            ],
        ],
    ],
];
```

### Tier Resolver

```php
<?php

namespace Glueful\Api\RateLimiting;

use Glueful\Api\RateLimiting\Contracts\TierResolverInterface;
use Symfony\Component\HttpFoundation\Request;

class TierResolver implements TierResolverInterface
{
    /**
     * Resolve user's rate limit tier
     */
    public function resolve(Request $request): string
    {
        $user = $request->attributes->get('user');

        if ($user === null) {
            return 'anonymous';
        }

        // Check user's subscription/plan
        $plan = $user['plan'] ?? $user['subscription'] ?? 'free';

        return match ($plan) {
            'enterprise', 'unlimited' => 'enterprise',
            'pro', 'professional', 'business' => 'pro',
            default => 'free',
        };
    }
}
```

### Tier Manager

```php
<?php

namespace Glueful\Api\RateLimiting;

class TierManager
{
    /** @var array<string, array> */
    private array $tiers;

    public function __construct(array $config = [])
    {
        $this->tiers = $config['tiers'] ?? [];
    }

    /**
     * Get limits for a tier
     */
    public function getLimits(string $tier): array
    {
        return $this->tiers[$tier] ?? $this->tiers['free'] ?? [];
    }

    /**
     * Check if tier has unlimited requests
     */
    public function isUnlimited(string $tier, string $window = 'minute'): bool
    {
        $limits = $this->getLimits($tier);
        $key = "requests_per_{$window}";

        return !isset($limits[$key]) || $limits[$key] === null;
    }

    /**
     * Get specific limit for tier
     */
    public function getLimit(string $tier, string $window): ?int
    {
        $limits = $this->getLimits($tier);
        $key = "requests_per_{$window}";

        return $limits[$key] ?? null;
    }
}
```

---

## Controller Usage Examples

### Basic Per-Route Limits

```php
<?php

namespace App\Http\Controllers;

use Glueful\Api\RateLimiting\Attributes\RateLimit;
use Glueful\Http\Controllers\Controller;

class UserController extends Controller
{
    // 60 requests per minute (default)
    #[RateLimit(attempts: 60)]
    public function index(): Response
    {
        return UserResource::collection(User::paginate());
    }

    // 10 requests per minute for expensive operation
    #[RateLimit(attempts: 10, perMinutes: 1)]
    public function export(): Response
    {
        return $this->exportUsers();
    }

    // Multiple time windows
    #[RateLimit(attempts: 60, perMinutes: 1)]
    #[RateLimit(attempts: 1000, perHours: 1)]
    #[RateLimit(attempts: 10000, perDays: 1)]
    public function search(): Response
    {
        return $this->searchUsers();
    }
}
```

### Tiered Limits

```php
<?php

namespace App\Http\Controllers;

use Glueful\Api\RateLimiting\Attributes\RateLimit;

class ApiController extends Controller
{
    // Different limits per tier
    #[RateLimit(tier: 'free', attempts: 100, perDays: 1)]
    #[RateLimit(tier: 'pro', attempts: 10000, perDays: 1)]
    #[RateLimit(tier: 'enterprise', attempts: 0)] // 0 = unlimited
    public function query(): Response
    {
        return $this->executeQuery();
    }
}
```

### Cost-Based Limits

```php
<?php

namespace App\Http\Controllers;

use Glueful\Api\RateLimiting\Attributes\RateLimit;
use Glueful\Api\RateLimiting\Attributes\RateLimitCost;

class AnalyticsController extends Controller
{
    // Base limit: 1000 "units" per day
    #[RateLimit(attempts: 1000, perDays: 1)]
    public function simpleQuery(): Response
    {
        // Costs 1 unit (default)
        return $this->runQuery();
    }

    #[RateLimit(attempts: 1000, perDays: 1)]
    #[RateLimitCost(cost: 10, reason: 'Complex aggregation query')]
    public function complexQuery(): Response
    {
        // Costs 10 units
        return $this->runComplexQuery();
    }

    #[RateLimit(attempts: 1000, perDays: 1)]
    #[RateLimitCost(cost: 100, reason: 'Full data export')]
    public function exportAll(): Response
    {
        // Costs 100 units
        return $this->exportAllData();
    }
}
```

---

## Rate Limit Middleware

### Enhanced Middleware

```php
<?php

namespace Glueful\Api\RateLimiting\Middleware;

use Glueful\Api\RateLimiting\RateLimitManager;
use Glueful\Api\RateLimiting\RateLimitHeaders;
use Glueful\Api\RateLimiting\RateLimitExceededException;
use Glueful\Routing\Middleware\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly RateLimitManager $manager,
        private readonly RateLimitHeaders $headers,
    ) {}

    public function handle(Request $request, callable $next, ...$params): Response
    {
        // Get route-specific limits from attributes
        $limits = $this->manager->getLimitsForRoute($request);

        if ($limits === []) {
            // No limits defined, use defaults
            $limits = $this->manager->getDefaultLimits($request);
        }

        // Check each limit
        foreach ($limits as $limit) {
            $result = $this->manager->attempt($request, $limit);

            if (!$result->allowed) {
                throw new RateLimitExceededException(
                    $result->limit,
                    $result->retryAfter,
                    $limit->tier ?? 'default'
                );
            }
        }

        // Process request
        $response = $next($request);

        // Add rate limit headers (use most restrictive result)
        $this->headers->addToResponse($response, $result);

        return $response;
    }
}
```

### Rate Limit Headers

```php
<?php

namespace Glueful\Api\RateLimiting;

use Symfony\Component\HttpFoundation\Response;

class RateLimitHeaders
{
    /**
     * Add rate limit headers to response
     *
     * Headers follow draft-ietf-httpapi-ratelimit-headers
     */
    public function addToResponse(Response $response, RateLimitResult $result): void
    {
        // Standard headers
        $response->headers->set('X-RateLimit-Limit', (string) $result->limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $result->remaining);
        $response->headers->set('X-RateLimit-Reset', (string) $result->resetAt);

        // IETF draft headers (future standard)
        $response->headers->set('RateLimit-Limit', (string) $result->limit);
        $response->headers->set('RateLimit-Remaining', (string) $result->remaining);
        $response->headers->set('RateLimit-Reset', (string) $result->resetAt);

        // Policy header for detailed info
        $response->headers->set('RateLimit-Policy', $this->formatPolicy($result));
    }

    /**
     * Add headers for exceeded limit
     */
    public function addExceededHeaders(Response $response, RateLimitResult $result): void
    {
        $this->addToResponse($response, $result);

        // Retry-After header (RFC 7231)
        if ($result->retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $result->retryAfter);
        }
    }

    private function formatPolicy(RateLimitResult $result): string
    {
        $window = $result->resetAt - time();
        return "{$result->limit};w={$window}";
    }
}
```

### Rate Limit Exception

```php
<?php

namespace Glueful\Api\RateLimiting;

use Glueful\Http\Exceptions\HttpException;
use Glueful\Http\Exceptions\Contracts\RenderableException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RateLimitExceededException extends HttpException implements RenderableException
{
    public function __construct(
        private readonly int $limit,
        private readonly ?int $retryAfter = null,
        private readonly string $tier = 'default',
    ) {
        parent::__construct(429, 'Too Many Requests');
    }

    public function render(?Request $request): Response
    {
        $response = new Response(json_encode([
            'success' => false,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many requests. Please slow down.',
                'limit' => $this->limit,
                'retry_after' => $this->retryAfter,
                'tier' => $this->tier,
            ],
        ]), 429, ['Content-Type' => 'application/json']);

        if ($this->retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $this->retryAfter);
        }

        return $response;
    }
}
```

---

## Rate Limit Manager

```php
<?php

namespace Glueful\Api\RateLimiting;

use Glueful\Api\RateLimiting\Attributes\RateLimit;
use Glueful\Api\RateLimiting\Attributes\RateLimitCost;
use Glueful\Api\RateLimiting\Contracts\RateLimiterInterface;
use Glueful\Api\RateLimiting\Contracts\TierResolverInterface;
use Symfony\Component\HttpFoundation\Request;

class RateLimitManager
{
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly TierResolverInterface $tierResolver,
        private readonly TierManager $tierManager,
        private readonly array $config = [],
    ) {}

    /**
     * Attempt a request against rate limits
     */
    public function attempt(Request $request, RateLimit $limit): RateLimitResult
    {
        // Get user tier
        $tier = $this->tierResolver->resolve($request);

        // Check if tier is unlimited for this window
        if ($limit->attempts === 0 || $this->tierManager->isUnlimited($tier)) {
            return new RateLimitResult(
                allowed: true,
                limit: PHP_INT_MAX,
                remaining: PHP_INT_MAX,
                resetAt: time() + $limit->getDecaySeconds(),
            );
        }

        // Build rate limit key
        $key = $this->buildKey($request, $limit, $tier);

        // Get cost multiplier
        $cost = $this->getCost($request);

        // Check limit using configured algorithm
        return $this->limiter->attempt(
            key: $key,
            maxAttempts: $limit->attempts,
            decaySeconds: $limit->getDecaySeconds(),
            tokensRequired: $cost,
        );
    }

    /**
     * Get limits defined on the current route
     *
     * @return RateLimit[]
     */
    public function getLimitsForRoute(Request $request): array
    {
        $route = $request->attributes->get('_route');
        if ($route === null) {
            return [];
        }

        // Get limits from route attributes
        $controller = $route->getController();
        $method = $route->getMethod();

        $limits = [];

        // Class-level limits
        $classReflection = new \ReflectionClass($controller);
        foreach ($classReflection->getAttributes(RateLimit::class) as $attr) {
            $limits[] = $attr->newInstance();
        }

        // Method-level limits
        if ($method !== null) {
            $methodReflection = $classReflection->getMethod($method);
            foreach ($methodReflection->getAttributes(RateLimit::class) as $attr) {
                $limits[] = $attr->newInstance();
            }
        }

        return $limits;
    }

    /**
     * Get default limits based on tier
     */
    public function getDefaultLimits(Request $request): array
    {
        $tier = $this->tierResolver->resolve($request);
        $tierLimits = $this->tierManager->getLimits($tier);

        $limits = [];

        if (isset($tierLimits['requests_per_minute'])) {
            $limits[] = new RateLimit(
                attempts: $tierLimits['requests_per_minute'],
                perMinutes: 1
            );
        }

        if (isset($tierLimits['requests_per_hour'])) {
            $limits[] = new RateLimit(
                attempts: $tierLimits['requests_per_hour'],
                perHours: 1
            );
        }

        if (isset($tierLimits['requests_per_day'])) {
            $limits[] = new RateLimit(
                attempts: $tierLimits['requests_per_day'],
                perDays: 1
            );
        }

        return $limits;
    }

    /**
     * Build rate limit key
     */
    private function buildKey(Request $request, RateLimit $limit, string $tier): string
    {
        $user = $request->attributes->get('user');

        // Use custom key if provided
        if ($limit->key !== null) {
            return "rate_limit:{$limit->key}:{$tier}";
        }

        // User-based key if authenticated
        if ($user !== null) {
            $userId = $user['id'] ?? $user['uuid'] ?? null;
            if ($userId !== null) {
                return "rate_limit:user:{$userId}:{$tier}";
            }
        }

        // Fall back to IP
        $ip = $request->getClientIp();
        return "rate_limit:ip:{$ip}:{$tier}";
    }

    /**
     * Get cost from route attributes
     */
    private function getCost(Request $request): int
    {
        $route = $request->attributes->get('_route');
        if ($route === null) {
            return 1;
        }

        $controller = $route->getController();
        $method = $route->getMethod();

        if ($method === null) {
            return 1;
        }

        $methodReflection = new \ReflectionMethod($controller, $method);
        $costAttrs = $methodReflection->getAttributes(RateLimitCost::class);

        if ($costAttrs !== []) {
            return $costAttrs[0]->newInstance()->cost;
        }

        return 1;
    }
}
```

---

## Response Examples

### Successful Request

```http
HTTP/1.1 200 OK
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1706011260
RateLimit-Limit: 60
RateLimit-Remaining: 45
RateLimit-Reset: 1706011260
RateLimit-Policy: 60;w=60

{"data": [...]}
```

### Rate Limited Request

```http
HTTP/1.1 429 Too Many Requests
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1706011260
Retry-After: 45
Content-Type: application/json

{
    "success": false,
    "error": {
        "code": "RATE_LIMIT_EXCEEDED",
        "message": "Too many requests. Please slow down.",
        "limit": 60,
        "retry_after": 45,
        "tier": "free"
    }
}
```

---

## Implementation Phases

### Phase 1: Core Infrastructure (Week 1) ✅ COMPLETE

**Deliverables:**
- [x] `RateLimiterInterface` contract
- [x] `FixedWindowLimiter` implementation
- [x] `SlidingWindowLimiter` implementation
- [x] `RateLimitResult` value object
- [x] Cache/Memory storage adapters

**Acceptance Criteria:**
```php
$limiter = new SlidingWindowLimiter($storage);
$result = $limiter->attempt('user:123', 60, 60);
$this->assertTrue($result->allowed);
```

### Phase 2: Attributes & Middleware (Week 1-2) ✅ COMPLETE

**Deliverables:**
- [x] `#[RateLimit]` attribute (IS_REPEATABLE for multi-window)
- [x] `#[RateLimitCost]` attribute
- [x] `EnhancedRateLimiterMiddleware`
- [x] `RateLimitHeaders` class (IETF-compliant)
- [x] 429 response handling with Retry-After

**Acceptance Criteria:**
```php
#[RateLimit(attempts: 100, perMinutes: 1)]
public function index(): Response { }
// Works with rate limit headers
```

### Phase 3: Tiered Limits (Week 2) ✅ COMPLETE

**Deliverables:**
- [x] `TierResolverInterface`
- [x] `TierResolver` implementation
- [x] `TierManager` class
- [x] Configuration in `config/api.php`
- [x] Tier-specific limits (anonymous, free, pro, enterprise)

**Acceptance Criteria:**
```php
// Free user gets 60/min, Pro user gets 300/min
#[RateLimit(tier: 'free', attempts: 60)]
#[RateLimit(tier: 'pro', attempts: 300)]
```

### Phase 4: Advanced Features (Week 3) ✅ COMPLETE

**Deliverables:**
- [x] `TokenBucketLimiter` implementation
- [x] Cost-based limiting via `#[RateLimitCost]`
- [x] Route integration via `Route::rateLimit()` fluent method
- [x] Service registration in CoreProvider
- [x] Unit tests (44 tests, 88 assertions)

**Acceptance Criteria:**
```php
#[RateLimitCost(cost: 10)]
public function expensiveOperation(): Response { }
```

---

## Testing Strategy

### Unit Tests

```php
<?php

namespace Glueful\Tests\Unit\Api\RateLimiting;

use PHPUnit\Framework\TestCase;
use Glueful\Api\RateLimiting\Limiters\SlidingWindowLimiter;

class SlidingWindowLimiterTest extends TestCase
{
    public function testAllowsRequestsWithinLimit(): void
    {
        $storage = new MemoryStorage();
        $limiter = new SlidingWindowLimiter($storage);

        for ($i = 0; $i < 60; $i++) {
            $result = $limiter->attempt('test', 60, 60);
            $this->assertTrue($result->allowed);
        }
    }

    public function testBlocksRequestsOverLimit(): void
    {
        $storage = new MemoryStorage();
        $limiter = new SlidingWindowLimiter($storage);

        for ($i = 0; $i < 60; $i++) {
            $limiter->attempt('test', 60, 60);
        }

        $result = $limiter->attempt('test', 60, 60);
        $this->assertFalse($result->allowed);
        $this->assertEquals(0, $result->remaining);
    }

    public function testCalculatesRetryAfter(): void
    {
        $storage = new MemoryStorage();
        $limiter = new SlidingWindowLimiter($storage);

        for ($i = 0; $i < 61; $i++) {
            $result = $limiter->attempt('test', 60, 60);
        }

        $this->assertNotNull($result->retryAfter);
        $this->assertGreaterThan(0, $result->retryAfter);
    }
}
```

### Integration Tests

```php
<?php

namespace Glueful\Tests\Integration\Api\RateLimiting;

use Glueful\Testing\TestCase;

class RateLimitingIntegrationTest extends TestCase
{
    public function testEnforcesRateLimits(): void
    {
        // Make 60 requests (at limit)
        for ($i = 0; $i < 60; $i++) {
            $response = $this->get('/api/users');
            $response->assertStatus(200);
            $response->assertHeader('X-RateLimit-Remaining');
        }

        // 61st request should fail
        $response = $this->get('/api/users');
        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }

    public function testDifferentLimitsPerTier(): void
    {
        // Free user
        $response = $this->actingAs($this->freeUser)->get('/api/users');
        $response->assertHeader('X-RateLimit-Limit', '60');

        // Pro user
        $response = $this->actingAs($this->proUser)->get('/api/users');
        $response->assertHeader('X-RateLimit-Limit', '300');
    }
}
```

---

## Configuration Reference

```php
// config/api.php
return [
    'rate_limiting' => [
        'enabled' => true,
        'driver' => 'redis', // redis, cache, memory

        // Default limits (when no attribute specified)
        'defaults' => [
            'requests_per_minute' => 60,
            'requests_per_hour' => 1000,
            'requests_per_day' => 10000,
        ],

        // Headers
        'headers' => true,
        'header_prefix' => 'X-RateLimit-',

        // Algorithm
        'algorithm' => 'sliding', // fixed, sliding, bucket

        // Tiers
        'tiers' => [
            'anonymous' => [
                'requests_per_minute' => 30,
                'requests_per_hour' => 500,
                'requests_per_day' => 5000,
            ],
            'free' => [
                'requests_per_minute' => 60,
                'requests_per_hour' => 1000,
                'requests_per_day' => 10000,
            ],
            'pro' => [
                'requests_per_minute' => 300,
                'requests_per_hour' => 10000,
                'requests_per_day' => 100000,
            ],
            'enterprise' => [
                'requests_per_minute' => null, // unlimited
                'requests_per_hour' => null,
                'requests_per_day' => null,
            ],
        ],

        // Key prefix in storage
        'prefix' => 'rate_limit:',

        // Bypass for certain IPs (internal services)
        'bypass_ips' => [
            '127.0.0.1',
            '::1',
        ],
    ],
];
```

---

## Security Considerations

1. **Key Collision**: Ensure rate limit keys are unique per user/route
2. **Distributed Environments**: Use Redis for consistent limiting across servers
3. **Header Spoofing**: Don't trust client-sent rate limit info
4. **Bypass Prevention**: Don't allow unlimited tiers without proper authentication
5. **Clock Skew**: Handle time synchronization issues in distributed systems
