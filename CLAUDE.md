# CLAUDE.md

> **Agent Workflow**: Follow the rules in `~/.agents/AGENT_WORKFLOW.md`

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Glueful Framework** is a high-performance PHP 8.3+ API framework designed for enterprise applications. It provides a comprehensive set of features including authentication, caching, queuing, database management, extensions, and advanced security features.

## Development Commands

### Testing and Quality Assurance
```bash
# Run all tests
composer test

# Run specific test suites
composer run test:unit
composer run test:integration
composer run test:coverage

# Code quality checks
composer run phpcs        # Check coding standards
composer run phpcbf       # Fix coding standards automatically

# Static analysis (PHPStan)
composer run analyse      # Analyze entire src/ directory
composer run analyse:strict    # Level 8 strict analysis
composer run analyse:changed   # Only analyze changed files vs main branch
composer run phpstan     # Full analysis with memory optimization

# Component-specific analysis
composer run phpstan:di   # Dependency injection components (level 8)
composer run phpstan:db   # Database components (level 8) 
composer run phpstan:http # HTTP components (level 7)
composer run phpstan:cache # Cache components with strict config

# Validation and CI
composer run types:validate  # Validate all component types
composer run ci          # Full CI suite (test + analyse + phpcs)
composer run ci:fast     # Fast CI (only changed files)

# Production optimization
composer run optimize    # Optimize autoloader for production
```

### Framework CLI Commands

**Essential Development Commands:**
```bash
# Start development server
php glueful serve

# Generate application key
php glueful generate:key

# Database operations
php glueful migrate:run                    # Run pending migrations
php glueful migrate:rollback               # Rollback last migration  
php glueful migrate:status                 # Check migration status
php glueful db:status                      # Check database connection

# Cache management
php glueful cache:clear                    # Clear all cache
php glueful cache:clear --tag=users        # Clear specific cache tags
php glueful cache:status                   # View cache statistics

# Queue operations
php glueful queue:work                     # Start queue worker
php glueful queue:autoscale               # Auto-scale queue workers

# Security commands
php glueful security:check                # Security health check
php glueful security:scan                 # Security vulnerability scan

# Encryption commands
php glueful encryption:test               # Verify encryption service works
php glueful encryption:file encrypt /path/to/file   # Encrypt a file
php glueful encryption:file decrypt /path/to/file.enc  # Decrypt a file
php glueful encryption:rotate --table=users --columns=ssn  # Re-encrypt DB columns

# Extension management
php glueful extensions:info               # List all extensions
php glueful extensions:enable <name>      # Enable extension
php glueful extensions:disable <name>     # Disable extension

# System utilities
php glueful system:check                  # System health check
php glueful help                          # List all available commands
```

**Scaffold Commands:**
```bash
# Model and Controller scaffolding
php glueful scaffold:model User --fillable=name,email --migration
php glueful scaffold:controller UserController --resource
php glueful scaffold:request CreateUserRequest
php glueful scaffold:resource UserResource --model

# Middleware scaffolding
php glueful scaffold:middleware RateLimitMiddleware
php glueful scaffold:middleware Admin/AuthMiddleware  # Nested namespace

# Filter scaffolding
php glueful scaffold:filter UserFilter                # Query filter class

# Queue job scaffolding
php glueful scaffold:job ProcessPayment
php glueful scaffold:job SendNewsletter --queue=emails --tries=5 --backoff=120
php glueful scaffold:job GenerateReport --unique

# Event scaffolding
php glueful event:create UserRegistered                         # Basic event
php glueful event:create Auth/LoginFailed                       # Event in subdirectory
php glueful event:create SecurityAlert --type=security          # Event with category
php glueful event:listener SendWelcomeEmail                     # Basic listener
php glueful event:listener SendWelcomeEmail --event=App\\Events\\UserRegisteredEvent

# Validation rule scaffolding
php glueful scaffold:rule UniqueEmail
php glueful scaffold:rule PasswordStrength --params=minLength,requireNumbers
php glueful scaffold:rule RequiredWithoutField --implicit

# Test scaffolding
php glueful scaffold:test UserServiceTest              # Unit test (default)
php glueful scaffold:test UserApiTest --feature        # Feature test
php glueful scaffold:test PaymentTest --methods=testCharge,testRefund
php glueful scaffold:test Services/UserServiceTest --class=App\\Services\\UserService
```

