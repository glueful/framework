# Logging

This guide covers Glueful's comprehensive logging system, including framework vs application logging boundaries, the LogManager, channels, performance monitoring, and production best practices.

## Table of Contents

1. [Overview](#overview)
2. [Framework vs Application Logging](#framework-vs-application-logging)
3. [LogManager Core Features](#logmanager-core-features)
4. [Channel-Based Logging](#channel-based-logging)
5. [Database Logging](#database-logging)
6. [Query Performance Logging](#query-performance-logging)
7. [HTTP Request Logging](#http-request-logging)
8. [Performance Monitoring](#performance-monitoring)
9. [Configuration](#configuration)
10. [Log Maintenance](#log-maintenance)
11. [Production Best Practices](#production-best-practices)
12. [Troubleshooting](#troubleshooting)

## Overview

Glueful provides a comprehensive logging system built on Monolog with advanced features designed for production environments:

### Key Features

- **PSR-3 Compliant**: Standard logging interface with all log levels
- **Clear Separation of Concerns**: Framework infrastructure vs application business logic
- **Advanced Performance Monitoring**: Built-in timing, memory tracking, and performance analysis
- **N+1 Query Detection**: Automatic detection of N+1 query problems with recommendations
- **Multiple Output Channels**: App, API, framework, error, and debug channels
- **Database Logging**: Structured storage with automatic cleanup
- **Intelligent Sampling**: Configurable sampling rates for high-volume environments
- **Security-First**: Automatic sanitization of sensitive data
- **Production Ready**: File rotation, cleanup, and memory management

### Architecture

The logging system separates concerns between:
- **Framework Logging**: Performance metrics, protocol errors, system health
- **Application Logging**: Business logic, user actions, custom events
- **Query Logging**: Database performance, slow queries, N+1 detection
- **Request Logging**: HTTP requests, responses, middleware processing

## Framework vs Application Logging

Glueful follows industry best practices for framework logging boundaries, ensuring clear separation of concerns between framework infrastructure and application business logic.

### ✅ **Framework Automatically Logs** (Infrastructure Concerns)

The framework handles these logging concerns automatically:

- **Unhandled Exceptions & Fatal Errors** - Framework-level failures and PHP errors
- **HTTP Protocol Errors** - Malformed requests, routing failures, invalid JSON
- **Framework Lifecycle Events** - Startup, shutdown, configuration loading
- **HTTP Auth Failures** - Missing headers, malformed JWT tokens (protocol level)
- **Slow Query Detection** - Configurable performance monitoring
- **HTTP Client Infrastructure Failures** - Connection timeouts, DNS issues, server errors
- **API Deprecation Warnings** - Framework-managed endpoint versioning

### 🔧 **Application Should Log** (Business Concerns)

Your application code should handle these logging scenarios via events and custom logging:

- **Business Authentication Logic** - User login attempts, permission checks
- **Business External Service Failures** - Payment processing, email delivery status
- **User Behavior Tracking** - Custom analytics and business metrics
- **Custom Validation** - Business rule violations and domain-specific errors
- **CRUD Operations** - User data changes, audit trails
- **Business State Changes** - Order status updates, user role changes

### Using Framework Events for Application Logging

The framework emits events that your application can listen to for business logging:

#### Security Events

```php
use Glueful\Events\RateLimitExceededEvent;
use Glueful\Events\HttpAuthFailureEvent;

class SecurityLoggingListener
{
    private LoggerInterface $logger;
    
    public function onRateLimitExceeded(RateLimitExceededEvent $event): void
    {
        // Your business security logging
        $this->logger->warning('Rate limit violation detected', [
            'ip_address' => $event->ipAddress,
            'endpoint' => $event->endpoint,
            'method' => $event->method,
            'user_agent' => $event->request->headers->get('User-Agent'),
            'timestamp' => now()->toISOString()
        ]);
        
        // Custom business logic
        if ($this->isSuspiciousActivity($event)) {
            $this->blacklistIp($event->ipAddress);
            $this->sendSecurityAlert($event);
        }
    }
    
    public function onHttpAuthFailure(HttpAuthFailureEvent $event): void
    {
        // Application logs business context of auth failures
        $this->logger->info('Authentication attempt failed', [
            'reason' => $event->reason,
            'ip_address' => $event->request->getClientIp(),
            'endpoint' => $event->request->getPathInfo(),
            'user_agent' => $event->request->headers->get('User-Agent'),
            'timestamp' => now()->toISOString()
        ]);
    }
}
```

#### Query Events

```php
use Glueful\Events\QueryExecutedEvent;

class QueryLoggingListener
{
    public function onQueryExecuted(QueryExecutedEvent $event): void
    {
        // Log business-specific query context
        if ($this->isBusinessCriticalTable($event->sql)) {
            $this->logger->info('Business critical query executed', [
                'table' => $this->extractTableName($event->sql),
                'operation' => $this->detectOperation($event->sql),
                'execution_time_ms' => $event->executionTime * 1000,
                'user_id' => $this->getCurrentUserId()
            ]);
        }
    }
}
```

### Request Context Logging

Use the framework's contextual logging for consistent request correlation:

```php
// In your controllers
public function createUser(Request $request): Response
{
    // Get contextual logger with request context pre-populated
    $logger = $request->attributes->get('contextual_logger')();
    
    $logger->info('Creating new user', [
        'type' => 'application',
        'email' => $request->request->get('email'),
        'registration_source' => 'api'
    ]);
    // Log automatically includes: request_id, ip, user_agent, path, method, user_id
    
    // Your business logic...
    
    $logger->info('User created successfully', [
        'type' => 'application',
        'user_uuid' => $user->uuid,
        'email' => $user->email
    ]);
    
    return Response::created($user, 'User created successfully');
}
```

## LogManager Core Features

### Basic Usage

```php
use Glueful\Logging\LogManager;

// Get logger instance (singleton)
$logger = LogManager::getInstance();

// Basic logging with all PSR-3 levels
$logger->emergency('System is unusable');
$logger->alert('Action must be taken immediately');
$logger->critical('Critical conditions');
$logger->error('Error conditions', ['error' => $exception->getMessage()]);
$logger->warning('Warning conditions');
$logger->notice('Normal but significant condition');
$logger->info('Informational messages');
$logger->debug('Debug-level messages');

// Contextual logging
$logger->info('User login', [
    'user_id' => 123,
    'ip_address' => $request->getClientIp(),
    'user_agent' => $request->headers->get('User-Agent')
]);
```

### Advanced Features

#### Performance Timing

```php
// Time operations
$timerId = $logger->startTimer('database_operation');

// Perform your operation
$results = $this->performDatabaseOperation();

// End timing (automatically logs duration)
$duration = $logger->endTimer($timerId);

// Manual timing
$logger->timeOperation('user_lookup', function() {
    return $this->userRepository->findById($userId);
});
```

#### Memory Monitoring

```php
// Get current memory usage
$memoryUsage = $logger->getCurrentMemoryUsage();

// Log with automatic memory context
$logger->info('Processing complete', [], true); // true = include memory info

// Memory warnings are automatic when thresholds exceeded
```

#### Batch Logging

```php
// Configure batch mode for high-volume logging
$logger->configure([
    'batch_mode' => true,
    'batch_size' => 100,
    'flush_interval' => 30 // seconds
]);

// Manual flush when needed
$logger->flushBatch();
```

## Channel-Based Logging

Glueful uses multiple channels to organize different types of logs:

### Available Channels

1. **app** - General application logging
2. **api** - API request/response logging
3. **framework** - Framework internals and performance
4. **error** - Error and exception logging
5. **debug** - Development and debugging information

### Using Channels

```php
// Channel-specific logging
$logger->channel('api')->info('API request processed', $context);
$logger->channel('error')->error('Database connection failed', $context);
$logger->channel('debug')->debug('Cache miss', ['key' => $cacheKey]);

// Switch channels
$apiLogger = $logger->channel('api');
$apiLogger->info('Request started');
$apiLogger->info('Request completed');
```

## Database Logging

Store logs in the database for structured querying and analysis.

### Setup

Database logging uses the `app_logs` table with automatic schema creation:

```sql
CREATE TABLE app_logs (
    id VARCHAR(255) PRIMARY KEY,
    level VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    context JSON,
    execution_time DECIMAL(10,4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level (level),
    INDEX idx_created_at (created_at)
);
```

### Usage

```php
use Glueful\Logging\DatabaseLogHandler;

// Enable database logging
$logger->pushHandler(new DatabaseLogHandler($connection));

// Logs are automatically stored in database
$logger->info('User action', [
    'user_id' => 123,
    'action' => 'profile_update',
    'ip_address' => $request->getClientIp()
]);
```

### Querying Database Logs

```php
use Glueful\Logging\DatabaseLogPruner;

$pruner = new DatabaseLogPruner($connection);

// Get recent logs
$recentErrors = $pruner->getLogsByLevel('error', 100);

// Get logs by date range
$logs = $pruner->getLogsByDateRange('2025-01-01', '2025-01-31');

// Search logs by context
$userLogs = $pruner->searchLogs(['user_id' => 123]);
```

## Query Performance Logging

Glueful includes sophisticated database query logging with performance analysis.

### Features

- **Slow Query Detection**: Configurable thresholds with automatic alerts
- **N+1 Query Detection**: Pattern recognition with recommendations
- **Query Complexity Analysis**: Scoring based on joins, subqueries, aggregations
- **Performance Statistics**: Comprehensive tracking by query type and performance

### Basic Usage

```php
use Glueful\Database\QueryLogger;

$queryLogger = new QueryLogger($logger);

// Enable query logging
$queryLogger->configure(true, true); // enable logging, enable analysis

// Log a query (usually automatic via database layer)
$startTime = $queryLogger->startTiming();
$result = $connection->select($sql, $params);
$queryLogger->logQuery($sql, $params, $startTime, null, 'user_lookup');
```

### N+1 Query Detection

```php
// Configure N+1 detection
$queryLogger->configureN1Detection(
    threshold: 5,        // Detect when 5+ similar queries
    timeWindow: 5        // Within 5 seconds
);

// Automatic detection and recommendations
// Example log output:
// "N+1 Query Pattern Detected: 15 similar queries in 2.3 seconds
//  Query: SELECT * FROM orders WHERE user_id = ?
//  Recommendation: Use eager loading or joins to reduce query count"
```

### Slow Query Analysis

```php
// Configure slow query detection
$queryLogger->setSlowQueryThreshold(100); // 100ms

// Automatic logging of slow queries with analysis
// Example output:
// "Slow Query Detected (234ms): Complex JOIN operation
//  Suggestions: Add index on user_id, consider query optimization"
```

## HTTP Request Logging

Comprehensive HTTP request and response logging via middleware.

### Features

- **Complete Request Lifecycle**: From request start to response completion
- **Performance Monitoring**: Request timing and slow request detection
- **Error Correlation**: Request ID tracking across logs
- **Context Injection**: Automatic request context in all logs

### Setup

```php
use Glueful\Http\Middleware\LoggerMiddleware;

// Add to middleware stack
Router::addMiddleware(new LoggerMiddleware());

// Or with custom configuration
Router::addMiddleware(new LoggerMiddleware('api', 'info'));
```

### Features

```php
// Automatic logging includes:
// - Request method, URL, headers
// - Request body (sanitized)
// - Response status, headers
// - Execution time
// - Memory usage
// - User context (if authenticated)

// Example log output:
// [INFO] HTTP Request: POST /api/users
// Context: {
//   "request_id": "req_abc123",
//   "method": "POST",
//   "url": "/api/users",
//   "user_id": 123,
//   "ip_address": "192.168.1.100",
//   "execution_time": 45.2,
//   "memory_usage": "2.3MB",
//   "response_status": 201
// }
```

## Performance Monitoring

### Built-in Performance Tracking

```php
// Memory usage monitoring
$logger->logMemoryUsage('After database operation');

// Execution time tracking
$logger->timeOperation('complex_calculation', function() {
    return $this->performComplexCalculation();
});

// Request correlation
$logger->setRequestId($requestId);
$logger->info('Processing started'); // Automatically includes request_id
```

### Performance Alerts

```php
// Configure performance thresholds
$logger->configure([
    'slow_request_threshold' => 1000,    // 1 second
    'memory_warning_threshold' => '128M',
    'memory_critical_threshold' => '256M'
]);

// Automatic alerts when thresholds exceeded
// [WARNING] Slow request detected: 1.2s execution time
// [CRITICAL] Memory usage critical: 280MB used
```

## Configuration

### Framework Logging Settings

Framework logging is configured in `config/logging.php`:

```php
'framework' => [
    'enabled' => env('FRAMEWORK_LOGGING_ENABLED', true),
    'level' => env('FRAMEWORK_LOG_LEVEL', 'info'),
    'channel' => env('FRAMEWORK_LOG_CHANNEL', 'framework'),
    
    // Feature-specific toggles
    'log_exceptions' => env('LOG_FRAMEWORK_EXCEPTIONS', true),
    'log_deprecations' => env('LOG_FRAMEWORK_DEPRECATIONS', true),
    'log_lifecycle' => env('LOG_FRAMEWORK_LIFECYCLE', true),
    'log_protocol_errors' => env('LOG_FRAMEWORK_PROTOCOL_ERRORS', true),
    
    // Performance monitoring (optional)
    'slow_requests' => [
        'enabled' => env('LOG_SLOW_REQUESTS', true),
        'threshold_ms' => env('SLOW_REQUEST_THRESHOLD', 1000),
    ],
    'slow_queries' => [
        'enabled' => env('LOG_SLOW_QUERIES', true),
        'threshold_ms' => env('SLOW_QUERY_THRESHOLD', 200),
    ],
    'http_client' => [
        'log_failures' => env('LOG_HTTP_CLIENT_FAILURES', true),
        'slow_threshold_ms' => env('HTTP_CLIENT_SLOW_THRESHOLD', 5000)
    ]
],

'channels' => [
    'app' => [
        'driver' => 'daily',
        'path' => 'storage/logs/app.log',
        'level' => 'info',
        'days' => 14
    ],
    'api' => [
        'driver' => 'daily',
        'path' => 'storage/logs/api.log',
        'level' => 'info',
        'days' => 30
    ],
    'error' => [
        'driver' => 'daily',
        'path' => 'storage/logs/error.log',
        'level' => 'error',
        'days' => 90
    ]
],

'database' => [
    'enabled' => false,
    'table' => 'app_logs',
    'retention_days' => 30
]
```

### Environment Variables

```env
# Framework Logging (Infrastructure/Protocol concerns)
FRAMEWORK_LOGGING_ENABLED=true
FRAMEWORK_LOG_LEVEL=info
LOG_FRAMEWORK_EXCEPTIONS=true
LOG_FRAMEWORK_DEPRECATIONS=true
LOG_FRAMEWORK_LIFECYCLE=true
LOG_FRAMEWORK_PROTOCOL_ERRORS=true

# Framework Performance Monitoring
LOG_SLOW_REQUESTS=true
SLOW_REQUEST_THRESHOLD=1000
LOG_SLOW_QUERIES=true
SLOW_QUERY_THRESHOLD=200
LOG_HTTP_CLIENT_FAILURES=true
HTTP_CLIENT_SLOW_THRESHOLD=5000

# Application logging
APP_LOG_LEVEL=info
DATABASE_LOGGING_ENABLED=false
LOG_RETENTION_DAYS=30
LOG_SAMPLING_RATE=1.0
```

## Deprecation Management

Configure deprecated API endpoints in `config/api.php`:

```php
'deprecated_routes' => [
    '/api/v1/users' => [
        'since' => '2.0.0',
        'removal_version' => '3.0.0',
        'replacement' => '/api/v2/users',
        'reason' => 'Improved user data structure'
    ],
    'GET /api/legacy/*' => [
        'since' => '1.5.0',
        'removal_version' => '2.0.0',
        'replacement' => '/api/v2/*'
    ]
]
```

The framework will automatically:
- Log deprecation warnings to the framework channel
- Add deprecation headers to responses
- Provide client guidance for migration

## Log Maintenance

### Automatic Cleanup

```php
use Glueful\Cron\LogCleaner;

$cleaner = new LogCleaner();

// Clean old log files
$fileStats = $cleaner->cleanLogFiles(30); // 30 days retention

// Clean database logs
$dbStats = $cleaner->cleanDatabaseLogs(30);

// Get cleanup summary
$summary = $cleaner->getCleanupSummary();
/*
[
    'files_cleaned' => 45,
    'files_size_freed' => '234MB',
    'db_records_cleaned' => 15000,
    'errors' => []
]
*/
```

### Manual Maintenance

```bash
# CLI commands for log maintenance
php glueful logs:clean --days=30
php glueful logs:rotate
php glueful logs:analyze --performance
```

### Database Log Pruning

```php
use Glueful\Logging\DatabaseLogPruner;

$pruner = new DatabaseLogPruner($connection);

// Clean logs older than 30 days
$cleaned = $pruner->pruneLogs(30);

// Clean by quantity (keep last 10000 records)
$cleaned = $pruner->pruneByQuantity(10000);

// Get statistics
$stats = $pruner->getLogStatistics();
```

## Production Best Practices

### Framework Logging (Production)
```env
FRAMEWORK_LOGGING_ENABLED=true
FRAMEWORK_LOG_LEVEL=error  # Only log errors in production
LOG_FRAMEWORK_EXCEPTIONS=true
LOG_FRAMEWORK_DEPRECATIONS=true
LOG_FRAMEWORK_LIFECYCLE=false  # Reduce noise
LOG_FRAMEWORK_PROTOCOL_ERRORS=true

# Performance monitoring (adjust thresholds for production)
SLOW_REQUEST_THRESHOLD=2000  # 2 seconds
SLOW_QUERY_THRESHOLD=500     # 500ms
```

### Application Logging (Production)
```env
LOG_LEVEL=error  # Application should log errors/warnings
LOG_TO_FILE=true
LOG_TO_DB=false  # Consider impact on performance
LOG_ROTATION_DAYS=30
```

### High-Volume Environments

```php
// Configure for production
$logger->configure([
    'sampling_rate' => 0.1,           // Log only 10% of entries
    'batch_mode' => true,             // Batch writes for performance
    'batch_size' => 100,              // Write 100 entries at once
    'flush_interval' => 30,           // Flush every 30 seconds
    'minimum_level' => 'warning',     // Only log warnings and above
    'memory_limit' => '64M'           // Limit memory usage
]);
```

### Security Configuration

```php
// Ensure sensitive data is sanitized
$logger->configure([
    'sanitize_data' => true,
    'sensitive_keys' => ['password', 'token', 'api_key', 'secret'],
    'redaction_text' => '[REDACTED]'
]);
```

### Monitoring and Alerting

#### Framework Logs to Monitor
- High frequency of HTTP protocol errors (potential attacks)
- Unhandled exceptions (framework stability)
- Slow query/request patterns (performance issues)
- Deprecation usage (migration planning)

#### Application Logs to Monitor
- Authentication failure patterns (security)
- Business critical operation failures (reliability)
- User behavior anomalies (fraud detection)
- External service failures (integration health)

## Troubleshooting

### Common Issues

#### No framework logs appearing
- Check `FRAMEWORK_LOGGING_ENABLED=true`
- Verify log file permissions
- Check framework log level configuration

#### Too many framework logs
- Increase `FRAMEWORK_LOG_LEVEL` (debug → info → warning → error)
- Disable specific features (lifecycle, deprecations)
- Adjust performance thresholds

#### Missing application context
- Ensure event listeners are registered
- Use contextual logger in controllers
- Verify user context is available after authentication

#### High Memory Usage

```php
// Check memory configuration
$logger->configure([
    'memory_limit' => '32M',          // Lower memory limit
    'batch_size' => 50,               // Smaller batches
    'flush_interval' => 15            // More frequent flushes
]);
```

#### Slow Logging Performance

```php
// Optimize for speed
$logger->configure([
    'async_writing' => true,
    'sampling_rate' => 0.05,          // Log only 5%
    'minimum_level' => 'error',       // Only errors
    'batch_mode' => true
]);
```

### Log File Locations
- Framework: `storage/logs/framework.log`
- Application: `storage/logs/app.log`
- API: `storage/logs/api.log`
- Errors: `storage/logs/error.log`

## Best Practices

### ✅ Do
- Use events for business logging
- Leverage contextual logging for request correlation
- Log user actions and business state changes
- Configure appropriate log levels for each environment
- Use structured logging with consistent field names

### ❌ Don't
- Log business logic in framework middleware
- Duplicate framework logging in application code
- Log sensitive information (passwords, tokens, PII)
- Create custom logging for framework concerns
- Mix business and infrastructure logging

## Summary

Glueful's logging architecture provides:

✅ **Automatic framework logging** for infrastructure concerns
🔧 **Event-driven application logging** for business concerns  
📊 **Request context correlation** for debugging
⚡ **Configurable performance monitoring**
🔒 **Security-focused event emission**
📈 **Production-ready log management**
🚀 **Advanced Performance Monitoring**: Automatic detection of slow queries, N+1 problems, and performance issues
🏭 **Production-Ready Features**: Sampling, batching, rotation, and cleanup
💾 **Multiple Storage Options**: Files, database, and custom handlers
🔒 **Security-First Design**: Automatic data sanitization and secure logging practices
🛠️ **Developer-Friendly**: Rich debugging information and performance insights

This approach ensures you get comprehensive infrastructure monitoring automatically while maintaining full control over your application-specific logging requirements.