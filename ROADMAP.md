# Glueful Framework Roadmap

This roadmap tracks high‑level direction for the framework runtime (router, DI, HTTP, config, security, caching, queues, observability). The API skeleton and documentation site have their own cadences and may reference this roadmap for synchronization.

## Purpose & Scope
- Framework only: core libraries and runtime behavior.
- Application scaffold (glueful/api‑skeleton) and docs site (glueful/docs) follow this roadmap but ship independently.

## Release Cadence & Compatibility
- Versioning: Semantic Versioning. 1.x is the stable baseline after the repo split.
- Cadence: Patches as needed; minors roughly every 4–8 weeks; majors only for clearly communicated breaking changes.
- Deprecations: Soft‑deprecate in N, remove no earlier than N+2 minors with warnings and migration notes.
- Pre‑public phase: minors may include breaking changes when called out in the CHANGELOG (codename releases like “Aurora”).

## Themes for 1.x
- Stability & performance: keep startup and dispatch budgets; expand compiled artifacts (routes, container) and caching.
- Observability/operations: first‑class tracing, metrics, structured logging, and diagnostics.
- Security hardening: CSP builder, headers, rate limiting/distributed coordination, health/readiness governance.
- Developer experience: DI DSL ergonomics, CLI UX, cookbook completeness, clearer error messages.
- Interop: PSR‑7/PSR‑15 coverage, adapters, and guidance.

## Milestones (subject to change)

### 1.37.0 — Kaus (Released 2026-02-15)
- **Deferred Extension Commands**: `ServiceProvider::commands()` and `discoverCommands()` no longer silently drop commands when the console application isn't yet created. Commands are stored in a static `$deferredCommands` array and picked up by `ConsoleApplication` on construction via `flushDeferredCommands()`.
- **ORM Builder `forPage()` Fix**: Changed `offset()->limit()` to `limit()->offset()` so `QueryValidator` doesn't throw "OFFSET requires LIMIT" during pagination.
- **`ExtendsBuilder` Interface**: New contract (`Contracts\ExtendsBuilder`) for scopes that add macros to the ORM Builder, replacing duck-typed `method_exists()` checks. `SoftDeletingScope` implements it.
- **WebhookDispatcher DI Fix**: `CoreProvider` factory now passes `ApplicationContext` as the 3rd argument.
- **OpenAPI Documentation Hardening**: `DocGenerator` receives context from `OpenApiGenerator` for CLI usage. Server URL fallback chain: `api_url()` → config → discovered URL → `"/"`. Empty properties now render as `{}` instead of `[]` in generated JSON.
- Notes: No breaking changes. Extension CLI commands that were previously lost now register correctly.

### 1.36.0 — Jabbah (Released 2026-02-14)
- **Model Event Isolation**: `HasEvents::$modelEventCallbacks` now keyed by `[className][event]` instead of flat `[$event]`. Prevents event callbacks registered in one model (e.g., `EntityType::creating`) from firing on unrelated models (e.g., `Entity`).
- **Boot-safe Event Registration**: `registerModelEvent()` no longer calls `new static()` to validate event names. Eliminates "No database connection" errors when models boot without `ApplicationContext`.
- **Base64 Upload File Extensions**: Base64 uploads now produce correct file extensions (`.png`, `.jpg`, etc.) derived from the MIME type instead of always defaulting to `.bin`.
- Notes: No breaking changes. Fixes cross-model event leaking and boot-time DB errors.

### 1.35.0 — Izar (Released 2026-02-14)
- **Cloud Storage Direct Write**: `FlysystemStorage::store()` bypasses the atomic temp+move pattern for S3/R2/GCS/Azure disks, writing directly via `writeStream()`. Fixes `io_move_failed` CopyObject failures on Cloudflare R2 and compatible stores. Local disks retain the atomic pattern for crash safety.
- **Blob Lookup Fix**: `BlobRepository::findByUuidWithDeleteFilter()` rewritten to use explicit three-parameter `where('status', '!=', 'deleted')` instead of array-format `['!=', 'deleted']` which the query builder treated as `=`.
- **Storage Error Propagation**: `FlysystemStorage` now always includes the underlying exception message in upload errors, replacing the generic "Storage write failed" for non-Flysystem exceptions.
- **Base64 Upload File Extensions**: Base64 uploads now produce correct file extensions (`.png`, `.jpg`, etc.) derived from the MIME type instead of always defaulting to `.bin`.
- Notes: No breaking changes. Fixes blob 404s, S3-compatible upload failures, and base64 file naming.

### 1.34.0 — Hamal (Released 2026-02-14)
- **Auth Middleware Exception Isolation**: `$next($request)` moved outside the auth `try/catch` so downstream controller/middleware exceptions propagate correctly instead of being swallowed as 401 "Authentication error occurred".
- **`Utils::getUser()` Modernization**: No longer requires legacy `role`/`info` JWT claims (only `uuid` required). Checks request attributes from auth middleware before falling back to token decoding.
- **Symfony Request Token Fallback**: `AuthMiddleware` and `JwtAuthenticationProvider` fall back to extracting Bearer token from the Symfony Request when `RequestContext`-based extraction returns null.
- **`UploadController` + `FileUploader` DI Registration**: Both registered in `StorageProvider` with config-driven factory definitions, fixing container resolution failures on blob upload routes.
- **Queue Serialization Fix**: `DriverRegistry` cache key generation replaced `serialize()` with `json_encode()` to avoid Closure serialization crashes.
- **Login Tracking Cleanup**: Removed legacy column updates (`ip_address`, `user_agent`, `x_forwarded_for_ip_address`, `last_login_date`) from `AuthenticationService` — already tracked in `auth_sessions`.
- Notes: No breaking changes. All fixes backward-compatible.

