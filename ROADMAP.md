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

### 1.5 (Minor)
- Router: content negotiation helpers; ETag/conditional middleware patterns.
- DI: container dump optimizations; service map/codegen helpers.
- Config: DSN parsing utilities (DB, Redis), environment validation helpers.
- Observability: OpenTelemetry exporter; span decorators; sampling controls.
- Security: CSP builder configuration + presets; refined admin/allow‑lists.
- Extensions: composer/manifest diagnostics; optional signing/verification hooks.
- Caching: distributed strategy knobs; stampede/lock improvements.

### 1.6 (Minor)
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
  - “PHP CI / Lint & Style”
  - “PHP CI / PHPUnit (PHP 8.2)” (and PHP 8.3 optional)
  - “PHP CI / PHPStan”
  - “PHP CI / Security Audit” (optional)

This document is intentionally concise; detailed designs will be tracked per issue/RFC.
