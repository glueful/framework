# CHANGELOG

All notable changes to the Glueful framework will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

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