## Architecture Overview

### Core Framework Structure

**Bootstrap Flow:**
1. `src/Framework.php` - Framework initialization and configuration
2. `src/Application.php` - Application lifecycle management
3. `src/Bootstrap/ApplicationContext.php` - Request-scoped context with container access

**Key Architectural Components:**

- **Router System**: `src/Routing/` - High-performance router with O(1) static route lookup, route caching, attribute-based routing, and advanced middleware pipeline
- **Dependency Injection**: `src/Container/` - Full container with service providers and compilation
- **HTTP Layer**: `src/Http/` - Request/response handling and foundational HTTP components
- **API Resources**: `src/Http/Resources/` - JSON transformation layer for consistent API responses
- **Database**: `src/Database/` - Query builder, migrations, connection pooling  
- **Authentication**: `src/Auth/` - JWT, LDAP, SAML, API keys
- **Caching**: `src/Cache/` - Distributed caching with Redis/Memcached
- **Queue System**: `src/Queue/` - Job processing with Redis/Database backends
- **Extensions**: `src/Extensions/` - Modular extension system
- **Security**: `src/Security/` - Rate limiting, vulnerability scanning, lockdown mode
- **Encryption**: `src/Encryption/` - AES-256-GCM encryption with key rotation support
- **File Uploads**: `src/Uploader/` - File uploads with thumbnails, media metadata extraction
- **Storage**: `src/Storage/` - Flysystem-based storage abstraction (local, S3, etc.)

### Configuration System

Configuration files in `config/` directory:
- `app.php` - Core application settings, paths, performance
- `database.php` - Database connections and pooling
- `cache.php` - Cache drivers and distribution
- `security.php` - Security policies and rate limits
- `session.php` - Session/JWT authentication settings
- `encryption.php` - Encryption keys and settings
- `uploads.php` - File upload settings, blob visibility, signed URLs
- `filesystem.php` - Storage disks, thumbnail generation settings

Environment variables override config values using `env()` helper.

### Service Provider Architecture

Service providers in `src/Container/Providers/` register services:
- `CoreProvider.php` - Essential framework services
- `FileProvider.php` - File operations and storage
- `ImageProvider.php` - Image processing (Intervention Image)
- `StorageProvider.php` - Flysystem storage disks
- `LockProvider.php` - Distributed locking

Additional providers in specialized directories:
- `src/Auth/AuthBootstrap.php` - Authentication providers
- `src/Events/ServiceProvider/EventProvider.php` - Event system
- `src/Http/ServiceProvider/HttpClientProvider.php` - HTTP client

### Router Architecture

**High-Performance Routing:**
- **O(1) Static Route Lookup**: Static routes use hash table for constant-time lookups
- **Route Bucketing**: Dynamic routes grouped by first path segment for performance
- **Route Caching**: Compiled route cache with opcache integration
- **Reflection Caching**: Method/function reflection cached for parameter resolution

**Advanced Features:**
- **Attribute-Based Routing**: Use PHP 8 attributes for controller route definitions
- **Middleware Pipeline**: Enhanced middleware system with `RouteMiddleware` interface
- **Parameter Resolution**: Automatic DI container injection and type casting
- **Route Groups**: Nested route groups with prefix and middleware inheritance
- **CORS Support**: Built-in CORS handling with configurable policies

**Router Usage Patterns:**
```php
// Basic route registration
$router->get('/users/{id}', [UserController::class, 'show'])
    ->where('id', '\d+')
    ->middleware(['auth', 'rate_limit'])
    ->name('users.show');

// Route groups with shared configuration
$router->group(['prefix' => '/api/v1', 'middleware' => ['auth']], function($router) {
    $router->get('/users', [UserController::class, 'index']);
    $router->post('/users', [UserController::class, 'store']);
});

// Attribute-based routing in controllers
#[Controller(prefix: '/api')]
class UserController {
    #[Get('/users/{id}', where: ['id' => '\d+'])]
    public function show(int $id): Response { }
}
```

