# Token and Session Management Reimplementation Plan (No Backward Compatibility)

## Scope
- Full replacement of current token/session model.
- No legacy refresh-token storage, no fallback paths, no dual-read/write.
- Existing sessions/tokens become invalid at cutover.
- This is a security-first cutover, not a compatibility rollout.
- Day-one requirements in this document are framework-level responsibilities, not app-level customizations.

## Migration Source of Truth
- Database migrations for this work are maintained in the API skeleton, not the framework repo.
- Use:
  - `/Users/michaeltawiahsowah/Sites/glueful/api-skeleton/database/migrations`
- Framework changes should assume schema contracts, while DDL is authored and applied from the API skeleton migration path above.

## Framework Day-One Requirements
- The following must ship in core framework code from the first release of this reimplementation:
  - hash-only refresh-token persistence and rotation transaction flow
  - replay detection and session-scope revocation behavior
  - session-version based access-token invalidation
  - immediate session-cache invalidation hooks on revoke/logout/version-bump
  - provider-aware refresh issuance (`jwt` and non-`jwt` adapters)
  - cleanup task framework for token/session retention enforcement
  - built-in metrics/logging fields for auth latency, replay, lock wait, and failure reason codes
  - configuration surface (env/config) for TTLs, retention windows, idempotency window, and limits
- App projects should only set policy values and optional overrides, not re-implement auth/session core logic.

## Target Design
- Access token: short-lived JWT (5-15 min), includes `sid`, `ver`, `jti`.
- Refresh token: opaque random secret, stored as hash only.
- Session: canonical server-side state for device/login lifecycle.
- Refresh: strict one-time-use rotation in a single DB transaction.

## Schema

### `auth_sessions`
- `uuid` (pk/unique), `user_uuid`, `provider`, `remember_me`
- `status` (`active|revoked|expired`)
- `session_version` int default 1
- `expires_at`, `last_seen_at`, `revoked_at`, `created_at`, `updated_at`
- `ip_address`, `user_agent`

Indexes:
- `(user_uuid, status)`
- `(status, expires_at)`
- `(uuid, status)`

### `auth_refresh_tokens`
- `uuid` (pk/unique), `session_uuid`, `user_uuid`
- `token_hash` (sha256/binary, unique)
- `status` (`active|consumed|revoked|expired`)
- `parent_uuid`, `replaced_by_uuid`
- `issued_at`, `expires_at`, `consumed_at`, `created_at`

Indexes:
- unique `(token_hash)`
- `(session_uuid, status)`
- `(expires_at)`

Design note:
- `auth_refresh_tokens.user_uuid` is intentionally denormalized (also derivable via `session_uuid -> auth_sessions.user_uuid`) to optimize user-scoped revocation and cleanup queries without mandatory joins.

## Explicit Schema Delta (Current -> New)
- Drop from `auth_sessions`:
  - `access_token`
  - `refresh_token`
  - `access_expires_at`
  - `refresh_expires_at`
  - `last_token_refresh`
  - `token_fingerprint`
- Add to `auth_sessions`:
  - `session_version` int default 1
  - `expires_at`
  - `last_seen_at`
  - `revoked_at`
- Keep in `auth_sessions`:
  - `uuid`, `user_uuid`, `provider`, `remember_me`, `status`, `ip_address`, `user_agent`, timestamps
- New table:
  - `auth_refresh_tokens` as canonical refresh-token store

## Runtime Flows

## Login
1. Create `auth_sessions` row (`active`).
2. Issue access JWT with `sid=session_uuid`, `ver=session_version`.
3. Generate refresh token, hash it, insert active row in `auth_refresh_tokens`.
4. Return token pair.

## Access Authentication
1. Verify JWT signature and expiry.
2. Read `sid` and `ver`.
3. Resolve session state from cache (DB fallback on cache miss).
4. Reject if session inactive or `session_version` mismatch.

## Refresh (Single Transaction)
1. Hash presented refresh token.
2. `SELECT ... FOR UPDATE` by `token_hash`.
3. Validate token row is `active` and not expired; validate session is `active`.
4. Resolve provider from session:
   - if `provider === 'jwt'`, issue local access token
   - if `provider !== 'jwt'`, dispatch to provider-specific issuer/adapter
