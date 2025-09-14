# Glueful Database Advanced Features

This comprehensive guide covers Glueful's advanced database capabilities, including connection pooling, query optimization, performance monitoring, and enterprise-grade features for high-performance applications.

## Table of Contents

1. [Overview](#overview)
2. [Connection Pooling](#connection-pooling)
3. [Query Builder Advanced Features](#query-builder-advanced-features)
4. [Query Optimization](#query-optimization)
5. [Performance Monitoring](#performance-monitoring)
6. [Query Logging and Analytics](#query-logging-and-analytics)
7. [Database Driver System](#database-driver-system)
8. [Advanced Query Patterns](#advanced-query-patterns)
9. [Transaction Management](#transaction-management)
10. [Configuration](#configuration)
11. [Production Optimization](#production-optimization)

## Overview

Glueful's database system provides enterprise-grade features designed for high-performance applications with advanced capabilities including:

### Key Features

- **Connection Pooling**: Multi-engine connection pool management with health monitoring and async maintenance
- **Modular Query Builder**: Orchestrator pattern with 34+ specialized components replacing monolithic design
- **Advanced Monitoring**: N+1 detection, pattern recognition, execution plan analysis, and profiling
- **Multi-Database Support**: MySQL, PostgreSQL, and SQLite with driver-specific optimizations and SSL support
- **Transaction Management**: Nested transactions with savepoints and automatic deadlock retry logic
- **Query Optimization**: Automatic analysis, pattern recognition, and database-specific optimizations
- **Migration System**: Complete schema management with batch tracking and checksum verification
- **Production Features**: Query caching, soft deletes, pagination, connection leak detection

### Architecture Components

1. **ConnectionPoolManager**: Multi-engine pool orchestration with global statistics and performance scoring
2. **QueryBuilder**: Lightweight orchestrator coordinating modular components via dependency injection
3. **DevelopmentQueryMonitor**: Real-time N+1 detection and slow query analysis
4. **QueryProfilerService**: Sampling-based profiling with configurable thresholds
5. **ExecutionPlanAnalyzer**: EXPLAIN plan analysis with optimization recommendations
6. **MigrationManager**: Full migration lifecycle with transaction safety and rollback support
7. **TransactionManager**: Enterprise-grade transaction handling with savepoint management
8. **Schema Builders**: Complete schema building system with database-agnostic SQL generation

## Connection Pooling

### Overview

Glueful's connection pooling system provides efficient connection management with automatic lifecycle handling, health monitoring, and statistics tracking.

### Basic Usage

```php
use Glueful\Database\Connection;

// Recommended: use the framework connection (pooling is automatic if enabled in config)
$db = container()->get('database'); // or: $db = new Connection();

// Fluent query via QueryBuilder
$results = $db
    ->table('users')
    ->select(['*'])
    ->where(['active' => 1])
    ->get();

// Advanced: access the underlying pool (rarely needed)
// Note: the pool manager is initialized after a Connection is created when pooling is enabled
$poolManager = Glueful\Database\Connection::getPoolManager();
if ($poolManager) {
    $mysqlPool = $poolManager->getPool('mysql');
    $pooled = $mysqlPool->acquire();
    try {
        // Low-level access, e.g., health checks via $pooled->query('SELECT 1')
    } finally {
        $mysqlPool->release($pooled);
    }
}
```

### Pool Configuration

```php
// config/database.php
return [
    'pooling' => [
        'enabled' => env('DB_POOLING_ENABLED', true),
        'defaults' => [
            'min_connections' => env('DB_POOL_MIN_CONNECTIONS', 2),
            'max_connections' => env('DB_POOL_MAX_CONNECTIONS', 10),
            'idle_timeout' => env('DB_POOL_IDLE_TIMEOUT', 300),
            'max_lifetime' => env('DB_POOL_MAX_LIFETIME', 3600),
            'acquisition_timeout' => env('DB_POOL_ACQUIRE_TIMEOUT', 30),
            'health_check_interval' => env('DB_POOL_HEALTH_CHECK_INTERVAL', 60),
            'health_check_timeout' => env('DB_POOL_HEALTH_CHECK_TIMEOUT', 5),
            'max_use_count' => env('DB_POOL_MAX_USE_COUNT', 1000),
            'retry_attempts' => env('DB_POOL_RETRY_ATTEMPTS', 3),
            'retry_delay' => env('DB_POOL_RETRY_DELAY', 100),
        ],
        'engines' => [
            'mysql' => [ 'max_connections' => 20, 'min_connections' => 5 ],
            'pgsql' => [ 'max_connections' => 15, 'min_connections' => 3 ],
            'sqlite' => [ 'max_connections' => 1, 'min_connections' => 1 ],
        ]
    ]
];
```

### Pool Statistics and Monitoring

```php
// Get statistics for all pools
$stats = $poolManager->getStats();
/*
[
    'mysql' => [
        'active_connections' => 12,
        'idle_connections' => 3,
        'total_connections' => 15,
        'total_created' => 45,
        'total_destroyed' => 30,
        'total_acquisitions' => 1250,
        'total_releases' => 1238,
        'total_timeouts' => 2,
        'total_health_checks' => 120,
        'failed_health_checks' => 0
    ]
]
*/

// Get aggregate statistics
$aggregate = $poolManager->getAggregateStats();

// Get health status
$health = $poolManager->getHealthStatus();
/*
[
    'mysql' => [
        'healthy' => true,
        'active_connections' => 12,
        'health_check_failure_rate' => 0.0,
        'timeout_rate' => 0.16
    ]
]
*/
```

### Pooled Connection Features

```php
use Glueful\Database\PooledConnection;
use Glueful\Database\ConfigurableConnectionPool;

// Acquire pooled connection after a Connection has initialized pooling
$pool = Glueful\Database\Connection::getPoolManager()->getPool('mysql');
$connection = $pool->acquire();

// Get connection statistics
$stats = $connection->getStats();
/*
[
    'id' => 'conn_abc123',
    'age' => 125.45,              // seconds since creation
    'idle_time' => 5.23,          // seconds since last use
    'use_count' => 47,            // number of times used
    'in_transaction' => false,     // transaction state
    'is_healthy' => true,         // health status
    'peak_memory' => 2048576,     // peak memory usage
    'queries_executed' => 234     // total queries executed
]
*/

// Check connection state
$isHealthy = $connection->isHealthy();
$inTransaction = $connection->isInTransaction();
$age = $connection->getAge();
$idleTime = $connection->getIdleTime();

// Async maintenance workers (ReactPHP, Swoole, or fork-based)
$pool->startMaintenanceWorker('swoole'); // or 'react', 'fork'
```

## Query Builder Architecture (Modular Design)

### Orchestrator Pattern Implementation

The QueryBuilder uses a modern orchestrator pattern, replacing the monolithic 2,184-line version with a lightweight coordinator that delegates to specialized components:

```php
use Glueful\Database\QueryBuilder;

// The QueryBuilder is constructed with 14 specialized components:
// - QueryStateInterface: Manages query state and metadata
// - WhereClauseInterface: Handles WHERE clause construction
// - SelectBuilderInterface: Builds SELECT queries
// - InsertBuilderInterface: Builds INSERT queries
// - UpdateBuilderInterface: Builds UPDATE queries
// - DeleteBuilderInterface: Builds DELETE queries
// - JoinClauseInterface: Handles table joins
// - QueryModifiersInterface: Manages ORDER BY, GROUP BY, HAVING, LIMIT
// - TransactionManagerInterface: Handles transactions and savepoints
// - QueryExecutorInterface: Executes queries
// - ResultProcessorInterface: Processes results
// - PaginationBuilderInterface: Handles pagination
// - SoftDeleteHandlerInterface: Manages soft deletes
// - QueryValidatorInterface: Validates SQL safety

// All components are injected via DI container
$queryBuilder = container()->get(QueryBuilder::class);
```

## Query Builder Advanced Features

### Complex Query Construction

```php
// Multi-table joins with complex conditions
$results = $db
    ->table('users')
    ->select(['users.*', 'profiles.bio', 'roles.name AS role_name'])
    ->leftJoin('profiles', 'profiles.user_id', '=', 'users.id')
    ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
    ->where(['users.active' => 1])
    ->whereIn('roles.name', ['admin', 'moderator'])
    ->where('users.created_at', '>', '2024-01-01')
    ->orWhere(function ($q) {
        $q->whereNull('users.deleted_at');
        $q->orWhere('users.deleted_at', '>', date('Y-m-d H:i:s'));
    })
    ->orderBy(['users.last_login' => 'DESC', 'users.created_at' => 'ASC'])
    ->limit(50)
    ->get();
```

### Advanced Filtering and Search

```php
// Multi-column text search (build OR group with LIKE)
$users = $db
    ->table('users')
    ->select(['*'])
    ->where(function ($q) {
        $q->whereLike('username', '%john smith%');
        $q->orWhere('email', 'LIKE', '%john smith%');
        $q->orWhere('first_name', 'LIKE', '%john smith%');
        $q->orWhere('last_name', 'LIKE', '%john smith%');
    })
    ->orderBy(['username' => 'ASC'])
    ->get();

// Advanced filtering with multiple operators
$orders = $db
    ->table('orders')
    ->select(['*'])
    ->where(function ($q) {
        $q->whereIn('status', ['pending', 'processing']);
        $q->whereBetween('total', 100, 1000);
        $q->orWhere('customer_email', 'LIKE', '%@company.com');
    })
    ->where('created_at', '>=', '2024-01-01')
    ->get();

// JSON column searching (database-agnostic)
$logs = $db
    ->table('logs')
    ->select(['*'])
    ->whereJsonContains('metadata', 'login_failed')
    ->whereJsonContains('details', 'active', '$.status')  // MySQL path syntax
    ->get();
```

### Query Building with Optimization

```php
use Glueful\Database\Connection;
use Glueful\Database\QueryOptimizer;

$db = container()->get(Connection::class);

// Enable query optimization and caching
$optimizedResults = $db->table('orders')
    ->select(['orders.*', 'customers.name', 'products.title'])
    ->join('customers', 'customers.id', '=', 'orders.customer_id')
    ->join('order_items', 'order_items.order_id', '=', 'orders.id')
    ->join('products', 'products.id', '=', 'order_items.product_id')
    ->where(['orders.status' => 'completed'])
    ->orderBy(['orders.created_at' => 'DESC'])
    ->optimize()           // Enable query optimization
    ->cache(3600)         // Cache results for 1 hour
    ->withPurpose('Order history with customer and product details')
    ->get();

// Manual optimization with QueryOptimizer
$optimizer = new QueryOptimizer();
$optimizer->setConnection($db);
$optimized = $optimizer->optimizeQuery(
    "SELECT * FROM orders WHERE status = ? ORDER BY created_at DESC",
    ['completed']
);
```

### Raw Expressions and Complex Queries

```php
// Using raw expressions for complex calculations
$q = $db->table('orders');
$salesReport = $q
    ->select([
        'DATE(created_at) as date',
        $q->raw('COUNT(*) as order_count'),
        $q->raw('SUM(total) as daily_revenue'),
        $q->raw('AVG(total) as avg_order_value'),
        $q->raw('MAX(total) as highest_order')
    ])
    ->where(['status' => 'completed'])
    ->whereBetween('created_at', $startDate, $endDate)
    ->groupBy(['DATE(created_at)'])
    ->having(['order_count' => 5])  // At least 5 orders per day
    ->havingRaw('SUM(total) > ?', [1000])  // Daily revenue > $1000
    ->orderBy(['date' => 'DESC'])
    ->get();
```

### Pagination with Optimization

```php
// Optimized pagination with count query optimization
$page = $request->get('page', 1);
$perPage = $request->get('per_page', 20);

$paginatedResults = $db
    ->table('products')
    ->select(['*'])
    ->join('categories', 'categories.id', '=', 'products.category_id')
    ->where(['products.active' => 1])
    ->where(function ($q) use ($searchTerm) {
        $q->where('products.name', 'LIKE', "%{$searchTerm}%");
        $q->orWhere('products.description', 'LIKE', "%{$searchTerm}%");
    })
    ->orderBy(['products.featured' => 'DESC', 'products.created_at' => 'DESC'])
    ->paginate($page, $perPage);

/*
Returns:
[
    'data' => [...],
    'current_page' => 1,
    'per_page' => 20,
    'total' => 1250,
    'last_page' => 63,
    'has_more' => true,
    'from' => 1,
    'to' => 20,
    'execution_time_ms' => 45.67
]
*/
```

## Query Optimization

### Automatic Query Analysis and Optimization

```php
use Glueful\Database\QueryOptimizer;

$optimizer = new QueryOptimizer();
$optimizer->setConnection($db);

// Analyze and optimize a complex query
$result = $optimizer->optimizeQuery(
    "SELECT u.*, p.bio FROM users u 
     LEFT JOIN profiles p ON p.user_id = u.id 
     WHERE u.status = ? AND u.created_at > ? 
     ORDER BY u.last_login DESC",
    ['active', '2024-01-01']
);

/*
Returns:
[
    'original_query' => '...',
    'optimized_query' => '...',
    'suggestions' => [
        [
            'type' => 'missing_index',
            'description' => 'Query may benefit from an index',
            'solution' => 'Add an index to the referenced column',
            'impact' => 'high'
        ],
        [
            'type' => 'inefficient_join',
            'description' => 'Join order could be optimized',
            'solution' => 'Reorder joins to start with most restrictive conditions',
            'impact' => 'medium'
        ]
    ],
    'estimated_improvement' => [
        'execution_time' => 25,    // 25% improvement
        'resource_usage' => 30,    // 30% less resources
        'confidence' => 'high'
    ]
]
*/
```

### Database-Specific Optimizations

```php
// MySQL-specific optimizations
$mysqlOptimizer = new QueryOptimizer();
$mysqlOptimizer->setConnection($mysqlConnection);

// Optimization may include:
// - STRAIGHT_JOIN hints for complex joins
// - Index usage optimization
// - WITH ROLLUP for aggregations
$optimized = $mysqlOptimizer->optimizeQuery($complexQuery, $params);

// PostgreSQL-specific optimizations
$pgsqlOptimizer = new QueryOptimizer();
$pgsqlOptimizer->setConnection($pgsqlConnection);

// May include specialized PostgreSQL optimizations
$optimized = $pgsqlOptimizer->optimizeQuery($complexQuery, $params);
```

### Manual Optimization Triggers

```php
// Enable optimizer on a specific query
$db
    ->table('orders')
    ->select(['*'])
    ->join('customers', 'customers.id', '=', 'orders.customer_id')
    ->where(['status' => 'pending'])
    ->optimize()
    ->get();
```

## Performance Monitoring

### Query Performance Analysis

```php
use Glueful\Database\QueryLogger;

$logger = new QueryLogger($frameworkLogger);

// Configure performance monitoring
$logger->configure(
    enableDebug: true,
    enableTiming: true,
    maxLogSize: 500
);

// Configure N+1 detection
$logger->configureN1Detection(
    threshold: 5,      // 5 similar queries triggers detection
    timeWindow: 5      // within 5 seconds
);

// Get comprehensive statistics
$stats = $logger->getStatistics();
/*
[
    'total' => 1250,
    'select' => 980,
    'insert' => 125,
    'update' => 95,
    'delete' => 35,
    'other' => 15,
    'error' => 3,
    'total_time' => 15670.25  // milliseconds
]
*/

// Get average execution time
$avgTime = $logger->getAverageExecutionTime(); // 12.54 ms

// Format execution time for display
$formattedTime = $logger->formatExecutionTime(1250.5); // "1.25 s"
```

### N+1 Query Detection

```php
// Automatic N+1 detection with recommendations
// The logger will automatically detect patterns like:

// BAD: N+1 pattern
foreach ($users as $user) {
    $profile = $db->table('profiles')
        ->select(['*'])
        ->where(['user_id' => $user->id])
        ->first();
}

// Logger will detect this pattern and recommend:
// "Consider using eager loading or preloading related data in a single query
//  instead of multiple individual lookups"

// GOOD: Optimized approach
$userIds = array_column($users, 'id');
$profiles = $db->table('profiles')
    ->select(['*'])
    ->whereIn('user_id', $userIds)
    ->get();

// Map profiles to users
$profilesByUserId = [];
foreach ($profiles as $profile) {
    $profilesByUserId[$profile['user_id']] = $profile;
}
```

### Query Complexity Analysis

```php
use Glueful\Database\QueryAnalyzer;
use Glueful\Database\Tools\QueryPatternRecognizer;

// Queries are automatically analyzed for complexity
$analyzer = new QueryAnalyzer();
$patternRecognizer = new QueryPatternRecognizer();

$complexQuery = "
    SELECT
        u.username,
        COUNT(o.id) as order_count,
        SUM(o.total) as total_spent,
        AVG(o.total) as avg_order,
        ROW_NUMBER() OVER (PARTITION BY u.department ORDER BY SUM(o.total) DESC) as rank
    FROM users u
    LEFT JOIN orders o ON o.user_id = u.id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE u.active = 1
    AND o.created_at > DATE_SUB(NOW(), INTERVAL 1 YEAR)
    GROUP BY u.id, u.username, u.department
    HAVING COUNT(o.id) > 5
    ORDER BY total_spent DESC, u.username
";

// Complexity factors analyzed:
// - JOIN operations (+1 each)
// - Subqueries (+2 each)
// - Aggregation functions (+1)
// - Window functions (+2)
// - GROUP BY/HAVING (+1 each)
// - UNION/INTERSECT/EXCEPT (+2 each)
// Results in complexity score for optimization prioritization

// Pattern recognition for optimization
$patterns = $patternRecognizer->analyze($complexQuery);
// Detects: N+1 patterns, missing indexes, inefficient joins, etc.
```

## Development Monitoring and Profiling

### DevelopmentQueryMonitor

```php
use Glueful\Database\DevelopmentQueryMonitor;

// Advanced development monitoring with N+1 detection
$monitor = new DevelopmentQueryMonitor($logger);

// Configure monitoring thresholds
$monitor->setSlowQueryThreshold(200); // 200ms
$monitor->setN1DetectionThreshold(5); // 5 similar queries
$monitor->enableStackTraces();
$monitor->enableMemoryTracking();

// Monitor analyzes queries in real-time and provides:
// - N+1 query detection with stack traces
// - Slow query identification
// - Memory usage per query
// - Query pattern analysis
// - Performance recommendations
```

### QueryProfilerService

```php
use Glueful\Database\Tools\QueryProfilerService;

// Sampling-based profiling for production
$profiler = new QueryProfilerService();
$profiler->setSamplingRate(0.1); // Profile 10% of queries
$profiler->setSlowQueryThreshold(100); // 100ms

// Get profiling results
$profile = $profiler->getProfile();
/*
[
    'total_queries' => 10000,
    'sampled_queries' => 1000,
    'slow_queries' => 45,
    'average_time' => 12.5,
    'p95_time' => 89.3,
    'p99_time' => 234.7,
    'query_patterns' => [...],
    'recommendations' => [...]
]
*/
```

### ExecutionPlanAnalyzer

```php
use Glueful\Database\Tools\ExecutionPlanAnalyzer;

// Analyze query execution plans
$analyzer = new ExecutionPlanAnalyzer($connection);
$analysis = $analyzer->analyze($query, $params);

/*
Returns:
[
    'execution_plan' => [...],
    'estimated_rows' => 1250,
    'estimated_cost' => 324.5,
    'index_usage' => [
        'users' => 'PRIMARY',
        'orders' => 'idx_user_date'
    ],
    'warnings' => [
        'Full table scan on orders table',
        'Using filesort for ORDER BY'
    ],
    'recommendations' => [
        'Consider adding index on orders.status',
        'Rewrite query to avoid filesort'
    ]
]
*/
```

## Query Logging and Analytics

### Business Context Logging

```php
use Glueful\Database\Connection;

$db = container()->get(Connection::class);

// Add business context to queries for better debugging
$userProfile = $db->table('users')
    ->withPurpose('User profile page data loading')
    ->select(['users.*', 'profiles.bio', 'profiles.avatar_url'])
    ->join('profiles', 'profiles.user_id', '=', 'users.id')
    ->where(['users.id' => $userId])
    ->first();

// Query will be logged with business context
// Log entry will include: purpose, execution time, SQL, bindings

// Complex query with purpose tracking
$dashboardData = $db->table('users')
    ->withPurpose('Admin dashboard user statistics')
    ->select(['users.status', $db->table('users')->raw('COUNT(*) as count')])
    ->where('created_at', '>=', date('Y-m-d', strtotime('-30 days')))
    ->groupBy(['status'])
    ->get();
```

### Query Log Analysis

```php
// Get detailed query log
$queryLog = $logger->getQueryLog();

foreach ($queryLog as $entry) {
    echo "Query: {$entry['sql']}\n";
    echo "Type: {$entry['type']}\n";
    echo "Tables: " . implode(', ', $entry['tables']) . "\n";
    echo "Complexity: {$entry['complexity']}\n";
    echo "Execution time: {$entry['time']}\n";
    echo "Purpose: {$entry['purpose']}\n";
    if ($entry['error']) {
        echo "Error: {$entry['error']}\n";
    }
    echo "---\n";
}
```

### Event-Driven Logging

```php
// Listen to query execution events for custom logging
use Glueful\Events\Database\QueryExecutedEvent;

Event::listen(QueryExecutedEvent::class, function($event) {
    // Custom application-specific query logging
    if ($event->executionTime > 1.0) { // > 1 second
        $this->alertingService->sendSlowQueryAlert([
            'sql' => $event->sql,
            'execution_time' => $event->executionTime,
            'connection' => $event->connectionName,
            'metadata' => $event->metadata
        ]);
    }
    
    // Log to business analytics
    $this->analyticsService->trackDatabaseQuery([
        'query_type' => $this->determineQueryType($event->sql),
        'tables' => $event->metadata['tables'] ?? [],
        'execution_time' => $event->executionTime,
        'purpose' => $event->metadata['purpose'] ?? null
    ]);
});
```

## Database Driver System

### SSL/TLS Support

```php
// config/database.php - SSL configuration for secure connections
return [
    'mysql' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST'),
        'ssl' => [
            'enabled' => env('DB_SSL_ENABLED', false),
            'ca' => env('DB_SSL_CA_PATH'),
            'cert' => env('DB_SSL_CERT_PATH'),
            'key' => env('DB_SSL_KEY_PATH'),
            'cipher' => env('DB_SSL_CIPHER'),
            'verify' => env('DB_SSL_VERIFY', true)
        ]
    ],
    'pgsql' => [
        'driver' => 'pgsql',
        'sslmode' => env('PGSQL_SSLMODE', 'prefer'), // disable|allow|prefer|require|verify-ca|verify-full
        'sslcert' => env('PGSQL_SSL_CERT'),
        'sslkey' => env('PGSQL_SSL_KEY'),
        'sslrootcert' => env('PGSQL_SSL_ROOT_CERT')
    ]
];
```

## Database Drivers

### Multi-Database Support

```php
use Glueful\Database\Connection;
use Glueful\Database\Driver\MySQLDriver;
use Glueful\Database\Driver\PostgreSQLDriver;
use Glueful\Database\Driver\SQLiteDriver;

$db = container()->get(Connection::class);

// Database-agnostic query building
$driver = $db->getDriver(); // Returns database-specific driver instance

// Driver-specific identifier wrapping (for reserved words)
$wrappedTable = $driver->wrapIdentifier('users');     // `users` for MySQL
$wrappedColumn = $driver->wrapIdentifier('user_name'); // `user_name` for MySQL

// Driver-specific features and optimizations
if ($driver instanceof MySQLDriver) {
    // MySQL-specific features
    $sql = "INSERT INTO users (username, email) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE last_login = NOW()";
    $db->executeRaw($sql, ['john', 'john@example.com']);

} elseif ($driver instanceof PostgreSQLDriver) {
    // PostgreSQL-specific features with RETURNING
    $sql = "INSERT INTO users (username, email) VALUES (?, ?)
            ON CONFLICT (username) DO UPDATE SET last_login = NOW()
            RETURNING id";
    $result = $db->executeRaw($sql, ['john', 'john@example.com']);
    $newId = $result[0]['id'] ?? null;

} elseif ($driver instanceof SQLiteDriver) {
    // SQLite-specific features
    $sql = "INSERT OR REPLACE INTO users (username, email, last_login)
            VALUES (?, ?, datetime('now'))";
    $db->executeRaw($sql, ['john', 'john@example.com']);
}
```

### Driver Capabilities

```php
// Check driver capabilities
$capabilities = $connection->getCapabilities();
/*
[
    'supports_json' => true,
    'supports_window_functions' => true,
    'supports_upsert' => true,
    'supports_returning' => true,  // PostgreSQL
    'supports_full_text_search' => true,
    'max_identifier_length' => 64
]
*/
```

## Migration System

### Complete Schema Management

```php
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Database\Schema\Builders\SchemaBuilder;

// Migration manager with batch tracking and checksum verification
$migrationManager = container()->get(MigrationManager::class);

// Create a new migration
$migrationManager->create('create_users_table');

// Migration class example
class CreateUsersTable implements MigrationInterface
{
    public function up(SchemaBuilder $schema): void
    {
        $schema->create('users')
            ->id()
            ->string('username', 50)->unique()
            ->string('email', 255)->unique()
            ->text('bio')->nullable()
            ->json('preferences')->nullable()
            ->timestamps()
            ->softDeletes()
            ->index(['email', 'username'])
            ->build();
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('users');
    }
}

// Run migrations with transaction safety
$migrationManager->run(); // All migrations in transaction
$migrationManager->rollback(steps: 2); // Rollback last 2 batches
$migrationManager->status(); // Check migration status with checksums
```

### Schema Builder Features

```php
// Advanced schema building with database-agnostic SQL generation
$schema = new SchemaBuilder($connection);

// Alter existing table
$schema->alter('users')
    ->addColumn('avatar_url', 'string', 500)->nullable()->after('email')
    ->modifyColumn('bio', 'longtext')
    ->dropColumn('old_field')
    ->renameColumn('username', 'user_name')
    ->addIndex(['created_at', 'status'], 'idx_user_activity')
    ->addForeignKey('role_id', 'roles', 'id')
        ->onDelete('cascade')
        ->onUpdate('restrict')
    ->build();

// Database-specific SQL generation
$mysqlGenerator = new MySQLSqlGenerator();
$postgresGenerator = new PostgreSQLSqlGenerator();
$sqliteGenerator = new SQLiteSqlGenerator();
```

## Advanced Query Patterns

### Bulk Operations

```php
// Bulk insert with batch processing
$users = [
    ['username' => 'user1', 'email' => 'user1@example.com'],
    ['username' => 'user2', 'email' => 'user2@example.com'],
    // ... 1000+ records
];

// Method 1: Using insertBatch for efficient bulk inserts
$batchSize = 100;
$totalInserted = 0;

foreach (array_chunk($users, $batchSize) as $batch) {
    $inserted = $db->table('users')->insertBatch($batch);
    $totalInserted += $inserted;
}

// Method 2: Using transactions for consistency with individual inserts
$totalInserted = $db->table('users')->transaction(function($db) use ($users) {
    $count = 0;
    foreach ($users as $user) {
        $db->table('users')->insert($user);
        $count++;
    }
    return $count;
});

// Bulk update with conditions
$affectedRows = $db->table('users')
    ->where(['active' => 1])
    ->where('created_at', '<', date('Y-m-d', strtotime('-30 days')))
    ->update(['last_seen' => date('Y-m-d H:i:s')]);

// Alternative: Raw SQL for better performance on very large updates
$db->executeRaw(
    "UPDATE users SET last_seen = ? WHERE active = 1 AND created_at < ?",
    [date('Y-m-d H:i:s'), date('Y-m-d', strtotime('-30 days'))]
);
```

### Upsert Operations

```php
use Glueful\Database\Connection;

$db = container()->get(Connection::class);

// Database-agnostic upsert using QueryBuilder
// Automatically generates the correct SQL for MySQL, PostgreSQL, or SQLite
$affected = $db->table('user_stats')
    ->upsert(
        [
            'user_id' => 1,
            'login_count' => 1,
            'last_login' => date('Y-m-d H:i:s')
        ],
        ['login_count', 'last_login'] // Columns to update on duplicate/conflict
    );

// Batch upsert with multiple records
$records = [
    ['user_id' => 1, 'login_count' => 1, 'last_login' => date('Y-m-d H:i:s')],
    ['user_id' => 2, 'login_count' => 1, 'last_login' => date('Y-m-d H:i:s')],
    ['user_id' => 3, 'login_count' => 1, 'last_login' => date('Y-m-d H:i:s')]
];

foreach ($records as $record) {
    $db->table('user_stats')->upsert(
        $record,
        ['login_count', 'last_login']
    );
}

// Under the hood, the framework generates appropriate SQL:
// - MySQL: INSERT ... ON DUPLICATE KEY UPDATE
// - PostgreSQL: INSERT ... ON CONFLICT DO UPDATE
// - SQLite: INSERT ... ON CONFLICT DO UPDATE

// For custom upsert logic, you can still use raw SQL
if ($db->getDriver() instanceof \Glueful\Database\Driver\MySQLDriver) {
    $db->executeRaw(
        "INSERT INTO user_stats (user_id, login_count, last_login)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
         login_count = login_count + VALUES(login_count),
         last_login = VALUES(last_login)",
        [1, 1, date('Y-m-d H:i:s')]
    );
}
```

### Soft Delete Management

```php
use Glueful\Database\Connection;
use Glueful\Database\Features\SoftDeleteHandler;

$db = container()->get(Connection::class);

// Soft delete functionality is built into QueryBuilder
// By default, delete() performs soft delete if enabled

// Normal delete (soft delete if enabled)
$deleted = $db->table('users')
    ->where(['id' => $userId])
    ->delete(); // Sets deleted_at timestamp

// Include soft-deleted records in queries
$allUsers = $db->table('users')
    ->withTrashed() // Include soft-deleted records
    ->select(['*'])
    ->get();

// Only soft-deleted records
$trashedUsers = $db->table('users')
    ->onlyTrashed() // Only soft-deleted records
    ->select(['*'])
    ->get();

// Default behavior (exclude soft-deleted records)
$activeUsers = $db->table('users')
    ->select(['*'])
    ->get(); // Automatically excludes soft-deleted

// Restore soft-deleted records
$restored = $db->table('users')
    ->where(['id' => $userId])
    ->restore(); // Sets deleted_at to null

// Multiple restore with conditions
$restored = $db->table('users')
    ->where('deleted_at', '>', date('Y-m-d', strtotime('-30 days')))
    ->restore(); // Restore recently deleted users

// Force delete (permanent deletion, bypasses soft delete)
// Note: This uses DeleteBuilder.forceDelete() internally
$deleted = $db->table('users')
    ->where(['id' => $userId])
    ->delete(); // If you need force delete, use raw SQL for now

// Alternative: Direct force delete using raw SQL
$db->executeRaw('DELETE FROM users WHERE id = ?', [$userId]);

// Configure soft delete column (if different from 'deleted_at')
// This is typically configured at the application level
$softDeleteHandler = container()->get(SoftDeleteHandler::class);
$softDeleteHandler->setSoftDeleteColumn('archived_at');
```

### Window Functions and Analytics

```php
use Glueful\Database\Connection;
use Glueful\Database\RawExpression;

$db = container()->get(Connection::class);

// Complex analytics queries with window functions (MySQL 8.0+, PostgreSQL)
$salesAnalytics = $db->table('sales')
    ->select([
        'date',
        'amount',
        'region',
        $db->table('sales')->raw('SUM(amount) OVER (PARTITION BY region) as region_total'),
        $db->table('sales')->raw('RANK() OVER (PARTITION BY region ORDER BY amount DESC) as region_rank'),
        $db->table('sales')->raw('LAG(amount, 1) OVER (ORDER BY date) as previous_day_amount'),
        $db->table('sales')->raw('AVG(amount) OVER (ORDER BY date ROWS BETWEEN 6 PRECEDING AND CURRENT ROW) as moving_avg_7_days')
    ])
    ->whereBetween('date', $startDate, $endDate)
    ->orderBy(['date' => 'ASC'])
    ->get();

// Alternative: Using selectRaw for cleaner syntax
$salesReport = $db->table('sales')
    ->select(['date', 'amount', 'region'])
    ->selectRaw('SUM(amount) OVER (PARTITION BY region) as region_total')
    ->selectRaw('RANK() OVER (PARTITION BY region ORDER BY amount DESC) as region_rank')
    ->selectRaw('LAG(amount, 1) OVER (ORDER BY date) as previous_day_amount')
    ->selectRaw('AVG(amount) OVER (ORDER BY date ROWS BETWEEN 6 PRECEDING AND CURRENT ROW) as moving_avg_7_days')
    ->whereBetween('date', $startDate, $endDate)
    ->orderBy(['date' => 'ASC'])
    ->get();

// Running totals and cumulative calculations
$cumulativeRevenue = $db->table('daily_revenue')
    ->select(['date', 'revenue'])
    ->selectRaw('SUM(revenue) OVER (ORDER BY date) as cumulative_revenue')
    ->selectRaw('AVG(revenue) OVER (ORDER BY date ROWS BETWEEN 29 PRECEDING AND CURRENT ROW) as rolling_30_day_avg')
    ->where('date', '>=', $startDate)
    ->orderBy(['date' => 'ASC'])
    ->get();

// Percentile and distribution analysis
$performanceAnalysis = $db->table('employee_sales')
    ->select(['employee_id', 'department', 'total_sales'])
    ->selectRaw('PERCENT_RANK() OVER (ORDER BY total_sales) as sales_percentile')
    ->selectRaw('NTILE(4) OVER (ORDER BY total_sales) as quartile')
    ->selectRaw('DENSE_RANK() OVER (PARTITION BY department ORDER BY total_sales DESC) as dept_rank')
    ->where('year', '=', date('Y'))
    ->get();
```

## Transaction Management

### Nested Transactions with Savepoints

```php
use Glueful\Database\Connection;
use Glueful\Database\Transaction\TransactionManager;

$db = container()->get(Connection::class);

// Automatic deadlock handling with retry
$result = $db->table('orders')->transaction(function($db) use ($orderData, $inventoryUpdates) {
    // Create order
    $orderId = $db->table('orders')->insertGetId($orderData);

    // Create order items in nested transaction (uses savepoints)
    $db->table('order_items')->transaction(function($db) use ($orderId, $orderData) {
        foreach ($orderData['items'] as $item) {
            $db->table('order_items')->insert([
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price']
            ]);
        }
    });

    // Update inventory with raw expression
    foreach ($inventoryUpdates as $update) {
        $db->table('inventory')
            ->where(['product_id' => $update['product_id']])
            ->update([
                'quantity' => $db->table('inventory')->raw('quantity - ?', [$update['quantity']])
            ]);
    }

    return $orderId;
});

// Alternative: Using TransactionManager directly for more control
$transactionManager = container()->get(TransactionManager::class);

$result = $transactionManager->transaction(function() use ($db, $orderData) {
    $orderId = $db->table('orders')->insertGetId($orderData);

    // Nested transaction with savepoint
    $transactionManager->transaction(function() use ($db, $orderId, $orderData) {
        foreach ($orderData['items'] as $item) {
            $db->table('order_items')->insert([
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity']
            ]);
        }
    });

    return $orderId;
}, maxAttempts: 3); // Retry up to 3 times on deadlock
```

### Manual Transaction Control

```php
try {
    $queryBuilder->beginTransaction();
    
    // Complex multi-step operation
    $userId = $queryBuilder->insert('users', $userData);
    $profileId = $queryBuilder->insert('profiles', array_merge($profileData, ['user_id' => $userId]));
    
    // Nested savepoint
    $queryBuilder->beginTransaction();
    try {
        $queryBuilder->insert('user_preferences', ['user_id' => $userId, 'theme' => 'dark']);
        $queryBuilder->commit(); // Commit savepoint
    } catch (Exception $e) {
        $queryBuilder->rollback(); // Rollback to savepoint
        // Continue with main transaction
    }
    
    $queryBuilder->commit(); // Commit main transaction
    
} catch (Exception $e) {
    $queryBuilder->rollback(); // Rollback main transaction
    throw $e;
}
```

### Transaction State Monitoring

```php
// Check transaction state
if ($queryBuilder->isTransactionActive()) {
    echo "Transaction level: " . $queryBuilder->getTransactionLevel();
}

// Connection statistics for pooled connections
if ($queryBuilder->isUsingPooledConnection()) {
    $connectionStats = $queryBuilder->getConnectionStats();
    echo "Connection age: " . $connectionStats['age'] . " seconds\n";
    echo "Use count: " . $connectionStats['use_count'] . "\n";
}
```

## Advanced Features

### Query Purpose Tracking

```php
use Glueful\Database\Features\QueryPurpose;

// Track business purpose for queries
$queryBuilder->withPurpose('User dashboard data loading')
    ->select(['*'])
    ->from('users')
    ->where(['active' => 1])
    ->get();

// Purpose is logged for debugging and optimization
```

### Connection Validation and Health Checks

```php
use Glueful\Database\ConnectionValidator;

$validator = new ConnectionValidator();

// Validate connection health
$isHealthy = $validator->validate($connection);

// Get detailed validation results
$results = $validator->getValidationResults();
/*
[
    'ping_successful' => true,
    'response_time_ms' => 1.2,
    'server_version' => '8.0.31',
    'charset_valid' => true,
    'timezone_valid' => true,
    'ssl_enabled' => true
]
*/
```

### Query Hashing and Caching

```php
use Glueful\Database\QueryHasher;
use Glueful\Database\QueryCacheService;

$hasher = new QueryHasher();
$cacheService = new QueryCacheService($cache, $hasher);

// Automatic query result caching
$results = $queryBuilder
    ->cache(ttl: 3600, tags: ['users', 'active'])
    ->select(['*'])
    ->from('users')
    ->where(['active' => 1])
    ->get();

// Cache invalidation by tags
$cacheService->invalidateTags(['users']);
```

### Soft Delete Management

```php
use Glueful\Database\Features\SoftDeleteHandler;

$softDeleteHandler = new SoftDeleteHandler();

// Configure soft delete column
$softDeleteHandler->setSoftDeleteColumn('deleted_at');

// Query with soft delete awareness
$activeUsers = $queryBuilder
    ->withoutTrashed() // Exclude soft deleted
    ->select(['*'])
    ->from('users')
    ->get();

$allUsers = $queryBuilder
    ->withTrashed() // Include soft deleted
    ->select(['*'])
    ->from('users')
    ->get();

$trashedOnly = $queryBuilder
    ->onlyTrashed() // Only soft deleted
    ->select(['*'])
    ->from('users')
    ->get();
```

## Configuration

### Database Configuration

```php
// config/database.php
return [
    'default' => env('DB_CONNECTION', 'mysql'),

    // Database role configuration (primary/backup)
    'roles' => [
        'primary' => env('DB_PRIMARY', 'mysql'),
        'backup' => env('DB_BACKUP', 'mysql_replica')
    ],

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'db' => env('DB_DATABASE'),
            'user' => env('DB_USERNAME'),
            'pass' => env('DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
            'engine' => 'InnoDB',

            // SSL configuration
            'ssl' => [
                'enabled' => env('DB_SSL_ENABLED', false),
                'ca' => env('DB_SSL_CA'),
                'cert' => env('DB_SSL_CERT'),
                'key' => env('DB_SSL_KEY')
            ],

            // Advanced MySQL options
            'options' => [
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_ALL_TABLES'",
                PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false)
            ]
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('PGSQL_HOST', '127.0.0.1'),
            'port' => env('PGSQL_PORT', 5432),
            'db' => env('PGSQL_DATABASE'),
            'user' => env('PGSQL_USERNAME'),
            'pass' => env('PGSQL_PASSWORD'),
            'sslmode' => env('PGSQL_SSLMODE', 'prefer'),
            'schema' => env('PGSQL_SCHEMA', 'public'),
            'charset' => 'utf8',
            'application_name' => env('APP_NAME', 'glueful')
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('SQLITE_DATABASE', database_path('database.sqlite')),
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL'
        ]
    ],

    // Connection pooling configuration
    'pooling' => [
        'enabled' => env('DB_POOLING_ENABLED', true),
        'defaults' => [
            'min_connections' => env('DB_POOL_MIN_CONNECTIONS', 2),
            'max_connections' => env('DB_POOL_MAX_CONNECTIONS', 10),
            'idle_timeout' => env('DB_POOL_IDLE_TIMEOUT', 300),
            'max_lifetime' => env('DB_POOL_MAX_LIFETIME', 3600),
            'acquisition_timeout' => env('DB_POOL_ACQUIRE_TIMEOUT', 30),
            'health_check_interval' => env('DB_POOL_HEALTH_CHECK_INTERVAL', 60),
            'health_check_timeout' => env('DB_POOL_HEALTH_CHECK_TIMEOUT', 5),
            'max_use_count' => env('DB_POOL_MAX_USE_COUNT', 1000),
            'retry_attempts' => env('DB_POOL_RETRY_ATTEMPTS', 3),
            'retry_delay' => env('DB_POOL_RETRY_DELAY', 100),
            'enable_async_maintenance' => env('DB_POOL_ASYNC_MAINTENANCE', false),
            'maintenance_worker' => env('DB_POOL_WORKER', 'fork') // fork|react|swoole
        ],
        'engines' => [
            'mysql' => [
                'max_connections' => env('MYSQL_POOL_MAX', 20),
                'min_connections' => env('MYSQL_POOL_MIN', 5)
            ],
            'pgsql' => [
                'max_connections' => env('PGSQL_POOL_MAX', 15),
                'min_connections' => env('PGSQL_POOL_MIN', 3)
            ],
            'sqlite' => [
                'max_connections' => 1, // SQLite limitation
                'min_connections' => 1
            ]
        ]
    ]
];
```

### Query Optimization Configuration

```php
// config/database_optimization.php
return [
    'query_optimization' => [
        'enabled' => env('DB_OPTIMIZATION_ENABLED', true),
        'default_threshold' => env('DB_OPTIMIZATION_THRESHOLD', 10.0), // 10% improvement required
        'cache_optimizations' => env('DB_OPTIMIZATION_CACHE', true),
        
        'engines' => [
            'mysql' => [
                'use_straight_join' => true,
                'optimize_group_by' => true,
                'index_hints' => true
            ],
            'pgsql' => [
                'use_query_planner_hints' => false,
                'optimize_window_functions' => true
            ]
        ]
    ],
    
    'query_analysis' => [
        'enabled' => env('DB_ANALYSIS_ENABLED', true),
        'complexity_threshold' => 5, // Queries with complexity > 5 get extra analysis
        'execution_plan_analysis' => env('DB_ANALYZE_EXECUTION_PLANS', false)
    ]
];
```

### Performance Monitoring Configuration

```php
// config/database_monitoring.php
return [
    'query_logging' => [
        'enabled' => env('DB_QUERY_LOGGING_ENABLED', true),
        'debug_mode' => env('DB_DEBUG_MODE', false),
        'max_log_size' => env('DB_MAX_LOG_SIZE', 500),
        
        'slow_query_detection' => [
            'enabled' => env('DB_SLOW_QUERY_DETECTION', true),
            'threshold_ms' => env('DB_SLOW_QUERY_THRESHOLD', 200),
            'log_level' => 'warning'
        ],
        
        'n1_detection' => [
            'enabled' => env('DB_N1_DETECTION_ENABLED', true),
            'threshold' => env('DB_N1_THRESHOLD', 5),
            'time_window' => env('DB_N1_TIME_WINDOW', 5)
        ]
    ],
    
    'performance_monitoring' => [
        'enabled' => env('DB_PERFORMANCE_MONITORING', true),
        'track_query_complexity' => true,
        'track_table_usage' => true,
        'emit_events' => true
    ]
];
```

## Production Optimization

### High-Performance Configuration

```php
// Production-optimized settings
return [
    'mysql' => [
        'options' => [
            PDO::ATTR_PERSISTENT => true,              // Use persistent connections
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Buffer query results
            PDO::MYSQL_ATTR_INIT_COMMAND => 
                "SET sql_mode='STRICT_ALL_TABLES', " .
                "SESSION query_cache_type='ON', " .
                "SESSION query_cache_size=67108864",    // 64MB query cache
        ]
    ],
    
    'pooling' => [
        'defaults' => [
            'min_connections' => 10,    // Higher minimum for production
            'max_connections' => 50,    // Higher maximum for production
            'acquire_timeout' => 10,    // Faster timeout
            'idle_timeout' => 600,      // 10 minutes
            'health_check_interval' => 30,
            'max_connection_age' => 1800 // 30 minutes
        ]
    ],
    
    'query_optimization' => [
        'enabled' => true,
        'default_threshold' => 5.0,     // Lower threshold for more optimizations
        'cache_optimizations' => true
    ],
    
    'query_logging' => [
        'debug_mode' => false,          // Disable debug mode in production
        'max_log_size' => 100,          // Smaller log size
        'slow_query_detection' => [
            'threshold_ms' => 100       // Lower threshold for production monitoring
        ]
    ]
];
```

### Monitoring and Alerting

```php
// Production monitoring setup
class DatabaseMonitoringService
{
    private QueryLogger $logger;
    private ConnectionPoolManager $poolManager;
    
    public function getHealthMetrics(): array
    {
        $poolStats = $this->poolManager->getAggregateStats();
        $queryStats = $this->logger->getStatistics();
        
        return [
            'database_health' => [
                'total_connections' => $poolStats['total_active_connections'] + $poolStats['total_idle_connections'],
                'active_connections' => $poolStats['total_active_connections'],
                'connection_pool_utilization' => $this->calculatePoolUtilization($poolStats),
                'average_query_time' => $this->logger->getAverageExecutionTime(),
                'slow_query_rate' => $this->calculateSlowQueryRate($queryStats),
                'error_rate' => $this->calculateErrorRate($queryStats),
                'n1_detections_last_hour' => $this->getN1DetectionsCount()
            ]
        ];
    }
    
    public function checkAlerts(): array
    {
        $alerts = [];
        $metrics = $this->getHealthMetrics()['database_health'];
        
        // Connection pool alerts
        if ($metrics['connection_pool_utilization'] > 0.9) {
            $alerts[] = 'Connection pool utilization above 90%';
        }
        
        // Performance alerts
        if ($metrics['average_query_time'] > 500) { // 500ms
            $alerts[] = 'Average query time above 500ms';
        }
        
        if ($metrics['slow_query_rate'] > 0.1) { // 10%
            $alerts[] = 'Slow query rate above 10%';
        }
        
        // Error rate alerts
        if ($metrics['error_rate'] > 0.05) { // 5%
            $alerts[] = 'Database error rate above 5%';
        }
        
        return $alerts;
    }
}
```

### Performance Optimization Best Practices

```php
use Glueful\Database\Connection;
use Glueful\Database\ConnectionPoolManager;
use Glueful\Database\QueryLogger;
use Glueful\Database\QueryOptimizer;
use Glueful\Database\Tools\ExecutionPlanAnalyzer;

// 1. Use connection pooling in production (automatic when enabled in config)
$db = container()->get(Connection::class); // Pooling is automatic if enabled

// Manual pool management (rarely needed)
$poolManager = container()->get(ConnectionPoolManager::class);
$pool = $poolManager->getPool('mysql');
$connection = $pool->acquire();
try {
    // Use connection
} finally {
    $pool->release($connection);
}

// 2. Enable query optimization and caching for complex queries
$results = $db->table('complex_table')
    ->select(['complex_table.*', 'related_table.name'])
    ->join('related_table', 'related_table.id', '=', 'complex_table.related_id')
    ->where(['complex_table.status' => 'active'])
    ->optimize()           // Enable query optimization
    ->cache(300)          // Cache results for 5 minutes
    ->withPurpose('Complex data aggregation')  // Track purpose for monitoring
    ->get();

// 3. Use bulk operations to prevent N+1 queries
$users = $userService->getActiveUsers();
$userIds = array_column($users, 'id');

// Efficient: Single query for all profiles
$profiles = $db->table('profiles')
    ->select(['*'])
    ->whereIn('user_id', $userIds)
    ->get();

// Bulk insert with batching
$records = [...]; // Large dataset
$batchSize = 100;
foreach (array_chunk($records, $batchSize) as $batch) {
    $db->table('logs')->insertBatch($batch);
}

// 4. Monitor and analyze query performance
$queryLogger = container()->get(QueryLogger::class);
$queryLogger->configure(
    enableDebug: false,     // Disable debug in production
    enableTiming: true,     // Track execution times
    maxLogSize: 100        // Limit log size
);
$queryLogger->configureN1Detection(
    threshold: 5,          // Detect after 5 similar queries
    timeWindow: 5          // Within 5 seconds
);

// 5. Analyze execution plans for optimization
$analyzer = new ExecutionPlanAnalyzer($db);
$query = "SELECT u.*, p.* FROM users u
          JOIN profiles p ON p.user_id = u.id
          WHERE u.active = 1";
$analysis = $analyzer->analyze($query, []);

if (!empty($analysis['warnings'])) {
    // Address performance warnings
    foreach ($analysis['recommendations'] as $recommendation) {
        $logger->warning('Query optimization needed: ' . $recommendation);
    }
}

// 6. Use transactions for bulk operations
$db->transaction(function($db) use ($orderData) {
    $orderId = $db->table('orders')->insertGetId($orderData);

    foreach ($orderData['items'] as $item) {
        $db->table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity']
        ]);
    }

    return $orderId;
});

// 7. Use pagination for large result sets
$page = $request->get('page', 1);
$results = $db->table('products')
    ->select(['*'])
    ->where(['active' => 1])
    ->orderBy(['created_at' => 'DESC'])
    ->paginate($page, perPage: 25);

// 8. Optimize with index hints and query purpose tracking
$result = $db->table('users')
    ->withPurpose('User dashboard data loading')
    ->select(['users.*', 'profiles.bio', 'user_stats.login_count'])
    ->join('profiles', 'profiles.user_id', '=', 'users.id')
    ->join('user_stats', 'user_stats.user_id', '=', 'users.id')
    ->where(['users.active' => 1])
    ->whereNotNull('users.last_login')
    ->orderBy(['users.last_login' => 'DESC'])
    ->limit(20)
    ->enableDebug(false)  // Disable debug in production
    ->get();
```

## Summary

Glueful's advanced database system represents a modern, enterprise-grade implementation that significantly exceeds typical framework capabilities:

### Architecture Highlights
- **Modular Design**: Orchestrator pattern with 34+ specialized components replacing monolithic approach
- **Dependency Injection**: All components properly injected for testability and flexibility
- **Production Ready**: SSL support, connection leak detection, performance scoring

### Key Features
- **Advanced Connection Pooling**: Multi-engine pools with async maintenance workers (ReactPHP/Swoole/Fork)
- **Sophisticated Monitoring**: N+1 detection, execution plan analysis, pattern recognition, profiling
- **Complete Migration System**: Schema management with batch tracking and checksum verification
- **Enterprise Transactions**: Nested transactions, savepoints, automatic deadlock retry
- **Query Optimization**: Pattern recognition, database-specific optimizations, caching
- **Multi-Database Support**: MySQL, PostgreSQL, SQLite with SSL and driver-specific features
- **Developer Experience**: Purpose tracking, health checks, soft deletes, advanced pagination

### Performance & Reliability
- **Connection Management**: Health monitoring, leak detection, automatic recycling
- **Query Analysis**: Real-time profiling, slow query detection, memory tracking
- **Optimization**: Automatic query optimization, execution plan analysis, result caching
- **Production Features**: Configurable pooling, SSL/TLS support, comprehensive logging

The implementation provides a sophisticated, production-ready database layer suitable for high-traffic, distributed applications while maintaining excellent developer experience through modular design and comprehensive tooling.
