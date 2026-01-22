# CHANGELOG

All notable changes to the Glueful framework will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [1.13.0] - 2026-01-22 — Saiph

Feature release introducing Enhanced Scaffold Commands and Database Factories & Seeders, completing Priority 2 developer experience features for v1.13.0.

### Added

#### Enhanced Scaffold Commands
- **scaffold:middleware**: New command to generate route middleware classes implementing `RouteMiddleware` interface with options:
  - `--force` / `-f` - Overwrite existing files
  - `--path` / `-p` - Custom output path
  - Supports nested namespaces (e.g., `Admin/AuthMiddleware`)
- **scaffold:job**: New command to generate queue job classes extending `Glueful\Queue\Job` with options:
  - `--queue` - Specify target queue name
  - `--tries` - Number of retry attempts
  - `--backoff` - Backoff delay in seconds
  - `--timeout` - Job timeout in seconds
  - `--unique` - Generate unique job (prevents duplicates)
- **scaffold:rule**: New command to generate validation rule classes implementing `Rule` interface with options:
  - `--params` - Constructor parameters (comma-separated)
  - `--implicit` - Generate implicit rule (validates empty values)
- **scaffold:test**: New command to generate PHPUnit test classes with options:
  - `--unit` - Generate unit test (default)
  - `--feature` - Generate feature test with HTTP testing traits
  - `--class` - Target class to test
  - `--methods` - Methods to generate test stubs for (comma-separated)

#### Database Factories & Seeders
- **Factory**: New `Glueful\Database\Factory\Factory` base class for test data generation:
  - `definition()` - Define default model attributes
  - `count(int $n)` - Set number of models to create
  - `state(array|string|callable)` - Apply state transformations
  - `sequence(array...)` - Rotate attribute values across created models
  - `make()` / `makeMany()` - Create models without persisting
  - `create()` / `createMany()` - Create and persist models
  - `has(string $relation, int|Factory)` - Create with has-many relationships
  - `for(string $relation, Factory|Model)` - Create with belongs-to relationships
  - `recycle(Collection|Model)` - Reuse existing models for relationships
  - `afterCreating(callable)` / `afterMaking(callable)` - Lifecycle callbacks
- **FakerBridge**: New `Glueful\Database\Factory\FakerBridge` class for optional Faker integration:
  - `getInstance()` - Get cached Faker instance
  - `isAvailable()` - Check if Faker is installed
  - `setLocale(string)` - Configure Faker locale
  - `create(string $locale)` - Create new Faker instance
  - Helpful error messages when Faker is not installed
- **Seeder**: New `Glueful\Database\Seeders\Seeder` base class for database seeding:
  - `run()` - Abstract method to implement seeding logic
  - `call(string|array $class)` - Call other seeders
  - `withTransaction(callable)` - Wrap operations in database transaction
  - `truncate(string $table)` - Clear table before seeding
  - `$dependencies` - Declare seeder execution order
- **HasFactory**: New `Glueful\Database\ORM\Concerns\HasFactory` trait for models:
  - `factory(int $count = 1)` - Get factory instance for model
  - `factoryUsing(string $factoryClass)` - Use custom factory class
  - Auto-resolves factory class from model name convention

#### Console Commands
- **db:seed**: New command to run database seeders with options:
  - `[class]` - Specific seeder class to run (default: DatabaseSeeder)
  - `--force` / `-f` - Required to run in production environment
  - Environment protection prevents accidental production seeding
- **scaffold:factory**: New command to generate model factory classes with options:
  - `--model` / `-m` - The model class the factory creates
  - `--force` / `-f` - Overwrite existing files
  - `--path` / `-p` - Custom output path
  - Warns if Faker is not installed
- **scaffold:seeder**: New command to generate database seeder classes with options:
  - `--model` / `-m` - The model class to use for seeding
  - `--force` / `-f` - Overwrite existing files
  - `--path` / `-p` - Custom output path
  - Special handling for DatabaseSeeder (main orchestrator)

### Changed
- **Console**: All new commands registered in `ConsoleProvider` and `Application` command list.
- **Application.php**: Added `SeedCommand`, `FactoryCommand`, `SeederCommand`, `MiddlewareCommand`, `JobCommand`, `RuleCommand`, `TestCommand` to command registry.

### Documentation
- New `docs/FACTORIES.md` with comprehensive usage guide covering:
  - Factory creation and definition
  - Factory states and sequences
  - Relationships (has/for)
  - Seeder creation and execution
  - Console commands reference
  - Best practices
- Updated `docs/implementation-plans/priority-2/README.md` marking enhanced scaffold commands and factories/seeders as complete.

### Notes
- **Faker Dependency**: Factories require `fakerphp/faker` as a dev dependency. Install with `composer require --dev fakerphp/faker`.
- **Production Safety**: The `db:seed` command requires `--force` flag in production environments.
- **Generated Files**: Factories are created in `database/factories/`, seeders in `database/seeders/`.

## [1.12.0] - 2026-01-21 — Mintaka

Feature release introducing API Resource Transformers, completing all Priority 1 features for modern API development.

### Added

#### API Resource Transformers
- **JsonResource**: New base class for transforming data into consistent JSON API responses with property delegation and nested resource resolution.
- **ModelResource**: New ORM-aware resource class extending JsonResource with model-specific helpers:
  - `attribute()` / `hasAttribute()` - Attribute access with defaults
  - `dateAttribute()` / `whenDateNotNull()` - ISO 8601 date formatting
  - `isRelationLoaded()` / `getRelation()` - Relationship introspection
  - `relationshipResource()` / `relationshipCollection()` - Transform loaded relationships
  - `hasAnyRelationLoaded()` / `hasAllRelationsLoaded()` - Bulk relationship checks
