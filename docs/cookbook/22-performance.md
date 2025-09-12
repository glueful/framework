# Glueful Performance Optimization Guide

This comprehensive guide covers all performance optimization features in Glueful, including response optimization, database optimization, caching, profiling, and analysis tools.

## Table of Contents

1. [Overview](#overview)
2. [Response Performance Optimization](#response-performance-optimization)
3. [Query Optimization](#query-optimization)
4. [Query Caching System](#query-caching-system)
5. [Query Analysis Tools](#query-analysis-tools)
6. [Database Profiling Tools](#database-profiling-tools)
7. [Query Logger Optimizations](#query-logger-optimizations)
8. [Session Analytics Optimization](#session-analytics-optimization)
9. [API Metrics System Performance](#api-metrics-system-performance)
10. [Response Caching Strategies](#response-caching-strategies)
11. [Memory Management Features](#memory-management-features)
12. [Best Practices](#best-practices)
13. [Performance Metrics](#performance-metrics)
14. [Troubleshooting](#troubleshooting)

## Overview

Glueful provides a comprehensive suite of performance optimization tools designed to maximize application performance across all layers:

### Response Performance
- **40,000+ operations per second** for Response API
- **25μs average response time** for response generation
- **Zero memory overhead** compared to direct JsonResponse usage
- **HTTP caching** with proper headers and ETag validation
- **Application-level caching** for expensive operations

### Database Performance
- **Automatically optimize queries** for different database engines (MySQL, PostgreSQL, SQLite)
- **Cache query results** intelligently with automatic invalidation
- **Analyze and profile** database operations for bottlenecks
- **Detect performance issues** and provide actionable recommendations
- **Monitor query patterns** and identify N+1 problems

### System Performance
- **Session analytics optimization** with intelligent caching
- **API metrics** with asynchronous recording and batch processing
- **Memory management** with monitoring, pooling, and efficient processing
- **Response caching strategies** with multiple layers and invalidation

### Performance Improvements

The optimization features provide significant performance gains:

- **Response generation**: 40,000+ operations per second
- **Complex queries**: 20-40% performance improvement
- **Join-heavy queries**: Up to 50% improvement for inefficient joins
- **Cached queries**: 50-95% improvement for frequently accessed data
- **High-traffic applications**: 30-50% reduction in database load
- **Memory efficiency**: Up to 98% reduction in memory usage for large datasets

## Response Performance Optimization

The Glueful Response API provides excellent out-of-the-box performance with 40,000+ operations per second and 25μs average response time.

### 📊 When Additional Optimization is Needed

Most applications will never need optimization beyond the standard Response class. Consider additional caching only when:

- **Serving > 50,000 requests per minute**
- **Response generation becomes a bottleneck** (profiling shows high CPU usage)
- **Identical responses generated repeatedly** (e.g., configuration endpoints)

### 🎯 Recommended Optimization Strategies

#### 1. HTTP Caching (Recommended)

Use proper HTTP caching headers instead of application-level caching:

```php
// Add caching headers to responses
public function getConfiguration(): Response
{
    $config = $this->configService->getPublicConfig();
    
    return Response::success($config, 'Configuration retrieved')
        ->setMaxAge(3600)           // Cache for 1 hour
        ->setPublic()               // Allow CDN/proxy caching
        ->setEtag(md5(serialize($config))); // Enable conditional requests
}

// For user-specific data
public function getUserProfile(int $userId): Response
{
    $profile = $this->userService->getProfile($userId);
    
    return Response::success($profile, 'Profile retrieved')
        ->setMaxAge(300)            // 5 minutes
        ->setPrivate()              // Don't cache in shared caches
        ->setLastModified($profile->updated_at);
}
```

#### 2. Application-Level Caching

Cache expensive operations, not responses:

```php
class UserService
{
    public function getProfile(int $userId): array
    {
        return cache()->remember("user_profile:$userId", 600, function() use ($userId) {
            return $this->repository->getUserWithPermissions($userId);
        });
    }
}

// Controller stays clean
public function show(int $userId): Response
{
    $profile = $this->userService->getProfile($userId);
    return Response::success($profile, 'Profile retrieved');
}
```

#### 3. Middleware-Based Response Caching

For repeated identical responses:

```php
class ResponseCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CacheStore $cache,
        private array $cacheableRoutes = []
    ) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (!$this->shouldCache($request)) {
            return $handler->handle($request);
        }

        $cacheKey = $this->generateCacheKey($request);
        
        // Try to get from cache
        if ($cached = $this->cache->get($cacheKey)) {
            return unserialize($cached);
        }

        // Generate response
        $response = $handler->handle($request);

        // Cache successful responses
        if ($response->getStatusCode() === 200) {
            $this->cache->set($cacheKey, serialize($response), 300);
        }

        return $response;
    }

    private function shouldCache(Request $request): bool
    {
        return $request->getMethod() === 'GET' && 
               in_array($request->getPathInfo(), $this->cacheableRoutes);
    }
}
```

#### 4. Reverse Proxy Caching

Use Nginx, Varnish, or CDN for maximum performance:

```nginx
# Nginx configuration
location /api/config {
    proxy_pass http://backend;
    proxy_cache api_cache;
    proxy_cache_valid 200 1h;
    proxy_cache_key "$request_uri";
    add_header X-Cache-Status $upstream_cache_status;
}
```

### 🔧 Implementation Examples

#### HTTP Cache Helper

Add to BaseController for easy HTTP caching:

```php
abstract class BaseController
{
    protected function cached(Response $response, int $maxAge = 300, bool $public = false): Response
    {
        $response->setMaxAge($maxAge);
        
        if ($public) {
            $response->setPublic();
        } else {
            $response->setPrivate();
        }
        
        // Add ETag for conditional requests
        $response->setEtag(md5($response->getContent()));
        
        return $response;
    }
    
    protected function notModified(): Response
    {
        return new Response('', 304);
    }
}

// Usage
class ConfigController extends BaseController
{
    public function show(): Response
    {
        $config = $this->configService->getPublicConfig();
        
        return $this->cached(
            Response::success($config, 'Configuration retrieved'),
            3600,  // 1 hour
            true   // public caching
        );
    }
}
```

#### Smart Caching Service

For application-level caching with tags:

```php
class SmartCache
{
    public function __construct(private CacheStore $cache) {}
    
    public function rememberResponse(string $key, int $ttl, callable $callback, array $tags = []): Response
    {
        $cached = $this->cache->get($key);
        
        if ($cached) {
            return unserialize($cached);
        }
        
        $response = $callback();
        
        if ($response instanceof Response && $response->getStatusCode() === 200) {
            $this->cache->set($key, serialize($response), $ttl);
            
            // Tag the cache entry for easy invalidation
            foreach ($tags as $tag) {
                $this->cache->tag($tag, $key);
            }
        }
        
        return $response;
    }
    
    public function invalidateTag(string $tag): void
    {
        $keys = $this->cache->getTaggedKeys($tag);
        foreach ($keys as $key) {
            $this->cache->delete($key);
        }
    }
}

// Usage
class UserController extends BaseController
{
    public function show(int $userId): Response
    {
        return $this->smartCache->rememberResponse(
            "user_profile:$userId",
            600,
            fn() => Response::success(
                $this->userService->getProfile($userId),
                'Profile retrieved'
            ),
            ["user:$userId", 'user_profiles']
        );
    }
}
```

### 📈 Performance Monitoring

#### Track Response Performance

```php
class ResponsePerformanceMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $start = microtime(true);
        $response = $handler->handle($request);
        $duration = (microtime(true) - $start) * 1000;
        
        // Add performance headers in development
        if (app()->isDebug()) {
            $response->headers->set('X-Response-Time', $duration . 'ms');
            $response->headers->set('X-Memory-Usage', memory_get_usage(true));
        }
        
        // Log slow responses
        if ($duration > 100) { // 100ms threshold
            logger()->warning('Slow response detected', [
                'url' => $request->getUri(),
                'duration' => $duration,
                'memory' => memory_get_usage(true)
            ]);
        }
        
        return $response;
    }
}
```

#### Cache Hit Rate Monitoring

```php
class CacheMetrics
{
    private static int $hits = 0;
    private static int $misses = 0;
    
    public static function hit(): void { self::$hits++; }
    public static function miss(): void { self::$misses++; }
    
    public static function getStats(): array
    {
        $total = self::$hits + self::$misses;
        return [
            'hits' => self::$hits,
            'misses' => self::$misses,
            'hit_rate' => $total > 0 ? (self::$hits / $total) * 100 : 0
        ];
    }
}
```

### 🎯 Performance Targets

#### Benchmarks for Different Application Types

**Small Applications (< 1K requests/min)**
- Standard Response API: ✅ Sufficient
- Additional optimizations: ❌ Not needed

**Medium Applications (1K-10K requests/min)**  
- HTTP caching: ✅ Recommended
- Application caching: ✅ For expensive operations
- Response caching: ⚠️ Only if needed

**Large Applications (> 10K requests/min)**
- All above optimizations: ✅ Required
- Reverse proxy caching: ✅ Essential  
- CDN integration: ✅ Recommended

### 🔍 Response Optimization Best Practices

#### 1. Measure First, Optimize Second

```php
// Use profiling to identify bottlenecks
$profiler = app()->get(ProfilerInterface::class);
$profiler->start('user_profile_generation');

$profile = $this->userService->getProfile($userId);

$profiler->end('user_profile_generation');
```

#### 2. Cache Invalidation Strategy

```php
class UserService
{
    public function updateProfile(int $userId, array $data): User
    {
        $user = $this->repository->update($userId, $data);
        
        // Clear related caches
        cache()->forget("user_profile:$userId");
        cache()->invalidateTag("user:$userId");
        
        return $user;
    }
}
```

#### 3. Gradual Optimization

```php
// Start with simple HTTP caching
return Response::success($data)->setMaxAge(300);

// Add application caching if needed
$data = cache()->remember($key, 300, $callback);

// Add response caching only for high-traffic endpoints
// (via middleware or custom implementation)
```

### ✅ Response Performance Summary

The standard Glueful Response API provides excellent performance (40K+ ops/sec) for the vast majority of applications. When additional performance is needed:

1. **Start with HTTP caching** - proper, standards-compliant, works with CDNs
2. **Add application-level caching** - cache expensive operations, not responses  
3. **Use reverse proxy caching** - for maximum performance at scale
4. **Implement response caching selectively** - only for specific high-traffic endpoints

## Query Optimization

The Query Optimizer analyzes SQL queries and implements database-specific optimizations automatically.

### Features

- Database-specific optimizations for MySQL, PostgreSQL, and SQLite
- Automatic detection of inefficient query patterns
- Performance improvement estimation
- Detailed suggestions for manual query optimization
- Specialized optimization for JOINs, WHERE clauses, GROUP BY, and ORDER BY operations

### Basic Usage

```php
use Glueful\Database\Connection;
use Glueful\Database\QueryOptimizer;

// Get a database connection
$connection = new Connection();

// Create the query optimizer
$optimizer = new QueryOptimizer($connection);

// Optimize a query
$result = $optimizer->optimizeQuery(
    "SELECT * FROM users JOIN orders ON users.id = orders.user_id WHERE users.status = 'active'",
    [] // Query parameters (if using prepared statements)
);

// The result contains:
// - original_query: The original query string
// - optimized_query: The optimized version of the query
// - suggestions: Array of optimization suggestions
// - estimated_improvement: Estimated performance improvement metrics
```

### Integration with Query Builder

```php
use Glueful\Database\QueryBuilder;

$users = (new QueryBuilder($connection))
    ->select('users.*', 'orders.id as order_id')
    ->from('users')
    ->join('orders', 'users.id', '=', 'orders.user_id')
    ->where('users.status', '=', 'active')
    ->optimize() // Enable optimization
    ->get();
```

### Database-Specific Optimizations

#### MySQL Optimizations

- Use of `STRAIGHT_JOIN` hint for complex joins when beneficial
- Reordering of JOIN clauses for better execution
- Optimization of WHERE clauses to leverage indexes
- Addition of `WITH ROLLUP` for appropriate aggregate queries
- Optimizing ORDER BY to minimize filesort operations

```php
// MySQL-specific query with potential for optimization
$query = "
    SELECT 
        customers.name,
        COUNT(orders.id) as order_count,
        SUM(orders.total) as total_spent
    FROM customers
    LEFT JOIN orders ON customers.id = orders.customer_id
    WHERE customers.region = 'Europe'
    GROUP BY customers.id
    ORDER BY total_spent DESC
";

$result = $optimizer->optimizeQuery($query);

// MySQL might optimize this with:
// 1. STRAIGHT_JOIN to enforce join order
// 2. WITH ROLLUP for the GROUP BY if appropriate
// 3. Index hints for better performance
```

#### PostgreSQL Optimizations

- JOIN type optimizations based on data volume
- Index usage recommendations
- Multi-dimensional aggregation optimizations with CUBE and ROLLUP
- Optimized CTE (Common Table Expression) handling

#### SQLite Optimizations

- Optimized JOIN ordering
- Simplification of complex queries where possible
- Column order optimizations in WHERE clauses

### Optimization Results

```php
// Accessing optimization results
$optimizedQuery = $result['optimized_query'];

// View improvement metrics
$improvement = $result['estimated_improvement'];
echo "Estimated execution time improvement: {$improvement['execution_time']}%";
echo "Estimated resource usage improvement: {$improvement['resource_usage']}%";

// Review optimization suggestions
foreach ($result['suggestions'] as $suggestion) {
    echo "Suggestion: {$suggestion['description']}";
    echo "Solution: {$suggestion['solution']}";
}
```

### Advanced Usage

#### Custom Optimization Thresholds

```php
// Custom QueryBuilder extension
class OptimizedQueryBuilder extends QueryBuilder
{
    protected $optimizationThreshold = 10; // Default: apply optimization if 10% improvement
    
    public function setOptimizationThreshold(int $percentage): self
    {
        $this->optimizationThreshold = $percentage;
        return $this;
    }
    
    public function get()
    {
        if ($this->optimizeQuery) {
            $optimizer = new QueryOptimizer($this->connection);
            $result = $optimizer->optimizeQuery($this->toSql(), $this->getBindings());
            
            // Only use optimized query if improvement exceeds threshold
            if ($result['estimated_improvement']['execution_time'] > $this->optimizationThreshold) {
                return $this->connection->select(
                    $result['optimized_query'], 
                    $this->getBindings()
                );
            }
        }
        
        return parent::get();
    }
}

// Usage
$qb = new OptimizedQueryBuilder($connection);
$qb->setOptimizationThreshold(20) // Only optimize if 20% or better improvement
   ->select('*')
   ->from('products')
   ->optimize()
   ->get();
```

#### Monitoring Optimization Effectiveness

```php
// Enable query timing
$startTime = microtime(true);

// Execute with optimization
$optimizer = new QueryOptimizer($connection);
$result = $optimizer->optimizeQuery($query);
$optimizedQuery = $result['optimized_query'];
$optimizedResults = $connection->select($optimizedQuery);

$optimizedTime = microtime(true) - $startTime;

// Execute without optimization
$startTime = microtime(true);
$originalResults = $connection->select($query);
$originalTime = microtime(true) - $startTime;

// Compare performance
$improvementPercentage = (($originalTime - $optimizedTime) / $originalTime) * 100;
echo "Original query execution time: {$originalTime}s\n";
echo "Optimized query execution time: {$optimizedTime}s\n";
echo "Actual improvement: {$improvementPercentage}%\n";
echo "Estimated improvement: {$result['estimated_improvement']['execution_time']}%\n";
```

## Query Caching System

The Query Cache System improves application performance by storing and reusing database query results.

### How It Works

1. When a query with caching enabled is executed, the system first checks if the results for this exact query exist in the cache
2. If found (cache hit), the cached results are returned immediately without executing the query
3. If not found (cache miss), the query executes normally and the results are stored in cache
4. Subsequent identical queries will use the cached results until the cache expires or is invalidated

### Implementation Components

#### QueryBuilder Integration

```php
// Basic usage with default TTL
$users = $queryBuilder
    ->select('users', ['id', 'name', 'email'])
    ->where(['status' => 'active'])
    ->cache()
    ->get();

// With custom TTL (1 hour)
$orders = $queryBuilder
    ->select('orders', ['*'])
    ->where(['status' => 'pending'])
    ->cache(3600)
    ->get();
```

#### Repository Method Caching with Attributes

```php
<?php

namespace App\Repositories;

use Glueful\Database\Attributes\CacheResult;

class ProductRepository
{
    /**
     * Cache the results of this method for 2 hours
     */
    #[CacheResult(ttl: 7200, keyPrefix: 'products', tags: ['products', 'catalog'])]
    public function getFeaturedProducts(): array
    {
        // Method implementation that might include complex database queries
        return $this->queryBuilder
            ->select('products', ['*'])
            ->where(['featured' => true])
            ->orderBy(['popularity' => 'DESC'])
            ->get();
    }
    
    /**
     * Cache results with default TTL (1 hour)
     */
    #[CacheResult]
    public function getProductsByCategory(string $category): array
    {
        // Database query implementation
    }
    
    /**
     * Cache with custom tags for targeted invalidation
     */
    #[CacheResult(ttl: 1800, tags: ['product-counts', 'dashboard-stats'])]
    public function countProductsByStatus(): array
    {
        // Database query implementation
    }
}
```

To use a repository method with the `CacheResult` attribute:

```php
// Inject the QueryCacheService
public function __construct(
    private ProductRepository $repository,
    private QueryCacheService $cacheService
) {}

// Get results using the cache service
public function getProducts(): array
{
    return $this->cacheService->cacheRepositoryMethod(
        $this->repository, 
        'getFeaturedProducts'
    );
}

// With method arguments
public function getProductsByCategory(string $category): array
{
    return $this->cacheService->cacheRepositoryMethod(
        $this->repository, 
        'getProductsByCategory',
        [$category]
    );
}
```

#### CacheResult Attribute Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `ttl` | int | 3600 | Time-to-live in seconds for the cached result |
| `keyPrefix` | string | '' | Custom prefix for the cache key. If empty, uses class and method name |
| `tags` | array | [] | Array of cache tags for targeted invalidation |

### Configuration

```php
// config/database.php
'query_cache' => [
    'enabled' => env('QUERY_CACHE_ENABLED', true),
    'default_ttl' => env('QUERY_CACHE_TTL', 3600),
    'exclude_tables' => ['logs', 'sessions', 'cache'],
    'exclude_patterns' => [
        '/RAND\(\)/i',
        '/NOW\(\)/i',
        '/CURRENT_TIMESTAMP/i'
    ]
]
```

### Usage Examples

#### Basic Caching

```php
// Enable caching with default TTL
$popularProducts = $queryBuilder
    ->select('products', ['*'])
    ->where(['featured' => true])
    ->orderBy(['popularity' => 'DESC'])
    ->limit(10)
    ->cache()
    ->get();
```

#### Custom TTL

```php
// Cache for 5 minutes (300 seconds)
$recentArticles = $queryBuilder
    ->select('articles', ['id', 'title', 'excerpt'])
    ->where(['published' => true])
    ->orderBy(['published_at' => 'DESC'])
    ->limit(5)
    ->cache(300)
    ->get();
```

#### Combining with Optimization

```php
// Both optimize and cache a complex query
$analyticsData = $queryBuilder
    ->select('sales', ['region', 'product_category', $queryBuilder->raw('SUM(amount) as total')])
    ->join('products', 'sales.product_id = products.id')
    ->where(['sales.date' => $queryBuilder->raw('BETWEEN ? AND ?'), ['2025-01-01', '2025-03-31']])
    ->groupBy(['region', 'product_category'])
    ->orderBy(['total' => 'DESC'])
    ->optimize()
    ->cache(1800)  // 30 minutes
    ->get();
```

#### Manually Invalidating Cache

```php
// Invalidate all cached queries related to the 'products' table
$cacheService = new QueryCacheService();
$cacheService->invalidateTable('products');
```

### Best Practices

#### When to Use Query Caching

Query caching is most beneficial for:

1. **Read-heavy operations**: Queries that are read frequently but updated infrequently
2. **Expensive queries**: Complex joins, aggregations, or queries on large tables
3. **Predictable, repeating queries**: Queries that are executed frequently with the same parameters

#### When to Avoid Query Caching

Caching may not be appropriate for:

1. **Rapidly changing data**: Tables with frequent updates
2. **Unique queries**: Queries that are rarely executed with the same parameters
3. **Non-deterministic queries**: Queries with functions like RAND(), NOW(), or UUID()
4. **User-specific sensitive data**: Be cautious with user-specific data and privacy concerns

## Query Analysis Tools

The Query Analyzer provides advanced analysis capabilities for SQL queries, helping developers identify performance issues and optimize database operations.

### Key Features

- **Execution Plan Retrieval**: Fetches and normalizes database execution plans across different engines
- **Performance Issue Detection**: Identifies common SQL anti-patterns and inefficient query structures
- **Optimization Suggestions**: Provides actionable recommendations to improve query performance
- **Index Recommendations**: Suggests indexes that could enhance query execution speed
- **Multi-database Support**: Works with MySQL, PostgreSQL, and SQLite engines

### Usage Examples

#### Basic Usage

```php
// Create a new query analyzer instance
$analyzer = new \Glueful\Database\QueryAnalyzer();

// Analyze a query
$query = "SELECT * FROM users WHERE last_login < '2025-01-01' ORDER BY created_at";
$results = $analyzer->analyzeQuery($query);

// Display analysis results
print_r($results);
```

#### Analysis with Parameters

```php
$query = "SELECT * FROM products WHERE category_id = ? AND price > ?";
$params = [5, 99.99];
$results = $analyzer->analyzeQuery($query, $params);
```

### Execution Plan Analysis

```php
$plan = $results['execution_plan'];

// Example output for MySQL:
// [
//   [
//     'id' => 1,
//     'select_type' => 'SIMPLE',
//     'table' => 'products',
//     'type' => 'ref',
//     'possible_keys' => 'category_id_index',
//     'key' => 'category_id_index',
//     'key_len' => 4,
//     'ref' => 'const',
//     'rows' => 243,
//     'Extra' => 'Using where; Using filesort'
//   ]
// ]
```

### Issue Detection

```php
foreach ($results['potential_issues'] as $issue) {
    echo "Severity: {$issue['severity']}\n";
    echo "Issue: {$issue['message']}\n";
    echo "Details: {$issue['details']}\n\n";
}
```

Common detected issues include:
- Full table scans
- Use of temporary tables
- Filesort operations
- Inefficient LIKE patterns with leading wildcards
- Large IN clauses
- Missing WHERE clauses
- Non-indexed joins

### Optimization Suggestions

```php
foreach ($results['optimization_suggestions'] as $suggestion) {
    echo "Priority: {$suggestion['priority']}\n";
    echo "Suggestion: {$suggestion['suggestion']}\n";
    echo "Details: {$suggestion['details']}\n\n";
}
```

### Index Recommendations

```php
foreach ($results['index_recommendations'] as $recommendation) {
    echo "Table: {$recommendation['table']}\n";
    echo "Columns: " . implode(', ', $recommendation['columns']) . "\n";
    echo "Type: {$recommendation['type']}\n";
    echo "Priority: {$recommendation['priority']}\n";
    echo "Suggestion: {$recommendation['suggestion']}\n\n";
}
```

### Database Compatibility

| Feature | MySQL | PostgreSQL | SQLite |
|---------|-------|------------|--------|
| Execution Plan | ✓ | ✓ | ✓ |
| Issue Detection | ✓ | ✓ | ✓ |
| Optimization Suggestions | ✓ | ✓ | ✓ |
| Index Recommendations | ✓ | ✓ | ✓ |

## Database Profiling Tools

The database profiling tools provide comprehensive query analysis capabilities, enabling developers to measure query execution time, analyze database execution plans, and identify problematic query patterns.

### Components

#### QueryProfilerService

The QueryProfilerService executes and profiles database queries, capturing:

- Execution time
- Memory usage
- Row count
- Query parameters
- Backtrace information
- Execution status

```php
use Glueful\Database\Tools\QueryProfilerService;

$profiler = new QueryProfilerService();

// Profile a query
$results = $profiler->profile(
    "SELECT * FROM users WHERE status = ?",
    ['active'],
    function() use ($db, $status) {
        return $db->select("SELECT * FROM users WHERE status = ?", ['active']);
    }
);

// Get recent profiles
$recentProfiles = $profiler->getRecentProfiles(10, 100); // 10 profiles with min 100ms duration
```

#### ExecutionPlanAnalyzer

```php
use Glueful\Database\Tools\ExecutionPlanAnalyzer;
use Glueful\Database\Connection;

$connection = new Connection();
$analyzer = new ExecutionPlanAnalyzer($connection);

// Get and analyze a query execution plan
$plan = $analyzer->getExecutionPlan(
    "SELECT products.*, categories.name FROM products JOIN categories ON products.category_id = categories.id"
);

$analysis = $analyzer->analyzeExecutionPlan($plan);

// Output recommendations
foreach ($analysis['recommendations'] as $recommendation) {
    echo "- {$recommendation}\n";
}
```

#### QueryPatternRecognizer

```php
use Glueful\Database\Tools\QueryPatternRecognizer;

$recognizer = new QueryPatternRecognizer();

// Add a custom pattern
$recognizer->addPattern(
    'no_limit',
    '/SELECT .+ FROM .+ WHERE .+ ORDER BY .+ (?!LIMIT)/i',
    'Query with ORDER BY but no LIMIT clause',
    'Add a LIMIT clause to avoid sorting entire result sets'
);

// Analyze a query
$patterns = $recognizer->recognizePatterns(
    "SELECT id, name FROM products WHERE stock > 0 ORDER BY price DESC"
);

// Output pattern matches
foreach ($patterns as $name => $info) {
    echo "Pattern: {$name}\n";
    echo "Description: {$info['description']}\n";
    echo "Recommendation: {$info['recommendation']}\n";
}
```

### Query Profile CLI Command

The `db:profile` command provides a convenient CLI interface to profile database queries:

```bash
# Basic query profiling
php glueful db:profile --query="SELECT * FROM users WHERE email LIKE '%example.com'"

# Profile with execution plan
php glueful db:profile --query="SELECT * FROM orders JOIN order_items ON orders.id = order_items.order_id" --explain

# Profile with pattern recognition
php glueful db:profile --query="SELECT * FROM products" --patterns

# Profile from file with JSON output
php glueful db:profile --file=query.sql --explain --patterns --output=json
```

#### Options

| Option | Description |
|--------|-------------|
| `-q, --query=SQL` | SQL query to profile (required unless --file is used) |
| `-f, --file=PATH` | File containing SQL query to profile |
| `-e, --explain` | Show execution plan analysis |
| `-p, --patterns` | Detect query patterns and provide recommendations |
| `-o, --output=FORMAT` | Output format (table, json) (default: table) |

## Query Logger Optimizations

The QueryLogger class has been enhanced with several performance optimizations for high-volume environments.

### Key Optimizations

#### 1. Audit Logging Sampling

```php
// Configure to log only 10% of operations
$queryLogger->configureAuditLogging(true, 0.1);
```

#### 2. Table Name Caching

Lookup results for sensitive and audit tables are cached, eliminating redundant checks.

#### 3. Batch Processing

```php
// Enable batching with a batch size of 10
$queryLogger->configureAuditLogging(true, 1.0, true, 10);

// Manually flush any remaining batched entries when needed
$queryLogger->flushAuditLogBatch();
```

#### 4. Enhanced N+1 Query Detection

```php
// Configure N+1 detection sensitivity
$queryLogger->configureN1Detection(5, 5); // threshold, time window in seconds
```

### Performance Impact

In benchmark tests, these optimizations show significant performance improvements:

- **Table Lookup Caching**: 15-30% faster
- **10% Sampling**: 70-80% faster
- **Batched Processing**: 40-60% faster
- **All Optimizations Combined**: 90-95% faster

### Usage Example

```php
use Glueful\Database\QueryLogger;
use Glueful\Logging\LogManager;

$logger = new LogManager('app_logs');
$queryLogger = new QueryLogger($logger);

// Configure for high-volume environment
$queryLogger->configure(true, true);
$queryLogger->configureAuditLogging(
    true,     // Enable audit logging
    0.1,      // Sample 10% of operations
    true,     // Enable batching
    50        // Process in batches of 50
);

// Use the logger as normal
$queryLogger->logQuery(
    "SELECT * FROM users WHERE id = ?",
    [1],
    $queryLogger->startTiming()
);

// Don't forget to flush any remaining batched entries at the end of the request
register_shutdown_function(function() use ($queryLogger) {
    $queryLogger->flushAuditLogBatch();
});
```

### Performance Metrics

```php
$metrics = $queryLogger->getAuditPerformanceMetrics();
/*
[
    'total_operations' => 1000,
    'logged_operations' => 100,
    'skipped_operations' => 900,
    'total_audit_time' => 150.5,  // milliseconds
    'avg_audit_time' => 1.505     // milliseconds per logged operation
]
*/
```

## Session Analytics Optimization

The SessionAnalytics class provides comprehensive session tracking with performance optimizations for high-traffic applications.

### Key Features

- **Cache-optimized analytics**: Intelligent caching with configurable TTL
- **Geographic distribution analysis**: Efficient country/region tracking
- **Device and browser analytics**: Performance-optimized user agent parsing
- **Security event tracking**: Real-time suspicious activity detection
- **Memory-efficient filtering**: Optimized session aggregation and filtering

### Basic Usage

```php
use Glueful\Auth\SessionAnalytics;

// Initialize analytics service
$analytics = container()->get(SessionAnalytics::class);

// Get performance-optimized session metrics
$metrics = $analytics->getSessionMetrics($userUuid);
/*
[
    'total_sessions' => 45,
    'active_sessions' => 3,
    'average_duration' => 1847.5,
    'geographic_distribution' => [
        'US' => 25,
        'UK' => 12,
        'CA' => 8
    ],
    'device_breakdown' => [
        'desktop' => 32,
        'mobile' => 13
    ]
]
*/
```

### Advanced Analytics Features

#### Session Behavior Analysis

```php
// Analyze session patterns with caching
$patterns = $analytics->analyzeSessionPatterns($userUuid);
/*
[
    'login_frequency' => [
        'daily_avg' => 2.3,
        'peak_hours' => [9, 14, 20],
        'peak_days' => ['monday', 'wednesday']
    ],
    'session_duration_trends' => [
        'avg_duration' => 1847,
        'trend' => 'increasing',
        'variance' => 234.5
    ],
    'geographic_patterns' => [
        'primary_locations' => ['New York', 'London'],
        'travel_detected' => false
    ]
]
*/
```

#### Security Risk Assessment

```php
// Calculate security risk score efficiently
$riskScore = $analytics->calculateRiskScore($sessionId);
/*
[
    'overall_score' => 85, // 0-100 scale
    'risk_factors' => [
        'unusual_location' => false,
        'suspicious_timing' => false,
        'device_mismatch' => true,
        'multiple_concurrent' => false
    ],
    'recommendations' => [
        'Verify new device authentication',
        'Monitor for concurrent sessions'
    ]
]
*/
```

### Configuration for High Performance

```php
// config/session.php
'analytics' => [
    'enabled' => true,
    'cache_ttl' => 300, // 5 minutes for session metrics
    'geographic_cache_ttl' => 3600, // 1 hour for geographic data
    'bulk_processing_enabled' => true,
    'max_concurrent_analysis' => 10,
    'memory_limit_per_analysis' => '64M'
]
```

## API Metrics System Performance

The ApiMetricsService provides comprehensive API performance monitoring with optimization features for production environments.

### Key Features

- **Asynchronous metric recording**: Non-blocking metric collection
- **Batch processing**: Configurable batch sizes for optimal database performance
- **Daily aggregation**: Automatic data compression for long-term storage
- **Rate limiting integration**: Performance monitoring with circuit breakers
- **Memory-efficient processing**: Chunked data processing for large datasets

### Basic Usage

```php
use Glueful\Services\ApiMetricsService;

// Initialize metrics service
$metrics = container()->get(ApiMetricsService::class);

// Record API call (asynchronous)
$metrics->recordApiCall($endpoint, $method, $responseTime, $statusCode, [
    'user_id' => $userId,
    'ip_address' => $clientIp,
    'user_agent' => $userAgent
]);

// Get performance metrics
$performanceData = $metrics->getEndpointPerformance($endpoint, [
    'time_range' => '24h',
    'include_percentiles' => true
]);
```

### Performance Optimization Features

#### Asynchronous Processing

```php
// Configure async processing for high-traffic applications
$metrics->configureAsyncProcessing([
    'enabled' => true,
    'batch_size' => 100,
    'flush_interval' => 30, // seconds
    'max_memory_usage' => '128M'
]);

// Record metrics without blocking the main request
$metrics->recordApiCallAsync($endpoint, $method, $responseTime, $statusCode);
```

#### Daily Aggregation

```php
// Automatic daily aggregation reduces storage and improves query performance
$dailyStats = $metrics->getDailyAggregatedStats($date);
/*
[
    'total_requests' => 15420,
    'avg_response_time' => 245.7,
    'error_rate' => 2.3,
    'top_endpoints' => [
        '/api/users' => 3245,
        '/api/orders' => 2156
    ],
    'performance_percentiles' => [
        'p50' => 198.2,
        'p95' => 567.8,
        'p99' => 1234.5
    ]
]
*/
```

### Configuration for Production

```php
// config/api_metrics.php
'performance' => [
    'async_enabled' => true,
    'batch_processing' => true,
    'batch_size' => 500,
    'flush_interval' => 30,
    'daily_aggregation' => true,
    'retention_days' => 90,
    'memory_limit' => '256M',
    'max_concurrent_processing' => 5
]
```

## Response Caching Strategies

The ResponseCachingTrait provides multiple caching strategies optimized for different use cases and performance requirements.

### Key Features

- **Multiple caching strategies**: Response, query, fragment, and edge caching
- **Permission-aware caching**: Different TTL for user types and roles
- **ETag validation**: Efficient cache revalidation with conditional requests
- **CDN integration**: Edge cache headers for maximum performance
- **Tag-based invalidation**: Intelligent cache invalidation
- **Performance tracking**: Cache hit/miss metrics and optimization insights

### Basic Usage

```php
use Glueful\Controllers\Traits\ResponseCachingTrait;

class ProductController extends BaseController
{
    use ResponseCachingTrait;
    
    public function index(): Response
    {
        return $this->cacheResponse('products.index', 3600, function() {
            $products = $this->productService->getAllProducts();
            return Response::success($products, 'Products retrieved');
        });
    }
}
```

### Advanced Caching Strategies

#### Permission-Aware Caching

```php
// Different cache TTL based on user permissions
public function getProducts(): Response
{
    $cacheKey = $this->getPermissionAwareCacheKey('products', auth()->user());
    $ttl = auth()->user()->hasRole('admin') ? 1800 : 3600; // Shorter cache for admins
    
    return $this->cacheResponse($cacheKey, $ttl, function() {
        return $this->productService->getProductsForUser(auth()->user());
    });
}
```

#### CDN Edge Caching

```php
// Optimize for CDN edge caching
public function getPublicContent(): Response
{
    return $this->cacheForCDN('public.content', 7200, function() {
        return $this->contentService->getPublicContent();
    }, [
        'vary_headers' => ['Accept-Language'],
        'edge_ttl' => 3600,
        'browser_ttl' => 1800
    ]);
}
```

### Cache Invalidation Strategies

```php
// Cache with tags for intelligent invalidation
public function getOrderSummary(int $orderId): Response
{
    return $this->cacheWithTags(
        "order.summary.{$orderId}", 
        1800,
        ['orders', "order.{$orderId}", "user." . auth()->id()],
        function() use ($orderId) {
            return $this->orderService->getOrderSummary($orderId);
        }
    );
}
```

### Configuration

```php
// config/cache.php
'response_caching' => [
    'enabled' => true,
    'default_ttl' => 3600,
    'permission_aware' => true,
    'cdn_integration' => true,
    'etag_validation' => true,
    'performance_tracking' => true
]
```

## Memory Management Features

Glueful includes comprehensive memory management features to optimize performance and prevent memory issues in production environments.

### MemoryManager

The MemoryManager class provides real-time memory monitoring and management capabilities.

#### Basic Usage

```php
use Glueful\Performance\MemoryManager;

$memoryManager = new MemoryManager();

// Get current memory usage
$usage = $memoryManager->getCurrentUsage();
/*
[
    'current' => '64MB',
    'peak' => '89MB',
    'limit' => '256MB',
    'percentage' => 25.0
]
*/

// Check memory thresholds
if ($memoryManager->isMemoryWarning()) {
    // Implement memory cleanup strategies
    $this->performMemoryCleanup();
}

if ($memoryManager->isMemoryCritical()) {
    // Emergency memory management
    $this->emergencyMemoryCleanup();
}
```

### MemoryPool

The MemoryPool class provides efficient object pooling to reduce memory allocation overhead.

```php
use Glueful\Performance\MemoryPool;

$pool = new MemoryPool();

// Acquire and release resources
$resource = $pool->acquire('database_connections');
try {
    // Use the resource
    $results = $resource->query($sql);
} finally {
    $pool->release('database_connections', $resource);
}
```

### ChunkedDatabaseProcessor

Process large datasets efficiently with minimal memory usage.

```php
use Glueful\Performance\ChunkedDatabaseProcessor;

$processor = new ChunkedDatabaseProcessor($connection, 1000);

// Process large result sets in chunks
$totalProcessed = $processor->processSelectQuery(
    "SELECT * FROM users WHERE status = ? AND created_at > ?",
    function($rows) {
        foreach ($rows as $row) {
            $this->processUser($row);
        }
        return count($rows);
    },
    ['active', '2024-01-01'],
    500 // chunk size
);
```

### Configuration

```php
// config/performance.php
'memory_management' => [
    'monitoring_enabled' => true,
    'warning_threshold' => '128M',
    'critical_threshold' => '200M',
    'auto_cleanup_enabled' => true,
    'pool_size_limits' => [
        'default' => 100,
        'database_connections' => 50,
        'api_clients' => 25
    ],
    'chunked_processing' => [
        'default_chunk_size' => 1000,
        'max_chunk_size' => 10000,
        'memory_limit' => '256M'
    ]
]
```

## Best Practices

### When to Use Performance Optimization

Performance optimization is most beneficial for:

1. **Complex queries** with multiple joins, subqueries, or aggregations
2. **Recurring queries** executed frequently in your application
3. **Performance-critical paths** where response time is crucial
4. **Large dataset operations** where efficiency gains are multiplied

### When Not to Use Performance Optimization

Performance optimization may not be worthwhile for:

1. **Simple queries** that are already efficient
2. **One-time queries** or administrative operations
3. **Queries handling very small datasets** where the overhead isn't justified

### General Guidelines

1. **Start with query analysis** before applying optimizations
2. **Use caching for read-heavy operations** on stable data
3. **Monitor performance metrics** to validate improvements
4. **Apply optimizations systematically** rather than randomly
5. **Test in staging environments** before deploying to production

### Production Considerations

For production environments:

```php
// config/database.php
'profiler' => [
    'enabled' => env('DB_PROFILER_ENABLED', false),
    'threshold' => env('DB_PROFILER_THRESHOLD', 100), // milliseconds
    'sampling_rate' => env('DB_PROFILER_SAMPLING', 0.05), // 5% of queries
    'max_profiles' => env('DB_PROFILER_MAX_PROFILES', 100),
],

'query_cache' => [
    'enabled' => env('QUERY_CACHE_ENABLED', true),
    'default_ttl' => env('QUERY_CACHE_TTL', 3600),
    'exclude_tables' => ['logs', 'sessions', 'cache'],
]
```

## Performance Metrics

### Query Performance Metrics

The optimization features provide detailed performance metrics:

- **High-traffic applications**: 30-50% reduction in database load
- **Complex queries**: 50-95% improvement in response time for cached queries
- **API endpoints**: Consistent response times during peak loads

### Monitoring Optimization Effectiveness

```php
use Glueful\Logging\Logger;

function logQueryOptimization($query, $result)
{
    $logger = new Logger('query-optimization');
    
    $logger->info('Query optimization result', [
        'original_query' => $result['original_query'],
        'optimized_query' => $result['optimized_query'],
        'estimated_improvement' => $result['estimated_improvement'],
        'suggestions_count' => count($result['suggestions'])
    ]);
    
    if ($result['estimated_improvement']['execution_time'] > 30) {
        // Log high-impact optimizations separately
        $logger->notice('High-impact query optimization', [
            'original_query' => $result['original_query'],
            'optimized_query' => $result['optimized_query'],
            'estimated_improvement' => $result['estimated_improvement'],
            'suggestions' => $result['suggestions']
        ]);
    }
}

// Usage
$result = $optimizer->optimizeQuery($query);
logQueryOptimization($query, $result);
```

## Troubleshooting

### Optimization Not Improving Performance

If optimization isn't yielding expected improvements:

1. **Verify database indexes**: The optimizer can suggest indexes but can't create them
2. **Check query complexity**: Some queries may already be optimized
3. **Database configuration**: Server settings may limit optimization benefits
4. **Data volume**: Benefits often increase with data volume

### Incorrect Results After Optimization

If the optimized query returns different results:

1. **Verify query semantics**: Ensure the optimized query maintains the original logic
2. **Check for edge cases**: Some optimizations may not handle all edge cases
3. **Database-specific behaviors**: Different databases may interpret SQL constructs differently

### Performance Regression

If optimization causes performance regression:

1. **Analyze the execution plan**: Compare execution plans of original and optimized queries
2. **Consider database statistics**: Ensure database statistics are up-to-date
3. **Query complexity**: Very complex queries might confuse the optimizer

### Cache Issues

Common cache-related issues:

1. **Cache not invalidating**: Check cache tags and invalidation logic
2. **Memory usage**: Monitor cache size and implement appropriate TTL values
3. **Cache misses**: Verify cache key generation for parameterized queries

### Debugging

When debugging cached queries, enable debug mode:

```php
$results = $queryBuilder
    ->enableDebug(true)
    ->select('products', ['*'])
    ->cache()
    ->get();
```

This will log cache hits, misses, and other cache-related operations to help identify potential issues.

---

This comprehensive guide covers all aspects of database performance optimization in Glueful. For specific implementation details and advanced configuration options, refer to the individual component documentation and source code.# Memory Management

This guide provides comprehensive documentation for Glueful's memory management and optimization features. It consolidates all memory-related tools and techniques into a single reference.

## Table of Contents

1. [Memory Manager](#memory-manager)
2. [Memory Alerting Service](#memory-alerting-service)
3. [Memory Efficient Iterators](#memory-efficient-iterators)
4. [Memory Pool](#memory-pool)
5. [Memory Monitor Command](#memory-monitor-command)
6. [Memory Tracking Middleware](#memory-tracking-middleware)
7. [Chunked Database Processor](#chunked-database-processor)
8. [Lazy Container](#lazy-container)

---

## Memory Manager

The Memory Manager provides advanced memory monitoring, tracking, and management capabilities with configurable thresholds and automatic garbage collection.

### Features

- **Real-time Memory Monitoring**: Track current, peak, and limit usage
- **Automatic Garbage Collection**: Trigger collection based on configurable thresholds
- **Memory State Tracking**: Monitor allocation patterns and system health
- **Configurable Alerts**: Set custom thresholds for different memory states

### Basic Usage

```php
use Glueful\API\Performance\MemoryManager;

// Initialize with custom configuration
$memoryManager = new MemoryManager([
    'warning_threshold' => 0.75,  // 75% of memory limit
    'critical_threshold' => 0.9,  // 90% of memory limit
    'auto_gc_threshold' => 0.8,   // Auto GC at 80%
    'enable_detailed_tracking' => true
]);

// Monitor memory usage
$usage = $memoryManager->getMemoryUsage();
echo "Current: {$usage['current_mb']}MB, Peak: {$usage['peak_mb']}MB";

// Check memory state
$state = $memoryManager->getMemoryState();
if ($state === MemoryManager::STATE_CRITICAL) {
    // Handle critical memory situation
    $memoryManager->emergencyCleanup();
}
```

### Configuration Options

```php
$config = [
    'warning_threshold' => 0.75,        // Warning at 75% usage
    'critical_threshold' => 0.9,        // Critical at 90% usage
    'auto_gc_threshold' => 0.8,         // Auto GC at 80% usage
    'enable_detailed_tracking' => true, // Track allocation patterns
    'gc_probability' => 0.1,            // 10% chance of GC per check
    'emergency_threshold' => 0.95       // Emergency cleanup at 95%
];
```

### Memory States

- **NORMAL**: Memory usage below warning threshold
- **WARNING**: Usage between warning and critical thresholds
- **CRITICAL**: Usage above critical threshold
- **EMERGENCY**: Usage above emergency threshold

### Advanced Features

```php
// Set custom memory limits
$memoryManager->setMemoryLimit('512M');

// Force garbage collection
$memoryManager->forceGarbageCollection();

// Get detailed memory statistics
$stats = $memoryManager->getDetailedStats();
print_r($stats);

// Register memory state change callbacks
$memoryManager->onStateChange(function($oldState, $newState) {
    error_log("Memory state changed from {$oldState} to {$newState}");
});
```

---

## Memory Alerting Service

The Memory Alerting Service provides intelligent memory monitoring with configurable thresholds, alert channels, and automatic escalation.

### Features

- **Multi-Channel Alerting**: Email, Slack, webhook notifications
- **Intelligent Throttling**: Prevent alert spam with configurable intervals
- **Escalation Policies**: Automatic escalation for critical situations
- **Historical Tracking**: Maintain alert history and patterns

### Basic Setup

```php
use Glueful\API\Performance\MemoryAlertingService;

$alertService = new MemoryAlertingService([
    'channels' => [
        'email' => [
            'enabled' => true,
            'recipients' => ['admin@example.com', 'ops@example.com'],
            'threshold' => 'warning'
        ],
        'slack' => [
            'enabled' => true,
            'webhook_url' => 'https://hooks.slack.com/...',
            'channel' => '#alerts',
            'threshold' => 'critical'
        ]
    ],
    'thresholds' => [
        'warning' => 75,   // 75% memory usage
        'critical' => 90,  // 90% memory usage
        'emergency' => 95  // 95% memory usage
    ]
]);

// Check memory and send alerts if needed
$alertService->checkAndAlert();
```

### Alert Channels

#### Email Alerts

```php
$emailConfig = [
    'enabled' => true,
    'recipients' => ['admin@example.com'],
    'threshold' => 'warning',
    'throttle_minutes' => 15,
    'template' => 'memory_alert'
];
```

#### Slack Alerts

```php
$slackConfig = [
    'enabled' => true,
    'webhook_url' => 'https://hooks.slack.com/services/...',
    'channel' => '#alerts',
    'username' => 'Glueful Monitor',
    'threshold' => 'critical',
    'throttle_minutes' => 5
];
```

#### Webhook Alerts

```php
$webhookConfig = [
    'enabled' => true,
    'url' => 'https://your-monitoring-system.com/alerts',
    'threshold' => 'warning',
    'timeout' => 30,
    'retry_attempts' => 3
];
```

### Escalation Policies

```php
$escalationConfig = [
    'enabled' => true,
    'levels' => [
        1 => ['email' => ['admin@example.com']],
        2 => ['email' => ['manager@example.com'], 'slack' => true],
        3 => ['webhook' => 'https://pager-duty.com/...']
    ],
    'escalation_intervals' => [5, 15, 30] // minutes
];
```

### Advanced Configuration

```php
$advancedConfig = [
    'history_retention_days' => 30,
    'alert_cooldown_minutes' => 10,
    'batch_alerts' => true,
    'include_system_info' => true,
    'custom_metrics' => [
        'cpu_usage' => true,
        'disk_usage' => true,
        'active_connections' => true
    ]
];
```

---

## Memory Efficient Iterators

Memory efficient iterators for processing large datasets without loading everything into memory.

### StreamingIterator

Process large datasets chunk by chunk:

```php
use Glueful\API\Performance\StreamingIterator;

$iterator = new StreamingIterator($dataSource, [
    'chunk_size' => 1000,
    'memory_limit' => '128M',
    'auto_gc' => true
]);

foreach ($iterator as $chunk) {
    // Process each chunk (array of 1000 items)
    foreach ($chunk as $item) {
        processItem($item);
    }
    
    // Memory is automatically managed
    unset($chunk);
}
```

### LazyIterator

Load items on-demand:

```php
use Glueful\API\Performance\LazyIterator;

$lazyIterator = new LazyIterator(function($offset, $limit) {
    return $database->getRecords($offset, $limit);
}, [
    'batch_size' => 500,
    'prefetch' => true
]);

foreach ($lazyIterator as $item) {
    // Items are loaded on-demand
    processItem($item);
}
```

### FilteredIterator

Apply filters without loading entire dataset:

```php
use Glueful\API\Performance\FilteredIterator;

$filteredIterator = new FilteredIterator($sourceIterator, [
    'filters' => [
        function($item) { return $item['status'] === 'active'; },
        function($item) { return $item['score'] > 50; }
    ],
    'early_exit' => true
]);

foreach ($filteredIterator as $item) {
    // Only items passing all filters
    processActiveHighScoreItem($item);
}
```

### Configuration Options

```php
$config = [
    'chunk_size' => 1000,           // Items per chunk
    'memory_limit' => '128M',       // Memory limit per chunk
    'auto_gc' => true,              // Automatic garbage collection
    'prefetch' => false,            // Prefetch next chunk
    'cache_chunks' => false,        // Cache processed chunks
    'parallel_processing' => false, // Process chunks in parallel
    'error_handling' => 'continue'  // 'continue', 'stop', 'retry'
];
```

---

## Memory Pool

Object storage and reuse system to reduce memory allocation overhead.

### Features

- **Object Pooling**: Reuse expensive objects
- **Automatic Cleanup**: Remove stale objects
- **Type Safety**: Strongly typed object pools
- **Statistics**: Monitor pool usage and efficiency

### Basic Usage

```php
use Glueful\API\Performance\MemoryPool;

// Create a pool for database connections
$connectionPool = new MemoryPool([
    'factory' => function() {
        return new DatabaseConnection($config);
    },
    'max_size' => 10,
    'min_size' => 2,
    'max_idle_time' => 300 // 5 minutes
]);

// Get object from pool
$connection = $connectionPool->acquire();

// Use the connection
$result = $connection->query('SELECT * FROM users');

// Return to pool
$connectionPool->release($connection);
```

### Typed Pools

```php
use Glueful\API\Performance\TypedMemoryPool;

class DatabaseConnectionPool extends TypedMemoryPool
{
    protected function createObject(): DatabaseConnection
    {
        return new DatabaseConnection($this->config);
    }
    
    protected function resetObject($object): void
    {
        $object->rollback(); // Reset state
        $object->clearCache();
    }
    
    protected function validateObject($object): bool
    {
        return $object->isConnected();
    }
}

$pool = new DatabaseConnectionPool(['max_size' => 15]);
```

### Pool Configuration

```php
$config = [
    'max_size' => 10,           // Maximum pool size
    'min_size' => 2,            // Minimum pool size
    'max_idle_time' => 300,     // Max idle time (seconds)
    'validation_interval' => 60, // Validation check interval
    'auto_cleanup' => true,     // Automatic cleanup
    'statistics' => true        // Enable statistics
];
```

### Pool Statistics

```php
$stats = $pool->getStatistics();
echo "Active: {$stats['active']}, Idle: {$stats['idle']}";
echo "Hit Rate: {$stats['hit_rate']}%";
echo "Created: {$stats['total_created']}, Destroyed: {$stats['total_destroyed']}";
```

---

## Memory Monitor Command

CLI tool for monitoring and analyzing memory usage patterns.

### Basic Usage

```bash
# Real-time memory monitoring
php glueful memory:monitor

# Monitor with custom interval
php glueful memory:monitor --interval=5

# Monitor specific process
php glueful memory:monitor --pid=1234

# Export monitoring data
php glueful memory:monitor --export=memory_report.json
```

### Command Options

```bash
# Monitoring options
--interval=N      # Check interval in seconds (default: 1)
--duration=N      # Monitor for N seconds (default: unlimited)
--threshold=N     # Alert threshold percentage (default: 80)
--pid=N          # Monitor specific process ID

# Output options
--format=FORMAT   # Output format: table, json, csv (default: table)
--export=FILE     # Export data to file
--quiet          # Suppress real-time output
--verbose        # Show detailed information

# Alert options
--email=ADDRESS   # Send alerts to email
--webhook=URL     # Send alerts to webhook
--slack=URL       # Send alerts to Slack
```

### Real-time Monitoring

```bash
# Display live memory usage
php glueful memory:monitor --interval=1 --format=table

┌─────────────────┬──────────────┬─────────────┬─────────────┐
│ Time            │ Current (MB) │ Peak (MB)   │ Limit (MB)  │
├─────────────────┼──────────────┼─────────────┼─────────────┤
│ 2023-10-15 14:30│ 245.7       │ 267.3       │ 512.0       │
│ 2023-10-15 14:31│ 248.2       │ 267.3       │ 512.0       │
│ 2023-10-15 14:32│ 251.8       │ 267.3       │ 512.0       │
└─────────────────┴──────────────┴─────────────┴─────────────┘
```

### Memory Analysis

```bash
# Generate comprehensive memory report
php glueful memory:monitor --duration=300 --export=analysis.json

# Analyze memory patterns
php glueful memory:analyze analysis.json

Memory Usage Analysis Report
============================
Average Usage: 245.7 MB
Peak Usage: 312.4 MB
Memory Efficiency: 87.3%
Potential Issues: 2 memory spikes detected
```

### Integration with Monitoring Systems

```bash
# Send alerts to external systems
php glueful memory:monitor \
    --threshold=85 \
    --webhook=https://monitoring.example.com/alerts \
    --email=ops@example.com
```

---

## Memory Tracking Middleware

HTTP middleware for tracking memory usage per request with detailed analytics.

### Features

- **Per-Request Tracking**: Monitor memory usage for each HTTP request
- **Detailed Analytics**: Track allocation patterns and peak usage
- **Performance Impact Analysis**: Correlate memory usage with response times
- **Configurable Reporting**: Flexible logging and alerting options

### Basic Setup

```php
use Glueful\API\Http\Middleware\MemoryTrackingMiddleware;

$middleware = new MemoryTrackingMiddleware([
    'enabled' => true,
    'track_peak' => true,
    'log_high_usage' => true,
    'threshold_mb' => 50,
    'detailed_tracking' => false
]);

// Add to middleware stack
$app->add($middleware);
```

### Configuration Options

```php
$config = [
    'enabled' => true,              // Enable/disable tracking
    'track_peak' => true,           // Track peak memory usage
    'log_high_usage' => true,       // Log requests with high usage
    'threshold_mb' => 50,           // High usage threshold (MB)
    'detailed_tracking' => false,   // Enable detailed allocation tracking
    'include_headers' => true,      // Add memory info to response headers
    'log_file' => 'memory.log',     // Custom log file
    'sample_rate' => 1.0            // Sampling rate (0.0-1.0)
];
```

### Response Headers

When enabled, adds memory information to response headers:

```http
X-Memory-Usage: 45.7
X-Memory-Peak: 52.3
X-Memory-Limit: 128.0
X-Memory-Efficiency: 89.2
```

### Detailed Tracking

```php
$middleware = new MemoryTrackingMiddleware([
    'detailed_tracking' => true,
    'track_allocations' => true,
    'track_deallocations' => true,
    'include_stack_traces' => false
]);
```

### Custom Handlers

```php
$middleware->setHighUsageHandler(function($usage, $request) {
    // Custom handling for high memory usage
    if ($usage > 100) {
        $alertService->sendAlert("High memory usage: {$usage}MB");
    }
});

$middleware->setAnalyticsHandler(function($analytics) {
    // Send analytics to monitoring system
    $metricsService->recordMemoryMetrics($analytics);
});
```

---

## Chunked Database Processor

Process large database result sets in memory-efficient chunks.

### Features

- **Chunked Processing**: Process large datasets without memory issues
- **Configurable Chunk Sizes**: Optimize for your specific use case
- **Progress Tracking**: Monitor processing progress
- **Error Handling**: Robust error handling and recovery

### Basic Usage

```php
use Glueful\API\Performance\ChunkedDatabaseProcessor;

$processor = new ChunkedDatabaseProcessor($connection, [
    'chunk_size' => 1000,
    'memory_limit' => '128M',
    'progress_callback' => function($processed, $total) {
        echo "Processed: {$processed}/{$total}\n";
    }
]);

// Process large dataset
$processor->process(
    'SELECT * FROM large_table WHERE active = 1',
    function($row) {
        // Process each row
        updateUserRecord($row);
    }
);
```

### Advanced Processing

```php
// Process with custom query builder
$processor->processQuery(
    $queryBuilder->select('*')->from('users')->where('status', 'active'),
    function($batch) {
        // Process entire batch
        foreach ($batch as $user) {
            sendNotification($user);
        }
    },
    [
        'batch_size' => 500,
        'parallel' => true
    ]
);
```

### Configuration Options

```php
$config = [
    'chunk_size' => 1000,           // Records per chunk
    'memory_limit' => '128M',       // Memory limit per chunk
    'timeout' => 300,               // Query timeout (seconds)
    'retry_attempts' => 3,          // Retry failed chunks
    'parallel_chunks' => 1,         // Process chunks in parallel
    'progress_callback' => null,    // Progress callback function
    'error_callback' => null        // Error callback function
];
```

### Progress Tracking

```php
$processor->setProgressCallback(function($stats) {
    $percentage = ($stats['processed'] / $stats['total']) * 100;
    echo "Progress: {$percentage}% ({$stats['processed']}/{$stats['total']})\n";
    echo "Memory: {$stats['memory_usage']}MB\n";
    echo "ETA: {$stats['estimated_remaining']} seconds\n";
});
```

### Error Handling

```php
$processor->setErrorCallback(function($error, $chunk) {
    error_log("Error processing chunk {$chunk}: {$error->getMessage()}");
    
    // Return true to continue, false to stop
    return true;
});
```

---

## Lazy Container

Deferred object creation container for improved memory efficiency and performance.

### Features

- **Lazy Loading**: Create objects only when needed
- **Dependency Injection**: Automatic dependency resolution
- **Circular Dependency Detection**: Prevent infinite loops
- **Performance Optimization**: Reduce startup memory and time

### Basic Usage

```php
use Glueful\API\Performance\LazyContainer;

$container = new LazyContainer();

// Register lazy services
$container->lazy('database', function() {
    return new DatabaseConnection($config);
});

$container->lazy('userService', function($container) {
    return new UserService($container->get('database'));
});

// Objects are created only when first accessed
$userService = $container->get('userService'); // Database connection created here
```

### Service Registration

```php
// Simple factory
$container->lazy('logger', function() {
    return new Logger('app');
});

// With dependencies
$container->lazy('emailService', function($container) {
    return new EmailService(
        $container->get('logger'),
        $container->get('config')
    );
});

// Singleton services
$container->singleton('cache', function() {
    return new RedisCache($config);
});
```

### Configuration

```php
$container = new LazyContainer([
    'auto_wire' => true,            // Automatic dependency injection
    'circular_detection' => true,   // Detect circular dependencies
    'cache_instances' => true,      // Cache created instances
    'debug_mode' => false          // Debug dependency resolution
]);
```

### Advanced Features

```php
// Conditional services
$container->lazy('paymentProcessor', function($container) {
    $config = $container->get('config');
    
    if ($config->get('payment.provider') === 'stripe') {
        return new StripeProcessor($config);
    }
    
    return new PayPalProcessor($config);
});

// Service aliases
$container->alias('db', 'database');
$container->alias('log', 'logger');

// Service tags
$container->tag('emailService', ['notification', 'communication']);
$container->tag('smsService', ['notification', 'communication']);

// Get all services with tag
$notificationServices = $container->getByTag('notification');
```

### Performance Monitoring

```php
// Monitor container performance
$stats = $container->getStatistics();
echo "Services created: {$stats['created']}\n";
echo "Services cached: {$stats['cached']}\n";
echo "Average creation time: {$stats['avg_creation_time']}ms\n";
echo "Memory saved: {$stats['memory_saved']}MB\n";
```

---

## Best Practices

### Memory Management Guidelines

1. **Monitor Continuously**: Use real-time monitoring for production systems
2. **Set Appropriate Thresholds**: Configure warnings before critical situations
3. **Use Object Pooling**: Reuse expensive objects when possible
4. **Process in Chunks**: Handle large datasets with chunked processing
5. **Lazy Load Resources**: Create objects only when needed

### Performance Optimization

1. **Choose Right Tools**: Select appropriate iterator for your use case
2. **Configure Limits**: Set memory limits for all processing tasks
3. **Enable Alerting**: Get notified before problems occur
4. **Track Metrics**: Monitor memory patterns and trends
5. **Regular Cleanup**: Implement automated cleanup processes

### Common Patterns

```php
// Combine multiple tools for optimal performance
$container = new LazyContainer();
$pool = new MemoryPool(['max_size' => 10]);
$processor = new ChunkedDatabaseProcessor($connection);

// Process large dataset with pooled connections
$processor->process($query, function($batch) use ($pool) {
    $worker = $pool->acquire();
    try {
        $worker->processBatch($batch);
    } finally {
        $pool->release($worker);
    }
});
```

This comprehensive memory management system provides all the tools needed to build memory-efficient, scalable applications with Glueful.

---

## Tracing and Observability

### Request Tracing

Glueful includes built-in request tracing capabilities for monitoring request flows and performance bottlenecks.

#### Tracing Middleware

```php
use Glueful\Http\Middleware\TracingMiddleware;

// Add tracing middleware to your application
$app->add(new TracingMiddleware([
    'enabled' => true,
    'sample_rate' => 1.0,  // Trace 100% of requests
    'trace_queries' => true,
    'trace_cache' => true,
    'trace_external_calls' => true
]));
```

#### Custom Tracing

```php
use Glueful\Tracing\Tracer;

// Start a trace span
$span = Tracer::startSpan('user_lookup');

try {
    $user = $userService->findById($userId);
    $span->setTag('user_id', $userId);
    $span->setTag('user_status', $user->status);
} catch (Exception $e) {
    $span->setTag('error', true);
    $span->setTag('error_message', $e->getMessage());
    throw $e;
} finally {
    $span->finish();
}
```

#### Distributed Tracing

```php
// For microservices, bind a tracer adapter
use Glueful\Tracing\Adapters\JaegerAdapter;

$tracer = new JaegerAdapter([
    'service_name' => 'glueful-api',
    'jaeger_endpoint' => 'http://localhost:14268/api/traces'
]);

Tracer::setAdapter($tracer);
```

### Performance Benchmarks in CI

Glueful includes comprehensive benchmarking tools for continuous performance monitoring in CI/CD pipelines.

#### Benchmark Configuration

```php
// tests/benchmarks/config.php
return [
    'benchmarks' => [
        'api_responses' => [
            'target' => 'ResponseBenchmark',
            'budget' => [
                'max_response_time' => 50, // milliseconds
                'min_throughput' => 1000   // requests per second
            ]
        ],
        'database_queries' => [
            'target' => 'DatabaseBenchmark', 
            'budget' => [
                'max_query_time' => 100,   // milliseconds
                'max_memory_usage' => 50   // MB
            ]
        ]
    ],
    'reporting' => [
        'formats' => ['json', 'junit'],
        'export_path' => 'benchmark-results/',
        'compare_baseline' => true
    ]
];
```

#### Running Benchmarks

```bash
# Run all benchmarks
php glueful benchmark:run

# Run specific benchmark
php glueful benchmark:run --suite=api_responses

# Run with budget enforcement
php glueful benchmark:run --enforce-budget

# Export results
php glueful benchmark:run --export=json --output=results.json
```

#### CI Integration

Example GitHub Actions workflow:

```yaml
# .github/workflows/benchmarks.yml
name: Performance Benchmarks

on: [push, pull_request]

jobs:
  benchmark:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          
      - name: Install Dependencies
        run: composer install
        
      - name: Run Benchmarks
        run: |
          php glueful benchmark:run --enforce-budget --export=json
          
      - name: Upload Results
        uses: actions/upload-artifact@v3
        with:
          name: benchmark-results
          path: benchmark-results/
```

#### Custom Benchmark Tests

```php
use Glueful\Testing\BenchmarkTestCase;

class ResponseBenchmark extends BenchmarkTestCase
{
    /**
     * @benchmark
     */
    public function benchmarkUserResponse()
    {
        $this->startTiming();
        
        $response = $this->get('/api/users/123');
        
        $this->endTiming();
        
        $this->assertLessThan(50, $this->getExecutionTime()); // ms
        $this->assertLessThan(10, $this->getMemoryUsage());   // MB
    }
    
    /**
     * @benchmark(iterations=1000)
     */
    public function benchmarkThroughput()
    {
        $this->measureThroughput(function() {
            return $this->get('/api/health');
        });
        
        $this->assertGreaterThan(1000, $this->getThroughput()); // ops/sec
    }
}
```

### Monitoring Dashboards

Glueful provides guidance and examples for setting up monitoring dashboards using popular tools.

#### Metrics Export

```php
// Export metrics for external monitoring systems
use Glueful\Monitoring\MetricsExporter;

$exporter = new MetricsExporter([
    'format' => 'prometheus',  // prometheus, grafana, datadog
    'endpoint' => '/metrics',
    'include_system_metrics' => true
]);

// Register metrics endpoint
Router::get('/metrics', [$exporter, 'export']);
```

#### Dashboard Configuration Examples

**Grafana Dashboard JSON:**
```json
{
  "dashboard": {
    "title": "Glueful API Metrics",
    "panels": [
      {
        "title": "Request Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(http_requests_total[5m])",
            "legendFormat": "Requests/sec"
          }
        ]
      },
      {
        "title": "Response Time",
        "type": "graph", 
        "targets": [
          {
            "expr": "histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[5m]))",
            "legendFormat": "95th percentile"
          }
        ]
      }
    ]
  }
}
```

**Prometheus Configuration:**
```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'glueful-api'
    static_configs:
      - targets: ['localhost:8000']
    metrics_path: '/metrics'
    scrape_interval: 15s
```

#### Key Metrics to Monitor

1. **Request Metrics**
   - Request rate (requests/second)
   - Response time percentiles (50th, 95th, 99th)
   - Error rate by status code
   - Endpoint-specific performance

2. **System Metrics**
   - Memory usage and trends
   - CPU utilization
   - Database connection pool status
   - Cache hit/miss ratios

3. **Business Metrics**
   - User registration rate
   - API endpoint usage patterns
   - Feature adoption metrics
   - Performance budget compliance

#### Alert Configuration

```php
// Configure alerts for critical metrics
use Glueful\Monitoring\AlertManager;

$alertManager = new AlertManager([
    'channels' => ['email', 'slack', 'webhook'],
    'rules' => [
        [
            'name' => 'High Response Time',
            'condition' => 'avg_response_time > 500',  // ms
            'duration' => '5m',
            'severity' => 'warning'
        ],
        [
            'name' => 'High Error Rate', 
            'condition' => 'error_rate > 5',           // %
            'duration' => '2m',
            'severity' => 'critical'
        ]
    ]
]);
```

This comprehensive observability system provides complete visibility into your Glueful application's performance and behavior in production environments.