# Core Email 2FA — Design Note

**Status:** Draft 2026-05-22.
**Date:** 2026-05-22
**Tier:** Core baseline (not Tier 2). Per `docs/FRAMEWORK_IMPROVEMENTS.md` § 5.1 the richer MFA (TOTP, WebAuthn, recovery codes) ships later as `glueful/mfa`; this is the minimal baseline that ships in the framework itself.

## Goal

Ship a baseline email-PIN 2FA feature inside the framework so every Glueful install can enable email-based 2FA without waiting for the richer `glueful/mfa` extension (which will add TOTP, WebAuthn, and recovery codes). Email delivery is still a soft requirement on `glueful/email-notification` — see Dependencies — so "without an extension" here means "the 2FA logic itself ships in framework core; no additional auth/MFA extension is needed for the email-PIN flow." A 6-digit PIN is emailed to the user; they submit it to complete login. Two routes (`POST /2fa/enable`, `POST /2fa/verify`) and one optional disable route cover the lifecycle. No TOTP, no recovery codes, no provider abstraction — those are `glueful/mfa` scope and come later.

Login flow is **two-stage** (Pattern B from the prior conversation): if 2FA is enabled, `/auth/login` returns a `challenge_token` instead of access tokens; the client completes via `/2fa/verify`.

**Out-of-the-box integration.** The framework's own `AuthController::login` (`src/Controllers/AuthController.php`) — not a hand-edited api-skeleton recipe — handles the 2FA branch. Consumers who run the migration and flip `TWO_FACTOR_ENABLED=true` get the feature without touching controller code. Consumers who don't want 2FA see no behavioural change because the master switch defaults to `false` (see Configuration).

**Top-line constraint (revised):** No changes to the **credentials/status validation logic** in `AuthenticationService::verifyCredentials()` (the find-user + status-allowlist + password-verify chain currently at `src/Auth/AuthenticationService.php:188-223`). However, `AuthenticationService::authenticate()` is **split** in this feature into:

- `verifyCredentials($credentials, $providerName): ?array` — finds the user, runs the status allowlist check, verifies the password, formats user data + profile. Returns `$userData` (or `null` on failure). **No session is created.**
- `issueSession(array $userData, ?string $providerName): array` — calls `TokenManager::createUserSession()` exactly as before. Returns the OIDC payload.
- `authenticate($credentials, $providerName)` is kept as a convenience that **first runs the existing provider short-circuit** (token / API-key credentials, lines 152-173 today) and **then falls back to `verifyCredentials()` + `issueSession()` for username/password**. The provider branch's behaviour is preserved verbatim — only the username/password fallback is split. Preserves the existing public contract for any caller that doesn't need the split.

This split is required because the current `authenticate()` calls `createUserSession()` at line 238 — it already issues access + refresh tokens and writes `auth_sessions` / refresh-token-store / session-cache rows. There is no "verified user before token issuance" gap to insert the 2FA branch into from outside, so a no-token pre-2FA login is incompatible with the prior "do not modify `AuthenticationService`" framing. The new split is the minimum surgical change that exposes the gap; the verification logic itself is untouched.

`AuthController::login` then becomes: `verifyCredentials` → 2FA check → `issueSession` (only if 2FA not required). Tokens are never written for users who still owe a second factor.

**Reuse stance:** This feature builds on existing framework primitives — `Glueful\Security\OTP` (generation + bcrypt hashing), `Glueful\Notifications\Services\NotificationService` (dispatch), `Glueful\Auth\JWTService` (challenge token signing), `Glueful\Auth\TokenManager::createUserSession` (final token issuance, same path the no-2FA branch already uses), and the `Glueful\Http\Response::success()` envelope. `TwoFactorService` is a deliberate fresh class rather than an extension of `EmailVerification` because the data model differs (jti-keyed PIN vs email-keyed) and `EmailVerification`'s static `sendPasswordResetEmail()` shape is legacy worth not extending. A small `OtpDispatcher` helper to share template/result-parsing plumbing across both services may be considered during implementation but is not required for v1.

## Non-goals (deferred to `glueful/mfa`)

- **TOTP / authenticator apps** — extension.
- **Recovery codes** — extension.
- **WebAuthn / passkeys / hardware keys / biometrics** — extension (possibly a separate `glueful/webauthn`).
- **Push notifications** — extension.
- **SMS** — not shipped, not planned.
- **Pluggable provider abstraction** — premature for a single-provider baseline. The richer extension can define its own interface; this feature is concrete.
- **Step-up (per-route MFA freshness via `#[RequireMfa]`)** — extension territory once multiple factors exist.
- **Cleanup jobs for stale state** — none needed; PINs live in cache with TTL, challenge tokens self-expire.

## Architecture overview

```
        ┌─────────────────────────────────────────────────┐
        │           Framework core (this feature)          │
        │                                                  │
        │   AuthController::login()  (modified)            │
        │     ├─ AuthenticationService::verifyCredentials()│
        │     │     (find user + status + password —      │
        │     │      validation logic unchanged)           │
        │     ├─ if (twoFactor->isEnabled($userUuid))      │
        │     │       → beginLogin + return challenge      │
        │     │         (no session created)               │
        │     └─ else                                       │
        │           → AuthenticationService::issueSession()│
        │             ↳ TokenManager::createUserSession()  │
        │           → LoginResponseShaper::shape()         │
        │             ↳ CSRF + login events                │
        │                                                  │
        │   /2fa/verify (login-purpose) reuses             │
        │   issueSession + LoginResponseShaper so the      │
        │   final wire response is identical.              │
        │                                                  │
        │   TwoFactorService                               │
        │     ├─ isEnabled(userUuid)                       │
        │     ├─ beginEnable(userUuid, email)              │
        │     ├─ beginLogin(user[], preferredProvider)    │
        │     │       → challenge_token                    │
        │     ├─ verify(challenge_token, code)             │
        │     │     ↳ login-purpose: re-validate DB row,   │
        │     │       then createUserSession()             │
        │     ├─ hasFreshVerification(sid)                 │
        │     └─ disable(userUuid, sid)                    │
        │                                                  │
        │   ChallengeTokenIssuer                           │
        │     (mints/verifies short-lived JWTs;            │
        │      uses framework's existing JwtService)       │
        │                                                  │
        │   JtiBlocklist                                   │
        │     (cache-backed single-use enforcement)        │
        │                                                  │
        │   TwoFactorController                            │
        │     POST /2fa/enable                             │
        │     POST /2fa/verify                             │
        │     POST /2fa/disable                            │
        │                                                  │
        │   CLI: 2fa:enable, 2fa:disable, 2fa:status       │
        └─────────────────────────────────────────────────┘
```