- **ResourceCollection**: New collection class for transforming multiple items with:
  - `withPagination()` - Manual pagination metadata
  - `withPaginationFrom()` - Auto-detect QueryBuilder or ORM pagination format
  - `withLinks()` - Generate pagination links with query parameters
  - `additional()` - Add extra response data
  - `preserveKeys()` - Maintain array keys during iteration
- **AnonymousResourceCollection**: Dynamic collection creation from `JsonResource::collection()` calls.
- **PaginatedResourceResponse**: Advanced pagination handler with:
  - `fromQueryResult()` - Create from QueryBuilder flat format
  - `fromOrmResult()` - Create from ORM meta format
  - `withBaseUrl()` / `withQueryParams()` - Link generation
  - `setPage()` / `setPerPage()` / `setTotal()` - Manual configuration
  - `getMeta()` / `getLinks()` - Access pagination data
- **MissingValue**: Sentinel class for conditional attribute omission.
- **Traits**:
  - `ConditionallyLoadsAttributes` - Conditional inclusion helpers:
    - `when()` - Include attribute when condition is true
    - `unless()` - Include attribute when condition is false
    - `mergeWhen()` - Merge multiple attributes conditionally
    - `whenHas()` - Include if key exists in source data
    - `whenNotNull()` - Include if value is not null
    - `whenLoaded()` - Include only if relationship is loaded
    - `whenCounted()` - Include relationship count if loaded
    - `whenPivotLoaded()` - Access pivot table data
  - `DelegatesToResource` - Property access delegation to underlying resource
  - `CollectsResources` - Collection transformation logic

#### Console Commands
- **Scaffold**: New `scaffold:resource` command to generate API resource classes with options:
  - `--model` / `-m` - Generate ModelResource with ORM integration
  - `--collection` / `-c` - Generate ResourceCollection class
  - `--force` / `-f` - Overwrite existing files
  - `--path` / `-p` - Custom output path

### Changed
- **Console**: `ResourceCommand` registered in `ConsoleProvider` and `Application` command list.

### Documentation
- New `docs/RESOURCES.md` with comprehensive usage guide covering:
  - Basic usage and resource creation
  - Conditional attributes and relationships
  - Collections and pagination
  - Model resources and ORM integration
  - Response customization and best practices
- Updated `docs/implementation-plans/README.md` marking all Priority 1 features as complete.
- Updated `docs/implementation-plans/03-api-resource-transformers.md` with implementation status.

### Tests
- New test suite in `tests/Unit/Http/Resources/`:
  - `JsonResourceTest.php` - Base resource transformation
  - `ResourceCollectionTest.php` - Collection handling
  - `ConditionalAttributesTest.php` - Conditional inclusion logic
  - `ModelResourceTest.php` - ORM integration with mock models
  - `PaginationTest.php` - Pagination and link generation
- Total: 71 tests, 155 assertions

## [1.11.0] - 2026-01-21 — Alnilam

Feature release introducing the ORM/Active Record system, completing the data layer of Priority 1 features.

### Added

#### ORM / Active Record
- **Model**: New `Model` base class implementing Active Record pattern with CRUD operations, mass assignment protection, and attribute handling.
- **Builder**: New `Builder` class wrapping QueryBuilder with model-aware query functionality, eager loading, and scope support.
- **Collection**: New `Collection` class for model results with rich iteration, filtering, and transformation methods.
- **Relations**: Complete relationship system:
  - `HasOne` - One-to-one relationship (owning side)
  - `HasMany` - One-to-many relationship
  - `BelongsTo` - Inverse one-to-one/many relationship
  - `BelongsToMany` - Many-to-many with pivot table support
  - `HasOneThrough` - Has-one through intermediate model
  - `HasManyThrough` - Has-many through intermediate model
  - `Pivot` - Pivot model for many-to-many relationships
- **Traits**:
  - `HasAttributes` - Attribute get/set, casting, dirty tracking, and serialization
  - `HasEvents` - Model lifecycle events integration with framework event system
  - `HasRelationships` - Relationship definition and eager loading
  - `HasTimestamps` - Automatic `created_at`/`updated_at` management
  - `HasGlobalScopes` - Global query scope registration and removal
  - `SoftDeletes` - Soft delete support with `deleted_at` column
- **Casts**: Custom attribute casting system:
  - `AsJson` - JSON encode/decode
  - `AsArrayObject` - JSON to ArrayObject with ARRAY_AS_PROPS
  - `AsCollection` - JSON to Collection instance
  - `AsDateTime` - String to DateTimeImmutable with configurable format
  - `AsEncryptedString` - Transparent encryption/decryption
  - `AsEnum` - Backed enum casting
  - `Attribute` - Custom getter/setter accessors with caching
- **Events**: Model lifecycle events extending `BaseEvent`:
  - `ModelCreating` / `ModelCreated`
  - `ModelUpdating` / `ModelUpdated`
  - `ModelSaving` / `ModelSaved`
  - `ModelDeleting` / `ModelDeleted`
  - `ModelRetrieved`
- **Scopes**: `SoftDeletingScope` global scope with `withTrashed()`, `onlyTrashed()`, `restore()` macros.
- **Contracts**: `ModelInterface`, `CastsAttributes`, `Scope`, `SoftDeletable` interfaces.
- **Provider**: New `ORMProvider` service provider for DI container registration.