5. Mark old token as `consumed` with `consumed_at`.
6. Insert replacement refresh-token row (`active`, linked via `parent_uuid`).
7. Increment `auth_sessions.session_version`.
8. Issue new access JWT with updated `ver`.
9. Commit and return new pair.

## Replay Handling
- If consumed/revoked token is presented:
  - Revoke entire session (`session_uuid`) and all active refresh tokens for that session.
  - Return explicit replay error code.
- Default behavior:
  - strict replay policy (no grace window), second concurrent refresh after consume is treated as replay.
- Optional behavior:
  - configurable 1-2s idempotency window for concurrent refresh UX, returning the same rotated pair.
  - mechanics:
    - detect `status=consumed` and `consumed_at` within the configured window
    - read `replaced_by_uuid`
    - load replacement token/session context and return the already-issued replacement pair
    - outside the window, treat as replay and revoke session scope

## Code Structure
- `RefreshService`: owns rotation transaction and replay policy.
- `SessionRepository`: session state read/write/revoke/version bump.
- `RefreshTokenRepository`: hash lookup, consume, insert next, family revoke.
- `AccessTokenIssuer`: JWT claims/signing.
- `SessionStateCache`: cache adapter and invalidation.
- `ProviderTokenIssuer` (or provider adapters): handles non-`jwt` provider issuance paths.

Integrate by simplifying:
- `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Auth/AuthenticationService.php`
- `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Auth/TokenManager.php`
- `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Auth/SessionStore.php`

## Implementation Steps (Direct Cutover)
1. Create new migrations:
   - create `auth_refresh_tokens`
   - add/reshape lifecycle columns on `auth_sessions`
   - drop legacy token columns/indexes not needed
2. Implement new repositories/services.
3. Replace refresh endpoint internals to call `RefreshService` only.
4. Replace access auth check to enforce session `status` + `version`.
5. Add cleanup task for expired refresh tokens and sessions.
6. Add metrics/logging/tracing.
7. Deploy cutover release and force re-authentication.

Implementation requirement:
- Steps 2-6 are framework deliverables and must be completed in core before any app-level adoption.

## Cleanup Policy
- Refresh-token cleanup:
  - delete `consumed|expired|revoked` rows older than 30 days.
- Session cleanup:
  - mark expired sessions (`expires_at < now`) as `expired`.
  - delete hard-revoked/expired sessions older than retention window (for example, 30-90 days).
- Schedule:
  - run every hour by default.
  - run every 15 minutes for high-traffic deployments.

## Operational Cutover
1. Schedule maintenance window.
2. Deploy schema + code together.
3. Revoke all pre-cutover sessions.
4. Monitor refresh success/error rates and DB latency.
5. Rollback means full app rollback, not mixed-mode compatibility.

## Performance Requirements
- Constant query count for refresh path.
- One lock-targeted token lookup and one commit transaction.
- No repeated token-table lookups in same request.
- p95 refresh latency target < 100ms under normal load.
- Handle lock contention explicitly and emit lock-wait metrics.
- Design must be partition-ready and operationally scalable without requiring app-layer rewrites.

## Security Requirements
- No raw refresh tokens at rest.
- Rotation required on every refresh.
- Replay detection must revoke the whole session scope.
- Access token invalidation via `session_version` bump.

## Observability
- Metrics:
  - `auth_refresh_requests_total`
  - `auth_refresh_success_total`
  - `auth_refresh_fail_total` by reason
  - `auth_refresh_latency_ms` (p50/p95/p99)
  - `auth_refresh_lock_wait_ms`
- Logs:
  - include `session_uuid`, `user_uuid`, reason codes
  - never log raw token
- Cache invalidation events:
  - log and metric on session revoke/logout/admin-kill/version-bump cache deletes.

## Cache Invalidation Contract
- Cache TTL must never exceed access-token TTL.
- On any session state change (`revoked`, `expired`, `version` bump):
  - delete cache key for that `session_uuid` immediately.
- On replay-triggered revoke:
  - invalidate session cache before returning response.
- Cache invalidation behavior is enforced by framework services, not app handlers.