### 1.33.0 — Gacrux (Released 2026-02-14)
- **Container-Enforced Request Resolution**: Eliminated all `fromGlobals()` / `createFromGlobals()` fallbacks from 15 service files. Auth services (`TokenManager`, `JwtAuthenticationProvider`, `SessionStore`, `EmailVerification`, `AuthenticationService`) and utility services (`RequestHelper`, `Utils`, `Cors`, `SpaManager`, `UserRepository`, `SecurityManager`) now resolve `RequestContext`/`Request` from the DI container's shared singleton.
- **Memory Safety**: Fixes unbounded memory growth on high-header requests where multiple independent `fromGlobals()` calls each reconstructed PSR-7 request objects from `$_SERVER` superglobals.
- **Long-Running Server Compatibility**: Services no longer read stale `$_SERVER` globals — all request data comes from the container-managed singleton that is reset between requests.
- **Silent Fallback Removal**: `SessionStoreResolver` and `TokenManager::getSessionStore()` no longer silently construct bare `SessionStore()` instances on container failure — errors surface immediately with clear messages.
- **`SessionStoreInterface::resetRequestCache()`**: Added to interface; removes `method_exists()` guard in `TokenManager`.
- Notes: No breaking changes for container-wired services. Direct instantiation without context now throws `\RuntimeException` instead of silently degrading.

### 1.32.0 — Fomalhaut (Released 2026-02-11)
- **Schema Builder `alterTable` Callback API**: `alterTable()` now accepts an optional callback parameter, mirroring the `createTable` dual-mode pattern. Without a callback, returns a fluent `TableBuilder` (unchanged). With a callback, applies alterations and auto-executes, returning `$this` for chaining.
- **ColumnBuilder Finalization Fix**: `gc_collect_cycles()` called before execute to ensure `ColumnBuilder` destructors register columns via `finalizeColumn()` before ALTER SQL is generated.
- Notes: No breaking changes. Existing callers (including convenience methods) use the no-callback path and are unaffected.

### 1.31.0 — Enif (Released 2026-02-09)
- **Centralized Context Propagation**: Framework boot now sets `ApplicationContext` on core services (`Model`, `Utils`, `CacheHelper`, `SecureErrorResponse`, `RoutesManager`, `ImageProcessor`, `ConfigManager`, `Webhook`) and auth services (`RequestUserContext`), eliminating scattered manual `setContext()` calls.
- **ORM Default Context**: `Model::setDefaultContext()` enables static model calls (`User::find($id)`) without explicitly passing context after boot. `__callStatic` falls back to the default context when no explicit context is provided.
- Notes: No breaking changes. Explicit context passing continues to work. Default context only available after `Framework::boot()`.

### 1.30.1 — Diphda (Patch, Released 2026-02-09)
- **JWTService Context Fix**: `AuthBootstrap::initialize()` now sets `JWTService::setContext()` before creating authentication providers, ensuring JWT operations have access to the application context.
- Notes: Patch release. No breaking changes.

### 1.30.0 — Diphda (Released 2026-02-09)
- **Exception Handler Consolidation**: Unified two overlapping exception handlers into a single source of truth. Modern `Handler` absorbs channel-based log routing, context optimization, framework/app classification, and test mode. Legacy `ExceptionHandler` reduced from 1041 to ~250 lines — a thin bootstrap shim that registers PHP global handlers and delegates to the DI-managed `Handler`.
- **Boot Wiring Reorder**: Global error handlers registered at the top of `Framework::boot()` (before Phase 1). Handler wired into shim after container build via `ExceptionHandler::setHandler()`.
- **Extension Import Migration**: payvia, email-notification, entrada, aegis extensions migrated from deleted `Glueful\Exceptions\*` bridge classes to modern `Glueful\Http\Exceptions\*` namespaces.
- Notes: No breaking changes. Static API (`logError`, `setTestMode`, `getTestResponse`) preserved as delegation wrappers. `setContext()` is now a no-op.

### 1.29.0 — Capella (Released 2026-02-07)
- **Leaf Worker Mode**: `queue:work process` action for direct in-process job execution. Spawned workers no longer recursively invoke the manager.
- **ProcessManager Wiring**: Config key normalization (`max_workers` / `max_workers_global`), new `stop()` API, manager-routed stop path in WorkCommand.
- **AutoScaleCommand Config**: Normalized config propagation to ProcessManager and AutoScaler with env-driven defaults.
- **Status Runtime Fix**: `getStatus()` now includes worker runtime for display formatting.
- **Distributed Lock Fix**: Lock key changed from host-scoped to shared queue-scoped for correct multi-host coordination.
- **Queue Presets**: Seven env-driven queue configurations (`critical`, `maintenance`, `default`, `high`, `emails`, `reports`, `notifications`) with per-queue autoscale toggle.
- **Schedule Queue Env Vars**: Hardcoded queue names in `schedule.php` replaced with `SCHEDULE_QUEUE_*` env vars.
- Notes: No breaking changes. Auto-scaling default-off for all presets.

### 1.28.3 — Bellatrix (Patch, Released 2026-02-07)
- **CLI `-q` Shortcut Fix**: Removed `-q` shortcut from `--queue` option in `WorkCommand`, `ServerCommand`, and `MaintenanceCommand` to avoid collision with Symfony Console's reserved `--quiet` (`-q`).
- Notes: Fixes `LogicException: An option with shortcut "q" already exists` when running queue commands. No breaking changes.

### 1.28.2 — Bellatrix (Patch, Released 2026-02-07)
- **Container Self-Registration**: `ContainerFactory::create()` registers the container under `ContainerInterface` for autowiring into CLI commands.
- **Migration Command DI**: `RunCommand`, `StatusCommand`, `RollbackCommand` accept container/context via constructor, receiving the booted container with extension migration paths.
- **PostgreSQL Schema-Safe Introspection**: All `PostgreSQLSqlGenerator` introspection queries now use `current_schema()` instead of hardcoding `public`. Covers `information_schema`, `pg_constraint`, `pg_index`, and foreign key lookups with proper `pg_namespace` joins.
- Notes: Fixes `migrate:run/status/rollback` not discovering extension migrations. PostgreSQL introspection now works correctly with non-`public` schemas. No breaking changes.