#### Console Commands
- **Scaffold**: New `scaffold:model` command to generate ORM model classes with options:
  - `--migration` / `-m` - Generate accompanying migration
  - `--soft-deletes` / `-s` - Include SoftDeletes trait
  - `--timestamps` / `-t` - Include HasTimestamps trait
  - `--fillable` - Comma-separated fillable attributes
  - `--table` - Custom table name
- **Reorganization**: Renamed command namespace from `make:*` / `generate:*` to `scaffold:*`:
  - `make:request` → `scaffold:request`
  - `generate:controller` → `scaffold:controller`

### Changed
- **Framework**: ORM initialization added to `Framework::initializeCoreServices()` via `Model::setContainer()`.
- **Container**: `ORMProvider` registered in `ContainerFactory` provider list.
- **CI**: GitHub Actions workflow jobs now run sequentially (lint → tests → coverage → static → security) for better debugging and resource usage.

### Documentation
- New `docs/ORM.md` with comprehensive usage guide covering models, relationships, eager loading, events, and casts.
- Updated `docs/implementation-plans/README.md` marking ORM as complete.
- Updated `docs/implementation-plans/01-orm-active-record.md` with implementation status.

### Tests
- New test suite in `tests/Unit/Database/ORM/`:
  - `ModelTest.php` - Model base functionality
  - `HasAttributesTest.php` - Attribute handling and casting
  - `RelationsTest.php` - Relationship loading and management
  - `CollectionTest.php` - Collection methods and iteration
  - `CastsTest.php` - Custom cast classes
  - `SoftDeletesTest.php` - Soft delete behavior

## [1.10.0] - 2026-01-21 — Elnath

Feature release introducing centralized exception handling and declarative request validation, completing the foundation layer of Priority 1 features.

### Added

#### Exception Handler
- **Exceptions**: New `ExceptionHandlerInterface` contract for customizable exception handling implementations.
- **Exceptions**: New `RenderableException` interface for exceptions that can render themselves to responses.
- **Exceptions**: New `HttpException` base class for all HTTP-related exceptions with status codes and context.
- **Exceptions**: New `Handler` class providing default exception handling with environment-aware error responses.
- **Exceptions**: New `ExceptionMiddleware` for automatic exception-to-response conversion in the middleware pipeline.
- **Exceptions**: New `ExceptionProvider` service provider for DI container registration.
- **Exceptions**: Client exceptions (4xx):
  - `BadRequestException` (400)
  - `UnauthorizedException` (401)
  - `ForbiddenException` (403)
  - `NotFoundException` (404)
  - `MethodNotAllowedException` (405)
  - `ConflictException` (409)
  - `UnprocessableEntityException` (422)
  - `TooManyRequestsException` (429)
- **Exceptions**: Server exceptions (5xx):
  - `InternalServerException` (500)
  - `ServiceUnavailableException` (503)
  - `GatewayTimeoutException` (504)
- **Exceptions**: Domain exceptions:
  - `ModelNotFoundException` - Entity not found in database
  - `AuthenticationException` - Authentication failure
  - `AuthorizationException` - Authorization/permission failure
  - `TokenExpiredException` - JWT/session token expired

#### Request Validation
- **Validation**: New `#[Validate]` attribute for declarative validation on controller methods.
- **Validation**: New `FormRequest` base class for complex validation with authorization, custom messages, and data preparation hooks.
- **Validation**: New `ValidatedRequest` wrapper for type-safe access to validated data.
- **Validation**: New `ValidationMiddleware` for automatic request validation in the middleware pipeline.
- **Validation**: New `RuleParser` for Laravel-style string rule syntax (`'required|email|max:255'`).
- **Validation**: New `'validate'` middleware alias for route-level validation.
- **Validation**: New validation rules:
  - `Confirmed` - Password confirmation matching
  - `Date` - Date format validation with optional format
  - `Before` / `After` - Date comparison rules
  - `Url` - URL format with optional protocol restriction
  - `Uuid` - UUID format validation
  - `Json` - JSON string validation
  - `Exists` - Database existence check
  - `Nullable` / `Sometimes` - Conditional validation
  - `File` - File upload validation (extensions, size)
  - `Image` - Image file validation (types, size)
  - `Dimensions` - Image dimensions (width, height, ratio)
- **Console**: New `make:request` command to generate FormRequest classes.

### Changed
- **Validation**: `ValidationException` now extends `ApiException` and returns proper 422 HTTP responses with structured error format.
- **Validation**: `DbUnique` rule updated to support both PDO injection and string-based syntax from `RuleParser`.
- **Application**: Request dispatch now uses centralized exception handling via `ExceptionMiddleware`.

### Documentation
- Updated implementation plans to mark Exception Handler and Request Validation as complete.
- Added implementation status indicators to architecture diagrams.

## [1.9.2] - 2026-01-20 — Deneb

Patch release with OpenAPI 3.1 support, automatic resource route expansion from database schemas, and documentation UI improvements.

### Added
- **Documentation**: New `ResourceRouteExpander` class that automatically expands `{resource}` routes to table-specific endpoints with full database schemas.
- **Documentation**: OpenAPI 3.1.0 support with proper JSON Schema draft 2020-12 alignment:
  - Nullable types use array syntax (`type: ["string", "null"]`)
  - License supports SPDX `identifier` field
  - `jsonSchemaDialect` declaration included
- **Documentation**: Scalar UI configuration options: `hide_client_button`, `show_developer_tools`.
- **Documentation**: Tags in documentation sidebar are now sorted alphabetically.

