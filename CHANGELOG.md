# CHANGELOG

All notable changes to the Glueful framework will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [1.1.0] - 2025-09-19 — Aurora

Aurora (internal, pre-public) release. This version intentionally includes breaking changes as we finalize core abstractions ahead of public availability. See Changed/Removed for details and migration notes.

### Added
- Alias support for services and improved provider bootstrapping.
- Core and Console service providers for out-of-the-box wiring.

### Changed
- Dependency Injection: replaced Symfony DI with a lightweight, custom container optimized for Glueful.
- Events: migrated to PSR-14 with a custom dispatcher implementation.
- Storage: migrated to Flysystem; updated configuration structure and options.
- Configuration: refactored to array-based schemas for clarity and compile-time validation.
- Validation: moved to a rules-based system with clearer composition.
- Composer dependencies refreshed to latest compatible versions.

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

### Fixed
- Documentation updates and cleanup in the database and storage guides.

### Security
- No security-specific changes in this release.

## [1.0.0] - 2025-09-13

First stable release of the split Glueful Framework package (formerly part of glueful/glueful). This version establishes the framework runtime as a standalone library and sets a clear baseline for future 1.x releases.

### Added
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
- Router now returns standardized JSON for 404/405 via `Glueful\Http\Response::error()`.
- DI: prefer DSL‑based `services()` definitions; compiled container recommended in production.
- Configuration/env alignment:
  - `REDIS_DB` (instead of `REDIS_CACHE_DB`), `MAIL_ENCRYPTION` (instead of `MAIL_SECURE`).
  - `LOG_FILE_PATH` (replaces `LOG_PATH`), PSR‑15 toggles (`PSR15_ENABLED`, `PSR15_AUTO_DETECT`, `PSR15_STRICT`).
  - S3 controls: `S3_ACL`, `S3_SIGNED_URLS`, `S3_SIGNED_URL_TTL`.
  - `.env.example` cleanup; `LOG_TO_DB=false` by default to avoid DB dependency.
- FileUploader resolves repositories via container; improved filename handling and MIME detection.
- Route cache invalidation also watches framework `src/routes/*.php` in development.
- ExceptionHandler removed from Composer autoload “files”; PSR‑4 autoload only.

### Removed
- Legacy route and middleware system and related documentation.
- Legacy docs/SETUP.md (moved to Cookbook); deprecated report docs.
- Old monolithic CI workflow and split test workflows (replaced by single `php-ci`).

### Fixed
- S3 bucket config typos in `S3Storage` (`services.storage.s3.bucket`).
- PHPStan short‑ternary warnings in `RouteCache` and `FileUploader`; safer file read fallbacks.
- Numerous type‑safety and strictness improvements across cache, auth, console, and DI layers.

### Security
- Allow‑listed health/readiness endpoints; expanded security checks and CLI audits.