## Core Configuration Surface (Framework-Owned)
- Framework must expose and honor:
  - access-token TTL
  - refresh-token TTL
  - session absolute expiry and inactivity timeout
  - replay handling mode (`strict` or optional idempotency window)
  - idempotency window duration when enabled
  - cleanup retention windows for refresh/session data
  - per-user/session/device limits (optional but framework-supported)
- Defaults should be secure and production-safe; app-level config only tunes values.

## `jti` Usage
- `jti` is used for audit/tracing/correlation by default.
- No blocklist is required in baseline design.
- If blocklist is later introduced, it must be a dedicated store with bounded TTL and explicit performance budgets.

## Acceptance Criteria
- New schema is sole source of truth.
- Refresh tokens rotate atomically and are one-time-use.
- Replay attempts trigger deterministic revocation behavior.
- End-to-end refresh remains well below client timeout budget.

## Implementation Status (Current)
- Completed:
  - Step 1 (schema cutover): `auth_refresh_tokens` created; legacy session token columns dropped for existing installs; fresh-install schema no longer includes legacy token columns.
  - Step 2 (core services/repositories): `RefreshService`, `SessionRepository`, `RefreshTokenRepository`, `AccessTokenIssuer`, `SessionStateCache`, and provider issuer adapters are implemented and wired.
  - Step 3 (refresh endpoint internals): refresh endpoint flow now routes through `RefreshService` using hashed refresh-token lookup/rotation and session-version bump.
  - Step 4 (access auth enforcement): JWT `sid/ver` checked against active session state.
  - Step 5 (cleanup task): refresh/session cleanup paths added and aligned to new model.
  - Step 6 (metrics/logging/tracing): auth-refresh counters, latency aggregates, lock-wait aggregates, and reason-code logs are emitted by refresh orchestration.
  - Step 7 (cutover behavior): implementation is non-backward-compatible by design.
- Verification completed:
  - Full-suite verification in target environment (migrations applied + end-to-end smoke tests) has been completed at application integration level.
- Optional hardening (not required for completion):
  - Expose refresh metrics in unified metrics endpoint/exporter (current implementation stores counters/aggregates in cache and emits structured logs).

Status:
- Core reimplementation is complete.

## Glueful Fit and Codebase Impact

## How This Fits Glueful
- Glueful already centralizes auth in:
  - `AuthController -> AuthenticationService -> TokenManager -> SessionStore`
- The reimplementation keeps endpoint contracts stable (`/auth/login`, `/auth/refresh-token`, `/auth/logout`) while replacing internals.
- This is a structural refactor of auth state management, not a routing/API redesign.

## Primary Integration Points
- Core auth path:
  - `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Controllers/AuthController.php`
  - `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Auth/AuthenticationService.php`
  - `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Auth/TokenManager.php`
  - `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Auth/SessionStore.php`
- Access-token enforcement:
  - `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Routing/Middleware/AuthMiddleware.php`
- Provider and contract layer:
  - `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Auth/Interfaces/AuthenticationProviderInterface.php`
  - `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Auth/JwtAuthenticationProvider.php`
  - `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Auth/ApiKeyAuthenticationProvider.php`
- Admin/query security traits that currently assume token columns on `auth_sessions`:
  - `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Controllers/Traits/FieldLevelPermissionsTrait.php`
  - `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Controllers/Traits/QueryRestrictionsTrait.php`

## Blast Radius Estimate
- Token/session related references in this repo are broad (about 159 string-level references discovered during scan).
- Estimated affected files for this cutover:
  - Core auth/runtime logic: 12-20 files
  - Schema/migrations/docs/tests: 8-12 files
  - Expected total: 20-30 files
- Relative footprint:
  - Repo source file count is roughly 837 files
  - Expected touched-file ratio is approximately 3-4%
- Risk profile:
  - Moderate repository-wide footprint
  - High criticality impact because changes sit directly in authentication and middleware hot paths

## Expected Change Categories
- New components:
  - `RefreshService`, `SessionRepository`, `RefreshTokenRepository`, `SessionStateCache`, provider token adapters
- Modified components:
  - existing auth orchestrators/controllers/middleware
- Removed or simplified behavior:
  - refresh/access token persistence in `auth_sessions`
  - legacy token-field assumptions in auth/session internals
- Expanded verification scope:
  - unit, integration, concurrency, and load tests for refresh/auth paths