The PIN lives in cache (one entry per active challenge_token `jti`). The user's enrollment state lives in a single column on the existing `users` table.

## Schema

One column added to `users`, shipped as a migration in api-skeleton (the framework owns the code, api-skeleton owns the schema — same precedent as the `009_CreateApiKeysTable` migration in 1.43.0):

| Column | Type | Constraints | Purpose |
|---|---|---|---|
| `two_factor_enabled` | `boolean` | not null, default `false` | Whether this user must complete a 2FA challenge to log in. |

That's it. No new table. The PIN is cache-backed (5-minute TTL via the framework's existing cache layer); enrollment state is a boolean. If the user is enrolled (`two_factor_enabled = true`), login requires the challenge.

Column name uses `two_factor_` prefix (not `2fa_`) because portable SQL identifiers don't start with digits.

## Cache layout

| Key | Value | TTL | Purpose |
|---|---|---|---|
| `2fa:pin:{jti}` | `{user: {allowlisted fields}, code_hash, preferred_provider}` | 300s | The PIN sent for a specific challenge, plus a **strictly-projected** user array needed to call `TokenManager::createUserSession` on login-purpose verify. `preferred_provider` carries the token provider requested by the original `/auth/login` (jwt, ldap, saml, ...) so a 2FA-gated non-JWT login completes via the same provider. Only the user allowlist below is cached for the `user` key — no raw repository row, no `password` hash, no internal flags. One-use — deleted on successful verify. Auto-expires if the challenge token isn't used. |
| `2fa:consumed_jti:{jti}` | `1` | 300s (= token's remaining lifetime) | Blocklist for consumed challenge tokens. Prevents replay. |
| `2fa:fresh:{sid}` | `timestamp` | `auth.two_factor.disable_freshness` (default 300s) | Marker indicating **the holder of a specific session** has recently completed a 2FA login-purpose verify. Keyed by `sid` (not `user_uuid`) so a fresh login does not bless other active tokens for the same user — a stolen token belongs to a different `sid` and gets no freshness. Read by `/2fa/disable` against the authenticated request's `sid` claim. Replaces the originally-planned `last_2fa_verified_at` access-token claim, which `TokenManager` cannot carry. |

**User-array allowlist (`2fa:pin:{jti}.user`).** To avoid leaking sensitive fields through the cache (the precedent is `AuthenticationService::formatUserData()` at `src/Auth/AuthenticationService.php:355`, which explicitly `unset($user['password'])` before session creation), `TwoFactorService::beginLogin()` projects the incoming user array to a fixed allowlist before caching:

| Field | Required | Notes |
|---|---|---|
| `uuid` | yes | Used to key `2fa:fresh`, identify the user on verify. |
| `email` | yes | For dispatch + masked `delivered_to`. |
| `email_verified_at` | no | Surfaced in the OIDC user object that `createUserSession` returns. |
| `username` | no | OIDC user object. |
| `profile` | no | OIDC user object (used for name fields by `createUserSession`). |
| `remember_me` | no | Toggles access/refresh TTL inside `createUserSession`. |
| `status` | no | If the framework uses it for session gating. |

Any other key passed in by the caller (the framework's `AuthController::login`) is **silently dropped** — `password`, internal timestamps, audit columns, etc. never reach the cache. Tests must include a regression that passes a raw repository row (including `password`) and asserts the cache entry has only allowlisted keys.

PIN is stored hashed using `Glueful\Security\OTP::hashOTP()` (bcrypt) — the same primitive used by `EmailVerification` for password-reset and email-verify OTPs. Verification uses `OTP::verifyHashedOTP()`, which is constant-time by virtue of bcrypt's `password_verify`. The plaintext only lives in the email message.

## Challenge token

A short-lived JWT, signed with the framework's existing JWT key (reuses `Glueful\Auth\JWTService` at `src/Auth/JWTService.php` — no new key material; resolves the key via `config($context, 'session.jwt_key')` and defaults to HS256 via `session.jwt_algorithm`). Shape:

```json
{
  "alg": "HS256",
  "typ": "JWT"
}
.
{
  "jti": "<128-bit hex>",
  "iat": 1716393600,
  "exp": 1716393900,
  "purpose": "2fa_enable" | "2fa_login",
  "user_uuid": "abc123..."
}
```

- **5-minute TTL** (configurable via `auth.two_factor.challenge_ttl`, default 300s).
- **`purpose` claim** distinguishes enrollment confirmation from login verification. Every other middleware in the framework must reject this token — only `/2fa/verify` accepts it.
- **Single-use via `jti`** — on successful `/2fa/verify`, the `jti` is added to a cache blocklist with TTL = remaining token lifetime. Replays fail.
- Token bytes contain no secrets; the PIN is sent separately via email and stored hashed in cache.

## Endpoints

### `POST /2fa/enable` (authenticated)

Initiates 2FA enrollment for the authenticated user. The server:
1. Validates the user isn't already enrolled.
2. Generates a 6-digit PIN via `OTP::generateNumeric(6)`, stores `OTP::hashOTP($pin)` in `2fa:pin:{jti}` cache with 5-min TTL.
3. Issues a challenge_token with `purpose: "2fa_enable"`.
4. Dispatches the PIN via `NotificationService::send('two_factor_pin', $notifiable, 'Two-Factor Code', [...data..., 'template_name' => 'two-factor-pin'], ['channels' => ['email']])` — same dispatch pattern as `EmailVerification::sendPasswordResetEmail()`.
5. Returns (wrapped in the framework's standard `Response::success()` envelope):

```json
{
  "success": true,
  "message": "Two-factor code sent",
  "data": {
    "challenge_token": "<JWT>",
    "expires_in": 300,
    "delivered_to": "u***@example.com"
  }
}
```

The client prompts the user for the PIN they just received. The user submits it via `/2fa/verify`.

### `POST /2fa/verify` (no Authorization header)

Verifies a PIN against a challenge_token. Body:

```json
{
  "challenge_token": "<JWT>",
  "code": "123456"
}
```

The server:
1. Verifies the challenge_token (signature, exp, `purpose` is `2fa_enable` or `2fa_login`, `jti` not in blocklist).
2. Looks up `2fa:pin:{jti}` in cache; rejects if missing/expired.
3. Verifies the submitted code via `OTP::verifyHashedOTP($code, $stored['code_hash'])` (bcrypt, constant-time).
4. Adds `jti` to the consumed blocklist (TTL = exp - now).
5. Deletes the cache PIN entry.
6. **For `2fa_login` only:** re-reads the user row by uuid and re-validates current DB state — the cached PIN entry holds a snapshot from `/auth/login` time, which could be up to 5 minutes stale. The same rules the normal login flow applies (`AuthenticationService::authenticate` at `src/Auth/AuthenticationService.php:202-208`) are re-applied here so 2FA can never bypass them. Specifically:
   - User row exists → otherwise throw `InvalidTwoFactorCodeException` ("account no longer exists").
   - `status` is in `security.auth.allowed_login_statuses` (default `['active']`) → otherwise throw `InvalidTwoFactorCodeException` ("account not eligible to log in").
   - `two_factor_enabled` is still `true` → otherwise throw `TwoFactorNotEnabledException`. (Canonical throw site for this exception.)
   All three collapse to a generic 401 on the wire when `security.auth.generic_error_responses` is true.
7. Dispatches based on `purpose`:
   - `2fa_enable`: sets `users.two_factor_enabled = true`. Returns 204.
   - `2fa_login`: calls `TokenManager::createUserSession($user, $preferredProvider)` where `$preferredProvider` is read from the PIN cache entry's `preferred_provider` key (set by `beginLogin` from the original `/auth/login` request — falls back to `'jwt'` if absent). This preserves the requested provider for 2FA-gated LDAP/SAML/etc. logins. Then writes the session-scoped `2fa:fresh:{sid}` cache marker (where `sid` comes from the just-issued access token's `sid` claim). The response is shaped via `LoginResponseShaper::shape($request, $session)` so it carries the same CSRF token and fires the same `LoginResponseBuilding`/`LoginResponseBuilt` events as a no-2FA login. Wrapped in the `Response::success()` envelope:

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "access_token": "<JWT>",
    "refresh_token": "<JWT>",
    "expires_in": 900,
    "user": { "id": "...", "email": "...", "..." }
  }
}
```

**Failure handling.** Internally the service distinguishes three failure modes — invalid/expired/blocklisted challenge token, missing/expired cache PIN, and code mismatch — represented by separate exception types (see hierarchy below) for logging and test assertions. On the wire, when `security.auth.generic_error_responses` is true (default), all three render as a single generic 401 ("Invalid or expired verification") to avoid leaking which dimension failed. The same flag already governs `forgotPassword`/`resetPassword` responses in `AuthController`.

### `POST /2fa/disable` (authenticated, requires recent 2FA verification)

Disables 2FA for the authenticated user. To prevent bypass (someone with a stolen access token disabling 2FA without proving they can pass it), this endpoint requires a fresh verification — implemented as a **session-scoped cache marker** `2fa:fresh:{sid}` written by `/2fa/verify` on successful login-purpose verify, with TTL = `auth.two_factor.disable_freshness` (default 300s). The endpoint reads the marker via `TwoFactorService::hasFreshVerification($sid)` using the `sid` claim from the current request's access token; presence = fresh, absence = re-elevation required.

**Why `sid`-keyed, not `user_uuid`-keyed:** a user-scoped marker `2fa:fresh:{user_uuid}` would bless every active access token belonging to that user. An attacker who already possesses a stolen, still-valid access token could wait for the real user to complete a legitimate 2FA login and then immediately call `/2fa/disable` with the stolen token within the freshness window — the marker would be present even though the attacker's token came from a separate session. Keying by `sid` (the session identifier `TokenManager::createUserSession` writes into the JWT at `src/Auth/TokenManager.php:489`) ties freshness to the specific session that completed verification. A stolen token from a different session has a different `sid` and gets no freshness.

**Why a cache marker, not a token claim:** the framework's `TokenManager::generateTokenPair` hard-codes the access-token payload to `sub`/`sid`/`ver` only (`src/Auth/TokenManager.php:163-167`) — any custom claim like `last_2fa_verified_at` is silently dropped. Modifying `TokenManager` to support custom claims would change the framework's access-token contract for one feature, affecting every consumer regardless of whether they use 2FA; a cache marker keeps the change local to this feature and easily reversible. (Session-data storage was the other option considered but would require coordinating writes through `SessionCacheManager` and the session-row lifecycle — a wider blast radius than a single cache key.)

If the freshness check fails, the endpoint responds with 403 and an explanatory error:

```json
{
  "code": "TWO_FACTOR_REELEVATION_REQUIRED",
  "message": "Re-verify 2FA in the last 5 minutes before disabling"
}
```

The client can re-issue a login flow (logout + login) to refresh the marker, or call `/2fa/enable` to start a confirmation cycle.

On success: sets `users.two_factor_enabled = false` and clears the current session's freshness marker (`2fa:fresh:{sid}`). Markers for other sessions of the same user expire on their own TTL; they are not bulk-cleared, since freshness only matters when paired with a still-valid session.

## Login flow

Two-stage, with the 2FA branch implemented directly in the framework's `AuthController::login` (`src/Controllers/AuthController.php`). `AuthenticationService::authenticate()` is **split** into `verifyCredentials()` + `issueSession()` (see the top-line constraint above) so the controller has a "verified user, no session yet" intermediate state to gate on. The credentials and status validation **logic** continues to run exactly as today — only the orchestration is split.

Shape of the modified `AuthController::login` (uses the split methods + the new `LoginResponseShaper` helper that wraps the existing CSRF + login-event response shaping so both the normal path and `/2fa/verify` go through the same pipeline):

```php
public function login(SymfonyRequest $request): Response
{
    // ... existing prelude (extract credentials, provider, remember_me) ...
    $preferredProvider = $providerName ?? ($credentials['provider'] ?? 'jwt');

    // Route 1 — token / API-key provider login. Bypasses the 2FA gate entirely
    // (these credentials don't have a "verified user, no session yet" intermediate
    // state, and API keys carry their own scoped auth model). Delegates to the
    // unchanged AuthenticationService::authenticate() provider short-circuit.
    if (isset($credentials['token']) || isset($credentials['api_key'])) {
        $result = $this->authService->authenticate($credentials, $providerName);
        if ($result === null) {
            throw new AuthenticationException('Invalid credentials');
        }
        return $this->loginResponseShaper->shape($request, $result);
    }

    // Route 2 — username/password login. Goes through the new split + 2FA gate.

    // Step 1 — credentials & status validation only. NO session is created here.
    // Replaces the old call to authService->authenticate() which issued tokens.
    $userData = $this->authService->verifyCredentials($credentials, $providerName);
    if ($userData === null) {
        throw new AuthenticationException('Invalid credentials');
    }

    // Step 2 — 2FA branch (additive, framework-owned).
    if ($this->twoFactor->isEnabled((string) $userData['uuid'])) {
        // Allowlisted projection — never cast (array) $user; never pass raw repo rows.
        // TwoFactorService::beginLogin also re-projects defensively, but the controller
        // should be precise about what it hands over. Pass the requested provider
        // through so a 2FA-gated LDAP/SAML/etc. login completes via the same provider.
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
            $preferredProvider  // ← carried into the PIN cache entry as preferred_provider.
        );
        // Challenge responses skip CSRF + LoginResponseBuilt events deliberately —
        // login is not yet complete and there is no session to bind CSRF to.
        return Response::success([
            'two_factor_required' => true,
            'challenge_token'     => $challenge['token'],
            'expires_in'          => $challenge['expires_in'],
            'delivered_to'        => $challenge['delivered_to'],  // masked
        ], 'Two-factor verification required');
    }

    // Step 3 — no 2FA required. Issue the session, then shape the response through
    // the shared helper so CSRF and LoginResponseBuilt events behave exactly as today.
    $session = $this->authService->issueSession($userData, $preferredProvider);
    return $this->loginResponseShaper->shape($request, $session);
}
```

**Shared response shaper.** A new service `Glueful\Auth\LoginResponseShaper` extracts the existing CSRF + `LoginResponseBuildingEvent` + `LoginResponseBuiltEvent` block from `AuthController::login` (currently at `src/Controllers/AuthController.php:144-178`). Both `AuthController::login` (no-2FA path) and `TwoFactorController::verify` (login-purpose path) call `shape($request, $session)` so the final login response — CSRF token, events, response envelope — is identical regardless of which path the user took. Without this, the 2FA-completed login would silently drop the CSRF token and skip the login-event hooks that downstream listeners depend on.

**Token / API-key credentials bypass the 2FA gate.** `AuthenticationService::authenticate()` short-circuits at `src/Auth/AuthenticationService.php:152-173` when `$credentials['token']` or `$credentials['api_key']` is set — these flows delegate to provider-specific bearer/API-key auth and return a session payload directly. The 2FA split applies only to the username/password path; the controller routes token/api_key credentials to the unchanged `authenticate()` path **before** the `verifyCredentials` → 2FA-gate → `issueSession` chain runs. API keys carry their own scoped auth model (see `docs/API_KEYS.md`) and aren't a fit for the email-PIN second factor.

**Provider preference flows through the 2FA gate.** A user who hits `/auth/login` with `{username, password, provider: "ldap"}` and has 2FA enabled gets a challenge response (same as any other 2FA-enrolled user). The `provider` value is stashed in the PIN cache entry alongside the user array and `code_hash`. When `/2fa/verify` completes the login, `TokenManager::createUserSession()` is called with that same `provider` value — so a 2FA-gated LDAP/SAML login completes as LDAP/SAML, not silently downgraded to JWT.

**Behaviour for consumers:**
- `TWO_FACTOR_ENABLED=false` (default) → `TwoFactorService::isEnabled()` short-circuits to `false`, the branch never fires, login response is bit-for-bit identical to today.
- `TWO_FACTOR_ENABLED=true` + migration run + a given user has `two_factor_enabled = true` → branch fires, response carries `{two_factor_required: true, challenge_token, ...}`.
- `TWO_FACTOR_ENABLED=true` + migration run + user has `two_factor_enabled = false` → branch is checked but `isEnabled` returns false; normal token-issuance path runs.
- `TWO_FACTOR_ENABLED=true` + migration **not** run → `isEnabled`'s DB read of the missing column would error; the column read is guarded (see `TwoFactorService::isEnabled` in the plan). Recommended: don't flip the env var until the migration is applied. If a consumer does, the kill-switch can be flipped back to `false` to restore service immediately.

**Client contract.** Clients that don't know about 2FA see the same `{access_token, refresh_token, ...}` payload as today (since 2FA-enabled consumers explicitly opted in). Clients that handle 2FA branch on the `two_factor_required` key in the login response. The addition is strictly additive — no existing field is removed or renamed.

Subsequent `/2fa/verify` returns the access tokens. **Freshness is tracked outside the token** — a session-scoped cache marker `2fa:fresh:{sid}` is written on successful login-purpose verify (see `/2fa/disable` section). The access token itself carries no 2FA-specific claim because `TokenManager::generateTokenPair` hard-codes the payload to `sub`/`sid`/`ver` only (`src/Auth/TokenManager.php:163-167`) and drops any extra fields passed to `AccessTokenIssuer::issuePair`.

**Token issuance call shape.** `TwoFactorService::verify` for login-purpose calls **`TokenManager::createUserSession(array $user, ?string $provider = null)`** (`src/Auth/TokenManager.php:476`) — the same method `AuthController::login` uses for the no-2FA path. It generates a real `sid` (NanoID), sets `ver = 1`, writes the `auth_sessions` row, populates the refresh-token store and session cache, and returns the OIDC-shaped `{access_token, refresh_token, expires_in, user, ...}` payload. Tokens issued this way pass `TokenManager::validateSessionClaims` (`src/Auth/TokenManager.php:587-616`), which rejects empty `sid` or `ver <= 0`.

To call `createUserSession`, the framework needs a user array (uuid + email at minimum). This is achieved by:

1. `TwoFactorService::beginLogin(array $user, ?string $preferredProvider = null)` accepts a user array (the framework's `AuthController::login` has it from the just-completed credentials check) plus the requested token provider, and projects the user down to `ALLOWED_USER_FIELDS` (see cache layout § user-array allowlist).
2. The projected array is stashed in the PIN cache entry under key `2fa:pin:{jti}` alongside `code_hash`. Anything outside the allowlist (notably `password`) is silently dropped.
3. `/2fa/verify` reads the projected array back out of the cache, re-validates the user row by uuid (see step 6 of the verify flow above), and passes the array to `createUserSession`. No second DB read of profile fields; the PIN's 5-minute TTL bounds the cache lifetime.

`AccessTokenIssuer::issuePair()` alone is **not** viable for this feature: `TokenManager::generateTokenPair` writes only `sub`/`sid`/`ver` into the JWT (`src/Auth/TokenManager.php:163-167`), and a call like `issuePair(['uuid' => $u, 'sid' => '', 'ver' => 0], ...)` produces tokens that the framework rejects on the very next request via `validateSessionClaims`.

The controller code above uses `Response::success(data, message)` to produce the framework's standard `{success, message, data}` envelope — matching how every other endpoint in `AuthController` returns.

## Configuration

`config/auth.php` gets a `two_factor` block (merged through the framework's existing config layer):

```php
return [
    // ... existing auth config ...

    'two_factor' => [
        'enabled' => env('TWO_FACTOR_ENABLED', false),    // master switch — default OFF so fresh installs without the migration don't break.
        'pin_length' => (int) env('TWO_FACTOR_PIN_LENGTH', 6),
        'pin_ttl' => (int) env('TWO_FACTOR_PIN_TTL', 300),
        'challenge_ttl' => (int) env('TWO_FACTOR_CHALLENGE_TTL', 300),
        'template_name' => env('TWO_FACTOR_TEMPLATE_NAME', 'two-factor-pin'),
        'disable_freshness' => (int) env('TWO_FACTOR_DISABLE_FRESHNESS', 300),
    ],
];
```

`template_name` is the value passed to `NotificationService::send()` in the `data` payload — matching the existing `password-reset` template precedent. The `glueful/email-notification` extension owns the template file.

**Default is `false`** because the framework ships with the integration wired into `AuthController::login` but no consumer has the migration applied yet. A fresh install will:
1. Boot normally (no errors — `isEnabled` short-circuits before touching the DB).
2. Behave like a pre-2FA framework — login returns tokens directly.

Consumers opt in by (a) running the `010_AddTwoFactorEnabledToUsers` migration, (b) setting `TWO_FACTOR_ENABLED=true`, and (c) confirming `glueful/email-notification` is installed. No controller editing required.

When `two_factor.enabled = false`, the routes (`POST /2fa/enable`, `POST /2fa/verify`, `POST /2fa/disable`) are **not registered at all** — the registration in `routes/2fa.php` is wrapped in a config guard so the routes do not appear in `route:debug` and return 404 if a client hits them directly. `TwoFactorService::isEnabled()` independently returns false regardless of the column value. Both behaviours combine to make the feature a no-op when the master switch is off — both a default-safe posture for new installs and an emergency kill-switch for operators who need to roll back without redeploying.

**TTL/attempt rationale (divergence from `EmailVerification` defaults).** `EmailVerification` ships 15-minute OTP TTL with 3 attempts + 30-minute cooldown. 2FA uses tighter bounds (5-min PIN, 5 attempts/min, no internal cooldown) because login latency budget is lower (users won't tolerate a long wait) and the single-use `jti` blocklist already prevents replay — an internal cooldown adds no security value once the PIN auto-expires in 5 minutes.

## Rate limiting

`/2fa/verify` and `/2fa/enable` are brute-force / abuse surfaces and ship with rate limits attached at the route level:

| Route | Limit | Window | Keyed by |
|---|---|---|---|
| `POST /2fa/verify` | 5 attempts | 1 minute | IP |
| `POST /2fa/enable` | 3 attempts | 1 minute | IP |
| `POST /2fa/disable` | 3 attempts | 1 minute | IP |

Limits are attached via the route builder `Route::rateLimit($attempts, $perMinutes)` (`src/Routing/Route.php:297`), then `EnhancedRateLimiterMiddleware` reads them from `Route::getRateLimitConfig()`:

```php
$router->post('/2fa/verify', [TwoFactorController::class, 'verify'])
    ->rateLimit(5, 1)
    ->middleware('rate_limit');
