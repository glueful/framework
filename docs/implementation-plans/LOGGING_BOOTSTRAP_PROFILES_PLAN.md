# Logging Bootstrap Profiles Plan

## Status

Implemented on 2026-03-03.

Delivered:

- Profile-driven logging bootstrap in `config/logging.php`
- Production logging safety validation in `src/Console/Commands/System/CheckCommand.php`
- Canonical documentation in `docs/LOGGING_BOOTSTRAP.md`
- Documentation entry-point link in `README.md`
- Updated environment guidance in `.env.example`
- Changelog entry in `CHANGELOG.md`
- Test coverage:
  - `tests/Unit/Logging/LoggingConfigProfileTest.php`
  - `tests/Feature/SystemCheckLoggingSafetyTest.php`

Verification:

- Targeted tests pass:
  - `vendor/bin/phpunit tests/Unit/Logging/LoggingConfigProfileTest.php tests/Feature/SystemCheckLoggingSafetyTest.php`

## Objective

Establish a clear, production-safe logging bootstrap model in core framework while preserving environment/config flexibility.

This plan keeps generic logging in core and standardizes how logging defaults are applied so teams start from known-safe behavior instead of implicit scattered defaults.

## Why This Plan

Current behavior is functional but confusing across files:

- `config/logging.php` currently defaults `LOG_TO_DB` to `true` when env is absent.
- `.env.example` currently sets `LOG_TO_DB=false`.
- Audit/event logging toggles live in `config/events.php` and are runtime-checked in `Framework::registerCoreEventSubscribers()`.
Result: teams can get different behavior depending on env completeness and documentation path.

## Scope

In scope:

- Core logging capability remains in framework.
- Add explicit environment-aware baseline profiles (development/staging/production/testing).
- Keep all logging/audit toggles overridable via env.
- Publish one canonical operator-facing guide.
- Add readiness checks to prevent unsafe prod logging configs.

Out of scope:

- Building first-class business/domain audit model (separate extension effort).
- Replacing Monolog/log infrastructure.
- Removing `activity_logs`.

## Current State (Code-Verified)

- Logger bootstrap:
  - `src/Logging/LogManager.php`
  - `src/Container/Providers/CoreProvider.php`
- Audit subscriber registration:
  - `src/Framework.php` (`events.enabled` + `events.listeners.audit_logging` gates)
  - `src/Events/Listeners/ActivityLoggingSubscriber.php`
- DB log storage:
  - `src/Logging/DatabaseLogHandler.php` (`activity_logs`, structured columns + indexes)
- Cleanup/retention:
  - `src/Logging/DatabaseLogPruner.php`
  - `src/Tasks/LogCleanupTask.php`
  - `src/Queue/Jobs/LogCleanupJob.php`
- Environment defaults:
  - `.env.example`
  - `config/logging.php`
  - `config/events.php`
- Existing docs gap:
  - No single canonical logging bootstrap document currently defines profile-driven defaults end-to-end.

## Target Behavior

1. Core always provides generic logging capability.
2. Safe baseline is selected by environment when env vars are not explicitly set.
3. Env vars always win (no loss of flexibility).
4. Production baseline is explicit and documented:
   - `LOG_TO_FILE=true`
   - `LOG_TO_DB=false` by default (opt-in for queryable incident/audit operations)
   - `LOG_LEVEL=warning` (or `info` if operator chooses)
   - `EVENTS_ENABLED=true`
   - `EVENTS_AUDIT_LOGGING=true`
   - `APP_DEBUG=false`
   - retention guidance:
     - debug: 7 days
     - app/framework: 30-90 days
     - auth/security/error: 365 days

## Implementation Design

### 1) Add Logging Baseline Profiles to Config

Add a `profiles` section to `config/logging.php`:

- `development`
- `staging`
- `production`
- `testing`

Each profile defines default values for:

- `application.level`
- `application.log_to_file`
- `application.log_to_db`
- `framework.level`
- optional default retention days by channel

Add a `profile` selector in config:

- `logging.profile` resolved from `LOG_PROFILE` env if set.
- fallback to `APP_ENV`.

### 2) Deterministic Merge Strategy

Implement profile resolution order:

1. Hardcoded config defaults
2. Selected profile defaults
3. explicit env variables (`LOG_*`, `FRAMEWORK_LOG_*`, etc.)
4. runtime overrides (if any)

This prevents hidden precedence bugs and keeps env overrides authoritative.

### 3) Keep Audit/Event Gates Explicit

No behavior change to event gating logic, but align documentation and checks around:

- `EVENTS_ENABLED`
- `EVENTS_AUDIT_LOGGING`

Ensure production profile examples always include both as `true` unless intentionally disabled.

### 4) Production Safety Checks

Enhance system validation command(s) to catch risky production states:

