# Core Email 2FA — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add baseline email-PIN 2FA to the framework core. Two-stage login: if a user has `two_factor_enabled = true`, `/auth/login` returns a `challenge_token` + emails a 6-digit PIN; the user submits both at `/2fa/verify` to complete login. Routes: `POST /2fa/enable`, `POST /2fa/verify`, `POST /2fa/disable`. One CLI command bundle (`2fa:*`). One column on `users`. No TOTP, no recovery codes, no provider abstraction — those ship later as `glueful/mfa`.

**Architecture:** `TwoFactorService` in `src/Auth/TwoFactor/` is the front door. It uses `ChallengeTokenIssuer` (mints/verifies short-lived JWTs with `purpose: "2fa_enable" | "2fa_login"`, signed via the framework's existing `Glueful\Auth\JWTService` — key from `config('session.jwt_key')`, algorithm `session.jwt_algorithm` defaulting to HS256) and `JtiBlocklist` (cache-backed single-use). PINs are generated and hashed via `Glueful\Security\OTP` (`generateNumeric()` + bcrypt `hashOTP()` + constant-time `verifyHashedOTP()` — the same primitives used by `EmailVerification` for password reset and email verify). They are stored under `2fa:pin:{jti}` with 5-min TTL, alongside a **strictly-projected** user array (allowlist only — no password hash, no internal flags — so the cache can never leak a raw repository row). On successful `/2fa/verify` for `2fa_login`, the framework's `TokenManager::createUserSession()` mints a real session — writes the `auth_sessions` row, generates `sid` (NanoID) + `ver = 1`, populates the refresh-token store + session cache, and returns the OIDC-shaped payload — **and** a cache marker `2fa:fresh:{sid}` is written with TTL = `disable_freshness` (default 300s). The marker is **session-scoped** (not user-scoped) so a stolen access token from a different session cannot piggyback on a legitimate user's recent verify. `/2fa/disable` extracts the current request's `sid` from the bearer token and consults the marker against it. The access token itself carries no 2FA claim because `TokenManager::generateTokenPair` hard-codes the access-token payload to `sub`/`sid`/`ver` only (`src/Auth/TokenManager.php:163-167`) and drops any extra fields. **The framework's own `AuthController::login` is modified to invoke the 2FA branch** — consumers get the feature out of the box, no recipe / no controller editing. To expose a "verified user, no session yet" intermediate state (today's `authenticate()` already calls `createUserSession()` internally at line 238), this feature **splits the username/password branch of `AuthenticationService::authenticate()` into `verifyCredentials()` + `issueSession()`**. The split is scoped to that branch only — `authenticate()` keeps its existing provider short-circuit (token / API-key credentials at lines 152-173) verbatim and then falls back to `verifyCredentials()` + `issueSession()` for username/password. The credentials/status validation logic itself is unchanged. A new `Glueful\Auth\LoginResponseShaper` service centralises the CSRF + `LoginResponseBuilding`/`LoginResponseBuilt` event wrapping so both the no-2FA path and `/2fa/verify` produce on-the-wire-identical login responses. The master switch `TWO_FACTOR_ENABLED` defaults to `false`, so a fresh install without the migration behaves exactly like a pre-2FA framework — even the `/2fa/*` routes are not registered when the master switch is off.

**Tech Stack:** PHP 8.3+, PHPUnit 10, SQLite in-memory for tests. Framework primitives reused: `JWTService` (challenge token signing), `TokenManager::createUserSession()` (final token issuance via the same path `AuthController::login` already uses for the no-2FA flow — ensures `sid`/`ver` claims pass `validateSessionClaims`), `OTP` (PIN generation + hashing), `NotificationService` + `Notifiable` (email dispatch — there is no `MailerInterface`; the `glueful/email-notification` extension provides the email channel, loaded dynamically — same pattern as `EmailVerification` at `src/Security/EmailVerification.php:128`), `Response::success()` (response envelope), cache, ORM. No new third-party dependencies. Soft requirement: `glueful/email-notification` must be installed when `two_factor.enabled = true`.

**Spec:** `docs/superpowers/specs/2026-05-22-core-email-2fa-design.md` (authoritative).

---

## Implementation Status (updated 2026-05-27)