### Changed
- **Documentation**: Default OpenAPI version changed from 3.0.0 to 3.1.0.
- **Documentation**: Renamed output file from `swagger.json` to `openapi.json` (modern naming convention).
- **Documentation**: Resource route tags renamed from "Resources - {table}" to "Table - {table}" for clarity.
- **Documentation**: Config key `paths.swagger` renamed to `paths.openapi`.

### Removed
- **Documentation**: Removed `TableDefinitionGenerator` class - resource routes now expand directly from database schemas without intermediate JSON files.
- **Documentation**: Removed `--database` and `--table` options from `generate:openapi` command (no longer needed).

### Fixed
- **Database**: Fixed `SchemaBuilder::getTableColumns()` returning empty arrays due to incorrect `array_is_list()` check on associative column data.

## [1.9.1] - 2026-01-19 — Castor

Patch release with a major refactor of the OpenAPI documentation system, adding interactive UI generation and improved PHPDoc parsing.

### Added
- **Documentation**: New `DocumentationUIGenerator` class for generating interactive API documentation HTML pages supporting:
  - Scalar (default) - Modern, beautiful API documentation
  - Swagger UI - Classic OpenAPI documentation interface
  - Redoc - Clean, responsive three-panel design
- **Documentation**: New `--ui` option for `generate:openapi` command to generate documentation UI alongside openapi.json.
- **Documentation**: New `config/documentation.php` centralizing all documentation settings including paths, API info, servers, security schemes, and UI configuration.
- **Documentation**: New `OpenApiGenerator` class orchestrating the full documentation pipeline with lazy-loaded dependencies.
- **Validation**: New `Numeric` rule for validating numeric values with optional range, integer-only, and positive-only constraints.
- **Validation**: New `Regex` rule for validating values against regular expression patterns.

### Changed
- **Documentation**: Renamed `ApiDefinitionsCommand` to `OpenApiDocsCommand` with enhanced options and better UX.
- **Documentation**: `CommentsDocGenerator` now uses `phpDocumentor/ReflectionDocBlock` for robust PHPDoc parsing instead of regex patterns.
- **Documentation**: `CommentsDocGenerator` now discovers extension routes via `ExtensionManager::getProviders()` for Composer-installed extensions.
- **Documentation**: `CommentsDocGenerator` now caches parsed route files based on file modification time.
- **Documentation**: `DocGenerator` extracted shared `mergeDefinition()` method from duplicate extension/route merge logic.
- **Documentation**: OpenAPI version is now configurable via `config('documentation.openapi_version')`.
- **Dependencies**: Updated Symfony packages from `^7.3` to `^7.4`.
- **Dependencies**: Added `phpdocumentor/reflection-docblock: ^6.0` for PHPDoc parsing.

### Removed
- **Documentation**: Removed `ApiDefinitionGenerator` class (replaced by `OpenApiGenerator`).

### Fixed
- **Console**: Fixed `Application` to use `add()` instead of undefined `addCommand()` method for Symfony Console 7.x compatibility.

## [1.9.0] - 2026-01-17 — Betelgeuse

Minor release raising the minimum PHP version to 8.3 and addressing compatibility with Symfony Console 7.3.

### Breaking Changes
- **PHP**: Minimum required PHP version is now 8.3 (up from 8.2). Update your environment before upgrading.

### Fixed
- **Console**: Renamed `Application::addCommand(string)` to `Application::registerCommandClass(string)` to resolve method signature conflict with Symfony Console 7.3's new `addCommand(Command|callable)` method.
- **Routing**: `RouteManifest::load()` now prevents double-loading routes during framework initialization, eliminating "Route already defined" warnings in CLI commands.
- **Security**: Fixed PHPStan strict boolean check in `CSRFMiddleware` for cookie token validation.
- **Tests**: Added missing PSR-4 namespace declarations to async test files (`AsyncStreamHelpersTest`, `SchedulerTimerCancellationTest`, `HttpStreamingClientTest`, `HttpPoolingConfigTest`, `RaceSemanticsTest`).

### Changed
- **CI**: Test matrix now targets PHP 8.3 and 8.4 (dropped PHP 8.2 support).
- **Routing**: Added `RouteManifest::reset()` and `RouteManifest::isLoaded()` helper methods for testing scenarios.

### Upgrade Guide
1. Ensure your environment runs PHP 8.3 or higher.
2. If you called `$app->addCommand(MyCommand::class)` directly, rename to `$app->registerCommandClass(MyCommand::class)`.
3. Run `composer update` to refresh dependencies.

## [1.8.1] - 2025-11-23 — Vega

Small patch release tightening password policy options and improving async stream helper ergonomics for buffered I/O callers.

### Added
- Helpers/Security: `Utils::validatePassword()` gained a `$requireLowercase` flag so applications can explicitly enforce mixed-case passwords alongside numbers, symbols, and uppercase requirements.

### Fixed
- Async/IO: `async_stream()` now accepts raw resources, `AsyncStream`, or `BufferedAsyncStream` instances and normalizes them before wrapping. This ensures buffered streams always reference a canonical async transport, respects configured buffer sizes, and keeps static analysis annotations accurate.

## [1.8.0] - 2025-11-13 — Spica

Feature release adding first-class session and login response events, enabling safe enrichment of cached session payloads and login responses without modifying framework code.

### Added
- Events/Auth:
  - `SessionCachedEvent`: Dispatched after a session is written to cache (and DB). Listeners can augment the cached payload (e.g., `user.organization`) or warm related caches. Implemented at `src/Auth/SessionCacheManager.php` after successful `cache->set` in `storeSession()`.
  - `LoginResponseBuildingEvent`: Dispatched just before returning the login JSON. Provides a mutable response map so apps can extend the payload (e.g., `context.organization`).
  - `LoginResponseBuiltEvent`: Dispatched after the login response is finalized for metrics/analytics.