```

**Important — string-decorator form does not work.** The middleware accepts `...$params` in its signature (`src/Api/RateLimiting/Middleware/EnhancedRateLimiterMiddleware.php:71`) but **never reads them** — at line 83 it pulls limits exclusively from `Route::getRateLimitConfig()`. So a route written as `->middleware('rate_limit:5,60')` enforces nothing. The existing `routes/auth.php` precedent uses that string form and therefore has unenforced limits in production — a pre-existing framework bug outside the scope of this feature. A follow-up issue should be opened to migrate the existing auth routes to the builder form.

**Per-user / per-claim keying:** The `Route::rateLimit($attempts, $perMinutes, $tier, $algorithm, $by)` builder accepts `$by` (`'ip' | 'user' | 'endpoint'`, default `'ip'`). 2FA routes use the default for v1. Reading a claim out of a request-body JWT (the challenge_token) is not a supported key source. If IP-only proves insufficient in practice, the follow-up is to either extend the rate-limit `$by` source to read request-body claims, or add an internal cooldown to `TwoFactorService` mirroring `EmailVerification::isRateLimited()`. Out of scope for v1.

**Why no internal cooldown layer:** `EmailVerification` enforces both a route-level limit and an internal `MAX_ATTEMPTS=3 / COOLDOWN_MINUTES=30` lockout (`src/Security/EmailVerification.php:36-39, 446-466`). 2FA deliberately ships only the route-level layer — see TTL rationale above.

## Exception hierarchy

```
\Glueful\Http\Exceptions\Domain\AuthenticationException (framework, 401)
├── InvalidChallengeTokenException     (signature / exp / purpose / blocklist)
├── InvalidTwoFactorCodeException      (wrong PIN, expired PIN, no PIN issued)
└── TwoFactorNotEnabledException       (verify called for a user with two_factor_enabled=false)