**Done & verified (additive, inert — not yet wired into the live auth path):**
- ✅ **Task 1 — Migration** (`api-skeleton/database/migrations/010_AddTwoFactorEnabledToUsers.php`). Lint clean. **Deviation:** rewritten to use the **no-callback** `alterTable('users')` form + explicit `$schema->execute()` flush (the callback form is unreleased and not in api-skeleton's pinned `glueful/framework ^1.44.0`; intelephense P1119 confirmed). `up()` forces `gc_collect_cycles()` before flush because `ColumnBuilder` registers via `__destruct`. `down()` uses schema-level `dropColumn()`. The chosen API exists in both 1.44.0 and the local working copy. Note: in 1.44.0 `executeAlterations()` does not wire `_drops` into `$changes`, so the `down()` drop is a no-op on that release — a framework limitation, not a migration bug.
- ✅ **Task 2 — JtiBlocklist + 4 exceptions** (`src/Auth/TwoFactor/JtiBlocklist.php`, `src/Auth/TwoFactor/Exceptions/*`). `JtiBlocklistTest`: 4 tests pass. PHPStan clean (added `@param CacheStore<mixed>`).
- ✅ **Task 3 — ChallengeTokenIssuer** (`src/Auth/TwoFactor/ChallengeTokenIssuer.php`). `ChallengeTokenIssuerTest`: 6 tests pass, PHPStan clean. Verified `JWTService::generate/decode` are static, overwrite `iat/exp/jti`, and `decode()` returns null on bad sig/format/expiry. **Note:** the expired-token test builds the expired JWT directly (negative TTL) rather than via `issue()`, because `issue()`'s own generate→decode round-trip rejects an already-expired token.
- ✅ **Task 4 — TwoFactorService** (`src/Auth/TwoFactor/TwoFactorService.php`). `TwoFactorServiceTest`: **15 integration tests pass** (allowlist projection drops `password`, provider plumbing jwt/ldap, verify-enable, verify-login + session-scoped freshness, wrong-PIN retry, replay rejection, the three re-validation throws, disable). PHPStan clean. Verified `OTP`/`TokenManager::createUserSession`/`NotificationService::send`/`config()` signatures.
- ✅ **Task 5 — TwoFactorController** (`src/Controllers/TwoFactorController.php`). enable/verify/disable; collapses verify failures to a generic 401 when `security.auth.generic_error_responses`; disable gated on session-scoped freshness (decodes bearer `sid`). Uses `RequestHelper::getRequestData` (repo convention). PHPStan clean. *(HTTP-level `TwoFactorControllerTest` deferred — see Deferred below.)*
- ✅ **Task 6 — Routes** (`routes/2fa.php` + registered in `RouteManifest` `api_routes`). Master-switch guard (`env('TWO_FACTOR_ENABLED', false) !== true` → early return); builder-form `Route::rateLimit()` (3/min enable+disable, 5/min verify); `/2fa/verify` has no `auth` middleware.
- ✅ **Task 7 — Container + config** (`CoreProvider` registers JtiBlocklist, ChallengeTokenIssuer, LoginResponseShaper, TwoFactorService; `config/auth.php` `two_factor` block). **Deviation:** the plan said `config/auth.php` exists — it did not; created it. `security.php` already had `auth.allowed_login_statuses`/`generic_error_responses`. `'database'` (not `Connection::class`) is the container key.
- ✅ **Task 8 — CLI** (`src/Console/Commands/TwoFactor/{Enable,Disable,Status}Command.php`). Auto-discovered via `#[AsCommand]` (recursive scan) — no manual registration. Use `getServiceDynamic('database')` (the `'database'` key isn't a class-string). PHPStan clean.
- ✅ **Task 9 — Email template** (`extensions/email-notification/src/Templates/html/two-factor-pin.html`). Handlebars-style, mirrors `password-reset.html`, uses `{{pin}}`/`{{ttl_minutes}}`.
- ✅ **Task 10 — Login pipeline refactor** — the security-critical part.
  - `AuthenticationService::authenticate()` username/password branch split into `verifyCredentials()` + `issueSession()`; provider short-circuit (token/api_key) preserved verbatim. PHPStan clean.
  - `LoginResponseShaper` created (shared CSRF + login-event shaping, extracted from `AuthController::login`).
  - `AuthController::login()` rewritten: token/api_key route → `authenticate()` + shaper; username/password → `verifyCredentials()` → 2FA gate → `issueSession()` + shaper. 2FA deps resolved internally (constructor signature unchanged). Unused event imports removed.
  - `TwoFactorController::verify()` login-purpose path uses the shaper.
  - README + CHANGELOG updated. *(HTTP-level `AuthControllerLoginTwoFactorTest` deferred — see below.)*
- ✅ **Task 11 — Verification.** PHPCS clean (18 changed files); PHPStan clean (all changed src together); **Unit 827 pass / Integration 93 pass / 0 regressions** (incl. the API-key provider path through the refactored `authenticate()`).

**Deferred (not blocking — service-layer logic is fully covered by `TwoFactorServiceTest`):**
- HTTP-level integration tests that boot the full app stack: `TwoFactorControllerTest` (Task 5 Step 2) and `AuthControllerLoginTwoFactorTest` (Task 10 Step 5). The controller/login wiring is thin delegation, verified by PHPStan + the green suite; these end-to-end tests need `Framework::create()->boot()` + router + DB infrastructure (pattern: `tests/Integration/Database/ORM/QueryExplainTest.php`).
- `docs/FRAMEWORK_IMPROVEMENTS.md` § 5.1 flip (cosmetic doc).

**Status: feature functionally complete and verified; all changes uncommitted per request.**

**Test-harness notes (used / for the deferred HTTP tests):** builder-level tests use `new Connection(['engine'=>'sqlite','sqlite'=>['primary'=>$tmpFile],'pooling'=>['enabled'=>false]])`; `JWTService` key is set via reflection on the private static `$key` (`$algorithm` defaults to HS256); `ApplicationContext` is path-constructible without a full boot; `TokenManager` and `NotificationService` are non-final (mockable via `createMock`).

---

## File Structure

**New files (framework repo):**

| Path | Responsibility |
|---|---|
| `src/Auth/TwoFactor/TwoFactorService.php` | Front-door API: `isEnabled`, `beginEnable`, `beginLogin`, `verify`, `disable`. Uses `OTP` directly for PIN gen/hash; no `PinGenerator` class. |
| `src/Auth/TwoFactor/ChallengeTokenIssuer.php` | Issues/verifies challenge JWTs via framework `JWTService`. |
| `src/Auth/TwoFactor/JtiBlocklist.php` | Cache-backed single-use enforcement. |
| `src/Auth/TwoFactor/Exceptions/InvalidChallengeTokenException.php` | **extends `\Glueful\Http\Exceptions\Domain\AuthenticationException`**. |
| `src/Auth/TwoFactor/Exceptions/InvalidTwoFactorCodeException.php` | **extends `\Glueful\Http\Exceptions\Domain\AuthenticationException`**. |
| `src/Auth/TwoFactor/Exceptions/TwoFactorNotEnabledException.php` | **extends `\Glueful\Http\Exceptions\Domain\AuthenticationException`**. |
| `src/Auth/TwoFactor/Exceptions/TwoFactorReelevationRequiredException.php` | **extends `\Glueful\Http\Exceptions\Client\ForbiddenException`** (semantic split — caller is authenticated but lacks freshness). |
| `src/Controllers/TwoFactorController.php` | Three POST endpoints. Returns via `Response::success()` envelope. Collapses verify-failure exceptions to a generic 401 when `security.auth.generic_error_responses` is true. |
| `src/Console/Commands/TwoFactor/EnableCommand.php` | `2fa:enable <user>` — admin force-enable. |
| `src/Console/Commands/TwoFactor/DisableCommand.php` | `2fa:disable <user>`. |
| `src/Console/Commands/TwoFactor/StatusCommand.php` | `2fa:status <user>`. |
| `routes/auth.php` (or wherever core auth routes live — confirm in Task 6) | Adds the three `/2fa/*` routes with rate-limit middleware. |
| `tests/Unit/Auth/TwoFactor/JtiBlocklistTest.php` | Consume + isConsumed semantics. |
| `tests/Unit/Auth/TwoFactor/ChallengeTokenIssuerTest.php` | Issue / verify / blocklist / wrong purpose. |
| `tests/Integration/Auth/TwoFactor/TwoFactorServiceTest.php` | Enrollment + login flows end to end against SQLite + cache + a fake `NotificationService` (or fake email channel). |
| `tests/Integration/Auth/TwoFactor/TwoFactorControllerTest.php` | HTTP-level: enable → verify → login → verify → disable. Includes generic-error-response collapsing. |

**No standalone `PinGenerator` class.** The spec mandates reuse of `Glueful\Security\OTP::generateNumeric()` and `OTP::hashOTP()` — same primitives already used by `EmailVerification::generateOTP()` (`src/Security/EmailVerification.php:175-177`) and `storeOTP()`. `TwoFactorService` calls them inline. The PIN length is read from config and passed as the `length` argument to `OTP::generateNumeric()`.

**No email template ships in the framework repo.** The `two-factor-pin` template lives in `glueful/email-notification` alongside the existing `password-reset` and `verification` templates. Task 9 below ships only the template-name contract and a stub for the extension to mirror.

**Modified files (framework repo):**

| Path | Change |
|---|---|
| `src/Container/Providers/CoreProvider.php` (or wherever core services register — confirm in Task 7) | Register `TwoFactorService`, `ChallengeTokenIssuer`, `JtiBlocklist`, `LoginResponseShaper` as `FactoryDefinition` entries in `defs()`. Wire CLI commands. |
| `src/Auth/AuthenticationService.php` | **Split the username/password branch of `authenticate()` into `verifyCredentials()` + `issueSession()`.** `authenticate()` keeps its existing provider short-circuit (token / API-key credentials at lines 152-173) verbatim and then falls back to `verifyCredentials()` + `issueSession()` for username/password — preserving the public contract for all four flows (JWT, LDAP, SAML, API key). The credentials/status validation logic itself does not change. Required to expose a "verified user, no session yet" intermediate state for the 2FA gate. |
| `src/Controllers/AuthController.php` | Inject `TwoFactorService` and `LoginResponseShaper` in the constructor. Replace the existing `authService->authenticate()` call in `login()` with `verifyCredentials()` → 2FA check → `issueSession()`. Extract the CSRF + `LoginResponseBuilding`/`LoginResponseBuilt` block (lines 144-178) into `LoginResponseShaper::shape()` and call it for the no-2FA path. The 2FA challenge response returns before the shaper deliberately (login is not yet complete). Strictly additive at the behaviour level when `TWO_FACTOR_ENABLED=false` (default). |
| `src/Auth/LoginResponseShaper.php` *(new)* | Shared service that wraps a session payload in the framework's standard login response: adds CSRF token if `CSRF_PROTECTION_ENABLED`, dispatches `LoginResponseBuildingEvent` + `LoginResponseBuiltEvent`, returns `Response::success`. Used by both `AuthController::login` (no-2FA path) and `TwoFactorController::verify` (login-purpose path) so the on-the-wire response is identical regardless of which path the user took. |
| `config/auth.php` | Add `two_factor` config block per the spec's Configuration section. `enabled` defaults to `false`. |
| `docs/FRAMEWORK_IMPROVEMENTS.md` | Flip § 5.1 row to "Email PIN 2FA in core ✅ shipped; TOTP / WebAuthn / recovery codes still extension scope". |
| `CHANGELOG.md` | Unreleased entry — describes the new env var + migration as the opt-in. |
| `README.md` | Short Authentication subsection explaining how to enable 2FA (run migration → set env var → install email-notification). |

**New files (api-skeleton repo):**

| Path | Responsibility |
|---|---|
| `database/migrations/010_AddTwoFactorEnabledToUsers.php` | Adds `users.two_factor_enabled` boolean column. The framework has no baseline migrations directory of its own (only `src/Database/Migrations/` infrastructure), so all migrations — including the `users` table itself — live in api-skeleton; this is the only viable location. Filename follows api-skeleton convention; confirm the next sequential number in Task 1. |

**No api-skeleton recipe.** The integration lives in the framework's own `AuthController::login` (see modified-files table above), so api-skeleton consumers don't have to hand-edit anything to use 2FA. They only need to run the migration shipped in their own repo, install `glueful/email-notification`, and set `TWO_FACTOR_ENABLED=true`.

---

## Task 1: Schema migration (api-skeleton)

- [x] **Step 1: Confirm next migration number**

```bash
ls /Users/michaeltawiahsowah/Sites/glueful/api-skeleton/database/migrations/ | sort | tail -3
```

Expected: `009_CreateApiKeysTable.php` is the most recent. Next is `010`.

- [x] **Step 2: Write the migration**

Create `/Users/michaeltawiahsowah/Sites/glueful/api-skeleton/database/migrations/010_AddTwoFactorEnabledToUsers.php`:

```php
<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

class AddTwoFactorEnabledToUsers implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        // Explicit idempotency guard — `SchemaBuilderInterface::hasColumn()` exists in
        // the framework; use it so re-running after a manual ALTER TABLE doesn't error.
        if ($schema->hasColumn('users', 'two_factor_enabled')) {
            return;
        }

        $schema->alterTable('users', function ($table) {
            $table->boolean('two_factor_enabled')->notNull()->default(false);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasColumn('users', 'two_factor_enabled')) {
            return;
        }
        $schema->alterTable('users', function ($table) {
            $table->dropColumn('two_factor_enabled');
        });
    }

    public function getDescription(): string
    {
        return 'Adds two_factor_enabled boolean column to users for the core email-PIN 2FA feature.';
    }
}
```

Confirm `SchemaBuilderInterface::hasColumn(string $table, string $column): bool` signature during implementation; if the method name differs, swap to the framework's actual capability (e.g. `columnExists`). Do **not** leave the guard as prose — the migration must be unconditionally idempotent.

**Acceptance:** `php glueful migrate:run` applies and rolls back cleanly on MySQL, PostgreSQL, and SQLite. Re-running the migration after a manual `ALTER TABLE` that already added the column does not error.

---

## Task 2: `JtiBlocklist`

One small utility class. Pure logic. PIN generation is delegated to `Glueful\Security\OTP` — no `PinGenerator` wrapper is needed (see File Structure note above).

- [x] **Step 1: `JtiBlocklist`**

```php
namespace Glueful\Auth\TwoFactor;

use Glueful\Cache\CacheStore;

final class JtiBlocklist
{
    public function __construct(private CacheStore $cache) {}

    public function consume(string $jti, int $ttl): void
    {
        $this->cache->set("2fa:consumed_jti:{$jti}", 1, $ttl);
    }

    public function isConsumed(string $jti): bool
    {
        return $this->cache->has("2fa:consumed_jti:{$jti}");
    }
}
```

- [x] **Step 2: Unit tests** — `JtiBlocklistTest` (consume + isConsumed against an `ArrayCacheDriver`; TTL expiry; double-consume idempotent).

**Acceptance:** Tests pass; PHPStan level 8 clean.

---

## Task 3: `ChallengeTokenIssuer`

- [x] **Step 1: Confirm `JWTService` surface (concrete API)**

`Glueful\Auth\JWTService` exposes **static** methods `generate(array $payload, int $expiration): string` and `decode(string $token): ?array`. Three constraints the issuer must respect:

  1. `generate()` **overwrites** the `iat`, `exp`, and `jti` keys (`src/Auth/JWTService.php:79-81`) — the caller cannot supply a `jti`. To learn the framework-chosen jti, decode the freshly-generated token and read `$payload['jti']`.
  2. `decode()` returns `null` on any failure (bad signature, malformed, expired, wrong alg) — it does **not** throw. Verification must check for `null`, not catch exceptions.
  3. Key + algorithm resolution is internal to `JWTService` (reads `config('session.jwt_key')` and `session.jwt_algorithm`, default HS256). The issuer needs no key plumbing.

- [x] **Step 2: `ChallengeTokenIssuer`**

```php
namespace Glueful\Auth\TwoFactor;

use Glueful\Auth\JWTService;
use Glueful\Auth\TwoFactor\Exceptions\InvalidChallengeTokenException;

final class ChallengeTokenIssuer
{
    public const PURPOSE_ENABLE = '2fa_enable';
    public const PURPOSE_LOGIN = '2fa_login';

    public function __construct(
        private JtiBlocklist $blocklist,
        private int $ttl = 300,
    ) {}

    /**
     * @return array{token: string, jti: string, exp: int}
     */
    public function issue(string $userUuid, string $purpose): array
    {
        if (!in_array($purpose, [self::PURPOSE_ENABLE, self::PURPOSE_LOGIN], true)) {
            throw new \InvalidArgumentException("Unknown 2FA purpose: {$purpose}");
        }

        // JWTService::generate() is static and auto-assigns iat/exp/jti
        // (src/Auth/JWTService.php:79-81), overwriting any values we provide.
        // We supply only the domain claims and read back the framework-chosen jti.
        $token = JWTService::generate([
            'purpose' => $purpose,
            'user_uuid' => $userUuid,
        ], $this->ttl);

        $payload = JWTService::decode($token);
        if (!is_array($payload) || !isset($payload['jti'], $payload['exp'])) {
            // Generate-then-decode round-trip failed — only happens if key/alg state is broken.
            throw new \RuntimeException('Failed to read freshly-issued challenge token');
        }

        return [
            'token' => $token,
            'jti' => (string) $payload['jti'],
            'exp' => (int) $payload['exp'],
        ];
    }

    /**
     * @return array{jti: string, exp: int, purpose: string, user_uuid: string}
     * @throws InvalidChallengeTokenException
     */
    public function verify(string $token): array
    {
        // decode() returns null on signature/format/exp failure — no exceptions thrown.
        $claims = JWTService::decode($token);
        if ($claims === null) {
            throw new InvalidChallengeTokenException('Invalid or expired challenge token');
        }

        $purpose = $claims['purpose'] ?? null;
        if ($purpose !== self::PURPOSE_ENABLE && $purpose !== self::PURPOSE_LOGIN) {
            throw new InvalidChallengeTokenException('Wrong token purpose');
        }

        $jti = (string) ($claims['jti'] ?? '');
        if ($jti === '' || $this->blocklist->isConsumed($jti)) {
            throw new InvalidChallengeTokenException('Token already consumed');
        }

        return [
            'jti' => $jti,
            'exp' => (int) $claims['exp'],
            'purpose' => $purpose,
            'user_uuid' => (string) ($claims['user_uuid'] ?? ''),
        ];
    }
}
```

- [x] **Step 3: Unit tests** — `ChallengeTokenIssuerTest`:
  - Issue → verify round-trip succeeds for both purposes; issued token's jti matches what `verify` returns.
  - Unknown purpose at issue → `InvalidArgumentException`.
  - Tampered signature → `verify` throws `InvalidChallengeTokenException` (decode returns null).
  - Expired token → `InvalidChallengeTokenException` (decode returns null when `exp < time()`).
  - Wrong-purpose token (e.g. a non-2FA JWT) → `InvalidChallengeTokenException`.
  - Consumed `jti` → `InvalidChallengeTokenException`.

Test setup must call `JWTService::setContext($context)` so the static service can resolve `session.jwt_key`.

**Acceptance:** All six paths covered.

---

## Task 4: `TwoFactorService` orchestration

The front door. Coordinates the issuer, blocklist, cache, `NotificationService` dispatch, the `users` table, and `TokenManager::createUserSession()` for login-purpose verify.

- [x] **Step 1: Use `TokenManager::createUserSession()` for token issuance + decide the freshness mechanism**

**Token issuance path (decided).** `AccessTokenIssuer::issuePair()` alone is not viable: `TokenManager::generateTokenPair` writes only `sub`/`sid`/`ver` into the JWT (`src/Auth/TokenManager.php:163-167`), and `TokenManager::validateSessionClaims` rejects tokens with empty `sid` or `ver <= 0` (`src/Auth/TokenManager.php:598-603`) — confirmed against the same rule in `SessionStore`. So `issuePair(['uuid' => $u, 'sid' => '', 'ver' => 0], ...)` produces tokens that the framework will reject on the very next request.

The correct path is **`TokenManager::createUserSession(array $user, ?string $provider = null)`** (`src/Auth/TokenManager.php:476`). It generates a real `sid` (NanoID), sets `ver = 1`, writes an `auth_sessions` row, issues the refresh-token store entry, populates `SessionCacheManager`, and returns the OIDC-shaped `{access_token, refresh_token, expires_in, user, ...}` payload. This is the same method `AuthController::login` uses for the no-2FA path.

To call `createUserSession`, `TwoFactorService` needs a user array (uuid + email + any profile fields), not just `user_uuid`. So:
- `beginLogin(array $user, ?string $preferredProvider = null)` accepts a user array plus the requested token provider, projects the user down to `ALLOWED_USER_FIELDS` (allowlist — see `projectUser()` below), and stashes the projected array + `preferred_provider` in the PIN cache entry alongside `code_hash`. `/2fa/verify` recovers both when the PIN is submitted (the PIN cache TTL of 5 minutes is the same window during which the verify call is valid). Raw repository rows are accepted defensively — anything outside the allowlist (password hashes, internal timestamps, etc.) is dropped before the cache write.
- `verify()` reads the user array back out of the cache and passes it to `createUserSession`.

**Freshness mechanism (decided).** Session-scoped cache marker `2fa:fresh:{sid}` with TTL = `auth.two_factor.disable_freshness` (default 300s), written by `TwoFactorService::verify()` on successful `PURPOSE_LOGIN` (the `sid` is read from the just-issued access token's `sid` claim — see service code in Step 3). Read by `TwoFactorController::disable()` against the **current request's** `sid` claim — not by user_uuid — so a stolen token from a different session cannot piggyback on a legitimate user's recent verify. Presence = fresh; absence = re-elevation required.

Why not session data or a TokenManager extension? Session-data freshness requires modifying `SessionCacheManager`/`AuthenticationService`, crossing the "no AuthenticationService modifications" constraint. A TokenManager extension would change the framework's access-token contract for one feature. The cache marker is local, simple, and reversible.

- [x] **Step 2: Mirror the `sendPasswordResetEmail` dispatch pattern**

Re-read `src/Security/EmailVerification.php:488-628` to confirm the dispatch shape (anonymous `Notifiable`, `NotificationService::send('two_factor_pin', $notifiable, 'Two-Factor Code', $data, ['channels' => ['email']])`, `template_name` in `$data`). Inject `NotificationService` from the container — no `MailerInterface` exists; the `glueful/email-notification` extension provides the email channel as a dynamically-loaded provider.

- [x] **Step 3: `TwoFactorService`** *(production code complete; lint + PHPStan clean)*

```php
namespace Glueful\Auth\TwoFactor;

use Glueful\Auth\TokenManager;
use Glueful\Auth\TwoFactor\Exceptions\InvalidTwoFactorCodeException;
use Glueful\Auth\TwoFactor\Exceptions\TwoFactorNotEnabledException;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Security\OTP;

final class TwoFactorService
{
    /**
     * Fields cached in 2fa:pin:{jti}.user. Anything not on this list is dropped
     * before the cache write, so raw repository rows (including password hashes)
     * can be passed by the caller without leaking through the cache layer.
     */
    private const ALLOWED_USER_FIELDS = [
        'uuid',
        'email',
        'email_verified_at',
        'username',
        'profile',
        'remember_me',
        'status',
    ];

    public function __construct(
        private ApplicationContext $context,
        private Connection $db,
        private CacheStore $cache,
        private NotificationService $notifications,
        private ChallengeTokenIssuer $issuer,
        private JtiBlocklist $blocklist,
        private TokenManager $tokenManager,
        private int $pinLength = 6,
        private int $pinTtl = 300,
        private int $disableFreshness = 300,
        private string $templateName = 'two-factor-pin',
        private bool $masterEnabled = true,
    ) {}

    /**
     * Strip the incoming user array to the allowlist. Drops password hashes,
     * audit timestamps, soft-delete flags, etc. Mirrors AuthenticationService::formatUserData()
     * (src/Auth/AuthenticationService.php:355) which unsets `password` before session creation.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function projectUser(array $user): array
    {
        $projected = [];
        foreach (self::ALLOWED_USER_FIELDS as $key) {
            if (array_key_exists($key, $user)) {
                $projected[$key] = $user[$key];
            }
        }
        return $projected;
    }

    public function isEnabled(string $userUuid): bool
    {
        // Master kill-switch short-circuits BEFORE touching the DB. This is what makes
        // the feature safe to ship at framework level with TWO_FACTOR_ENABLED=false as
        // the default — fresh installs without the migration never read the (possibly
        // missing) two_factor_enabled column.
        if (!$this->masterEnabled) {
            return false;
        }
        try {
            $row = $this->db->table('users')
                ->select(['two_factor_enabled'])
                ->where('uuid', $userUuid)
                ->first();
        } catch (\Throwable $e) {
            // Belt-and-suspenders: if an operator flips TWO_FACTOR_ENABLED=true before
            // running the migration, the column is missing. Log and fail closed (no 2FA)
            // rather than 500-ing the login flow.
            error_log('TwoFactorService::isEnabled query failed — column missing? ' . $e->getMessage());
            return false;
        }
        $value = $row['two_factor_enabled'] ?? false;
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * Begin a 2FA-enrollment challenge. Only uuid + email are needed because the
     * enable-purpose verify path does not issue tokens.
     *
     * @return array{token: string, expires_in: int, delivered_to: string}
     */
    public function beginEnable(string $userUuid, string $email): array
    {
        return $this->dispatchChallenge(
            ['uuid' => $userUuid, 'email' => $email],
            $email,
            ChallengeTokenIssuer::PURPOSE_ENABLE
        );
    }

    /**
     * Begin a 2FA-login challenge. Takes a user array (uuid + email + any
     * other profile fields the caller wants to pass through) and the
     * requested token provider for the eventual session.
     *
     * The user array is projected down to ALLOWED_USER_FIELDS via projectUser()
     * before being stashed in the PIN cache entry. Raw repository rows are accepted
     * but anything outside the allowlist (notably password hashes) is dropped before
     * the cache write. The preferred provider is cached alongside as a separate key,
     * so /2fa/verify can issue the final session via the same provider the original
     * /auth/login requested — without this, a 2FA-gated LDAP/SAML/etc. login would
     * silently complete as JWT.
     *
     * @param array{uuid: string, email: string} $user Must include uuid + email; any
     *                                                 other fields outside ALLOWED_USER_FIELDS
     *                                                 are silently dropped.
     * @param string|null $preferredProvider Provider name from /auth/login (jwt, ldap, saml, ...).
     *                                       Null defaults to 'jwt' at issue time.
     * @return array{token: string, expires_in: int, delivered_to: string}
     */
    public function beginLogin(array $user, ?string $preferredProvider = null): array
    {
        if (!isset($user['uuid'], $user['email'])) {
            throw new \InvalidArgumentException('beginLogin requires user[uuid] and user[email]');
        }
        // Project to allowlist — even if the caller hands us a raw repository row,
        // only known-safe fields reach the cache.
        $projected = $this->projectUser($user);
        return $this->dispatchChallenge(
            $projected,
            (string) $projected['email'],
            ChallengeTokenIssuer::PURPOSE_LOGIN,
            $preferredProvider
        );
    }

    /**
     * @return array{purpose: string, user_uuid: string, sid?: string, session?: array<string, mixed>}
     * @throws InvalidChallengeTokenException|InvalidTwoFactorCodeException
     */
    public function verify(string $challengeToken, string $code): array
    {
        $claims = $this->issuer->verify($challengeToken);

        $pinEntry = $this->cache->get("2fa:pin:{$claims['jti']}");
        if (
            !is_array($pinEntry)
            || ($pinEntry['user']['uuid'] ?? null) !== $claims['user_uuid']
        ) {
            throw new InvalidTwoFactorCodeException('No active PIN for this challenge');
        }

        // bcrypt verify is constant-time — no separate hash_equals needed.
        if (!OTP::verifyHashedOTP($code, (string) $pinEntry['code_hash'])) {
            throw new InvalidTwoFactorCodeException('Wrong code');
        }

        // Atomic-ish consume: delete the PIN, blocklist the jti.
        $this->cache->delete("2fa:pin:{$claims['jti']}");
        $this->blocklist->consume($claims['jti'], max(1, $claims['exp'] - time()));

        if ($claims['purpose'] === ChallengeTokenIssuer::PURPOSE_ENABLE) {
            $this->db->table('users')
                ->where('uuid', $claims['user_uuid'])
                ->update(['two_factor_enabled' => true]);

            return ['purpose' => 'enabled', 'user_uuid' => $claims['user_uuid']];
        }

        // PURPOSE_LOGIN. Before minting a real session, re-validate the current DB state.
        // The user array in $pinEntry was captured at /auth/login time and cached for up
        // to 5 minutes; during that window the account could have been disabled, deleted,
        // or had two_factor_enabled flipped off by an admin. Trusting the cached snapshot
        // would let /2fa/verify mint a fresh session even though the normal login path
        // (AuthenticationService::authenticate, src/Auth/AuthenticationService.php:202-208)
        // would now reject the same credentials.
        $current = $this->db->table('users')
            ->select(['uuid', 'status', 'two_factor_enabled'])
            ->where('uuid', $claims['user_uuid'])
            ->first();

        if ($current === null) {
            // User was deleted (or soft-deleted out of the queryable set) during the window.
            throw new InvalidTwoFactorCodeException('Account no longer exists');
        }

        $allowedStatuses = (array) config(
            $this->context,
            'security.auth.allowed_login_statuses',
            ['active']
        );
        $userStatus = (string) ($current['status'] ?? '');
        if ($allowedStatuses !== [] && !in_array($userStatus, $allowedStatuses, true)) {
            // Account status changed during the window (deactivated, suspended, etc.).
            // Use the same allowlist the normal login flow uses so 2FA can't bypass it.
            throw new InvalidTwoFactorCodeException('Account is not eligible to log in');
        }

        $twoFactorEnabled = $current['two_factor_enabled'] ?? false;
        if ($twoFactorEnabled !== true && $twoFactorEnabled !== 1 && $twoFactorEnabled !== '1') {
            // 2FA was disabled by admin during the window — there's no longer anything to
            // verify against. This is also the canonical throw site for
            // TwoFactorNotEnabledException, which the wire will collapse to a generic 401.
            throw new TwoFactorNotEnabledException('Two-factor authentication is no longer enabled');
        }

        // State checks pass — create a real session via TokenManager::createUserSession,
        // then write a session-scoped freshness marker for /2fa/disable.
        //
        // createUserSession generates sid (NanoID), sets ver = 1, writes
        // auth_sessions / refresh-token / session-cache rows, and returns an OIDC-shaped
        // payload — the same path AuthController::login uses for the no-2FA flow.
        // Tokens issued this way pass TokenManager::validateSessionClaims
        // (src/Auth/TokenManager.php:598-603), which rejects sid='' / ver<=0.

        /** @var array<string, mixed> $user */
        $user = $pinEntry['user'];
        // Refresh status from the freshly-read row so any other downstream check inside
        // createUserSession sees the current value.
        $user['status'] = $userStatus;
        // Honour the token provider the original /auth/login requested — falls back to
        // 'jwt' if missing (older cache entries, or callers that didn't pass one).
        $preferredProvider = (string) ($pinEntry['preferred_provider'] ?? 'jwt');
        $session = $this->tokenManager->createUserSession($user, $preferredProvider);
        if ($session === []) {
            // createUserSession returns [] on persistence failure.
            throw new \RuntimeException('Failed to create session after successful 2FA verify');
        }

        // Extract sid from the just-issued access token so we can scope the freshness
        // marker to *this* session. A user-scoped marker would let an attacker holding
        // a stolen, still-valid token from a different session piggyback on a legitimate
        // /2fa/login to call /2fa/disable.
        $accessClaims = \Glueful\Auth\JWTService::decode((string) $session['access_token']);
        $sid = is_array($accessClaims) ? (string) ($accessClaims['sid'] ?? '') : '';
        if ($sid === '') {
            // Shouldn't happen — createUserSession always sets sid — but if it ever
            // does, fail loudly rather than fall through to a user-scoped marker.
            throw new \RuntimeException('Issued access token has no sid claim');
        }
        $this->cache->set("2fa:fresh:{$sid}", time(), $this->disableFreshness);

        return [
            'purpose' => 'login',
            'user_uuid' => $claims['user_uuid'],
            'sid' => $sid,
            'session' => $session,   // {access_token, refresh_token, expires_in, user, ...}
        ];
    }

    /**
     * Read by TwoFactorController::disable. Returns true if the session identified
     * by $sid has completed a 2FA verification within the last $disableFreshness seconds.
     * Session-scoped (not user-scoped) so a stolen token from a different session
     * cannot ride on a legitimate user's recent /2fa/verify.
     */
    public function hasFreshVerification(string $sid): bool
    {
        if ($sid === '') {
            return false;
        }
        return $this->cache->has("2fa:fresh:{$sid}");
    }

    public function disable(string $userUuid, ?string $sid = null): void
    {
        $this->db->table('users')
            ->where('uuid', $userUuid)
            ->update(['two_factor_enabled' => false]);

        // Clear the freshness marker for the calling session only. Other active
        // sessions for the same user expire on their own TTL — they have no
        // ability to disable 2FA anyway once the column flips to false, and
        // bulk-clearing would require a user→sid index we don't maintain.
        if ($sid !== null && $sid !== '') {
            $this->cache->delete("2fa:fresh:{$sid}");
        }
    }

    /**
     * @param array{uuid: string, email: string} $user
     * @return array{token: string, expires_in: int, delivered_to: string}
     */
    private function dispatchChallenge(
        array $user,
        string $email,
        string $purpose,
        ?string $preferredProvider = null
    ): array {
        $challenge = $this->issuer->issue($user['uuid'], $purpose);

        // Generate + hash via the framework's existing OTP primitives (bcrypt).
        // Cache the projected user array + the requested token provider — verify()
        // reads both back to call TokenManager::createUserSession($user, $provider)
        // without a second DB hit. PIN TTL bounds how long the data sits in cache.
        $pin = OTP::generateNumeric($this->pinLength);
        $this->cache->set(
            "2fa:pin:{$challenge['jti']}",
            [
                'user' => $user,
                'code_hash' => OTP::hashOTP($pin),
                'preferred_provider' => $preferredProvider,
            ],
            $this->pinTtl
        );

        $this->sendPin($email, $pin);

        return [
            'token' => $challenge['token'],
            'expires_in' => $challenge['exp'] - time(),
            'delivered_to' => $this->maskEmail($email),
        ];
    }

    private function sendPin(string $email, string $pin): void
    {
        // Anonymous Notifiable — same shape as EmailVerification::sendPasswordResetEmail (src/Security/EmailVerification.php:531-566).
        $notifiable = new class ($email) implements Notifiable {
            public function __construct(private string $email) {}
            public function routeNotificationFor(string $channel): ?string
            {
                return $channel === 'email' ? $this->email : null;
            }
            public function getNotifiableId(): string { return md5($this->email); }
            public function getNotifiableType(): string { return 'two_factor_recipient'; }
            public function shouldReceiveNotification(string $type, string $channel): bool
            {
                return $channel === 'email';
            }
            public function getNotificationPreferences(): array { return ['email' => true]; }
        };

        $this->notifications->send(
            'two_factor_pin',
            $notifiable,
            'Your two-factor verification code',
            [
                'pin' => $pin,
                'ttl_minutes' => (int) ceil($this->pinTtl / 60),
                'subject' => 'Your two-factor verification code',
                'template_name' => $this->templateName,
            ],
            ['channels' => ['email']]
        );
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at <= 1) {
            return '***';
        }
        return substr($email, 0, 1) . str_repeat('*', $at - 1) . substr($email, $at);
    }
}
```

- [ ] **Step 4: Integration tests** *(NOT YET WRITTEN — next step)* — `tests/Integration/Auth/TwoFactor/TwoFactorServiceTest.php`:
  - `isEnabled` reflects the column value (true/false/1/0/'1' all handled).
  - `beginEnable($uuid, $email)` issues a challenge_token, stores `{user: {uuid, email}, code_hash}` in cache (bcrypt hash), dispatches via `NotificationService` (verify with a fake notification dispatcher capturing the PIN from the data payload).
  - `beginLogin(['uuid' => ..., 'email' => ..., ...], 'jwt')` projects the user to `ALLOWED_USER_FIELDS` before caching.
  - **Allowlist regression:** `beginLogin` called with a raw user row containing `password`, `created_at`, `last_login_at`, `internal_flag`, etc. results in a cache entry whose `user` key contains only the allowlisted fields — `password` in particular must not be present. Assert by reading `2fa:pin:{jti}` back from the cache and checking `array_keys`.
  - **Provider plumbing:** `beginLogin($user, 'ldap')` writes `'preferred_provider' => 'ldap'` into the cache entry alongside `user` and `code_hash`. `verify` with the right PIN later passes `'ldap'` to `TokenManager::createUserSession`. Test variants: `'jwt'`, `'ldap'`, `'saml'`, and `null` (which falls back to `'jwt'` at verify time).
  - `beginLogin` rejects missing `uuid` or `email` with `InvalidArgumentException`.
  - `verify` with the right PIN + enable-purpose sets the column; returns `['purpose' => 'enabled', ...]`.
  - `verify` with the right PIN + login-purpose calls `TokenManager::createUserSession(user, 'jwt')` and returns its OIDC-shaped output under `session`. Assert: `auth_sessions` row exists with `session_version = 1`; the access token's payload (via `JWTService::decode`) carries non-empty `sid` and `ver = 1`.
  - **Re-validation — user deleted during window:** `beginLogin` succeeds → user row is deleted from the DB → `/2fa/verify` with the right PIN throws `InvalidTwoFactorCodeException`. No `auth_sessions` row is created.
  - **Re-validation — status disallowed during window:** `beginLogin` succeeds → user's `status` column is set to a non-allowed value (e.g. `'suspended'` when the configured allowlist is `['active']`) → `/2fa/verify` with the right PIN throws `InvalidTwoFactorCodeException`. Configure the test to set `security.auth.allowed_login_statuses` explicitly so the assertion isn't environment-sensitive.
  - **Re-validation — 2FA disabled during window:** `beginLogin` succeeds → an admin sets `two_factor_enabled = false` → `/2fa/verify` with the right PIN throws `TwoFactorNotEnabledException` (the canonical throw site). No `auth_sessions` row is created.
  - **Freshness keying:** `verify` with the right PIN + login-purpose writes `2fa:fresh:{sid}` where `sid` matches the just-issued access token's `sid` claim. The return value also includes `sid` so the controller can read it back without re-decoding.
  - **Stolen-token piggyback regression:** start with two separate session ids `sidA` and `sidB` for the same user (e.g. by calling `createUserSession` twice manually). After a verify-login that writes `2fa:fresh:{sidA}`, `hasFreshVerification(sidA)` returns true and `hasFreshVerification(sidB)` returns false. This is the security boundary against the spec's stolen-token attack.
  - `hasFreshVerification` returns true within the window, false after the marker expires (or after `disable($uuid, $sid)` clears it).
  - Wrong PIN → `InvalidTwoFactorCodeException`. Cache PIN entry remains for re-attempts within the window.
  - Replay of same `challenge_token` → `InvalidChallengeTokenException` (consumed).
  - `disable($uuid, $sid)` clears both the column and the calling session's freshness marker; markers for other sessions of the same user remain (verified by setting up `2fa:fresh:{sidA}` and `2fa:fresh:{sidB}`, calling disable with `sidA`, asserting `sidB`'s marker still exists).
  - `masterEnabled = false` makes `isEnabled` return false regardless of the column value (kill-switch behaviour).

**Acceptance:** All seventeen scenarios pass against SQLite + `ArrayCacheDriver` + a fake `NotificationService` whose `send()` captures the args for assertion. The `JWTService::setContext()` call must run in test bootstrap so the static service can resolve `session.jwt_key`. The fixture user must exist in the `users` table since `createUserSession`'s downstream stores (`auth_sessions`, `refresh_tokens`, `SessionCacheManager`) read it for related metadata. The three re-validation tests are the load-bearing regression guards against the "stale pre-2FA authentication result" attack — without them, 2FA could mint sessions for accounts the normal login flow would now reject.

---

## Task 5: Controllers

- [ ] **Step 1: `TwoFactorController`**

```php
namespace Glueful\Controllers;

use Glueful\Auth\TwoFactor\Exceptions\InvalidChallengeTokenException;
use Glueful\Auth\TwoFactor\Exceptions\InvalidTwoFactorCodeException;
use Glueful\Auth\TwoFactor\Exceptions\TwoFactorNotEnabledException;
use Glueful\Auth\TwoFactor\Exceptions\TwoFactorReelevationRequiredException;
use Glueful\Auth\TwoFactor\TwoFactorService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\{Request, Response as SymfonyResponse};

final class TwoFactorController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private TwoFactorService $twoFactor,
        private \Glueful\Auth\LoginResponseShaper $loginResponseShaper,
    ) {
        // BaseController::__construct requires ApplicationContext (src/Controllers/BaseController.php:95).
        parent::__construct($context);
    }

    public function enable(Request $request): SymfonyResponse
    {
        $userUuid = $this->userContext->getUserUuid();
        if ($userUuid === null) {
            throw new AuthenticationException('Authentication required');
        }
        $user = $this->userContext->getUser();
        $challenge = $this->twoFactor->beginEnable($userUuid, $user->email);

        return Response::success([
            'challenge_token' => $challenge['token'],
            'expires_in' => $challenge['expires_in'],
            'delivered_to' => $challenge['delivered_to'],
        ], 'Two-factor code sent');
    }

    public function verify(Request $request): SymfonyResponse
    {
        $payload = $request->getPayload();
        $token = (string) $payload->get('challenge_token', '');
        $code = (string) $payload->get('code', '');

        try {
            $result = $this->twoFactor->verify($token, $code);
        } catch (InvalidChallengeTokenException | InvalidTwoFactorCodeException | TwoFactorNotEnabledException $e) {
            // Internal exceptions distinguish failure modes for logging/test assertions.
            // On the wire, collapse to a single 401 when generic-error-responses is on (default).
            if ((bool) config($this->getContext(), 'security.auth.generic_error_responses', true)) {
                throw new AuthenticationException('Invalid or expired verification');
            }
            throw $e;
        }

        if ($result['purpose'] === 'login') {
            // $result['session'] is the OIDC-shaped payload from TokenManager::createUserSession —
            // {access_token, refresh_token, expires_in, token_type, user, ...}. Shape it through
            // the shared helper so this response carries the same CSRF token + fires the same
            // LoginResponseBuilding/Built events as a no-2FA login. Without the shaper, downstream
            // listeners on the login pipeline would silently miss the 2FA path.
            return $this->loginResponseShaper->shape($request, $result['session']);
        }
        // 'enabled' purpose → 204
        return new SymfonyResponse('', 204);
    }

    public function disable(Request $request): SymfonyResponse
    {
        $userUuid = $this->userContext->getUserUuid();
        if ($userUuid === null) {
            throw new AuthenticationException('Authentication required');
        }

        // Freshness lives in a cache marker, not a token claim — TokenManager
        // hard-codes the access-token payload to sub/sid/ver only
        // (src/Auth/TokenManager.php:163-167), so a last_2fa_verified_at claim
        // would be dropped at issuance. The marker is written by
        // TwoFactorService::verify() on successful login-purpose verify and
        // is keyed by the issued session's `sid` — not by user_uuid — so a
        // stolen token from a different session cannot piggyback on a
        // legitimate user's recent verify.
        $sid = $this->currentSessionId($request);
        if ($sid === '' || !$this->twoFactor->hasFreshVerification($sid)) {
            throw new TwoFactorReelevationRequiredException();
        }

        $this->twoFactor->disable($userUuid, $sid);
        return new SymfonyResponse('', 204);
    }

    /**
     * Pull the `sid` claim out of the access token on the current request.
     * Prefers a UserContext accessor if the framework exposes one (e.g.
     * `getSessionId()`); falls back to decoding the bearer token directly.
     */
    private function currentSessionId(Request $request): string
    {
        if (method_exists($this->userContext, 'getSessionId')) {
            $sid = (string) $this->userContext->getSessionId();
            if ($sid !== '') {
                return $sid;
            }
        }
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return '';
        }
        $token = substr($header, 7);
        $claims = \Glueful\Auth\JWTService::decode($token);
        if (!is_array($claims)) {
            return '';
        }
        return (string) ($claims['sid'] ?? '');
    }
}
```

**Constructor:** `BaseController::__construct` requires an `ApplicationContext` (`src/Controllers/BaseController.php:95`). The controller accepts it as the first parameter and forwards to `parent::__construct($context)`. The container's auto-wiring resolves both arguments.

**User resolution:** `$this->userContext->getUserUuid()` — same canonical pattern as `BaseController` uses elsewhere. Do **not** use `$request->getUser()` (Symfony's accessor returns HTTP Basic Auth username).

**Response envelope:** uses `Glueful\Http\Response::success($data, $message)` to match every other endpoint in `AuthController` (e.g. `src/Controllers/AuthController.php:178, 449`). 204 No Content for `enable`-purpose verify (no body) and `disable`.

**Failure collapsing:** the verify endpoint catches the three internal 401-class exceptions and rethrows a single generic `AuthenticationException` when `security.auth.generic_error_responses` is true (default). This matches the existing `forgotPassword`/`resetPassword` masking behaviour (`src/Controllers/AuthController.php:448, 517`). Tests that need to assert specific failure modes should toggle the config flag off in their setup.

**Freshness gate:** `disable` calls `TwoFactorService::hasFreshVerification($sid)` against the **current session's `sid` claim** (cache marker lookup), not against `user_uuid` and not via `$request->attributes->get('last_2fa_verified_at')`. The request-attribute path was the original design but is unreachable because access tokens carry no such claim. The session-scoped keying is the security boundary against the stolen-token piggyback scenario (see spec § `/2fa/disable`).

- [ ] **Step 2: Integration tests** — `TwoFactorControllerTest`:
  - POST `/2fa/enable` issues a challenge, response wrapped in `Response::success` envelope, dispatcher fired.
  - POST `/2fa/verify` with the right PIN flips the column for enable-purpose; returns 204.
  - Simulated login flow: framework's `AuthController::login` calls `beginLogin($projectedUserArray, $preferredProvider)` for a 2FA-enabled user → client posts to `/2fa/verify` with PIN → response carries the full `createUserSession` OIDC payload (access_token, refresh_token, expires_in, user). Assert: `auth_sessions` row exists; the issued access token passes `TokenManager::validateAccessToken` (non-empty `sid`, `ver = 1`).
  - POST `/2fa/disable` without recent verification → 403 reelevation required.
  - POST `/2fa/disable` after a fresh verify (within `disable_freshness` window, sent with the bearer token issued by that verify) → 204. The `2fa:fresh:{sid}` cache marker for that session is cleared on success.
  - **Stolen-token piggyback regression at the HTTP layer:** issue two sessions for the same user (`sessionA`, `sessionB`). Complete a 2FA verify-login that produces `sessionA`. Immediately call `POST /2fa/disable` with `sessionB`'s bearer token. Expect **403 re-elevation required**, not 204, even though the user just completed a verify. This is the load-bearing test for the session-scoped marker.
  - **Generic-error collapse:** with `security.auth.generic_error_responses=true` (default), three failure modes (bad token / wrong code / expired PIN) all render as identical 401 responses to the client.
  - **Non-generic mode:** with the flag off, the distinct exception types are renderable for test assertions.

**Acceptance:** All eight flows pass. The two load-bearing regression assertions are (a) "tokens issued via verify pass `validateAccessToken`" (guards against the empty-sid/ver bug) and (b) "disable with a different session's bearer returns 403" (guards against the stolen-token piggyback).

---

## Task 6: Routes + rate limiting

- [ ] **Step 1: Locate the core auth routes file**

```bash
find src routes config -name "*.php" -exec grep -l "/auth/login\|->post('/auth" {} +
```

Likely candidates: `src/routes/auth.php`, or routes registered in a service provider. Confirm where existing auth routes live.

- [ ] **Step 2: Add the three routes with rate limits, guarded by the master switch**

Wrap the entire registration in a config check so the routes do not exist when `TWO_FACTOR_ENABLED=false`. `routes/*.php` files don't have `ApplicationContext` in scope, so read the env directly (or use `config()` if the bootstrap exposes it — confirm during implementation):

```php
// routes/2fa.php
if (!filter_var(env('TWO_FACTOR_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    return;  // master switch off — routes are not registered, /2fa/* returns 404.
}

$router->post('/2fa/enable', [TwoFactorController::class, 'enable'])
    ->rateLimit(3, 1)        // 3 attempts per 1 minute
    ->middleware(['auth', 'rate_limit'])
    ->name('2fa.enable');

$router->post('/2fa/verify', [TwoFactorController::class, 'verify'])
    ->rateLimit(5, 1)        // 5 attempts per 1 minute
    ->middleware('rate_limit')
    ->name('2fa.verify');

$router->post('/2fa/disable', [TwoFactorController::class, 'disable'])
    ->rateLimit(3, 1)        // 3 attempts per 1 minute
    ->middleware(['auth', 'rate_limit'])
    ->name('2fa.disable');
```

**String-decorator form is ignored by the middleware.** `rate_limit:N,window` ignored by `EnhancedRateLimiterMiddleware` — it reads limits exclusively from `Route::getRateLimitConfig()` (`src/Api/RateLimiting/Middleware/EnhancedRateLimiterMiddleware.php:83`), not from middleware params (`...$params` at line 71 is unused). Use the builder method `Route::rateLimit($attempts, $perMinutes = 1, ...)` (`src/Routing/Route.php:297`) to populate that config, then attach the middleware. Note the unit is **minutes**, not seconds — `5 per 60s` = `rateLimit(5, 1)`.

`/2fa/verify` has **no** `auth` middleware — the challenge_token in the body authenticates the request. The other two require a valid access token.

**Note on existing `routes/auth.php` precedent:** the existing auth routes use the string-decorator form (`rate_limit:5,60`) which doesn't actually enforce. That's a pre-existing framework bug outside the scope of this feature; the 2FA routes ship with the builder form so they actually rate-limit. A follow-up issue should be opened to migrate the existing routes.

**Keying:** the `Route::rateLimit($attempts, $perMinutes, $tier, $algorithm, $by)` builder accepts `$by` (`'ip' | 'user' | 'endpoint'`, default `'ip'`). 2FA routes use the default `'ip'` keying for v1. Per-challenge-token-claim keying would require a custom rate-limit `$by` source — documented follow-up (see spec § Rate limiting).

**Acceptance:**
- With `TWO_FACTOR_ENABLED=true`: `php glueful route:debug` lists all three with their middleware chains. An integration test (or manual `ab` / `hey` run) confirms the 6th request to `/2fa/verify` from a single IP within 1 minute returns 429.
- With `TWO_FACTOR_ENABLED=false` (default): `php glueful route:debug` does **not** list any `/2fa/*` routes. `curl -X POST /2fa/verify` returns 404. This is the load-bearing regression check that the config guard actually fires.

---

## Task 7: Service container registration

- [ ] **Step 1: Confirm `CoreProvider` API**

`Glueful\Container\Providers\CoreProvider` exposes a `defs(): array` method (`src/Container/Providers/CoreProvider.php:16`) returning an associative array of definitions. Each entry uses `FactoryDefinition` from `Glueful\Container\Definition` — **not** an imperative `$container->set(...)` call. See the existing `TokenManager` registration at `src/Container/Providers/CoreProvider.php:241-248` for the canonical shape.

- [ ] **Step 2: Add definitions to `CoreProvider::defs()`**

```php
// Append to the array returned by CoreProvider::defs()

$defs[\Glueful\Auth\TwoFactor\JtiBlocklist::class] = new FactoryDefinition(
    \Glueful\Auth\TwoFactor\JtiBlocklist::class,
    fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\TwoFactor\JtiBlocklist(
        $c->get(\Glueful\Cache\CacheStore::class)
    )
);

$defs[\Glueful\Auth\TwoFactor\ChallengeTokenIssuer::class] = new FactoryDefinition(
    \Glueful\Auth\TwoFactor\ChallengeTokenIssuer::class,
    fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\TwoFactor\ChallengeTokenIssuer(
        $c->get(\Glueful\Auth\TwoFactor\JtiBlocklist::class),
        (int) config($this->context, 'auth.two_factor.challenge_ttl', 300),
    )
);

$defs[\Glueful\Auth\LoginResponseShaper::class] = new FactoryDefinition(
    \Glueful\Auth\LoginResponseShaper::class,
    fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\LoginResponseShaper(
        $this->context
    )
);

$defs[\Glueful\Auth\TwoFactor\TwoFactorService::class] = new FactoryDefinition(
    \Glueful\Auth\TwoFactor\TwoFactorService::class,
    fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\TwoFactor\TwoFactorService(
        $this->context,  // ApplicationContext — needed to read security.auth.allowed_login_statuses for /2fa/verify re-validation.
        $c->get('database'),  // CoreProvider registers the connection under the string key 'database', not Connection::class.
        $c->get(\Glueful\Cache\CacheStore::class),
        $c->get(\Glueful\Notifications\Services\NotificationService::class),
        $c->get(\Glueful\Auth\TwoFactor\ChallengeTokenIssuer::class),
        $c->get(\Glueful\Auth\TwoFactor\JtiBlocklist::class),
        $c->get(\Glueful\Auth\TokenManager::class),
        (int) config($this->context, 'auth.two_factor.pin_length', 6),
        (int) config($this->context, 'auth.two_factor.pin_ttl', 300),
        (int) config($this->context, 'auth.two_factor.disable_freshness', 300),
        (string) config($this->context, 'auth.two_factor.template_name', 'two-factor-pin'),
        (bool) config($this->context, 'auth.two_factor.enabled', false),  // default OFF — see spec § Configuration.
    )
);
```

**Notes:**
- `'database'` is the canonical key in `CoreProvider` (`src/Container/Providers/CoreProvider.php:202-203`). `Connection::class` is **not** registered as an alias — using it returns nothing. The rest of the provider uses `$c->get('database')` (e.g. lines 219, 223), and `TwoFactorService` must do the same.
- `TokenManager::class` is already registered in `CoreProvider` (`src/Container/Providers/CoreProvider.php:241-248`) and is the correct issuance entry point — replaces the prior plan's reference to `AccessTokenIssuer`, which alone produces tokens that fail `validateSessionClaims`.
- `ChallengeTokenIssuer` no longer receives `JWTService` — it calls static methods directly.
- No `PinGenerator` definition — `TwoFactorService` calls `OTP::generateNumeric()` and `OTP::hashOTP()` directly.
- `NotificationService` — confirm whether this is already a first-class binding in `CoreProvider` or another provider. If not bound, mirror the resolution pattern from `EmailVerification::__construct` (`src/Security/EmailVerification.php:91-167`): resolve `NotificationDispatcher` from the container when available, fall back to ad-hoc construction otherwise. Add the binding here if it doesn't exist.
- `JWTService::setContext($context)` must run during bootstrap (it likely already does — confirm by grepping for `JWTService::setContext` in the bootstrap chain).

- [ ] **Step 3: `config/auth.php`** — add the `two_factor` block per the spec's Configuration section. Keys: `enabled`, `pin_length`, `pin_ttl`, `challenge_ttl`, `template_name`, `disable_freshness`.

**Acceptance:** `app($context, TwoFactorService::class)` returns the service without throwing. An integration test resolves it from a booted container and successfully dispatches one challenge end-to-end (cache write + fake notification channel capture + cache marker write on verify-login).

---

## Task 8: CLI commands

- [ ] **Step 1: `2fa:enable <user>`** — admin force-enable. Skips the PIN challenge: directly sets `two_factor_enabled = true`. Prints a warning that this bypasses the email-confirmation step (operator is responsible for the user actually being able to receive email).

- [ ] **Step 2: `2fa:disable <user>`** — sets the column to false. No reelevation check (admin-side).

- [ ] **Step 3: `2fa:status <user>`** — prints enabled/disabled.

- [ ] **Step 4: Register the commands** in the service provider's command list.

**Acceptance:** All three commands appear in `php glueful list`. `2fa:enable` + `2fa:status` show the expected enabled state after each.

---

## Task 9: Email template (lives in `glueful/email-notification`)

The template ships in the `glueful/email-notification` extension's template directory — same location as the existing `password-reset` and `verification` templates. The framework only ships the `template_name` contract; the extension owns the rendering.

- [ ] **Step 1: Identify the template directory** in the `glueful/email-notification` repo. Likely `templates/` or `resources/views/`. Confirm by inspecting where `password-reset` lives.

- [ ] **Step 2: Create `two-factor-pin.html.twig`** (or `.blade.php` — whatever templating engine the extension uses for the existing templates). Minimal HTML — uses `{{ pin }}` and `{{ ttl_minutes }}`. Subject is set by the dispatch call (`'Your two-factor verification code'`).

- [ ] **Step 3: Plain-text fallback** if the extension's other templates have one — match the convention.

**Acceptance:** Sent emails render the PIN and TTL. A test installation with `glueful/email-notification` installed receives the email with the correct values; without the extension installed, `/2fa/enable` fails at dispatch time with a clear error (soft-dependency contract).

**If `glueful/email-notification` is not in your repo set:** open a follow-up issue against that extension repository with this template task. The framework feature can ship without it, but `two_factor.enabled = true` won't function end-to-end until the extension is updated.

---

## Task 10: Refactor login pipeline & wire the 2FA branch

This is what makes the feature work out of the box. The 2FA branch lives in the framework's own `src/Controllers/AuthController.php`; consumers do not edit anything to use it. Reaching the right insertion point requires modest refactors to `AuthenticationService` and `AuthController` because today's `authenticate()` already issues tokens.

- [ ] **Step 1: Split `AuthenticationService::authenticate()` into `verifyCredentials()` + `issueSession()` — scoped to the username/password flow only**

`authenticate()` today has two top-level branches (`src/Auth/AuthenticationService.php:152-173`):

  - **Provider short-circuit** for `token` and `api_key` credentials → delegates to `authManager->authenticateWithProvider(...)` and returns the provider's session payload directly. Used by JWT bearer, API keys, LDAP/SAML bearer-token flows.
  - **Default flow** for `username`/`email` + `password` (line 175+) — find user → status allowlist → password verify → format → `createUserSession`.

The 2FA split applies **only to the second branch**. The provider short-circuit stays in `authenticate()` and is reached unchanged. Concretely, the new methods cover **only the username/password path**:

```php
/**
 * Find user by username/email, enforce status allowlist, verify password, return formatted user data.
 * **Username/password flow only** — does NOT handle token or api_key provider credentials. Callers
 * should route token/api_key requests through authenticate() directly.
 * Does NOT create a session — use issueSession() for that.
 *
 * @param array<string, mixed> $credentials Must contain username|email + password.
 * @return array<string, mixed>|null  Formatted $userData ready for issueSession(), or null on failure.
 */
public function verifyCredentials(array $credentials, ?string $providerName = null): ?array
{
    // ... existing find-user logic (lines ~181-195) ...
    // ... existing status allowlist check (lines ~202-208) — unchanged ...
    // ... existing password validation + verify (lines ~211-223) — unchanged ...
    // ... existing $userData formatting (lines ~226-235) ...
    return $userData;
}

/**
 * Create a session for an already-verified user. Returns the OIDC session payload.
 *
 * @param array<string, mixed> $userData As returned by verifyCredentials()
 */
public function issueSession(array $userData, ?string $providerName = null): array
{
    $preferredProvider = $providerName ?? ($userData['provider'] ?? 'jwt');
    return $this->tokenManager->createUserSession($userData, $preferredProvider);
}

public function authenticate(array $credentials, ?string $providerName = null): ?array
{
    // Provider short-circuit (lines 152-173 today) — UNCHANGED. Token/api_key credentials
    // are still handled here and never touch verifyCredentials().
    if ($providerName !== null && $providerName !== '') {
        // ... existing token/api_key branches (lines 152-173) verbatim ...
    }

    // Username/password fallback — delegate to the new split methods.
    $userData = $this->verifyCredentials($credentials, $providerName);
    if ($userData === null) {
        return null;
    }
    return $this->issueSession($userData, $providerName);
}
```

The provider short-circuit's behaviour and contract are preserved verbatim. Any existing caller of `authenticate()` continues to work for all four flows (JWT, LDAP, SAML, API key). The credentials/status validation logic does not change — only the orchestration is split. `authenticate()`'s public contract is preserved for any caller that doesn't need the split.

- [ ] **Step 2: Create `LoginResponseShaper`**

`src/Auth/LoginResponseShaper.php`:

```php
namespace Glueful\Auth;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\Auth\LoginResponseBuildingEvent;
use Glueful\Events\Auth\LoginResponseBuiltEvent;
use Glueful\Events\EventService;
use Glueful\Http\Response;
use Glueful\Routing\Middleware\CSRFMiddleware;
use Symfony\Component\HttpFoundation\{Request, Response as SymfonyResponse};

/**
 * Shapes the successful-login response: adds CSRF token (if enabled) and dispatches
 * LoginResponseBuilding / LoginResponseBuilt events. Used by both the normal
 * AuthController::login path and the /2fa/verify login-purpose path so the on-the-wire
 * response is identical regardless of which path the user took.
 */
final class LoginResponseShaper
{
    public function __construct(private ApplicationContext $context) {}

    /**
     * @param array<string, mixed> $session The OIDC session payload from issueSession() / createUserSession()
     */
    public function shape(Request $request, array $session): SymfonyResponse
    {
        // CSRF — same logic currently at src/Controllers/AuthController.php:144-159.
        if (env('CSRF_PROTECTION_ENABLED', true) === true) {
            try {
                $csrf = new CSRFMiddleware();
                $token = $csrf->generateToken($request);
                $session['csrf_token'] = [
                    'token'      => $token,
                    'header'     => 'X-CSRF-Token',
                    'field'      => '_token',
                    'expires_at' => time() + (int) env('CSRF_TOKEN_LIFETIME', 3600),
                ];
            } catch (\Throwable $e) {
                error_log('Failed to generate CSRF token during login: ' . $e->getMessage());
            }
        }

        // Login events — same logic currently at src/Controllers/AuthController.php:161-176.
        $tokens = [
            'access_token'  => $session['access_token']  ?? null,
            'refresh_token' => $session['refresh_token'] ?? null,
            'expires_in'    => $session['expires_in']    ?? null,
            'token_type'    => $session['token_type']    ?? 'Bearer',
        ];
        $user = $session['user'] ?? [];
        try {
            $events = app($this->context, EventService::class);
            $events->dispatch(new LoginResponseBuildingEvent($tokens, $user, $session));
            $events->dispatch(new LoginResponseBuiltEvent($session));
        } catch (\Throwable $e) {
            error_log('Login response events failed: ' . $e->getMessage());
        }

        return Response::success($session, 'Login successful');
    }
}
```

- [ ] **Step 3: Update `AuthController::login` to use the split + the shaper, while preserving the provider short-circuit**

In `src/Controllers/AuthController.php`, inject `TwoFactorService` and `LoginResponseShaper`. Then rewrite `login()`:

```php
public function login(SymfonyRequest $request)
{
    $credentials = RequestHelper::getRequestData($request);
    // ... existing remember_me / provider extraction unchanged ...
    $preferredProvider = $providerName ?? ($credentials['provider'] ?? 'jwt');

    // Route 1 — token / API-key provider login. Bypasses the 2FA gate entirely (these
    // credentials don't have a "verified user, no session yet" intermediate state, and
    // API keys carry their own scoped auth model). Delegates to the unchanged
    // AuthenticationService::authenticate() provider short-circuit.
    if (isset($credentials['token']) || isset($credentials['api_key'])) {
        $result = $this->authService->authenticate($credentials, $providerName);
        if ($result === null) {
            throw new AuthenticationException('Invalid credentials');
        }
        return $this->loginResponseShaper->shape($request, $result);
    }

    // Route 2 — username/password login. Goes through the new split + 2FA gate.

    // Step 1: credentials & status validation only. NO session is created here.
    $userData = $this->authService->verifyCredentials($credentials, $providerName);
    if ($userData === null) {
        throw new AuthenticationException('Invalid credentials');
    }

    // Step 2: 2FA branch.
    if ($this->twoFactor->isEnabled((string) $userData['uuid'])) {
        $challenge = $this->twoFactor->beginLogin(
            [
                'uuid'              => (string) $userData['uuid'],
                'email'             => (string) ($userData['email'] ?? ''),
                'email_verified_at' => $userData['email_verified_at'] ?? null,
                'username'          => $userData['username'] ?? null,
                'profile'           => $userData['profile'] ?? null,
                'remember_me'       => (bool) ($credentials['remember_me'] ?? false),
                'status'            => $userData['status'] ?? null,
            ],
            $preferredProvider  // ← carry the requested provider into the challenge state.
        );
        // Challenge responses deliberately skip CSRF + login events — login is not yet
        // complete and there is no session to bind CSRF to.
        return Response::success([
            'two_factor_required' => true,
            'challenge_token'     => $challenge['token'],
            'expires_in'          => $challenge['expires_in'],
            'delivered_to'        => $challenge['delivered_to'],
        ], 'Two-factor verification required');
    }

    // Step 3: no 2FA. Issue the session and shape the response (CSRF + events).
    $session = $this->authService->issueSession($userData, $preferredProvider);
    return $this->loginResponseShaper->shape($request, $session);
}
```

The existing CSRF block (lines 144-159) and event-dispatch block (lines 161-176) are **removed from this method** since the shaper now owns them. Both routes (token/API-key and password) flow through the shaper, so all four current flows (JWT, LDAP, SAML, API key) keep their CSRF + event behaviour.

- [ ] **Step 4: Update `TwoFactorController::verify` to use the shaper for the login-purpose path**

Replace the `return Response::success($result['session'], 'Login successful');` line in `TwoFactorController::verify` with `return $this->loginResponseShaper->shape($request, $result['session']);`. Inject `LoginResponseShaper` into the controller's constructor alongside `TwoFactorService`.

This guarantees the 2FA-completed login response carries the same CSRF token and fires the same `LoginResponseBuilding`/`LoginResponseBuilt` events as a no-2FA login — without it, downstream listeners would silently miss the 2FA path.

- [ ] **Step 5: Integration tests** — `tests/Integration/Controllers/AuthControllerLoginTwoFactorTest.php`:
  - **Master switch off (default):** `POST /auth/login` with valid credentials returns the today-shaped `{access_token, refresh_token, csrf_token, ...}` response. No 2FA fields. No `2fa:pin:*` cache entries created. `LoginResponseBuilt` event fired.
  - **Master switch on + user has `two_factor_enabled = true`:** `POST /auth/login` returns `{two_factor_required: true, challenge_token, expires_in, delivered_to}`. Notification dispatcher was invoked with the `two-factor-pin` template. **No** `access_token`/`refresh_token`/`csrf_token` in the response. **No** `LoginResponseBuilt` event fired (login not complete).
  - **Master switch on + user has `two_factor_enabled = false`:** `POST /auth/login` returns the today-shaped token response with CSRF + events.
  - **Master switch on + missing column (migration not run):** `isEnabled` catches the DB error, logs, returns false; `POST /auth/login` falls through to the no-2FA path and returns tokens. Login does not 500.
  - **Bad credentials with 2FA enabled:** still returns the same 401/`AuthenticationException` as today — `verifyCredentials()` returns null first, the 2FA branch never executes for invalid credentials.
  - **End-to-end 2FA-completed login carries CSRF + events:** complete a full enable → login → verify flow. Assert `/2fa/verify` response includes a `csrf_token`, and `LoginResponseBuilding` + `LoginResponseBuilt` events were dispatched. This is the load-bearing regression check that the shaper is correctly wired into both paths.
  - **Provider-bypass regression — token credentials:** `POST /auth/login` with `{provider: "ldap", token: "..."}` even when `TWO_FACTOR_ENABLED=true` AND the resolved user has `two_factor_enabled=true`. Expect a normal token response (not a challenge) — the controller's token/api_key route bypasses the 2FA gate. Same flow for `{provider: "jwt", api_key: "..."}`.
  - **Provider-preservation regression — 2FA-gated LDAP login completes as LDAP:** `POST /auth/login` with `{username, password, provider: "ldap"}` for a 2FA-enrolled user. After PIN verify, assert the returned session was produced by the LDAP provider (or whatever `TokenManager::createUserSession` does for `provider='ldap'`), not by the default JWT path. Inspect the cached `2fa:pin:{jti}` to confirm `preferred_provider: "ldap"` was stashed, and the `createUserSession` call inside `TwoFactorService::verify` was invoked with that provider string. Without this regression, a 2FA-gated LDAP/SAML login silently completes as JWT.

- [ ] **Step 6: README documentation**

Under the Authentication section, add a short "Email 2FA" subsection covering:
1. Run the `010_AddTwoFactorEnabledToUsers` migration.
2. Install `glueful/email-notification` if not already present.
3. Set `TWO_FACTOR_ENABLED=true` in `.env`.
4. Per-user enrollment via `POST /2fa/enable` or the `2fa:enable` CLI command.

No code-paste recipe. The integration is automatic once the env var flips.

**Acceptance:** A new developer goes through the four README steps and ends up with working 2FA — `POST /auth/login` returns a challenge instead of tokens for enrolled users, `POST /2fa/verify` completes the flow with a fully-shaped login response (CSRF + events). No source-code editing required.

---

## Task 11: Verification

- [ ] **Step 1: PHPStan + tests + phpcs**

```bash
composer run phpstan
composer test
composer run phpcs
```

All three clean.

- [ ] **Step 2: Manual end-to-end smoke test** against a fresh api-skeleton install:

```bash
# 1. Apply migration (adds users.two_factor_enabled).
php glueful migrate:run

# 2. Flip the env var (out-of-the-box: no controller editing needed).
echo 'TWO_FACTOR_ENABLED=true' >> .env

# 3. Make sure glueful/email-notification is installed and configured.
composer show glueful/email-notification

# 4. Admin-enable 2FA for a test user (skips the self-service email confirmation).
php glueful 2fa:enable <user-uuid>

# 5. Trigger login — should now return a challenge instead of tokens.
curl -X POST /auth/login -d '{"email":"...","password":"..."}'
# → expect {two_factor_required: true, challenge_token, delivered_to}

# Email arrives with PIN
curl -X POST /2fa/verify -d '{"challenge_token":"...","code":"123456"}'
# → expect Response::success envelope wrapping {access_token, refresh_token, expires_in, user, ...}
# → decode access_token; note the `sid` claim — cache now contains 2fa:fresh:<sid>

# Disable within the freshness window (default 300s), bearing the SAME token
# that just came back from /2fa/verify (so the request's sid matches)
curl -X POST /2fa/disable -H "Authorization: Bearer <access_token>"
# → expect 204
# → cache 2fa:fresh:<sid> is cleared

# Stolen-token piggyback regression: create a second session (e.g. /auth/login
# with a different remember_me, or another /2fa/verify cycle). With the OLDER
# session's bearer token but within disable_freshness of the NEWER session's
# verify:
curl -X POST /2fa/disable -H "Authorization: Bearer <older-session-token>"
# → expect 403 TwoFactorReelevationRequiredException (the older session has
#   no fresh-marker of its own)

# Re-elevation: try disable again after waiting > disable_freshness seconds
curl -X POST /2fa/disable -H "Authorization: Bearer <access_token>"
# → expect 403 TwoFactorReelevationRequiredException
```

**Acceptance:** All steps produce the expected responses. The user's `two_factor_enabled` column flips on enable and off on disable.

---

## Out-of-scope reminders

- TOTP / authenticator apps — `glueful/mfa` extension.
- Recovery codes — `glueful/mfa` extension.
- WebAuthn / passkeys / biometrics — separate extension.
- Push notifications — separate extension (via `glueful/notiva`).
- SMS — not shipped.
- Pluggable provider abstraction — `glueful/mfa` defines its own if needed.
- Step-up MFA / per-route freshness — `glueful/mfa` territory.
- Cleanup jobs for stale PINs — cache TTL handles it.

## Acceptance criteria (rollup)

- `010_AddTwoFactorEnabledToUsers.php` (in api-skeleton) applies and rolls back on MySQL/PostgreSQL/SQLite. Re-running after a manual add does not error.
- **Out-of-the-box integration.** No api-skeleton "recipe" exists; the framework's own `AuthController::login` invokes the 2FA branch. Consumers enable 2FA in four steps with zero source-code editing: run the migration, install `glueful/email-notification`, set `TWO_FACTOR_ENABLED=true`, enroll users.
- **Safe default — behaviour-identical when off.** With `TWO_FACTOR_ENABLED=false` (default), `POST /auth/login` returns bit-identical response to today — same fields including `csrf_token`, same `LoginResponseBuilding`/`LoginResponseBuilt` events. No DB read of the `two_factor_enabled` column. No `2fa:*` cache entries. The `/2fa/*` routes are not even registered. A fresh install without the migration boots cleanly.
- **No-token pre-2FA login.** When 2FA is required, the challenge response is returned before any session is created — no `auth_sessions` row, no refresh-token storage, no session cache. Tokens are issued only via `/2fa/verify` (or the no-2FA branch). Required `AuthenticationService::authenticate()` to be split into `verifyCredentials()` + `issueSession()` since today's `authenticate()` already issues tokens internally.
- **2FA-completed login is response-identical to no-2FA login.** Both paths go through the new `LoginResponseShaper`, so the wire response (CSRF token, login events, `Response::success` envelope) matches regardless of which branch the user took. Regression test asserts the 2FA-completed response includes `csrf_token` and that `LoginResponseBuilt` fired.
- **Defensive on missing column.** If an operator flips `TWO_FACTOR_ENABLED=true` before running the migration, `TwoFactorService::isEnabled` catches the DB error, logs once, and returns false. Login does not 500; falls through to the today-shaped no-2FA response.
- **Routes are config-guarded.** `routes/2fa.php` early-returns when `TWO_FACTOR_ENABLED=false`. `route:debug` does not list any `/2fa/*` routes in the disabled state; direct `curl` hits return 404.
- **Provider compatibility preserved.** Token credentials (`{token: ...}`) and API-key credentials (`{api_key: ...}`) bypass the 2FA gate entirely — they're routed through the unchanged `AuthenticationService::authenticate()` provider short-circuit. The 2FA split applies only to the username/password DB flow. Regression tests cover token + 2FA-enrolled user and api_key + 2FA-enrolled user.
- **Provider preference flows through 2FA.** A 2FA-gated login with `provider=ldap` (or `saml`, etc.) completes as the requested provider, not silently as JWT. `beginLogin` accepts and caches `preferred_provider`; `TwoFactorService::verify` passes it to `createUserSession`. Regression test asserts the issued session matches the requested provider.
- `POST /2fa/enable` dispatches a bcrypt-hashed PIN (via `OTP::hashOTP()`) to the user's email through `NotificationService` and returns a challenge_token wrapped in the `Response::success()` envelope.
- `POST /2fa/verify` with the right PIN + enable-purpose flips `users.two_factor_enabled`. Returns 204.
- `POST /2fa/verify` with the right PIN + login-purpose returns the OIDC-shaped `createUserSession` payload (access_token, refresh_token, expires_in, user, ...) wrapped in the `Response::success()` envelope. Issued access tokens carry non-empty `sid` and `ver = 1`, so `TokenManager::validateAccessToken` accepts them on subsequent requests. The **session-scoped** freshness marker `2fa:fresh:{sid}` is written to cache (key derived from the just-issued access token's `sid` claim) with TTL = `auth.two_factor.disable_freshness`.
- **Re-validation on verify:** before minting a session, `/2fa/verify` re-reads the user row by uuid and rejects if the user no longer exists, the `status` is not in `security.auth.allowed_login_statuses` (default `['active']`), or `two_factor_enabled` is no longer `true`. Closes the window during which an account could be disabled or 2FA-revoked between `/auth/login` and `/2fa/verify`.
- The cached PIN entry contains only allowlisted user fields (`uuid`, `email`, `email_verified_at`, `username`, `profile`, `remember_me`, `status`). Raw repository rows passed to `beginLogin` are projected — `password` and other internal fields never reach the cache.
- `POST /2fa/disable` extracts the current request's `sid` from the bearer access token, then requires the `2fa:fresh:{sid}` cache marker to be present (read via `TwoFactorService::hasFreshVerification($sid)`); returns 403 `TwoFactorReelevationRequiredException` otherwise. `disable` clears the calling session's marker on success.
- **Stolen-token piggyback regression:** a `POST /2fa/disable` request bearing a token from session B returns 403 even if session A just completed a 2FA verify within the freshness window for the same user.
- All three verify failure modes (bad token / wrong code / missing PIN) render as a **single generic 401** when `security.auth.generic_error_responses=true` (default). Distinct exception types remain accessible internally for logging and test assertions.
- All three routes ship with rate-limit middleware actually enforcing (5/min for verify; 3/min for enable/disable) via the `Route::rateLimit($attempts, $perMinutes)` builder — **not** the `rate_limit:N,window` string form, which the middleware ignores (`src/Api/RateLimiting/Middleware/EnhancedRateLimiterMiddleware.php:71, 83`). IP-keyed via the default `$by`.
- Exceptions inherit from the correct bases: 401-class auth failures from `Domain\AuthenticationException`; reelevation from `Client\ForbiddenException`.
- `2fa:enable`, `2fa:disable`, `2fa:status` CLI commands work against a real user.
- PHPStan level 8 clean. PSR-12 / phpcs clean.
- README's four-step opt-in (migration + extension + env var + per-user enroll) produces a working integration in a clean install — no controller editing required.