- Controllers/Auth:
  - `AuthController::login()`: Pre-return response enrichment hook wired using the new login response events.

### Changed
- Docs:
  - `docs/SESSION_EVENTS_PROPOSAL.md` updated to reflect final implementation (paths under `src/...`), setter-based mutation (no PHP by-ref promotion), dispatch locations, and a concrete listener example.

### Notes
- Backward compatible: No behavior change unless listeners are registered.
- Performance: Events are synchronous; heavy listeners should offload to queues.
- Guidance: Prefer adding app-specific data under a `context.*` key to avoid collisions with reserved fields.

## [1.7.4] - 2025-10-28 — Arcturus

Patch release adding a minimal, configurable account‑status gate to authentication and new docs for writing migrations that create views/functions.

### Added
- Auth: Optional status policy in `AuthenticationService::authenticate()` and refresh‑token flow. Users must have a status in `security.auth.allowed_login_statuses` (default: `['active']`) to log in or refresh.

### Changed
- Config: Introduced `security.auth.allowed_login_statuses` under `config/security.php` and read it in authentication flows. This centralizes auth policy under security.

### Notes
- Behavior is secure by default and silent on failure (prevents account enumeration). Override the allowed statuses in your app’s `config/security.php` as needed.
- If you previously added an `auth.allowed_login_statuses` key during development, move it to `security.auth.allowed_login_statuses`.

## [1.7.3] - 2025-10-21 — Pollux

Patch release fixing QueryBuilder 2‑argument where/orWhere handling and further improving dev‑server log clarity.

### Fixed
- Database/QueryBuilder: Normalize 2‑argument `where($column, $value)` and `orWhere($column, $value)` to
  use the `=` operator internally. This resolves a `TypeError` where non‑string values (e.g., integers)
  were interpreted as the operator and passed to `WhereClause::add()`.
  - Improves portability for boolean filters across PostgreSQL/MySQL/SQLite.

### Improved
- CLI: `serve` command further refines classification of PHP built‑in server access/lifecycle lines written to STDERR
  (e.g., “Accepted”, “Closed without sending a request”, “[200]: GET /…”) as normal output, while preserving real errors.

## [1.7.2] - 2025-10-21 — Antares

Patch release improving route loading resilience and dev-server log clarity.

### Fixed
- Extensions: `ServiceProvider::loadRoutesFrom()` is now idempotent and exception-safe.
  - Prevents duplicate route registration if the same routes file is loaded more than once.
  - Catches exceptions from route files; logs and continues in production, rethrows in non‑production for fast feedback.

### Improved
- CLI: `serve` command log handling reclassifies PHP built‑in server access/startup lines from STDERR as normal output, reducing false `[ERROR]` noise while preserving real error reporting.

## [1.7.1] - 2025-10-21 — Canopus

Patch release addressing extension discovery/boot sequencing so extensions reliably load at runtime.

### Fixed
- Extensions: Call `ExtensionManager::discover()` before `::boot()` during framework initialization
  (`src/Framework.php`). This resolves a bug where enabled extensions appeared as
  “EXCLUDED from final provider list” and their `boot()` never ran.
- Migrations: Extension migrations registered via `loadMigrationsFrom()` are now properly discovered
  by `migrate:status`/`migrate:run` once providers are discovered at boot.
- CLI: `extensions:why`/`extensions:list` now reflect included providers after boot, improving
  diagnostics when extensions are enabled via config or Composer discovery.

### Impact
- Applications that previously saw “No pending migrations found” for extension migrations should now
  see those migrations once the provider is enabled. No config changes are required.

## [1.7.0] - 2025-10-18 — Procyon

Major async/concurrency subsystem. Introduces a fiber-based scheduler, async HTTP client with streaming, buffered I/O, cooperative cancellation, metrics instrumentation, and a Promise-style wrapper for ergonomic chaining. Includes centralized async configuration and DI wiring.

### Added
- Async/Concurrency: Fiber-based `Glueful\Async\FiberScheduler` with `spawn`, `all`, `race`, and `sleep` semantics.
- Tasks: `FiberTask`, `ClosureTask`, `CompletedTask`, `FailedTask`, `DelayedTask`, `RepeatingTask`, `TimeoutTask`.
- Helpers: `scheduler()`, `async()`, `await()`, `await_all()`, `await_race()`, `async_sleep()`, `async_sleep_default()`, `async_stream()`, `cancellation_token()`.
- Async I/O: `Glueful\Async\IO\AsyncStream` and `BufferedAsyncStream` with line/whole-read helpers and buffered reads/writes.
- Async HTTP: `Glueful\Async\Http\CurlMultiHttpClient` with cooperative polling, pooling via `poolAsync()`, and streaming via `HttpStreamingClient::sendAsyncStream()`; `FakeHttpClient` for testing.
- Promise: `Glueful\Async\Promise` wrapper providing `then/catch/finally` and `all/race` composition over Tasks.
- Cancellation: `SimpleCancellationToken` and cooperative propagation across scheduler, I/O, and HTTP.
- Instrumentation: Expanded `Metrics` interface and implementations (`LoggerMetrics`, `NullMetrics`) with fiber/task events (suspend/resume, queue depth, cancellation, resource limits).
- Config: New `config/async.php` with `scheduler`, `http`, `streams`, and `limits` settings.
- DI: `AsyncProvider` wires `Metrics`, `Scheduler`, `HttpClient`, and registers `AsyncMiddleware` (alias `"async"`).