**API URL Configuration:**

The framework supports flexible API URL patterns. Configure in `.env`:

```env
# Pattern A: Subdomain (api.example.com/v1/users)
API_USE_PREFIX=false
API_VERSION_IN_PATH=true

# Pattern B: Path prefix (example.com/api/v1/users) - default
API_USE_PREFIX=true
API_PREFIX=/api
API_VERSION_IN_PATH=true
```

**Important:** Application routes in `routes/api.php` must use the `api_prefix()` helper:

```php
// routes/api.php (receives $context from framework)
$router->group(['prefix' => api_prefix($context)], function ($router) {
    $router->get('/users', [UserController::class, 'index']);
});
```

Helper functions available (all require `ApplicationContext`):
- `api_prefix($context)` - Returns route prefix (e.g., `/api/v1`)
- `api_url($context, '/path')` - Returns full URL (e.g., `https://api.example.com/v1/path`)
- `is_api_path($context, $path)` - Checks if path matches API prefix

See [docs/API_URLS.md](docs/API_URLS.md) for complete documentation.

### Database Architecture

**Connection Management:**
- Connection pooling with `ConnectionPool.php` and `ConnectionPoolManager.php`
- Multiple database drivers: MySQL, PostgreSQL, SQLite
- Query builder with advanced features in `src/Database/Query/`

**Query Building Pattern:**
```php
// Via connection (inject or resolve from container)
$connection = app($context, Connection::class);
$users = $connection->table('users')
    ->where('status', 'active')
    ->join('profiles', 'users.id', 'profiles.user_id')
    ->paginate(25);

// Or via Model static methods
$users = User::query()
    ->where('status', 'active')
    ->with('profile')
    ->paginate(25);
```

### Authentication System

**Multi-Provider Support:**
- JWT authentication via `JwtAuthenticationProvider.php`
- LDAP integration via `LdapAuthenticationProvider.php` 
- SAML SSO via `SamlAuthenticationProvider.php`
- API key authentication via `ApiKeyAuthenticationProvider.php`

**Session Management:**
- Advanced session analytics in `SessionAnalytics.php`
- Session caching with `SessionCacheManager.php`
- Transaction-safe sessions with `SessionTransaction.php`

### API Resources

**JSON Transformation Layer:**
- `JsonResource` - Base class for transforming data to JSON
- `ModelResource` - ORM-aware resource with relationship helpers
- `ResourceCollection` - Collection handling with pagination
- `PaginatedResourceResponse` - Advanced pagination with link generation

**Resource Usage Patterns:**
```php
// Basic resource
class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->resource['uuid'],
            'name' => $this->resource['name'],
            'email' => $this->resource['email'],
            'posts' => $this->whenLoaded('posts'),  // Only if loaded
        ];
    }
}

// In controller - single resource
public function show(int $id): Response
{
    $user = User::with('posts')->find($id);
    return UserResource::make($user)->toResponse();
}

// In controller - collection with pagination
public function index(): Response
{
    $result = User::query()->paginate(page: 1, perPage: 25);
    return UserResource::collection($result['data'])
        ->withPaginationFrom($result)
        ->withLinks('/api/users')
        ->toResponse();
}
```

**Conditional Attributes:**
- `when($condition, $value)` - Include attribute conditionally
- `mergeWhen($condition, $attributes)` - Merge multiple attributes conditionally
- `whenLoaded($relation)` - Include only if relationship is loaded
- `whenCounted($relation)` - Include relationship count if loaded
- `whenPivotLoaded($table, $attribute)` - Access pivot data

**Scaffolding:**
```bash
php glueful scaffold:resource UserResource           # Basic resource
php glueful scaffold:resource UserResource --model   # ORM model resource
php glueful scaffold:resource UserCollection --collection  # Collection
```

See [docs/RESOURCES.md](docs/RESOURCES.md) for complete documentation.

### Event System

**Scaffold Commands:**
```bash
php glueful event:create UserRegistered              # Create event class
php glueful event:create Auth/LoginFailed            # Event in subdirectory
php glueful event:listener SendWelcomeEmail          # Create listener class
php glueful event:listener AuditLog --event=App\\Events\\UserCreatedEvent
```

