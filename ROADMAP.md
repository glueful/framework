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
