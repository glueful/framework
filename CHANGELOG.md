# CHANGELOG

All notable changes to the Glueful framework will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

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