### 1.28.1 — Bellatrix (Patch, Released 2026-02-06)
- **Router Group Stack Fix**: `Router::group()` now uses `try/finally` to always clean up `groupStack` and `middlewareStack`, preventing prefix leakage across extensions when exceptions occur inside group callbacks.
- **Cache-Aware Route Registration**: Router allows extensions to overwrite routes pre-loaded from cache instead of throwing duplicate route errors. Handles both static and dynamic routes.
- Notes: Stability patch. No breaking changes. Fixes cascading route failures when extensions are used with route caching.

### 1.28.0 — Bellatrix (Released 2026-02-05)
- **Route Caching Support**: Refactored controllers to use cacheable route syntax.
  - ResourceController methods renamed to RESTful conventions (index, show, store, update, destroy).
  - Removed wrapper methods pattern for cleaner, more maintainable code.
  - All routes now use `[Controller::class, 'method']` syntax instead of closures.
  - Methods accept `Request` directly and extract parameters from attributes.
- **Route Caching Infrastructure**: Added validation tools for cache compatibility.
  - RouteCompiler: `validateHandlers()`, `hasClosures()`, `getClosureRoutes()` for detecting non-cacheable routes.
  - RouteCache: `cacheContainsClosures()` auto-invalidates cache and logs warnings when closures detected.
  - Helps developers identify routes needing conversion to controller syntax.
- **Breaking Changes**: Method signatures changed for ResourceController extensions.
- Notes: Enables route caching for improved performance. Use `route:debug` to find closure-based routes.

### 1.27.0 — Avior (Released 2026-02-04)
- **New CLI Commands**: `doctor`, `env:sync`, `route:debug`, `route:cache:clear`, `route:cache:status`, `cache:inspect`, `test:watch`, `dev:server`.
- **Database Transaction Callbacks**: `afterCommit()` and `afterRollback()` on Connection class.
- **Route Cache Improvements**: Signature-based invalidation with SHA-256 hash.
- **Extensions Enable/Disable**: Commands now edit config directly with `--dry-run` and `--backup` options.
- Notes: No breaking changes. DX improvements for development workflow.

### 1.26.0 — Atria (Released 2026-01-31)
- **Extension Discovery Fixes**: Improved reliability of Composer-based extension discovery.
  - `PackageManifest` now falls back to `installed.json` when `installed.php` lacks provider metadata.
  - Composer's `installed.php` may omit the `extra` field; `installed.json` has complete package data.
- **ExtensionManager Lazy Discovery**: CLI commands that create their own container now auto-discover extensions.
  - `getProviders()` triggers discovery if not yet run, fixing empty provider lists in CLI tools.
- **Discovery Efficiency**: Added `$discovered` flag to ensure discovery runs exactly once.
  - Prevents redundant discovery when zero extensions are legitimately installed.
- Notes: No breaking changes. Fixes edge cases in extension discovery for CLI and documentation generation.

### 1.25.0 — Ankaa (Released 2026-01-31)
- **Multi-File Route Discovery**: RouteManifest enhanced with automatic app route file discovery.
  - All `*.php` files in the application's `routes/` directory are auto-discovered.
  - Alphabetical loading order for deterministic behavior.
  - Exclusion patterns: files starting with underscore (`_helpers.php`) are excluded as partials.
  - Double-load prevention tracks loaded files to avoid duplicate route registration.
- **Route Loading Priority**: Application routes load first (highest priority), framework routes act as fallback.
- **Domain-Driven Organization**: Enables splitting large route files into domain-specific files (e.g., `identity.php`, `social.php`, `engagement.php`).
- Notes: No breaking changes. Backward compatible with single `routes/api.php` files.

### 1.24.0 — Alpheratz (Released 2026-01-31)
- **Encryption Service**: Comprehensive AES-256-GCM encryption for strings, files, and database fields.
  - `EncryptionService` with authenticated encryption (random nonce, 16-byte auth tag).
  - Self-identifying output format: `$glueful$v1$<key_id>$<nonce>$<ciphertext>$<tag>`.
  - Key ID in output enables O(1) key lookup during rotation (no trial decryption).
  - `encrypt()`, `decrypt()`, `encryptBinary()`, `decryptBinary()`, `isEncrypted()` methods.
  - `encryptFile()`, `decryptFile()` for file encryption.
- **AAD (Additional Authenticated Data)**: Context binding prevents cross-field attacks.
  - Encrypting with `aad: 'user.ssn'` requires same AAD for decryption.
  - Prevents copying encrypted data between different fields.
- **Key Rotation Support**: Seamless migration to new encryption keys.
  - `encryption.previous_keys` config array for old keys.
  - O(1) key lookup via key ID embedded in ciphertext.
  - Old data decrypts with previous keys; new data uses current key.
- **Exception Classes**: `InvalidKeyException`, `KeyNotFoundException` for clear error handling.
- **Base64 Key Support**: Keys can use `base64:` prefix for safe env file storage.
- **CLI Commands**: `encryption:test`, `encryption:file`, `encryption:rotate`.
  - `encryption:test` verifies encryption is working correctly.
  - `encryption:file encrypt/decrypt /path/to/file` for file operations.
  - `encryption:rotate --table=users --columns=ssn` for key rotation.
- **Test Coverage**: 32 tests covering core encryption, AAD, key validation, binary handling, rotation, files.
- Notes: No breaking changes. Encryption is opt-in and additive. Uses `APP_KEY` by default.

### 1.23.0 — Aldebaran (Released 2026-01-31)
- **Blob Visibility Support**: Per-blob `public`/`private` visibility controls.
  - Upload requests accept `visibility` parameter (`public` or `private`).
  - Defaults to configured `uploads.default_visibility` (private by default).
  - Public blobs accessible without auth (unless global access is `private`).
  - Private blobs require authentication or valid signed URL.
  - Database schema updated with `visibility` column and index.