**Event Development:**
- **All events MUST extend `Glueful\Events\Contracts\BaseEvent`** (PSR-14 compliant)
- BaseEvent provides: event IDs, timestamps, metadata support, propagation control
- Custom events should call `parent::__construct()` and use BaseEvent's metadata methods

**Example Custom Event:**
```php
use Glueful\Events\Contracts\BaseEvent;

class OrderShippedEvent extends BaseEvent
{
    public function __construct(
        public readonly string $orderId,
        array $metadata = []
    ) {
        parent::__construct(); // Required for BaseEvent features

        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
    }
}
```

**Dispatching Events:**
```php
// Via injected EventService (preferred)
$this->events->dispatch(new OrderShippedEvent($orderId));

// Via container
app($context, EventService::class)->dispatch(new OrderShippedEvent($orderId));
```

**Registering Listeners:**
```php
// config/events.php
return [
    'listeners' => [
        \App\Events\UserCreatedEvent::class => [
            \App\Events\Listeners\SendWelcomeEmailListener::class,
        ],
    ],
];
```

### Middleware Development

**Router Middleware:**
- **All middleware MUST implement `Glueful\Routing\Middleware\RouteMiddleware`** interface
- Method signature: `handle(Request $request, callable $next, ...$params): Response`
- Runtime parameter support for configurable middleware behavior
- Enhanced error handling with structured responses

**Middleware Development Pattern:**
```php
use Glueful\Routing\Middleware\RouteMiddleware;
use Symfony\Component\HttpFoundation\{Request, Response};

class MyMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, ...$params): Response
    {
        // Pre-processing with configurable parameters
        $config = $this->parseParams($params);
        
        // Process request
        $response = $next($request);
        
        // Post-processing with structured error handling
        return $this->processResponse($response, $config);
    }
    
    private function parseParams(array $params): array
    {
        // Handle runtime configuration
        return array_merge($this->getDefaults(), $params);
    }
}
```

**Middleware Registration:**
```php
// In service provider
$container->set('my_middleware', MyMiddleware::class);

// Usage in routes
$router->get('/protected', $handler)
    ->middleware(['my_middleware:param1,param2']);

// Field selection middleware usage
$router->group(['middleware' => ['field_selection']], function ($router) {
    $router->get('/users/{id}', [UserController::class, 'show']);
    $router->get('/posts', [PostController::class, 'index']);
});
```

### Extension System

**Extension Development:**
- Base class: `src/Extensions/ServiceProvider.php`
- Extensions located in `extensions/` directory
- Each extension has its own service provider class
- Extension lifecycle: register → boot

**Creating Extensions:**
```bash
php glueful extensions:create MyExtension
```

**Extension Structure:**
```php
use Glueful\Extensions\ServiceProvider;
use Glueful\Bootstrap\ApplicationContext;

class MyExtension extends ServiceProvider
{
    // Return service definitions for DI compilation
    public static function services(): array
    {
        return [
            MyService::class => ['class' => MyService::class, 'shared' => true],
        ];
    }

    // Runtime configuration (routes, config merging)
    public function register(ApplicationContext $context): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }

    // Boot after all providers registered
    public function boot(ApplicationContext $context): void
    {
        // Optional initialization
    }
}
```

### GraphQL-Style Field Selection

**REST API Enhancement:**
- **Dual Syntax Support**: Both REST-style (`?fields=id,name&expand=posts.comments`) and GraphQL-style (`?fields=user(id,name,posts(title,comments(text)))`)
- **N+1 Prevention**: Built-in expander system for batch loading relations
- **Performance Protection**: Configurable depth limits, field counts, and item limits
- **Route-Level Security**: Whitelist enforcement via `#[Fields]` attributes

**Core Components:**
- `FieldSelector` in `src/Support/FieldSelection/` - Lightweight request parser and validator
- `Projector` - Handles field projection with expander support for efficient data loading
- `FieldSelectionMiddleware` - Automatic request/response processing
- `#[Fields]` attribute - Declarative route-level field restrictions