### Changed
- Scheduler: Resource limit enforcement (max concurrent tasks, per-task execution time, optional memory and file-descriptor caps); timer handling via min-heap; richer metrics hooks.
- HTTP: Refactored `CurlMultiHttpClient` to use a shared `curl_multi` pump and optional `max_concurrent` cap; retry knobs exposed via config.

### Fixed
- Cancellation and timeouts are honored during sleeps and I/O waits across scheduler and async streams.

### Documentation
- High-level async docs added to the site (API reference and troubleshooting); extensive PHPDoc across async packages.

### Tests
- New unit/integration coverage for async scheduler, HTTP client, streaming, timers, and helpers (see `tests/Unit/Async/*`, `tests/Integration/Async/*`).

### Migration Notes
- New `config/async.php`. Defaults are backward-compatible (limits disabled when set to 0). No changes required unless opting into limits or tuning.
- To use async within routes, add `AsyncMiddleware` (alias `async`) or use the helpers (`async()`, `await_all()`, etc.).

## [1.6.2] - 2025-10-14 — Capella

Template configuration responsibility moved to the Email Notification extension.

### Changed
- Mail/Templates: The primary templates directory is now controlled by the Email Notification
  extension configuration. The framework no longer sets a default `services.mail.templates.path`.
  Applications can still provide this key in their own config if desired; otherwise the extension’s
  `templates.extension_path` (and its internal default) will be used.
- Mail/Templates: `services.mail.templates.custom_paths`, caching, layout, mappings, and global
  variables remain supported at the framework level.

### Migration Notes
- If you previously relied on the framework’s default `templates.path`, set your preferred primary
  directory via the extension config (`email-notification.templates.extension_path`) or add your own
  `services.mail.templates.path` in application config.

## [1.6.1] - 2025-10-14 — Arcturus

JWT RS256 signing support.

### Added
- Auth/JWT: RS256 signing support via `JWTService::signRS256(array $claims, string $privateKey)`
  for generating JWTs using an RSA private key. Requires the `openssl` extension.

## [1.6.0] - 2025-10-13 — Sirius

Minor features and DX improvements.

### Added
- DI/Compile: emit `services.json` manifest during container compile at
  `storage/cache/container/services.json` containing `shared`, `tags`, `provider`, `type`, and `alias_of`.
- CLI: `di:container:map` now prefers the compiled `services.json` in production to avoid reflection.
- Runtime: `ContainerFactory` prefers a precompiled container class in production when available.
- Router: `ConditionalCacheMiddleware` for ETag/If-None-Match and Last-Modified/If-Modified-Since 304 handling.
- HTTP: `Response::withLastModified(DateTimeInterface)` helper.
- Config: `Glueful\Config\DsnParser` with `parseDbDsn()` and `parseRedisDsn()` utilities.
- CLI: `config:dsn:validate` to validate Database/Redis DSNs from flags or environment.
- Docs: `docs/roadmap-1.6-status.md` tracking 1.6 implementation status.

### Changed
- `di:container:compile` writes both the compiled container PHP and the `services.json` manifest.

### Fixed
- Removed redundant string casts flagged by PHPStan in container boot/loader paths.

## [1.5.0] - 2025-10-13 — Orion

Notification system wiring improvements and safer email verification flow.

### Added
- DI provider for notifications: `Glueful\Container\Providers\NotificationsProvider` registers
  `ChannelManager` and `NotificationDispatcher` as shared services.

### Changed
- EmailVerification and SendNotification now prefer DI-resolved `NotificationDispatcher`/`ChannelManager`
  with a safe fallback when DI isn’t available. This enables extensions to self‑register channels/hooks
  during boot without ad‑hoc construction.
- Removed hard dependency on `ExtensionManager` checks in email verification and password reset flows.
  Channel availability and configuration are determined at send time via the dispatcher.
- Soft diagnostics: added non‑blocking logs when the email channel is unavailable or no channels succeeded
  for email verification/password reset.
- Retry command now reads retry settings from `email-notification.retry` to align with the extension’s
  configuration namespace.

### Fixed
- Resolved namespace and escaping issues in SendNotification; addressed static analysis warnings and
  long‑line formatting in EmailVerification diagnostics.

### Developer Notes
- If an Email Notification extension is installed and enabled, it will be able to register its email
  channel and hooks against the shared dispatcher during boot. Existing fallback paths remain for
  environments without DI.

## [1.4.2] - 2025-10-11 — Rigel (patch)

Dev-only tidy-ups and documentation sync. No runtime changes.

### Fixed
- PSR-4 autoloading for tests: corrected namespace in `tests/Unit/Permissions/AttributeMiddlewareTest.php` to `Glueful\Tests\...`, removing Composer warnings during autoload generation.

### Documentation
- Updated ROADMAP and site release notes to reflect the 1.4.1 install flow improvements and guidance.

---

## [1.4.1] - 2025-10-11 — Rigel (patch)

Installation flow hardening and SQLite-first defaults. Improves non-interactive installs and avoids fragile checks during initial setup.

### Added
- Post-install guidance on switching databases and running migrations after install.
- Quiet/non-interactive support propagated to install sub-commands:
  - `migrate:run` and `cache:clear` honor `--no-interaction` and `--quiet` in install `--quiet` mode.

### Changed
- InstallCommand runs migrations with `--force` by default (equivalent to `-f`).
- Install process is SQLite-only; other engines are skipped during install and can be configured afterwards.
- `cache:clear` during install now passes `--force` (and non-interactive flags when quiet) to avoid confirmation prompts.

### Removed
- Database connection health check during install (SQLite does not require a network connection and migrations surface real issues).