- **Signed URL Support**: HMAC-based temporary access URLs for private blobs.
  - New `SignedUrl` helper class for URL signing and validation.
  - Time-limited access with customizable TTL (default 1 hour, max 7 days).
  - New endpoint `POST /blobs/{uuid}/signed-url` for generating signed URLs.
  - Automatic signature validation on blob retrieval.
  - Falls back to `APP_KEY` if no dedicated secret configured.
- **Test Coverage**: Comprehensive unit tests for blob and signed URL functionality.
  - `SignedUrlTest` with 17 tests covering generation, validation, expiration, tampering.
  - `UploadControllerTest` with 38 tests covering resize, caching, access control, visibility.
- **Configuration**: New uploads config options for visibility and signed URLs.
- Notes: No breaking changes. Enhances blob storage system with secure temporary access.

### 1.22.0 — Achernar (Released 2026-01-30)
- **Console Command Auto-Discovery**: Zero-config command registration via `#[AsCommand]` attribute scanning.
  - Commands auto-discovered from `src/Console/Commands/` directory.
  - Production caching for fast startup (auto-generated on first run).
  - New `commands:cache` CLI command for cache management.
- **Global State Removal**: Major refactoring to replace `$GLOBALS` with explicit `ApplicationContext` dependency injection.
  - All helper functions (`config()`, `app()`, `base_path()`, etc.) now require `ApplicationContext` as first parameter.
  - New `QueueContextHolder` class replaces deprecated static trait properties (PHP 8.3 compatibility).
  - PHPStan `banned_code` rule added to prevent future `$GLOBALS` usage.
- **Service Provider Updates**: `register()` and `boot()` methods now receive `ApplicationContext` parameter.
  - `ServiceProvider` interface updated with context parameter.
  - `BaseExtension::boot()` receives context for proper DI access.
- **Authentication Refactoring**: Auth services updated for explicit context dependency.
  - `AuthenticationService`, `SessionStore`, `TokenManager` refactored for context injection.
  - `ResolvesSessionStore` trait simplified with default `getContext()` method.
- **Code Quality**: Fixed PHPStan errors, PHPCS line length violations (25+ files), PHP 8.3 compatibility.
- **Test Suite**: Updated tests to properly initialize `ApplicationContext` for Router/RouteCache.
- Notes: **Breaking change** for extensions using old helper signatures. See migration guide in CHANGELOG.

### 1.21.0 — Mira (Released 2026-01-24)
- **File Uploader Refactoring**: Complete architecture overhaul of file upload system.
  - New `ThumbnailGenerator` class for dedicated thumbnail creation with ImageProcessor (Intervention Image).
  - New `MediaMetadataExtractor` class using getID3 for pure PHP metadata extraction.
  - New `MediaMetadata` readonly value object for type-safe metadata representation.
  - New `uploadMedia()` method on FileUploader for media files with automatic thumbnails.
- **Pure PHP Media Processing**: Removed ffprobe shell_exec dependency.
  - All metadata extraction now uses getID3 library (pure PHP, no external binaries).
  - Supports images, audio, and video file metadata extraction.
- **Enhanced Configuration**: New uploader configuration section in `config/filesystem.php`.
  - Global thumbnail enable/disable toggle (`THUMBNAIL_ENABLED`).
  - Configurable thumbnail dimensions, quality, and subdirectory.
  - Configurable supported formats for thumbnail generation.
- **Documentation**: Added storage adapter installation instructions for S3, GCS, Azure, SFTP, FTP.
- Notes: Minor release. No breaking changes. New `james-heinrich/getid3` dependency added.

### 1.20.0 — Regulus (Released 2026-01-24)
- **Framework Simplification**: Removed unused subsystems for a leaner, more maintainable codebase.
- **Resource Routes**: Added `/data` prefix to generic CRUD routes (`/api/v1/data/{table}`) to avoid conflicts with custom application routes.
- **Async System Removed**: Entire Fiber-based async subsystem removed (~30 files) as it was unused in practice.
- **Rate Limiting Consolidated**: Basic rate limiting system removed; enhanced system (`EnhancedRateLimiterMiddleware`) retained with all advanced features.
- **Configuration Cleanup**: Removed unused environment variables (`ENABLE_AUDIT`) and duplicate pagination settings.
- Notes: Minor release. Resource route URLs changed from `/api/v1/{table}` to `/api/v1/data/{table}`.

### 1.19.2 — Canopus (Released 2026-01-24)
- **ValidationException Consolidation**: Unified validation exceptions from 3 classes to 1.
  - Removed `Glueful\Exceptions\ValidationException` (legacy).
  - Removed `Glueful\Uploader\ValidationException` (empty class).
  - All code now uses `Glueful\Validation\ValidationException` with static factory methods.
- **Database Query Building**: Improved SQL parsing and cross-database compatibility.
  - `PaginationBuilder` regex improvements for ORDER BY, LIMIT, and complex query detection (UNION, CTEs, window functions).
  - `QueryValidator` now supports `schema.table` format in table name validation.
  - `WhereClause` expanded operators (IS, IS NOT, BETWEEN, REGEXP, ILIKE) with injection protection.
- **Bug Fixes**: PHPStan and Intelephense warnings in Handler.php, variable shadowing in ValidationHelper.
- Notes: Patch release. No breaking changes for most users. See CHANGELOG for migration if using legacy ValidationException directly.

### 1.19.1 — Canopus (Released 2026-01-22)
- **Simplified Configuration**: Consolidate URL and version environment variables.
  - Single `BASE_URL` for all URL derivation (removed `API_BASE_URL`).
  - Single `API_VERSION` as integer (removed `API_VERSION_FULL`, `API_DEFAULT_VERSION`).
  - Cleaner deployment configuration with fewer variables to manage.
- Notes: Patch release. Update `.env` files to use simplified format.