\Glueful\Http\Exceptions\Client\ForbiddenException (framework, 403)
└── TwoFactorReelevationRequiredException  (disable called without fresh verify)
```

Auth-domain failures inherit from `Domain\AuthenticationException` (matching the existing pattern — `AuthController::resetPassword` already throws this for password update failures at `src/Controllers/AuthController.php:531`). Re-elevation is a separate semantic (the caller is authenticated but lacks freshness), so it stays under `Client\ForbiddenException`. All exceptions are mapped to status codes by their base class — no per-exception renderer needed.

**Wire vs internal:** The three 401-class exceptions exist for logging, metrics, and test assertions. When `security.auth.generic_error_responses` is true (default), the response renderer collapses them to a single generic 401 message so the client cannot distinguish failure modes (see `/2fa/verify` failure handling above).

## Dependencies

- **Email delivery — `glueful/email-notification` (soft requirement).** There is no core `MailerInterface` in the framework. Email is delivered via `Glueful\Notifications\Services\NotificationService::send()`, with the `glueful/email-notification` extension providing the actual email channel (loaded dynamically by `class_exists('\Glueful\Extensions\EmailNotification\EmailNotificationProvider')` — same pattern as `EmailVerification` at `src/Security/EmailVerification.php:128`). When `two_factor.enabled = true` the extension is a soft requirement; without it, dispatch fails and the user cannot complete enrollment or login. Documented as a requirement in the README.
- **`Notifiable` recipient.** Dispatch requires a `Glueful\Notifications\Contracts\Notifiable` instance. `TwoFactorService` will follow the `sendPasswordResetEmail` pattern (`src/Security/EmailVerification.php:531-566`) and construct an anonymous `Notifiable` (or pass the `User` model directly if it already implements the contract — to be confirmed during implementation).
- **Template.** Ships an email template named `two-factor-pin` in `glueful/email-notification`'s template directory. Resolved through the `template_name` data field passed to `NotificationService::send()` — same convention as the existing `password-reset` template.
- **Cache.** PINs and the jti blocklist require a working cache driver. The framework's default Redis/array/file drivers all work.
- **JWT signing key.** Reuses the framework's existing JWT key via `Glueful\Auth\JWTService` — no new key material. Key resolved via `config($context, 'session.jwt_key')`, algorithm via `session.jwt_algorithm` (defaults to HS256).

## Security considerations

- **PIN hashed in cache.** Plaintext is in the email body and nowhere else; cache stores bcrypt hash via `OTP::hashOTP()`. Verification uses `OTP::verifyHashedOTP()`, which is constant-time via `password_verify`.
- **PIN scoped to challenge_token jti.** A PIN issued for challenge A cannot complete challenge B even for the same user.
- **Single-use challenge tokens** via `jti` blocklist with TTL matching the token's remaining lifetime.
- **Rate limiting shipped on all three routes** with conservative defaults.
- **Email security caveat.** Email-based 2FA is meaningfully weaker than TOTP / WebAuthn — a compromised email account compromises 2FA. It is widely deployed (Zoom, Slack, AWS root account, banks) and significantly stronger than no 2FA. The README will document this honestly and point users at the future `glueful/mfa` extension once it ships.
- **No SMS.** SIM-swap attacks are real and operationally trivial; not shipped, not planned.
- **PIN length:** 6 digits = 1 in 1,000,000. Combined with 5 attempts/minute rate limit and 5-minute TTL, brute-force is bounded at ~25 attempts per token = 0.0025% success rate per challenge. Acceptable for v1.
- **Replay of completed enrollment / login:** consumed `jti` blocklist with full-TTL retention prevents replay within the token's lifetime.

## Open questions

1. **Should `/2fa/disable` be in v1?** The user listed only `/2fa/enable` and `/2fa/verify`; I included `/2fa/disable` because not having it locks users out permanently. If you'd rather not ship it, the CLI `2fa:disable` admin command becomes the only path to disable. Flag if you want it dropped.
2. **Confirm api-skeleton migration ordering and idempotency.** The framework has no baseline migrations directory of its own — `find framework -type d -name migrations` returns only `src/Database/Migrations/` (infrastructure: `MigrationInterface`, `MigrationManager`). All migrations, including the `users` table itself, live in api-skeleton. So the column must be added in api-skeleton — the api-key precedent applies in spirit (cross-repo addition), but this time it's a column-on-existing-table rather than a new table. Confirm during implementation: (i) the next sequential migration number, (ii) whether the migration should be tolerant of the column already existing (in case some api-skeleton consumers have already extended `users` locally).

## Resolved during review

- **Mailer surface.** No core `MailerInterface` — dispatch goes through `NotificationService` and the `glueful/email-notification` extension. Documented in Dependencies above and as a soft requirement when `two_factor.enabled = true`. (Was Open Question 1 in the initial draft.)
- **Access-token claim for 2FA-completed login.** Originally `last_2fa_verified_at`. `TokenManager` strips custom claims at issuance (`src/Auth/TokenManager.php:163-167`), so this is unreachable. Replaced with the session-scoped `2fa:fresh:{sid}` cache marker (see `/2fa/disable` and cache layout). The future `glueful/mfa` extension is free to introduce its own custom-claim path via a TokenManager extension if it needs step-up freshness — the cache marker is a v1 concern only.

## Revisions

- **2026-05-22 — eighth review pass: provider compatibility after the split.** Three follow-ups to the previous structural pass:
  - **Token / API-key credentials bypass the 2FA gate.** `AuthenticationService::authenticate()` already short-circuits for `token` / `api_key` credentials (`src/Auth/AuthenticationService.php:152-173`), delegating to provider-specific bearer/API-key auth. The previous plan said `AuthController::login` should always call `verifyCredentials` first — that would have broken (or misrouted) those flows. **Resolved**: the new split is explicitly scoped to the username/password DB flow. The controller routes token/api_key credentials to the unchanged `authenticate()` path *before* the 2FA gate runs. Regression tests added for `{token: ...}` and `{api_key: ...}` against a 2FA-enrolled user.
  - **Provider preference now flows through the 2FA gate.** The previous plan stashed the projected user in the PIN cache but hardcoded `createUserSession($user, 'jwt')` in `TwoFactorService::verify`, so a 2FA-gated LDAP/SAML login would silently complete as JWT. **Resolved**: `beginLogin` now takes a second `?string $preferredProvider` argument, stashes it in the PIN cache entry as `preferred_provider`, and `verify` reads it back and passes it to `createUserSession`. `AuthController::login` derives the provider from `$providerName ?? ($credentials['provider'] ?? 'jwt')` (matching the existing `AuthenticationService.php:234` logic) and threads it through `beginLogin`. Regression test asserts a 2FA-gated `provider=ldap` login produces an LDAP-provider session, not JWT.
  - **Doc drift on the session-data parenthetical.** The "why a cache marker, not a token claim" section still said session-data storage was rejected because it would cross the (now-replaced) "no AuthenticationService modifications" constraint. Reworded to the actual current rationale: a cache marker is local to this feature and reversible; session-data storage would coordinate writes through `SessionCacheManager` and the session-row lifecycle, a wider blast radius.
- **2026-05-22 — seventh review pass: structural realignment of the login pipeline.** Three concrete corrections found by re-reading the framework's actual code:
  - **`AuthenticationService::authenticate()` already issues tokens.** Line 238 of `src/Auth/AuthenticationService.php` calls `TokenManager::createUserSession()` and returns the OIDC session payload, not a raw user record. The prior plan inserted the 2FA branch "between `authenticate()` and token issuance" — but there is no such gap, so the proposed branch would have run **after** session writes had already happened, violating the "challenge instead of tokens" model. **Resolved** by splitting `authenticate()` into `verifyCredentials()` (find user + status allowlist + password verify, returns `$userData`) and `issueSession()` (calls `createUserSession`). The prior top-line constraint of "no changes to `AuthenticationService::authenticate()`" was incompatible with no-token pre-2FA login; the constraint is now refined to "no changes to the credentials/status validation **logic**" — the surgical split exposes the gap without changing what gets verified.
  - **Routes are now registered behind a config guard.** Spec said routes were unregistered when `two_factor.enabled = false` but the plan registered them unconditionally. `routes/2fa.php` now wraps registration in an `env('TWO_FACTOR_ENABLED')` check so disabled-state installs return 404 from `/2fa/*` and the routes don't appear in `route:debug`.
  - **Added `Glueful\Auth\LoginResponseShaper` to preserve CSRF + login-event behaviour on the 2FA-completed login path.** Today `AuthController::login` adds a CSRF token (lines 144-159) and dispatches `LoginResponseBuildingEvent` + `LoginResponseBuiltEvent` (lines 161-176) around the response. The 2FA challenge response returns before reaching that block, and `/2fa/verify` would return the session payload from `TwoFactorController` directly — silently dropping the CSRF token and skipping the event hooks downstream listeners rely on. The shaper extracts that block into a service that both `AuthController::login` (no-2FA path) and `TwoFactorController::verify` (login-purpose path) call, so the final login response is identical regardless of which path the user took. The challenge response itself skips the shaper deliberately — login is not yet complete and there is no session to bind CSRF to.
- **2026-05-22 — sixth review pass: out-of-the-box integration (no api-skeleton recipe).** The previous revisions framed the login-flow change as a "documented recipe" that api-skeleton consumers had to hand-paste into their `AuthController::login`. That was incorrect: `AuthController` lives in the framework (`src/Controllers/AuthController.php`), not in api-skeleton — api-skeleton just routes to it. Modifying the framework's controller gives every consumer the feature out of the box.
  - **Top-line constraint refined.** "No modifications to `AuthenticationService`" still holds (credentials and status validation continue to run there unchanged), but `AuthController::login` is now explicitly modified by this feature. The 2FA branch is additive: it sits between `authenticate()` returning a verified user and the existing token-issuance call.
  - **Default `TWO_FACTOR_ENABLED=false`.** Fresh installs that haven't run the migration must not break. With the env var off, `TwoFactorService::isEnabled()` short-circuits before reading the (possibly missing) `users.two_factor_enabled` column. Consumers opt in by running the migration + flipping the env var + installing `glueful/email-notification` — no controller editing.
  - **Architecture diagram redrawn** to show the framework's `AuthController::login` orchestrating the branch directly. The api-skeleton box is gone.
  - **Login-flow section rewritten** as the actual modified controller code (with explicit allowlisted projection, `Response::success` envelope, and reference to `TokenManager::createUserSession` for the no-2FA path) rather than as a copy-paste recipe.
  - **Client contract.** Adding `two_factor_required` to the login response is strictly additive — no existing field is removed or renamed — so 2FA-unaware clients are unaffected when the master switch is off (and 2FA-aware consumers explicitly opted in by flipping it on).
- **2026-05-22 — fifth review pass closing stale-auth-snapshot bypass and final doc drift.**
  - **Re-validate the user row before issuing a session.** The cached PIN entry holds a user snapshot from `/auth/login` time, valid for up to 5 minutes. Without a recheck, `/2fa/verify` could mint a session for an account that has since been disabled, deleted, or had `two_factor_enabled` flipped off by an admin — even though the normal login flow would now reject the same credentials. `TwoFactorService::verify()` now re-reads the `users` row by uuid before calling `createUserSession`, enforces `security.auth.allowed_login_statuses` (default `['active']`) and the still-enabled flag, and throws `InvalidTwoFactorCodeException` or `TwoFactorNotEnabledException` accordingly. Gives `TwoFactorNotEnabledException` its canonical throw site (it was previously in the hierarchy but never actually thrown). `TwoFactorService` now also takes `ApplicationContext` so it can read the status-allowlist config.
  - **Doc drift fixes.** Architecture diagram updated to `hasFreshVerification(sid)` and `disable(userUuid, sid)` to match the session-scoped marker introduced in the previous revision. Resolved-during-review note about the cache marker now references `2fa:fresh:{sid}` instead of the obsolete `{user_uuid}` key.
- **2026-05-22 — fourth review pass closing freshness-scope and user-data-leak holes.** Two security-relevant fixes plus two wording cleanups:
  - **Freshness marker is now session-scoped, not user-scoped.** `2fa:fresh:{user_uuid}` blessed every active token for that user — an attacker holding a stolen still-valid access token from a different session could call `/2fa/disable` immediately after the real user completed a legitimate 2FA login. Moved to `2fa:fresh:{sid}`. The marker is written after `createUserSession()` by reading the just-issued access token's `sid` claim. `/2fa/disable` reads the current request's `sid` from the bearer token and consults the marker against it. Stolen-token piggyback regression test added at both service and controller levels.
  - **Cached user array is now allowlist-projected.** The previous revision wrote arbitrary `(array) $user` into the PIN cache entry, which would leak password hashes and internal account fields if the api-skeleton recipe passed a raw repository row. Defined `TwoFactorService::ALLOWED_USER_FIELDS` (`uuid`, `email`, `email_verified_at`, `username`, `profile`, `remember_me`, `status`) and `projectUser()` helper. `beginLogin` projects defensively before caching — even if the recipe hands over too much, only known-safe fields reach the cache. Mirrors `AuthenticationService::formatUserData()` (`src/Auth/AuthenticationService.php:355`) which `unset($user['password'])` for the same reason.
  - **Login recipe no longer uses `(array) $user`.** That cast can produce mangled private/protected property keys and may expose unwanted fields. Recipe now builds the array explicitly with named keys before passing to `beginLogin`. `beginLogin` still projects defensively as belt-and-suspenders.
  - **Goal wording clarified.** "Every install can enable 2FA without an extension" contradicted the documented `glueful/email-notification` soft requirement. Reworded: the 2FA logic itself ships in framework core; no additional auth/MFA extension is needed for the email-PIN flow; email delivery still requires `glueful/email-notification`.
- **2026-05-22 — third review pass closing the session-issuance gap.** The previous revision called `AccessTokenIssuer::issuePair(['uuid' => $u, 'sid' => '', 'ver' => 0], ...)` and flagged the empty-sid problem as an open implementation question. That was wrong: `TokenManager::validateSessionClaims` (`src/Auth/TokenManager.php:598-603`) and `SessionStore` both reject `sid === ''` or `ver <= 0`, so the tokens would fail on the next request. **Resolved** by switching to `TokenManager::createUserSession()` — the same method `AuthController::login` uses for the no-2FA path. This requires the framework to know the full user array, so `beginLogin` now takes `array $user` (uuid + email + other fields) and stashes it in the PIN cache entry. Also fixed: container key `'database'` (not `Connection::class`, which isn't registered as an alias — `src/Container/Providers/CoreProvider.php:202-203`); migration uses explicit `hasColumn` guard rather than prose; stale "register PinGenerator" and "last_2fa_verified_at claim" references removed from the plan's modified-files table and controller tests.
- **2026-05-22 — second review pass correcting concrete API mismatches.** Re-examination of the framework source found five API claims in the spec/plan that didn't hold up against the actual code:
  - `JWTService` exposes static `generate()`/`decode()` (not instance `encode()`/`decode()`); `generate()` overwrites `iat`/`exp`/`jti`, so the challenge issuer must decode-after-generate to learn the framework-chosen jti. `decode()` returns `?array` on failure rather than throwing — `verify()` checks for `null`, no try/catch.
  - `AccessTokenIssuer::issuePair($sessionData, $accessTtl, $refreshTtl, $refreshToken)` — not `issue($uuid, $claims)`. More importantly, `TokenManager::generateTokenPair` hard-codes the access-token payload to `sub`/`sid`/`ver` only (`src/Auth/TokenManager.php:163-167`) and drops any custom claims. The `last_2fa_verified_at` claim is unreachable via this path — **freshness moved to a dedicated cache marker `2fa:fresh:{user_uuid}`**. `/2fa/disable` reads the marker; `/2fa/verify` writes it on login-purpose success.
  - `EnhancedRateLimiterMiddleware` ignores route-string params (`...$params` at `src/Api/RateLimiting/Middleware/EnhancedRateLimiterMiddleware.php:71` is unused) — it reads limits only from `Route::getRateLimitConfig()`. So `->middleware('rate_limit:5,60')` enforces nothing. Switched to the `Route::rateLimit($attempts, $perMinutes)` builder form. Flagged that the existing `routes/auth.php` precedent has the same bug — pre-existing, out of scope for this feature.
  - `BaseController::__construct(ApplicationContext $context, ...)` requires the context (`src/Controllers/BaseController.php:95`). `TwoFactorController` accepts and forwards it.
  - `CoreProvider` uses `defs(): array` returning `FactoryDefinition` entries — not imperative `$container->set(...)`. Rewrote the registration snippet to match.
- **2026-05-22 — review pass aligning with framework patterns.** Replaced SHA-256 PIN hashing with `OTP::hashOTP()` (bcrypt) to match `EmailVerification`. Closed Open Question 1 (mailer surface): no core `MailerInterface` — dispatch via `NotificationService` with `glueful/email-notification` as a soft requirement. Moved auth-domain exceptions from `Client\UnauthorizedException` to `Domain\AuthenticationException` to match `AuthController::resetPassword`. Added wire-vs-internal failure handling honouring `security.auth.generic_error_responses`. Corrected JWT references (`JWTService`, `config('session.jwt_key')`, default HS256). Wrapped endpoint response payloads in the `Response::success()` envelope. Replaced `email_template` config with `template_name` (matching `password-reset` precedent). Walked back the "rate limit keyed by user_uuid claim" promise — `rate_limit:N,window` string syntax is IP-only; richer keying is a follow-up. Added TTL/attempts and single-layer-limiter rationales explaining the divergence from `EmailVerification` defaults. Added `Notifiable` recipient requirement to Dependencies. Reframed the migration-ownership question — the `009_CreateApiKeysTable` precedent doesn't actually apply to a column-on-existing-table change. Added top-line constraint that `AuthenticationService` is not modified, and a reuse-stance note explaining why `TwoFactorService` is a fresh class rather than an extension of `EmailVerification`.
- (initial draft replacing the deleted `2026-05-22-mfa-extension-design.md`)