### Fixed
- Eliminated redundant sqlite comparison that triggered a phpstan strict-comparison warning.
- `php glueful install --quiet` no longer prompts interactively when clearing cache or running migrations.

## [1.4.0] - 2025-10-11 — Rigel

Rigel release — consolidates session management behind a single, testable API and removes legacy token storage. This refactor simplifies dependency wiring, unifies TTL policy, and improves cache‑key safety for tokens.

### Added
- SessionStoreInterface and default SessionStore implementation as the canonical session API (create/update/revoke/lookup/health).
- TTL helpers on the store: `getAccessTtl()` and `getRefreshTtl()` with provider + remember‑me support.
- SessionStoreResolver utility and ResolvesSessionStore trait to consistently resolve the store via DI with a safe fallback.
- End‑to‑end smoke script for local validation: `tools/test_session_refactor.php` (temporary; remove as needed).

### Changed
- TokenManager now defers TTL policy to SessionStore and persists sessions via the store. Static resolver unified through SessionStoreResolver.
- JwtAuthenticationProvider and SessionCacheManager resolve the store via the new trait; reduced ad‑hoc instantiation.
- SessionAnalytics prefers the store for listing sessions (falls back to cache query when needed).
- Cache keys for sessions now use safe prefixes and hashed tokens:
  - `session_data_<uuid>`, `session_token_<sha256(token)>`, `session_refresh_<sha256(token)>`.
- JWTService cleaned up; in‑memory invalidation removed; DB‑backed revocation relied upon.

### Removed
- Legacy TokenStorageService and TokenStorageInterface (all usages migrated to SessionStore).
- Deprecated code paths and comments tied to the legacy storage/invalidation.

### Fixed
- Base64URL decoding uses URL‑safe decode paths in session flows.
- Cache key sanitization for tokens prevents invalid‑character failures across cache backends.


## [1.3.1] - 2025-10-10 — Altair

Altair patch — improves CI/automation ergonomics for initial installs and cleans up static analysis.

### Changed
- Console: `install` command is now truly non-interactive when any of these flags are present: `--quiet`, `--no-interaction`, or `--force`.
  - Skips the confirmation prompt about environment variables in these modes, enabling fully unattended setup in CI/CD.
  - Keeps informative output; for silent runs use Symfony’s global `-q` as usual.

### Fixed
- Console: removed redundant `method_exists()` guard around `InputInterface::isInteractive()` to satisfy PHPStan (the method is guaranteed by the interface).
- Minor DX polish in the install flow messaging.

## [1.3.0] - 2025-10-06 — Deneb

Deneb release — refines the HTTP client with first‑class, configurable retries via Symfony’s retry system, improving resilience and clarity for API integrations.

### Added
- HTTP client retry support using Symfony `RetryableHttpClient` with `GenericRetryStrategy`.
- `Client::withRetry(array $config)` to wrap any configured client with retries.
- `ApiClientBuilder` retry configuration via `retries(...)`, `buildWithRetries()`, and `getRetryConfig()`.
- Sensible defaults and presets in builders (e.g., payments/external service) for common retry scenarios.

### Changed
- Refactored client retry behavior to Symfony’s strategy-based approach (status codes, backoff, jitter, max retries), replacing custom retry handling for a more robust and testable implementation.

## [1.2.0] - 2025-09-23 — Vega

Vega release — introduces robust task management architecture and enhanced testing reliability. Named after one of the brightest stars in the night sky, this release brings enhanced reliability and clarity to task execution and framework testing infrastructure.

### Added
- **Tasks/Jobs Architecture**: Complete separation of business logic (Tasks) from queue execution (Jobs)
  - New `src/Tasks/` directory with business logic classes
  - New `src/Queue/Jobs/` wrappers for reliable queue integration
  - Support for both direct execution and queued processing
- **Task Management System**:
  - `CacheMaintenanceTask` - Comprehensive cache maintenance operations
  - `DatabaseBackupTask` - Database backup with configurable retention
  - `LogCleanupTask` - Log file cleanup with retention policies
  - `NotificationRetryTask` - Notification retry processing
  - `SessionCleanupTask` - Session cleanup and maintenance
- **Queue Job Wrappers**:
  - `CacheMaintenanceJob`, `DatabaseBackupJob`, `LogCleanupJob`, `NotificationRetryJob`, `SessionCleanupJob`
  - Reliable job execution with failure handling and logging
- **Enhanced Console Commands**:
  - New `cache:maintenance` command with improved options
  - Updated console application structure and service provider registration
- **Comprehensive Testing Suite**:
  - Complete integration test coverage for all Tasks and Jobs
  - Enhanced test bootstrap with proper DI container management
  - Fixed test interference issues and container state management

### Changed
- **Architecture Migration**: Migrated from `src/Cron/` to `src/Tasks/` + `src/Queue/Jobs/` pattern
- **Service Registration**: Tasks and Jobs now properly registered in DI container via `TasksProvider`
- **Testing Infrastructure**: Enhanced test bootstrap for better reliability and container management

### Removed
- **Legacy Cron Classes**: Removed all classes from `src/Cron/` directory
  - `CacheMaintenance.php`, `DatabaseBackup.php`, `LogCleaner.php`
  - `NotificationRetryProcessor.php`, `SessionCleaner.php`

### Fixed
- **Test Infrastructure**: Resolved integration test failures and DI container initialization issues
- **Code Quality**: Fixed PHP CodeSniffer violations across test files and bootstrap
- **Container Management**: Fixed test state interference between unit and integration tests