### 1.19.0 — Canopus (Released 2026-01-22)
- **Search & Filtering DSL**: Comprehensive URL query parameter syntax for filtering, sorting, and search.
  - Filter syntax: `filter[field][operator]=value` (14 operators with aliases).
  - Sorting: `sort=-created_at,name` (multi-column with direction).
  - Full-text search: `search=query&search_fields=title,body`.
  - `QueryFilter` base class with field whitelisting and custom filter methods.
  - PHP 8 attributes: `#[Filterable]`, `#[Searchable]`, `#[Sortable]`.
  - `FilterMiddleware` for automatic query parameter parsing.
  - `OperatorRegistry` for extensible operator management.
- **Search Engine Adapters**: Pluggable search backends.
  - `DatabaseAdapter` - SQL LIKE queries (default, no setup required).
  - `ElasticsearchAdapter` - Full Elasticsearch integration (optional).
  - `MeilisearchAdapter` - Full Meilisearch integration (optional).
  - `SearchResult` value object with pagination.
  - Auto-migration for `search_index_log` table.
- **Searchable Trait**: ORM model integration for search engines.
  - `makeSearchable()`, `removeFromSearch()`, `Model::search()`.
  - Auto-indexing on model save (configurable).
- **CLI**: `scaffold:filter` command for generating QueryFilter classes.
- Notes: No breaking changes. Search adapters are optional (database works out of the box). Completes Priority 3 Phase 3 (Search & Filtering DSL).

### 1.18.0 — Hadar (Released 2026-01-22)
- **Webhooks System**: Comprehensive webhook infrastructure with event-driven integrations.
  - Event-based subscriptions with wildcard matching (`user.*`, `*`).
  - HMAC-SHA256 signatures in Stripe-style format (`t=timestamp,v1=signature`).
  - Reliable delivery via queue with exponential backoff retry (1m, 5m, 30m, 2h, 12h).
  - Auto-migration for database tables (follows `DatabaseLogHandler` pattern).
  - `WebhookSubscription` and `WebhookDelivery` ORM models.
  - `DispatchesWebhooks` trait and `#[Webhookable]` attribute for event integration.
  - REST API for subscription management (`WebhookController`).
  - CLI commands: `webhook:list`, `webhook:test`, `webhook:retry`.
  - Static `Webhook` facade for easy dispatching.
- Notes: No breaking changes. Auto-migration creates tables on first use. Completes Priority 3 Phase 3 (first feature).

### 1.17.0 — Alnitak (Released 2026-01-22)
- **Rate Limiting Enhancements**: Comprehensive rate limiting system with per-route limits, tiered access, and multiple algorithms.
  - New `#[RateLimit]` attribute for per-route rate limiting (IS_REPEATABLE for multi-window).
  - New `#[RateLimitCost]` attribute for operation cost multipliers.
  - Tiered rate limiting with configurable tiers (anonymous, free, pro, enterprise).
  - Multiple algorithms: Fixed Window, Sliding Window, Token Bucket.
  - IETF-compliant rate limit headers (`RateLimit-*`, `X-RateLimit-*`).
  - Cost-based limiting for expensive operations.
  - `RateLimitManager` for orchestrating limiters, tiers, and attributes.
  - `EnhancedRateLimiterMiddleware` for automatic rate limit enforcement.
  - Pluggable storage via `StorageInterface` with Cache and Memory backends.
- Notes: No breaking changes. Backward compatible with existing `RateLimiterMiddleware`. Completes Priority 3 Phase 2.

### 1.16.0 — Meissa (Released 2026-01-22)
- **API Versioning Strategy**: Comprehensive API versioning system with multiple resolution strategies.
  - New `#[Version]` attribute for declaring API version requirements on routes.
  - New `#[Deprecated]` attribute for marking deprecated API versions with messages.
  - New `#[Sunset]` attribute for specifying sunset dates on deprecated endpoints.
  - Multiple version resolvers: URL prefix (`/v1/`), Header (`X-API-Version`), Query parameter, Accept header.
  - `VersionManager` for managing version resolution strategies.
  - `VersionNegotiationMiddleware` for automatic version detection.
  - `ApiVersion` value object for version comparisons and constraints.
  - Deprecation warnings via response headers.
  - Sunset headers for scheduled API retirement.
- Notes: No breaking changes. Versioning is opt-in and additive. Completes Priority 3 Phase 1.

### 1.15.0 — Rigel (Released 2026-01-22)
- **Real-Time Development Server**: Enhanced development server with file watching and integrated services.
  - New `FileWatcher` class for automatic file change detection with polling strategy.
  - New `RequestLogger` class for colorized HTTP request logging with timing.
  - New `LogEntry` class for structured request log entries.
  - `serve --watch` option for auto-restart on code changes.
  - `serve --queue` option for integrated queue worker.
  - Port auto-selection when preferred port is in use.
  - Colorized output with method and status code highlighting.
  - Configurable poll interval with `--poll-interval`.
- Notes: No breaking changes. Completes Priority 2 developer experience features.

### 1.14.0 — Bellatrix (Released 2026-01-22)
- **Interactive CLI Wizards**: Enhanced developer experience for console commands.
  - New `Prompter` class with fluent API for CLI prompts (ask, confirm, choice, multiChoice, suggest).
  - New `ProgressBar` wrapper with enhanced formats and auto-progress iteration.
  - New `Spinner` class with multiple animation styles for indeterminate progress.
  - `BaseCommand` enhanced with interactive helper methods.
  - Scaffold commands updated for interactive mode (prompts for arguments when not provided).
  - Full `--no-interaction` support for CI/CD environments.
- Notes: No breaking changes. Continues Priority 2 developer experience improvements.

### 1.13.0 — Saiph (Released 2026-01-22)
- **Enhanced Scaffold Commands**: Complete scaffold command system for rapid development.
  - `scaffold:middleware` - Generate route middleware classes implementing `RouteMiddleware`.
  - `scaffold:job` - Generate queue job classes with queue, retry, and timeout options.
  - `scaffold:rule` - Generate validation rule classes with parameter and implicit support.
  - `scaffold:test` - Generate PHPUnit test classes for unit and feature testing.
