# Priority 3: API-Specific Features Implementation Plans

> Detailed implementation blueprints for advanced API features including versioning, webhooks, enhanced rate limiting, and search/filtering capabilities in Glueful Framework.

## Overview

This folder contains comprehensive implementation plans for Priority 3 features identified in [FRAMEWORK_IMPROVEMENTS.md](../../FRAMEWORK_IMPROVEMENTS.md). These features focus on API-specific functionality that enables building production-grade, scalable REST APIs.

## Implementation Plans

| # | Feature | Document | Estimated Effort | Dependencies |
|---|---------|----------|------------------|--------------|
| 1 | API Versioning Strategy | [01-api-versioning.md](./01-api-versioning.md) | 2-3 weeks | Router, Middleware |
| 2 | Webhooks System | [02-webhooks-system.md](./02-webhooks-system.md) | 3-4 weeks | Queue, Events, HTTP Client |
| 3 | Rate Limiting Enhancements | [03-rate-limiting-enhancements.md](./03-rate-limiting-enhancements.md) | 2-3 weeks | Cache, Middleware |
| 4 | Search & Filtering DSL | [04-search-filtering-dsl.md](./04-search-filtering-dsl.md) | 3-4 weeks | ORM, QueryBuilder |

## Current State

### Existing API Infrastructure

| Component | Status | Description |
|-----------|--------|-------------|
| Router | ✅ Complete | High-performance router with O(1) static lookups |
| Rate Limiting | ✅ Basic | Global rate limiting with Redis/memory backends |
| HTTP Client | ✅ Complete | Guzzle-based HTTP client for outbound requests |
| Queue System | ✅ Complete | Redis/database queue with job retry support |
| Field Selection | ✅ Complete | GraphQL-style field selection middleware |
| API Resources | ✅ Complete | JSON transformation layer |

### What's Missing

| Feature | Current Limitation |
|---------|-------------------|
| API Versioning | ✅ **Implemented** - Multiple strategies (URL, header, query, Accept), deprecation system, middleware |
| Webhooks | No built-in webhook system |
| Rate Limiting | No per-route limits, no tiered limits |
| Search/Filtering | Basic field filtering only, no DSL |

## Implementation Order

The recommended implementation order based on dependencies and impact:

```
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│  Phase 1: API Governance ✅ COMPLETE                        │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ API Versioning Strategy ✅                           │   │
│  │ (foundation for evolving API contracts)              │   │
│  └─────────────────────────────────────────────────────┘   │
│                           │                                 │
│                           ▼                                 │
│  Phase 2: API Protection                                    │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Rate Limiting Enhancements                           │   │
│  │ (per-route limits, tiered access, cost-based)        │   │
│  └─────────────────────────────────────────────────────┘   │
│                           │                                 │
│                           ▼                                 │
│  Phase 3: Data Access                                       │
│  ┌─────────────────┐    ┌─────────────────────────────┐   │
│  │ Webhooks System │    │ Search & Filtering DSL      │   │
│  │ (event-driven)  │    │ (advanced data querying)    │   │
│  └─────────────────┘    └─────────────────────────────┘   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Design Principles

All implementations should follow these principles:

### 1. Build on Existing Infrastructure
- Leverage existing Router for versioning
- Use existing Queue system for webhook delivery
- Build on existing rate limiter for enhancements
- Extend QueryBuilder for search/filtering

### 2. Standards Compliance
- Follow REST API best practices
- Support standard HTTP headers (Accept-Version, X-RateLimit-*)
- Implement RFC 8288 for link relations
- Use JSON:API filter syntax where applicable

### 3. Developer Experience
- Intuitive attribute-based configuration
- Clear error messages with actionable guidance
- Comprehensive documentation with examples
- IDE-friendly with full type hints

### 4. Performance First
- Minimal overhead in hot paths
- Efficient caching strategies
- Lazy loading of version handlers
- Indexed search for filtering

### 5. Extensibility
- Plugin architecture for custom versioning strategies
- Custom webhook event handlers
- Configurable rate limit resolvers
- Extensible filter operators

## File Structure After Implementation

```
src/
├── Api/
│   ├── Versioning/                         # API Versioning ✅ IMPLEMENTED
│   │   ├── Contracts/
│   │   │   ├── VersionResolverInterface.php
│   │   │   ├── VersionNegotiatorInterface.php
│   │   │   └── DeprecatableInterface.php
│   │   ├── Resolvers/
│   │   │   ├── UrlPrefixResolver.php
│   │   │   ├── HeaderResolver.php
│   │   │   ├── QueryParameterResolver.php
│   │   │   └── AcceptHeaderResolver.php
│   │   ├── Attributes/
│   │   │   ├── Version.php                 # Named Version to avoid conflict with ApiVersion value object
│   │   │   ├── Deprecated.php
│   │   │   └── Sunset.php
│   │   ├── Middleware/
│   │   │   └── VersionNegotiationMiddleware.php
│   │   ├── ApiVersion.php                  # Value object
│   │   └── VersionManager.php
│   │
│   ├── Webhooks/                           # Webhooks System
│   │   ├── Contracts/
│   │   │   ├── WebhookInterface.php
│   │   │   ├── WebhookPayloadInterface.php
│   │   │   └── SignatureVerifierInterface.php
│   │   ├── Webhook.php
│   │   ├── WebhookSubscription.php
│   │   ├── WebhookDelivery.php
│   │   ├── WebhookDispatcher.php
│   │   ├── WebhookSignature.php
│   │   ├── Jobs/
│   │   │   └── DeliverWebhookJob.php
│   │   ├── Events/
│   │   │   ├── WebhookDispatched.php
│   │   │   ├── WebhookDelivered.php
│   │   │   └── WebhookFailed.php
│   │   └── Console/
│   │       ├── WebhookListCommand.php
│   │       ├── WebhookTestCommand.php
│   │       └── WebhookRetryCommand.php
│   │
│   ├── RateLimiting/                       # Enhanced Rate Limiting
│   │   ├── Contracts/
│   │   │   ├── RateLimiterInterface.php
│   │   │   ├── TierResolverInterface.php
│   │   │   └── CostCalculatorInterface.php
│   │   ├── Attributes/
│   │   │   ├── RateLimit.php
│   │   │   ├── RateLimitCost.php
│   │   │   └── RateLimitTier.php
│   │   ├── Limiters/
│   │   │   ├── FixedWindowLimiter.php
│   │   │   ├── SlidingWindowLimiter.php
│   │   │   └── TokenBucketLimiter.php
│   │   ├── Middleware/
│   │   │   └── RateLimitMiddleware.php
│   │   ├── RateLimitManager.php
│   │   ├── RateLimitHeaders.php
│   │   └── TierManager.php
│   │
│   └── Filtering/                          # Search & Filtering DSL
│       ├── Contracts/
│       │   ├── FilterableInterface.php
│       │   ├── SearchableInterface.php
│       │   └── FilterOperatorInterface.php
│       ├── QueryFilter.php
│       ├── FilterParser.php
│       ├── FilterBuilder.php
│       ├── SearchAdapter.php
│       ├── Operators/
│       │   ├── EqualOperator.php
│       │   ├── NotEqualOperator.php
│       │   ├── GreaterThanOperator.php
│       │   ├── LessThanOperator.php
│       │   ├── ContainsOperator.php
│       │   ├── StartsWithOperator.php
│       │   ├── EndsWithOperator.php
│       │   ├── InOperator.php
│       │   ├── NotInOperator.php
│       │   ├── BetweenOperator.php
│       │   └── NullOperator.php
│       ├── Attributes/
│       │   ├── Filterable.php
│       │   ├── Searchable.php
│       │   └── Sortable.php
│       └── Middleware/
│           └── FilterMiddleware.php
│
├── Console/
│   └── Commands/
│       ├── Api/                            # ✅ IMPLEMENTED
│       │   ├── VersionListCommand.php
│       │   └── VersionDeprecateCommand.php
│       ├── Webhook/
│       │   ├── WebhookListCommand.php
│       │   ├── WebhookTestCommand.php
│       │   └── WebhookRetryCommand.php
│       └── Scaffold/
│           ├── WebhookCommand.php
│           └── FilterCommand.php
│
└── ...existing...
```

## Testing Strategy

Each feature requires:

1. **Unit Tests** - Test individual components in isolation
2. **Integration Tests** - Test component interactions with database/cache
3. **Contract Tests** - Verify API contracts are maintained across versions
4. **Load Tests** - Benchmark rate limiting and filtering performance
5. **End-to-End Tests** - Full workflow testing

## Database Tables

### Auto-Migration Strategy

Priority 3 features that require database tables use an **auto-migration** pattern, following the approach established by `DatabaseLogHandler`. Tables are automatically created at runtime when the feature is first used.

#### How It Works

1. **Feature classes include `ensureTable()` method** - Checks if table exists, creates if not
2. **Uses existing Schema builder** - No separate migration files needed
3. **Tables created on first use** - Zero configuration required
4. **Idempotent** - Safe to call multiple times

#### Pattern Reference

```php
// From src/Logging/DatabaseLogHandler.php
private function ensureLogsTable(): void
{
    if (!$this->schema->hasTable($this->table)) {
        $table = $this->schema->table($this->table);

        $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
        $table->string('uuid', 12);
        // ... column definitions

        $table->create();
        $this->schema->execute();
    }
}
```

#### Benefits

| Benefit | Description |
|---------|-------------|
| Zero configuration | Tables created automatically when feature is used |
| No install commands | Users don't need to run migrations manually |
| Self-healing | Missing tables are recreated automatically |
| Framework-managed | Schema stays consistent with framework version |

#### Feature Table Management

| Feature | Table(s) | Auto-Created By |
|---------|----------|-----------------|
| Webhooks | `webhook_subscriptions`, `webhook_deliveries` | `WebhookDispatcher::ensureTables()` |
| Search Index | `search_index_log` | `SearchAdapter::ensureTable()` |
| Rate Limiting | None (uses Redis/Cache) | N/A |
| API Versioning | None | N/A |

---

### Webhooks System Tables

```sql
-- Webhook subscriptions
CREATE TABLE webhook_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    url VARCHAR(2048) NOT NULL,
    events JSON NOT NULL,
    secret VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_events ((CAST(events AS CHAR(255) ARRAY))),
    INDEX idx_active (is_active)
);