**Usage Examples:**
```php
use Glueful\Support\FieldSelection\FieldSelector;
use Glueful\Routing\Attributes\{Get, Fields};

#[Get('/users/{id}')]
#[Fields(allowed: ['id', 'name', 'email', 'posts', 'posts.comments'], strict: true)]
public function getUser(int $id, FieldSelector $selector): array
{
    $user = $this->userRepo->findAsArray($id);
    
    // Conditional loading based on requested fields
    if ($selector->requested('posts')) {
        $user['posts'] = $this->userRepo->findPostsForUser($id);
    }
    
    if ($selector->requested('posts.comments')) {
        // Batch load to prevent N+1 queries
        $postIds = array_column($user['posts'] ?? [], 'id');
        $comments = $this->userRepo->findCommentsForPosts($postIds);
        // ... group and attach comments
    }
    
    return $user; // Middleware automatically applies field projection
}
```

**Request Formats:**
```bash
# REST-style
GET /users/123?fields=id,name,email&expand=posts.title,posts.comments.text

# GraphQL-style  
GET /users/123?fields=user(id,name,posts(title,comments(text)))

# Wildcard with expand
GET /users/123?fields=*&expand=posts.comments
```

### Performance Features

**Memory Management:**
- Memory monitoring and alerting via `src/Performance/MemoryManager.php`
- Memory pools for object reuse
- Chunked processing for large datasets
- Streaming iterators for memory efficiency

**Caching Strategy:**
- Multi-layer caching with tagging
- Distributed cache with replication strategies
- Edge caching and CDN integration
- Cache invalidation patterns

## Development Guidelines

### Router System Notes

**Current Architecture:**
- Framework uses the modern `src/Routing/` system with high-performance routing
- All middleware use `RouteMiddleware` interface (not PSR-15 `MiddlewareInterface`)
- Route cache clearing may be needed during integration tests to prevent state leakage
- See `docs/MIDDLEWARE_MIGRATION_ROADMAP.md` for middleware development patterns and completed migrations

### Code Organization

**Follow existing patterns:**
- Use dependency injection containers, not static calls
- Implement interfaces for testability
- Use service providers for service registration
- Follow PSR-4 autoloading (namespace `Glueful`)

**Common Patterns:**
```php
// Controller pattern
class MyController extends BaseController
{
    public function __construct(
        private readonly MyService $service,
        private readonly LoggerInterface $logger
    ) {}
}

// Repository pattern  
class MyRepository extends BaseRepository
{
    use TransactionTrait;
    
    protected string $table = 'my_table';
}

// Extension pattern
use Glueful\Extensions\ServiceProvider;

class MyExtension extends ServiceProvider
{
    public static function services(): array
    {
        return [MyService::class => ['class' => MyService::class, 'shared' => true]];
    }

    public function register(ApplicationContext $context): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
```

### Environment Setup

**Required Environment Variables:**
```env
# Essential settings (configure in .env file)
APP_ENV=development
JWT_KEY=generated-key
DB_HOST=localhost
DB_DATABASE=glueful
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
```

**Development Features:**
- Query monitoring enabled in development (`DevelopmentQueryMonitor`)
- Var dumper integration
- Debug mode with detailed error reporting
- API documentation auto-enabled (disabled in production)

### Security Considerations

**Always enabled in production:**
- HTTPS enforcement (`FORCE_HTTPS=true`)
- Security headers middleware
- Rate limiting with adaptive algorithms
- CSRF protection
- Input validation and sanitization

**Security scanning:**
```bash
php glueful security:scan           # Comprehensive security scan
php glueful security:vulnerabilities # Check known vulnerabilities
```

### Database Migrations

**Migration workflow:**
```bash
php glueful migrate:create create_my_table   # Create migration
# Edit the generated migration file
php glueful migrate:run                      # Apply migration
```

**Migration best practices:**
- Use schema builders, not raw SQL
- Include rollback logic
- Test migrations on staging first
- Use database transactions for safety

### Performance Optimization

**Database:**
- Use connection pooling (enabled by default)
- Implement query caching for read-heavy operations
- Use chunked processing for large datasets
- Monitor queries with `php glueful db:profile`