- **Database Factories & Seeders**: Complete test data generation and database seeding system.
  - `Factory` base class with states, sequences, relationships, and lifecycle callbacks.
  - `FakerBridge` for optional Faker integration with availability checking.
  - `Seeder` base class with dependency ordering, transactions, and truncation helpers.
  - `HasFactory` trait for ORM models enabling `Model::factory()` syntax.
  - `db:seed` command with production environment protection.
  - `scaffold:factory` and `scaffold:seeder` commands for generating classes.
- **Documentation**: New `docs/FACTORIES.md` with comprehensive usage guide.
- Notes: No breaking changes. Factories require `fakerphp/faker` as dev dependency. Completes Priority 2 features for v1.13.0.

### 1.12.0 — Mintaka (Released 2026-01-21)
- **API Resource Transformers**: Complete JSON transformation layer for consistent, well-structured API responses.
  - `JsonResource` base class for transforming arrays and simple objects.
  - `ModelResource` class with ORM-specific helpers for model transformation.
  - `ResourceCollection` for handling multiple items with metadata support.
  - `PaginatedResourceResponse` for pagination with link generation.
  - Conditional attributes: `when()`, `mergeWhen()`, `whenHas()`, `whenNotNull()`.
  - Relationship helpers: `whenLoaded()`, `whenCounted()`, `whenPivotLoaded()`.
  - `MissingValue` sentinel pattern for clean attribute omission.
  - Pagination support for both QueryBuilder flat format and ORM meta format.
- **Console Commands**: New `scaffold:resource` command to generate API resource classes.
  - `--model` option for ModelResource with ORM-specific helpers.
  - `--collection` option for ResourceCollection with metadata support.
  - `--force` option to overwrite existing files.
  - `--path` option for custom output directory.
- Notes: No breaking changes; API Resources are opt-in and additive. Completes Priority 1 features.

### 1.11.0 — Alnilam (Released 2026-01-21)
- **ORM / Active Record**: Complete Active Record implementation built on QueryBuilder.
  - New `Model` base class with CRUD operations, mass assignment protection, and attribute handling.
  - New `Builder` class wrapping QueryBuilder with model-aware queries, eager loading, and scope support.
  - New `Collection` class for model results with rich iteration, filtering, and transformation methods.
  - Relations: `HasOne`, `HasMany`, `BelongsTo`, `BelongsToMany`, `HasOneThrough`, `HasManyThrough`, `Pivot`.
  - Traits: `HasAttributes`, `HasEvents`, `HasRelationships`, `HasTimestamps`, `HasGlobalScopes`, `SoftDeletes`.
  - Custom casts: `AsJson`, `AsArrayObject`, `AsCollection`, `AsDateTime`, `AsEncryptedString`, `AsEnum`, `Attribute`.
  - Model lifecycle events: `ModelCreating`, `ModelCreated`, `ModelUpdating`, `ModelUpdated`, `ModelSaving`, `ModelSaved`, `ModelDeleting`, `ModelDeleted`, `ModelRetrieved`.
  - Contracts: `ModelInterface`, `CastsAttributes`, `Scope`, `SoftDeletable`.
  - New `ORMProvider` service provider for DI container registration.
- **Console Commands**: Reorganized CLI commands to `scaffold:*` namespace.
  - New `scaffold:model` command to generate ORM model classes.
  - Renamed `make:request` → `scaffold:request`, `generate:controller` → `scaffold:controller`.
- **CI**: GitHub Actions jobs now run sequentially for better debugging.
- Notes: No breaking changes; ORM is opt-in and additive. Existing QueryBuilder code continues to work.

### 1.10.0 — Elnath (Released 2026-01-21)
- **Exception Handler**: Centralized exception handling with typed HTTP exceptions (4xx Client, 5xx Server, Domain).
  - New `ExceptionHandlerInterface` contract and `RenderableException` interface.
  - New `HttpException` base class with status codes and context.
  - New `Handler` class with environment-aware error responses.
  - New `ExceptionMiddleware` for automatic exception-to-response conversion.
  - Client exceptions: `BadRequestException`, `UnauthorizedException`, `ForbiddenException`, `NotFoundException`, `MethodNotAllowedException`, `ConflictException`, `UnprocessableEntityException`, `TooManyRequestsException`.
  - Server exceptions: `InternalServerException`, `ServiceUnavailableException`, `GatewayTimeoutException`.
  - Domain exceptions: `ModelNotFoundException`, `AuthenticationException`, `AuthorizationException`, `TokenExpiredException`.
- **Request Validation**: Declarative validation with PHP 8 attributes and FormRequest classes.
  - New `#[Validate]` attribute for inline validation on controller methods.
  - New `FormRequest` base class with authorization, custom messages, and data preparation hooks.
  - New `ValidatedRequest` wrapper for type-safe validated data access.
  - New `ValidationMiddleware` for automatic request validation in the pipeline.
  - New `RuleParser` for Laravel-style string rule syntax (`'required|email|max:255'`).
  - New `'validate'` middleware alias for route-level validation.
  - New validation rules: `Confirmed`, `Date`, `Before`, `After`, `Url`, `Uuid`, `Json`, `Exists`, `Nullable`, `Sometimes`, `File`, `Image`, `Dimensions`.
  - New `make:request` CLI command to generate FormRequest classes.
- Notes: No breaking changes; both features are opt-in and additive.

### 1.9.2 — Deneb (Released 2026-01-20)
- Documentation: New `ResourceRouteExpander` class that automatically expands `{resource}` routes to table-specific endpoints with full database schemas.
- Documentation: OpenAPI 3.1.0 support with JSON Schema draft 2020-12 alignment (nullable type arrays, SPDX license identifiers, jsonSchemaDialect).
- Documentation: Default OpenAPI version changed to 3.1.0; output file renamed from `swagger.json` to `openapi.json`.
- Documentation: Scalar UI improvements with `hideClientButton` and `showDeveloperTools` configuration options.
- Documentation: Tags in sidebar now sorted alphabetically; resource tags renamed to "Table - {name}".
- Database: Fixed `SchemaBuilder::getTableColumns()` returning empty arrays due to incorrect `array_is_list()` check.
- Removed: `TableDefinitionGenerator` class - resource routes now expand directly from database schemas.