## [1.1.0] - 2025-09-22 — Polaris

Polaris release — introduces comprehensive testing infrastructure and enhanced documentation to guide framework development. Like the North Star that guides navigation, this release provides developers with the tools and knowledge to build robust applications with confidence.

### Added
- Testing utilities with `TestCase` base class for application testing
- Comprehensive event system documentation covering all framework events
- Support for framework state reset in testing environments
- Complete event listener registration patterns and best practices
- Event system abstraction layer with `BaseEvent` class

### Updated
- Enhanced event system documentation with complete examples and best practices
- Improved testing infrastructure for better framework integration

### Changed
- Event system now provides clear abstraction layer over PSR-14 implementation

## [1.0.0] - 2025-09-20 — Aurora

Aurora release — the first stable release of the split Glueful Framework package (formerly part of glueful/glueful). This version establishes the framework runtime as a standalone library with comprehensive features and sets a clear baseline for future 1.x releases.

### Added
- Comprehensive permissions and authorization system.
- Alias support for services and improved provider bootstrapping.
- Core and Console service providers for out-of-the-box wiring.
- Next‑Gen Router (complete rewrite)
  - Fast static/dynamic matching with first‑segment bucketing and deterministic precedence.
  - Attribute route loader (Controller/Get/Post/Put/Delete attributes).
  - Route cache compiler with dev TTL and invalidation for both app and framework routes.
  - Standardized JSON errors for 404/405 (405 includes Allow header).
- Dependency Injection overhaul
  - DSL for service registration (Def, ServiceDef, Utils) and compile‑time service generation.
  - Compiled DI container support for faster production startup.
- Configuration & bootstrap
  - Lazy configuration cache and path helpers; clarified Framework::create(...)->boot() flow.
  - App providers via `config/serviceproviders.php`; unified discovery with extensions.
- Extensions System v2
  - Deterministic provider discovery (app providers + vendor extensions) via ProviderLocator.
  - Extension service compilation and performance improvements.
- Observability & logging
  - BootProfiler for startup timing; standardized log processor.
  - MetricsMiddleware + ApiMetricsService; pluggable tracing middleware.
- Security
  - Expanded middleware set (Auth, Rate Limiter, CSRF, Security Headers, Admin, IP allow‑list).
  - Hardened health/readiness endpoints with allow‑list support.
  - Security CLI commands: `security:check`, `security:scan`, `security:report`, `security:vulnerabilities`.
- Caching & performance
  - File cache sharding + in‑process stats cache; tagging & warmup utilities.
- File uploads
  - FileUploader now accepts Symfony UploadedFile natively; extension+MIME validation; hazard scanning.
  - S3 storage with configurable ACL and signed URLs (private by default) and TTL.
- Field selection
  - GraphQL‑style field selection and projection utilities.
- Tooling & Docs
  - Unified GitHub Actions pipeline (`php-ci`), updated PR/Issue templates.
  - Cookbook expanded, with setup (`docs/cookbook/00-setup.md`) and uploads (`docs/cookbook/23-file-uploads.md`).

### Changed
- Dependency Injection: replaced Symfony DI with a lightweight, custom container optimized for Glueful.
- Events: migrated to PSR-14 with a custom dispatcher implementation.
- Storage: migrated to Flysystem; updated configuration structure and options.
- Configuration: refactored to array-based schemas for clarity and compile-time validation.
- Validation: moved to a rules-based system with clearer composition.
- Composer dependencies refreshed to latest compatible versions.
- Router now returns standardized JSON for 404/405 via `Glueful\Http\Response::error()`.
- DI: prefer DSL‑based `services()` definitions; compiled container recommended in production.
- Configuration/env alignment:
  - `REDIS_DB` (instead of `REDIS_CACHE_DB`), `MAIL_ENCRYPTION` (instead of `MAIL_SECURE`).
  - `LOG_FILE_PATH` (replaces `LOG_PATH`), PSR‑15 toggles (`PSR15_ENABLED`, `PSR15_AUTO_DETECT`, `PSR15_STRICT`).
  - S3 controls: `S3_ACL`, `S3_SIGNED_URLS`, `S3_SIGNED_URL_TTL`.
  - `.env.example` cleanup; `LOG_TO_DB=false` by default to avoid DB dependency.
- FileUploader resolves repositories via container; improved filename handling and MIME detection.
- Route cache invalidation also watches framework `src/routes/*.php` in development.
- ExceptionHandler removed from Composer autoload "files"; PSR‑4 autoload only.

Breaking changes:
- DI container swap may affect service definitions, compiler passes, and container-aware utilities.
- Event system changes require updating listener/subscriber registration to PSR-14.
- Storage configuration keys and adapters changed to Flysystem-based configuration.
- Config and validation refactors may require updating custom rules, schemas, and boot code.

### Removed
- Legacy LDAP/SAML authentication integration.
- Queue configuration management classes.
- Custom config and serialization modules superseded by the new configuration approach.
- Symfony DI usage and related integration points.
- Legacy route and middleware system and related documentation.
- Legacy docs/SETUP.md (moved to Cookbook); deprecated report docs.
- Old monolithic CI workflow and split test workflows (replaced by single `php-ci`).

### Fixed
- Documentation updates and cleanup in the database and storage guides.
- S3 bucket config typos in `S3Storage` (`services.storage.s3.bucket`).
- PHPStan short‑ternary warnings in `RouteCache` and `FileUploader`; safer file read fallbacks.
- Numerous type‑safety and strictness improvements across cache, auth, console, and DI layers.

### Security
- Allow‑listed health/readiness endpoints; expanded security checks and CLI audits.