- `APP_ENV=production` with `APP_DEBUG=true`
- `LOG_TO_FILE=false` and `LOG_TO_DB=false` simultaneously (no durable logging)
- `EVENTS_ENABLED=false` or `EVENTS_AUDIT_LOGGING=false` in production (warning or fail based on strictness)
- retention values set to `0`/invalid

Primary target:

- `src/Console/Commands/System/CheckCommand.php` (`--production` path)

### 5) Documentation Consolidation

Add dedicated logging bootstrap doc:

- `docs/LOGGING_BOOTSTRAP.md`

Include:

- profile matrix by environment
- required vs optional production settings
- DB logging tradeoffs
- retention strategy by channel
- incident-response query examples against `activity_logs`
- deployment checklist snippet

Update docs with a single source of truth:

- Add `docs/LOGGING_BOOTSTRAP.md` and reference it from `README.md` and relevant implementation plans.

### 6) Intentional Breaking Cleanup

This plan assumes a clean break is acceptable.

Actions:

- Remove ambiguous fallback behavior that can silently change runtime logging.
- Enforce profile-first defaults by environment.
- Require explicit opt-in for DB logging in production profile.
- Tighten production checks to fail fast on unsafe logging/audit settings.

Migration expectation:

- Existing apps must align their `.env` values to the new profile model during upgrade.
- Publish a short upgrade checklist in release notes.

## File-Level Change Plan

1. `config/logging.php`
   - Add profile definitions.
   - Add deterministic profile merge helper logic or profile lookup structure.
   - Normalize defaults so config-level behavior matches `.env.example` intent.

2. `.env.example`
   - Add commented `LOG_PROFILE` guidance.
   - Add concise environment-specific recommended blocks.
   - Keep defaults safe and minimal.

3. `src/Logging/LogManager.php`
   - Ensure resolved config uses merged profile defaults.
   - Keep existing env override behavior.

4. `src/Console/Commands/System/CheckCommand.php`
   - Add production logging/audit validations.

5. `docs/LOGGING_BOOTSTRAP.md` (new)
   - Canonical operator/developer guide.

6. `README.md` (or docs index page)
   - Add pointer to `docs/LOGGING_BOOTSTRAP.md` under operations/observability references.

7. `CHANGELOG.md`
   - Document profile behavior and required migration steps.

## Test Plan

Add/extend tests for:

- profile resolution precedence (profile + explicit env behavior)
- production checks in `system:check --production`
- LogManager handler wiring with:
  - `LOG_TO_FILE=true/false`
  - `LOG_TO_DB=true/false`
- audit subscriber gating:
  - `EVENTS_ENABLED` and `EVENTS_AUDIT_LOGGING` permutations

Suggested test locations:

- `tests/Unit/Logging/*`
- `tests/Feature/*` (CLI production checks)

## Rollout Plan

Phase 1: Config + docs alignment

- Add profiles and docs.
- Remove legacy ambiguous defaults.

Phase 2: Validation hardening

- Add production checks and warnings.
- Add CI check to guard unsafe defaults in production mode.

Phase 3: Adoption + cleanup

- Update templates/skeleton recommendations.
- Remove stale legacy audit table references where they imply current default runtime behavior.

## Risks and Mitigations

- Risk: Existing apps may break due to tightened defaults and checks.
  - Mitigation: provide explicit migration guide and fail-fast diagnostics.

- Risk: Overly strict production checks may block existing pipelines.
  - Mitigation: warnings first, optional strict/fail mode later.

- Risk: Documentation drift returns.
  - Mitigation: add docs ownership note and include doc update in release checklist.

## Acceptance Criteria

- Fresh install behavior is deterministic by environment profile.
- Production defaults are clearly documented and safe.
- New profile model is deterministic and enforced.
- `system:check --production` flags unsafe logging/audit combinations.
- Logging bootstrap guidance exists in a single canonical doc and is linked from top-level docs entry points.

## Recommended Production Baseline (Reference)

- `APP_ENV=production`
- `APP_DEBUG=false`
- `LOG_TO_FILE=true`
- `LOG_TO_DB=false` (enable only when DB-backed searchable logs are required)
- `LOG_LEVEL=warning` (or `info` if operationally required)
- `EVENTS_ENABLED=true`
- `EVENTS_AUDIT_LOGGING=true`
- Retention:
  - `LOG_RETENTION_DEBUG_DAYS=7`
  - `LOG_RETENTION_APP_DAYS=30` to `90`
  - `LOG_RETENTION_FRAMEWORK_DAYS=30` to `90`
  - `LOG_RETENTION_AUTH_DAYS=365`
  - `LOG_RETENTION_SECURITY_DAYS=365`
  - `LOG_RETENTION_ERROR_DAYS=365`