### 1.9.1 — Castor (Released 2026-01-19)
- Documentation: Major refactor of OpenAPI documentation system with new `OpenApiGenerator` and `DocumentationUIGenerator` classes.
- Documentation: New `--ui` option for `generate:openapi` command supporting Scalar, Swagger UI, and Redoc.
- Documentation: New `config/documentation.php` centralizing all documentation settings.
- Documentation: `CommentsDocGenerator` now uses `phpDocumentor/ReflectionDocBlock` for robust PHPDoc parsing.
- Documentation: Extension route discovery now uses `ExtensionManager::getProviders()` for Composer-installed packages.
- Validation: New `Numeric` and `Regex` validation rules.
- Dependencies: Updated Symfony packages to ^7.4; added `phpdocumentor/reflection-docblock`.
- Console: Fixed `Application` to use `add()` instead of undefined `addCommand()` for Symfony Console compatibility.

### 1.9.0 — Betelgeuse (Released 2026-01-17)
- **Breaking**: Minimum PHP version raised to 8.3 (from 8.2). CI now tests PHP 8.3 and 8.4.
- Console: Renamed `Application::addCommand(string)` to `Application::registerCommandClass(string)` to resolve Symfony Console 7.3 compatibility.
- Routing: `RouteManifest::load()` now prevents double‑loading routes, eliminating duplicate route warnings in CLI commands.
- Security: Fixed PHPStan strict boolean check in `CSRFMiddleware` for cookie token validation.
- Tests: Added missing PSR‑4 namespace declarations to async test files.

### 1.8.1 — Vega (Released 2025-11-23)
- Security: Extend `Utils::validatePassword()` so policies can require lowercase characters alongside existing uppercase, numeric, and special-character toggles.
- Async I/O: Harden `async_stream()` helper to accept already-wrapped `AsyncStream`/`BufferedAsyncStream` instances or raw resources, always normalizing to a canonical async transport before applying buffered wrappers and config defaults. This fixes static analysis complaints around BufferedAsyncStream return types.

### 1.8.0 — Spica (Released 2025-11-13)
- Events (Auth): First-class hooks to enrich session cache and login responses
  - `SessionCachedEvent` dispatched after cache write in `SessionCacheManager::storeSession()`
  - `LoginResponseBuildingEvent` and `LoginResponseBuiltEvent` around `AuthController::login()` return
- DX: Documented event usage with setter-based mutation (no by-ref), paths under `src/...`, and listener examples in `docs/SESSION_EVENTS_PROPOSAL.md`
- Guidance: Encourage app data under `context.*` to avoid field collisions; events are synchronous so heavy work should be queued

### 1.7.4 — Arcturus (Released 2025-10-28)
- Authentication: Minimal, configurable account‑status gate enforced in core login and refresh flows via `security.auth.allowed_login_statuses` (default `['active']`). Silent failure to avoid account enumeration; policy is intentionally lean to let apps extend.

### 1.0.0 — Aurora (Released 2025-09-20)
- **First stable release** of the split Glueful Framework package with comprehensive features.
- **Comprehensive permissions and authorization system**.
- Core overhauls: custom DI container (replacing Symfony DI); PSR‑14 event dispatcher.
- Storage: migration to Flysystem with updated configuration model.
- Config & Validation: array‑schema configuration and rules‑based validation system.
- Next‑Gen Router with fast static/dynamic matching and attribute route loader.
- Alias support for services and improved provider bootstrapping.
- Security enhancements with expanded CLI commands and hardened endpoints.
- Cleanup: removal of LDAP/SAML auth and legacy config/serialization modules.
- Docs: database and storage guides refreshed; comprehensive cookbook.

### 1.1.0 — Polaris (Released 2025-09-22)
- **Testing infrastructure**: TestCase base class for application testing with framework state reset support.
- **Event system enhancements**: Comprehensive documentation covering all framework events with complete examples.
- **Event abstraction layer**: BaseEvent class providing PSR-14 compliant abstraction with enhanced features.
- **Documentation improvements**: Complete event listener registration patterns and best practices.
- Guides navigation and testing utilities for robust application development.

### 1.2.0 — Vega (Released 2025-09-23)
- **Tasks/Jobs Architecture**: Complete separation of business logic (Tasks) from queue execution (Jobs).
- **Task Management System**: Comprehensive task classes for cache maintenance, database backup, log cleanup, notification retry, and session cleanup.
- **Enhanced Console Commands**: New `cache:maintenance` command and improved console application structure.
- **Testing Infrastructure**: Complete integration test coverage with enhanced container management and reliability.
- **Code Quality**: Fixed PHP CodeSniffer violations and improved test state management.
- **Migration**: Removed legacy `src/Cron/` classes in favor of the new architecture.

### 1.3.0 — Deneb (Released 2025-10-06)
- HTTP: Strategy‑based retry support using Symfony `RetryableHttpClient` and `GenericRetryStrategy`.
- API Client Builder: `retries(...)`, `buildWithRetries()`, and `getRetryConfig()` for fluent retry configuration.
- Presets: sensible retry defaults for payments and external service integrations.
- Notes: No breaking changes; improves resilience and clarity for outbound HTTP.

### 1.3.1 — Altair (Released 2025-10-10)
- Console: `install` command runs truly non‑interactive in CI when `--quiet`, `--no-interaction`, or `--force` are provided (skips env confirmation prompt).
- DX: clean PHPStan signal by removing redundant `method_exists()` check on `InputInterface::isInteractive()`.