-- Webhook deliveries (for tracking/retry)
CREATE TABLE webhook_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    subscription_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending', 'delivered', 'failed', 'retrying') DEFAULT 'pending',
    attempts INT UNSIGNED DEFAULT 0,
    response_code INT UNSIGNED,
    response_body TEXT,
    delivered_at TIMESTAMP NULL,
    next_retry_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES webhook_subscriptions(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_next_retry (next_retry_at)
);
```

### Search Index Tracking Table (Optional)

```sql
-- Track which records have been indexed in search engines
CREATE TABLE search_index_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    indexable_type VARCHAR(255) NOT NULL,
    indexable_id VARCHAR(36) NOT NULL,
    index_name VARCHAR(255) NOT NULL,
    indexed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    checksum VARCHAR(64),  -- For detecting changes
    UNIQUE KEY idx_indexable (indexable_type, indexable_id, index_name),
    INDEX idx_indexed_at (indexed_at)
);
```

## Configuration Files

### config/api.php (New)

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Versioning
    |--------------------------------------------------------------------------
    */
    'versioning' => [
        'enabled' => true,
        'default' => 'v1',
        'strategy' => 'url', // url, header, query, accept
        'header' => 'X-API-Version',
        'query_param' => 'api_version',
        'deprecation' => [
            'sunset_header' => true,
            'warning_header' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'enabled' => true,
        'signature_header' => 'X-Webhook-Signature',
        'signature_algorithm' => 'sha256',
        'timeout' => 30,
        'retry' => [
            'max_attempts' => 5,
            'backoff' => [1, 5, 30, 120, 720], // minutes
        ],
        'events' => [
            // Register available webhook events
            // 'user.created' => \App\Events\UserCreated::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limiting' => [
        'enabled' => true,
        'driver' => 'redis', // redis, cache, database
        'headers' => true,
        'tiers' => [
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

    /*
    |--------------------------------------------------------------------------
    | Filtering
    |--------------------------------------------------------------------------
    */
    'filtering' => [
        'enabled' => true,
        'max_depth' => 3,
        'max_filters' => 20,
        'allowed_operators' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'in', 'between'],
        'sort_param' => 'sort',
        'filter_param' => 'filter',
        'search_param' => 'search',
    ],
];
```

## Status

| Feature | Status | PR | Release Target |
|---------|--------|-----|----------------|
| API Versioning Strategy | ✅ Complete | - | v1.16.0 |
| Webhooks System | 📋 Planned | - | v1.17.0 |
| Rate Limiting Enhancements | 📋 Planned | - | v1.16.0 |
| Search & Filtering DSL | 📋 Planned | - | v1.17.0 |

Legend: 📋 Planned | 🚧 In Progress | ✅ Complete | 🔄 Review

---

## Related Documentation

- [Priority 1 Implementation Plans](../README.md) - Completed foundational features
- [Priority 2 Implementation Plans](../priority-2/README.md) - Developer experience improvements
- [ORM Documentation](../../ORM.md) - Active Record implementation
- [Resources Documentation](../../RESOURCES.md) - API Resource transformers
- [FRAMEWORK_IMPROVEMENTS.md](../../FRAMEWORK_IMPROVEMENTS.md) - Full roadmap