**Caching:**
- Use cache tags for organized invalidation
- Implement distributed caching for multi-server setups
- Use edge caching for static content
- Monitor cache performance with `php glueful cache:status`

**Memory:**
- Enable memory monitoring in production
- Use memory pools for frequently created objects
- Implement streaming for large data processing
- Monitor with `php glueful system:memory`

### Image Processing

The framework uses Intervention Image v3 for modern image processing with comprehensive caching, security, and performance features.

**Basic Usage:**
```php
// Helper function (requires context)
image($context, '/path/to/image.jpg')
    ->resize(800, 600)
    ->quality(85)
    ->save('/path/to/output.jpg');

// Service injection
$processor = app($context, ImageProcessorInterface::class);
$result = $processor::make('/path/to/image.jpg')
    ->fit(300, 300)
    ->cached('thumbnail-300x300', 3600)
    ->getImageData();
```

**Advanced Operations:**
```php
// Remote image processing with security
image($context, 'https://example.com/image.jpg')
    ->resize(800, 600)
    ->watermark('/path/to/logo.png', 'bottom-right')
    ->quality(85)
    ->format('webp')
    ->cached()
    ->save('/path/to/output.webp');

// Batch operations
$processor = image($context, '/path/to/image.jpg');
$thumbnail = $processor->copy()->resize(200, 200)->getImageData();
$large = $processor->resize(1200, 800)->getImageData();
```

**Configuration:**
```env
# Driver selection (gd|imagick)
IMAGE_DRIVER=gd

# Processing limits
IMAGE_MAX_WIDTH=2048
IMAGE_MAX_HEIGHT=2048
IMAGE_MAX_FILESIZE=10M

# Quality settings
IMAGE_JPEG_QUALITY=85
IMAGE_WEBP_QUALITY=80

# Security
IMAGE_DISABLE_EXTERNAL_URLS=false
IMAGE_VALIDATE_MIME=true
IMAGE_CHECK_INTEGRITY=true

# Caching
IMAGE_CACHE_ENABLED=true
IMAGE_CACHE_TTL=86400
```

**Security Features:**
- Domain whitelisting for remote images
- MIME type validation and integrity checks
- File size and dimension limits
- Suspicious pattern detection
- Safe memory usage controls

**Performance:**
- Built-in caching with framework integration
- Memory-efficient processing with limits
- Support for both GD and ImageMagick drivers
- Lazy loading and streaming capabilities

### Encryption Service

The framework provides AES-256-GCM authenticated encryption for strings, files, and database fields with key rotation support.

**Basic Usage:**
```php
use Glueful\Encryption\EncryptionService;

// Inject via DI or resolve from container
$encryption = app($context, EncryptionService::class);

// Encrypt/decrypt strings
$encrypted = $encryption->encrypt('sensitive data');
$decrypted = $encryption->decrypt($encrypted);

// With AAD (Additional Authenticated Data) for context binding
$encrypted = $encryption->encrypt($ssn, aad: 'user.ssn');
$decrypted = $encryption->decrypt($encrypted, aad: 'user.ssn');

// Binary data
$encrypted = $encryption->encryptBinary($binaryData);
$decrypted = $encryption->decryptBinary($encrypted);

// Check if value is encrypted
if ($encryption->isEncrypted($value)) {
    $value = $encryption->decrypt($value);
}
```

**File Encryption:**
```php
// Encrypt entire files
$encryption->encryptFile('/path/to/file.pdf', '/path/to/file.pdf.enc');
$encryption->decryptFile('/path/to/file.pdf.enc', '/path/to/file.pdf');
```

**Configuration:**
```env
# Primary encryption key (32 bytes, base64 encoded)
APP_KEY=base64:AbCdEfGhIjKlMnOpQrStUvWxYz0123456789ABCD=

# Previous keys for rotation (comma-separated)
APP_PREVIOUS_KEYS=base64:OldKey1...,base64:OldKey2...
```