### 1.4.0 — Rigel (Released 2025-10-11)
- Sessions: Introduce SessionStoreInterface and default SessionStore for a unified, testable session lifecycle (create/update/revoke/lookup/health).
- TTL Policy: Canonical TTL helpers on the store (provider + remember‑me aware); TokenManager defers TTLs to the store.
- DI & Resolver: Add SessionStoreResolver and ResolvesSessionStore trait; providers and managers consistently resolve the store via DI.
- Caching: Safe cache keys for token mappings (hashed tokens; sanitized prefixes) to avoid backend key restrictions.
- Removals: TokenStorageService and TokenStorageInterface removed after migration; deprecated paths eliminated.
- Analytics: Store‑first listing with fallbacks; reduced cache‑shape coupling.

### 1.4.1 — Rigel (Patch) (Released 2025-10-11)
- Install UX: SQLite‑first install flow; other engines are skipped during install and can be configured post‑install.
- Non‑interactive defaults: `migrate:run` invoked with `--force` (and `--no-interaction`/`--quiet` when install runs with `--quiet`).
- Cache clear: `cache:clear` during install runs with `--force` and respects quiet mode flags to avoid prompts.
- Health checks: database connection probe removed from install (SQLite does not require a network connection; migrations surface real issues).
- DX: removed redundant comparison that triggered a PHPStan strict‑comparison warning.

### 1.4.2 — Rigel (Patch) (Released 2025-10-11)
- Dev ergonomics: fix PSR‑4 autoloading for tests by aligning test namespaces with `Glueful\Tests\…` to remove Composer warnings.
- Documentation: synced release notes to reflect 1.4.1 install flow improvements; no runtime behavior changes.

### 1.5.0 — Orion (Released 2025-10-13)
- Notifications: introduce shared DI provider for `ChannelManager` and `NotificationDispatcher` (NotificationsProvider).
- Email flows: EmailVerification and queue job SendNotification use DI‑first dispatcher/channel resolution with safe fallbacks.
- Safety: remove hard `ExtensionManager` prechecks in email verification/password reset; rely on dispatcher/channel availability.
- Diagnostics: soft logging when email channel is unavailable or when no channels succeeded (verification/password reset).
- Config: align retry configuration source to `email-notification.retry`.

### 1.6.0 — Sirius (Released 2025-10-13)
- Router: content negotiation helpers; ETag/conditional middleware patterns.
- DI: compiled container optimizations; `services.json` manifest + map/codegen helpers.
- Config: DSN parsing utilities (DB, Redis) and environment validation helpers.
- Security: CSP builder configuration + presets; refined admin/allow‑lists.
- Extensions: OpenTelemetry exporter planned as optional extension (not core).

### 1.7.0 — Procyon (Released 2025-10-18)
- Async/Concurrency: Introduce fiber‑based scheduler with `spawn`, `all`, `race`, `sleep`.
- Async HTTP: `CurlMultiHttpClient` with cooperative polling, streaming, pooling, and retry knobs.
- Async I/O: `AsyncStream` and `BufferedAsyncStream` for non‑blocking reads/writes and convenience helpers.
- Cancellation: Cooperative `CancellationToken` across scheduler, I/O, and HTTP.
- Promise: Lightweight chainable wrapper for Tasks with `then/catch/finally`, `all/race`.
- Metrics: Expanded instrumentation hooks (suspend/resume, queue depth, resource‑limit signals).
- Config & DI: Central `config/async.php`; `AsyncProvider` wires `Scheduler`, `HttpClient`, and metrics; `AsyncMiddleware` alias `async`.
- Notes: Defaults are backward‑compatible; resource limits off unless configured.

### 1.7.1 — Canopus (Released 2025-10-21)
- Extensions: Call `ExtensionManager::discover()` before `::boot()` during framework initialization to ensure providers are instantiated and booted at runtime.
- Migrations: Extension migrations registered via `loadMigrationsFrom()` are now visible to `migrate:status`/`migrate:run`.
- CLI: `extensions:why` and `extensions:list` reflect the final provider list after boot, improving diagnostics.

### 1.7.2 — Antares (Released 2025-10-21)
- Extensions: `ServiceProvider::loadRoutesFrom()` made idempotent and exception‑safe to avoid duplicate route registration and to keep boot resilient in production.
- CLI: `serve` command reclassifies PHP built‑in server access/startup lines as normal output to reduce false error noise.

### 1.7.3 — Pollux (Released 2025-10-21)
- Database: QueryBuilder normalizes 2‑argument `where($column, $value)` / `orWhere($column, $value)` to `=` operator, fixing a `TypeError` and improving portability of boolean/int filters across PostgreSQL/MySQL/SQLite.
- DX: Further cleanup of dev‑server STDERR log classification; access/lifecycle lines are treated as info while preserving real error visibility.

### 1.8 (Minor)
- Queue/workers: improved autoscaling rules; per‑queue budgets; graceful drain; health endpoints.

### 2.0 (Major; tentative)
- Planned only if a fundamental contract change is justified (e.g., core interface shifts). Otherwise continue 1.x with incremental improvements.

## Cross‑Repository Goals
- API Skeleton (glueful/api‑skeleton)
  - Smoke tests and deploy templates; default Redis queue; Docker/Helm examples.
- Docs (glueful/docs)
  - Publish and maintain cookbook sections (setup, routing, DI DSL, uploads, security, observability) alongside releases.

## Contribution & Coordination
- Issues/PRs: use labels `roadmap`, `epic`, `rfc`, `good‑first‑issue`.
- Proposals: open a Discussion or RFC issue outlining goals, scope, risks, and acceptance criteria.
- For potentially breaking changes, follow `BREAKING_CHANGE_PROCESS.md`.
- CI required checks (for PR merge):
  - "PHP CI / Lint & Style"
  - "PHP CI / PHPUnit (PHP 8.3)" (and PHP 8.4 optional)
  - "PHP CI / PHPStan"
  - "PHP CI / Security Audit" (optional)

This document is intentionally concise; detailed designs will be tracked per issue/RFC.