**CLI Commands:**
```bash
php glueful encryption:test                              # Verify encryption works
php glueful encryption:file encrypt /path/to/file        # Encrypt file
php glueful encryption:file decrypt /path/to/file.enc    # Decrypt file
php glueful encryption:rotate --table=users --columns=ssn,api_key  # Re-encrypt DB
```

**Key Features:**
- AES-256-GCM authenticated encryption (industry standard)
- Random nonce per encryption (prevents ciphertext repetition)
- AAD support for context binding (prevents field swapping attacks)
- O(1) key lookup during rotation (key ID embedded in ciphertext)
- Base64 key support for safe environment storage

### File Uploads & Blob Storage

The framework provides comprehensive file upload handling with blob storage, visibility controls, and signed URLs.

**Basic Upload:**
```php
use Glueful\Uploader\FileUploader;

$uploader = new FileUploader($storage);

// Simple file upload
$result = $uploader->upload($file, 'uploads/documents');

// Media upload with automatic thumbnail generation
$result = $uploader->uploadMedia($file, 'media/images');
// Returns: file info, thumbnail URL, MediaMetadata object
```

**Blob Visibility:**
```php
// Upload with visibility setting
POST /blobs/upload
{
    "file": "...",
    "visibility": "private"  // or "public"
}

// Public blobs: accessible without auth
// Private blobs: require auth or valid signed URL
```

**Signed URLs for Temporary Access:**
```php
// Generate signed URL for private blob
POST /blobs/{uuid}/signed-url?ttl=3600

// Response includes:
// - signed_url: temporary access URL
// - expires_at: ISO timestamp
// - expires_in: seconds until expiration
```

**Configuration:**
```env
# Default visibility for uploads
UPLOADS_DEFAULT_VISIBILITY=private

# Signed URL settings
UPLOADS_SIGNED_URLS_ENABLED=true
UPLOADS_SIGNED_URLS_TTL=3600

# Thumbnail settings
THUMBNAIL_ENABLED=true
THUMBNAIL_WIDTH=400
THUMBNAIL_HEIGHT=400
THUMBNAIL_QUALITY=80
```

**Media Metadata Extraction:**
```php
// Automatic metadata extraction with getID3
$result = $uploader->uploadMedia($videoFile, 'media/videos');

$metadata = $result['metadata'];
if ($metadata->isVideo()) {
    echo "Duration: " . $metadata->getFormattedDuration();  // HH:MM:SS
    echo "Dimensions: {$metadata->width}x{$metadata->height}";
}
```

## Testing Strategy

**Test Structure:**
- Unit tests: `tests/Unit/`
- Integration tests: `tests/Integration/`
- Feature tests: `tests/Feature/`

**Testing Commands:**
```bash
composer test                    # All tests
composer run test:unit          # Unit tests only
composer run test:integration   # Integration tests only
composer run test:coverage      # With coverage report

# Run single test or test class
vendor/bin/phpunit --filter="testMethodName"
vendor/bin/phpunit tests/Unit/Routing/RouterTest.php
vendor/bin/phpunit --filter="RouterTest"
```

## Common Development Tasks

### Adding New Features
1. Create service class in appropriate `src/` directory
2. Add service provider if needed
3. Register in DI container
4. Write tests
5. Update documentation

### Database Changes
1. Create migration: `php glueful migrate:create <name>`
2. Implement up/down methods
3. Test migration locally
4. Run `php glueful migrate:run`

### Adding API Endpoints
1. Create controller extending `BaseController`
2. Add route definition
3. Implement authentication/authorization
4. Add input validation
5. Write tests

### Extension Development
1. `php glueful extensions:create <name>`
2. Implement extension logic
3. Add routes and services
4. Test extension
5. Enable: `php glueful extensions:enable <name>`

## File Structure Notes

**Key Directories:**
- `src/` - Framework source code (PSR-4: `Glueful\`)
- `config/` - Configuration files
- `docs/` - Comprehensive documentation
- `tests/` - Test suite
- `storage/` - Logs, cache, uploads
- `extensions/` - User extensions

**Important Files:**
- `composer.json` - Dependencies and autoload
- `.env` - Environment configuration
- `src/Framework.php` - Framework entry point
- `src/Bootstrap/ApplicationContext.php` - Request-scoped context