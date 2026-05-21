# API Key Hardening — Design Note

**Status:** Revised 2026-05-21 to match the executed implementation plan. See "Revisions" section below.
**Date:** 2026-05-21
**Tier:** Tier 1 (core, near-term) — per `docs/FRAMEWORK_IMPROVEMENTS.md` § 5.3

## Revisions

Two architectural decisions in the initial draft were superseded after deeper exploration of the codebase. The plan at `docs/superpowers/plans/2026-05-21-api-key-hardening.md` is authoritative; this spec has been updated to match.

1. **Migration ownership: api-skeleton, not framework.** The initial draft proposed shipping the migration in `src/Database/Migrations/files/`. Investigation showed that schema lives in `api-skeleton/database/migrations/` by convention (`001_CreateInitialSchema.php` through `008_CreateAuthRefreshTokensTable.php`). The framework owns the code surface; api-skeleton owns the schema. Migration is `009_CreateApiKeysTable.php`.

2. **Single-track auth, no legacy fallback.** The initial draft kept `users.api_key` as a fallback "so existing consumers don't break." But the canonical `users` table (`001_CreateInitialSchema.php`) **has no `api_key` column** — `UserRepository::findByApiKey()` queries a column that doesn't exist in standard installs, making the fallback dead code. Worse, the dual-track approach was a security hole: a key revoked in the new table whose plaintext still existed in a custom `users.api_key` column would re-authenticate via fallback. The provider is single-track via `ApiKeyService::verify()`; `findByApiKey()` and the four `MigrateFromUsersCommand` references are removed. Three exceptions (not the previous four-way split for fallback semantics): `InvalidApiKeyException`, `ApiKeyExpiredException`, `InsufficientScopeException`.

## Goal

Introduce a dedicated `api_keys` table that supports scopes, IP allowlists, expiration, rotation with grace period, and environment-prefixed keys. The framework provides the code surface (model, service, provider, route attribute, middleware, CLI); api-skeleton provides the schema migration following the established convention.

## Non-goals (deferred)

- **Usage tracking** — `last_used_at`, per-key request counts, aggregate stats. Write-heavy; deserves its own design pass with retention policy.
- **OAuth2 server** — different extension (`glueful/oauth2-server`, Tier 2).
- **Legacy `users.api_key` migration helper** — no canonical install has the column; nothing to migrate.
- **Per-key rate limits as enforcement** — the column is reserved but actual enforcement remains the responsibility of the rate-limit middleware.
- **Per-key audit log** — separate concern.

## Schema

Expressed through `Glueful\Database\Schema\SchemaBuilderInterface` — **not** raw SQL — so the same migration runs against MySQL, PostgreSQL, and SQLite. Inline SQL with `INDEX ... INSIDE CREATE TABLE` is MySQL-specific and would not portable as written.

The migration file (`api-skeleton/database/migrations/009_CreateApiKeysTable.php`) defines a single `api_keys` table via the schema builder. Filename follows api-skeleton convention (`<NN>_<PascalCaseClassName>.php`):

| Column | Type | Constraints | Purpose |
|---|---|---|---|
| `id` | `bigIncrements` | primary key | Internal row identifier |
| `uuid` | `string(12)` | unique | Public identifier exposed in API responses. **Length 12 matches the framework's existing convention** (nanoid-style, see JobScheduler, DatabaseQueue, WebhookDispatcher, DatabaseLogHandler, ApiMetricsService, the migration scaffold template — all use `string('uuid', 12)`). Not RFC 4122. |
| `user_id` | `string(12)` | not null, indexed | App-level reference to `users.uuid`; no DB FK (cross-driver / cross-schema portability). Same 12-char convention. |
| `name` | `string(255)` | not null | Developer-facing label |
| `key_prefix` | `string(24)` | not null, indexed | First 16 chars of the plaintext key (e.g. `gf_live_a1b2c3d4`). Indexed for O(1) lookup. |
| `key_hash` | `string(64)` | not null, **unique** | SHA-256 hex of the full key. Unique constraint guarantees no accidental duplicate rows. |
| `scopes` | `text` | nullable | JSON array (e.g. `["read:*","write:posts"]`). Null = no scope restriction (full access). |
| `allowed_ips` | `text` | nullable | JSON array of CIDR/IPs. Null = no IP restriction. |
| `expires_at` | `timestamp` | nullable | Null = never expires. |
| `rotated_from_id` | `bigInteger` | nullable | Self-reference (app-level) to the key this one was rotated from. |
| `revoked_at` | `timestamp` | nullable | Soft revocation timestamp. |
| `created_at` | `timestamp` | not null, default `CURRENT_TIMESTAMP` | |
| `updated_at` | `timestamp` | not null, default `CURRENT_TIMESTAMP` | |

Indexes: `unique(uuid)`, `unique(key_hash)`, `index(user_id)`, `index(key_prefix)`.

**Migration sketch (illustrative — final code uses the actual schema builder API):**

```php
$schema->create('api_keys', function ($t) {
    $t->bigIncrements('id');
    $t->string('uuid', 12)->unique();
    $t->string('user_id', 12);
    $t->string('name', 255);
    $t->string('key_prefix', 24);
    $t->string('key_hash', 64)->unique();
    $t->text('scopes')->nullable();
    $t->text('allowed_ips')->nullable();
    $t->timestamp('expires_at')->nullable();
    $t->bigInteger('rotated_from_id')->nullable();
    $t->timestamp('revoked_at')->nullable();
    $t->timestamps();

    $t->index('user_id');
    $t->index('key_prefix');
});
```

The actual `SchemaBuilderInterface` shape may differ — the implementer should mirror existing framework migrations / extension migrations to match the real API. The semantic contract above is what matters.

## Key format

- **Plaintext:** `gf_live_<32-char-base62>` (production) or `gf_test_<32-char-base62>` (non-production)
- **Entropy:** 32 chars base62 ≈ 190 bits — brute force is computationally infeasible.
- **Prefix:** First 16 chars (e.g. `gf_live_a1b2c3d4`). Stored as `key_prefix` for indexed lookup.
- **Hash:** SHA-256 of the full plaintext key, stored as hex in `key_hash`.
- **Environment selector:** Resolved at creation from `APP_ENV` — `production` → `gf_live_`, anything else → `gf_test_`.

### Why SHA-256, not bcrypt

API keys have ~190 bits of entropy from generation. Brute force is computationally infeasible regardless of hash speed. bcrypt's slow hashing exists to make low-entropy passwords brute-resistant; using it on high-entropy keys adds 50–200ms per auth request for zero additional security. Industry practice (Stripe, GitHub, GitLab) uses SHA-256 for the same reason.

## File list

### New files

| File | Responsibility |
|---|---|
| `api-skeleton/database/migrations/009_CreateApiKeysTable.php` | Schema migration (lives in api-skeleton, NOT framework) |
| `src/Auth/ApiKey/ApiKey.php` | ORM model on the `api_keys` table |
| `src/Auth/ApiKey/ApiKeyService.php` | Generation, hashing, lookup, verification, rotation |
| `src/Auth/ApiKey/Support/CidrMatcher.php` | ~20-line CIDR/IP matcher |
| `src/Auth/ApiKey/Exceptions/InvalidApiKeyException.php` | extends `AuthenticationException`. Any auth-key failure other than expiration (not found, hash mismatch, revoked, IP-blocked). |
| `src/Auth/ApiKey/Exceptions/ApiKeyExpiredException.php` | extends `AuthenticationException`. Kept distinct so consumers can produce a specific "your key expired" diagnostic. |
| `src/Auth/ApiKey/Exceptions/InsufficientScopeException.php` | extends `AuthorizationException`. Thrown by `RequireScopeMiddleware`. |
| `src/Routing/Attributes/RequireScope.php` | Route attribute: `#[RequireScope('write:posts')]` |
| `src/Routing/Middleware/RequireScopeMiddleware.php` | Enforces declared scopes at route level |
| `src/Console/Commands/ApiKey/CreateCommand.php` | `php glueful apikey:create` |
| `src/Console/Commands/ApiKey/RotateCommand.php` | `php glueful apikey:rotate` |
| `src/Console/Commands/ApiKey/RevokeCommand.php` | `php glueful apikey:revoke` |
| `src/Console/Commands/ApiKey/ListCommand.php` | `php glueful apikey:list` |
| `tests/Unit/Auth/ApiKey/ApiKeyServiceTest.php` | Generation, hash, verify, scope match, IP allowlist |
| `tests/Unit/Auth/ApiKey/CidrMatcherTest.php` | CIDR matching edge cases (IPv4, single IP, /32) |
| `tests/Integration/Auth/ApiKeyAuthenticationTest.php` | Full HTTP flow: header extraction, table lookup, scope enforcement, rotation grace |

### Modified files

| File | Change |
|---|---|
| `src/Auth/ApiKeyAuthenticationProvider.php` | All four `AuthenticationProviderInterface` methods (`authenticate`, `validateToken`, `refreshTokens`, `generateTokens`) updated to use `ApiKeyService::verify()`. Single-track — no legacy `users.api_key` lookup. Populate `api_key_scopes` on the request. |
| `src/Repository/UserRepository.php` | Remove `findByApiKey()` — zero callers after the provider switches off it (org-wide grep verified across framework, extensions, api-skeleton, and other repos). |
| `src/Container/Providers/CoreProvider.php` | Register `ApiKeyService` and the `require_scope` middleware alias (matching how `rate_limit` is wired). |
| `src/Routing/AttributeRouteLoader.php` | Add `processRequireScopeAttributes()` mirroring `processRateLimitAttributes()`. Collects all `#[RequireScope]` instances, calls `$route->setRequireScopeConfig($configs)`, and auto-attaches the `require_scope` middleware. |
| `src/Routing/Route.php` | Add `setRequireScopeConfig()` and `getRequireScopeConfig()` (parallel to `setRateLimitConfig`/`getRateLimitConfig`). |
| `src/Routing/Router.php` | Set `_route` and `_route_params` on `$request->attributes` after match, before middleware execution — so `RequireScopeMiddleware` can read route-level metadata. |
| `src/Console/Commands/ApiKey/` registration | Register the new CLI commands wherever other framework console commands are registered (existing pattern). |
| `CLAUDE.md` | Pointer bullet under Authentication. |
| `docs/FRAMEWORK_IMPROVEMENTS.md` | Flip 5.3 row to ✅ after implementation. |
| `CHANGELOG.md` | Unreleased entry. |

### Migration location

The migration lives in `api-skeleton/database/migrations/009_CreateApiKeysTable.php`, following the established convention (`<NN>_<PascalCaseClassName>.php`, sequential — 009 because the latest is `008_CreateAuthRefreshTokensTable.php`). The framework owns the code surface; api-skeleton owns the schema. No framework-side `addMigrationPath()` call is needed because the migration lives in the consumer's existing migrations directory.

Other consumers using the framework without api-skeleton write their own equivalent migration. The integration test in the framework repo creates the `api_keys` table directly via PDO to stay self-contained.

## API surface

```php
use Glueful\Auth\ApiKey\ApiKeyService;

// Create — returns the plaintext key ONCE; never stored, never retrievable
[$plainKey, $apiKey] = ApiKeyService::create($context, [
    'user_id'     => $user->uuid,
    'name'        => 'Production Key',
    'scopes'      => ['read:*', 'write:posts'],
    'allowed_ips' => ['192.168.1.0/24'],
    'expires_at'  => '2027-05-21 00:00:00',  // optional; null = never expires
]);

// Verify (used internally by ApiKeyAuthenticationProvider)
$apiKey = ApiKeyService::verify($context, $plainKey, $clientIp);
// Returns ApiKey instance on success
// Throws InvalidApiKeyException / ApiKeyExpiredException on failure

// Rotate — creates a new key, sets old key's expires_at to now + grace
$rotation = ApiKeyService::rotate($context, $apiKey, graceHours: 24);
// Returns ['old_uuid' => ..., 'new_plain_key' => ..., 'old_expires_at' => ...]
// Both keys are valid until grace period elapses

// Revoke — immediate, sets revoked_at
ApiKeyService::revoke($context, $apiKey);

// List for a user
$keys = ApiKeyService::forUser($context, $userId);
```

### Route attribute

`RequireScope` is declared `IS_REPEATABLE` (matching `RateLimit` and `Middleware`):

```php
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RequireScope
{
    /** @var string[] */
    public readonly array $scopes;

    /** @param string|string[] $scopes  Multiple scopes within one attribute = OR. */
    public function __construct(string|array $scopes) { ... }
}
```

**Combining semantics:**

- **Within one attribute**, multiple scopes are **OR**: `#[RequireScope(['read:posts', 'admin:posts'])]` passes if the key has either scope.
- **Across multiple attributes**, semantics are **AND**: each attribute must independently pass.

```php
#[Get('/posts')]
#[RequireScope('read:posts')]
public function index(): Response { ... }

#[Post('/posts/{id}/publish')]
#[RequireScope(['write:posts', 'admin:posts'])]   // OR within attribute
#[RequireScope('publish:posts')]                   // AND across attributes
public function publish(int $id): Response { ... }
```

**Wiring (mirrors the RateLimit pattern):**

1. `AttributeRouteLoader::processRequireScopeAttributes($method, $route)` calls `$method->getAttributes(RequireScope::class)` and collects all instances (the `IS_REPEATABLE` flag is what makes this return more than one).
2. Each instance is normalized to its scope list; the loader passes the full collection to `$route->setRequireScopeConfig($configs)`.
3. **The loader also calls `$route->middleware('require_scope')` automatically** when at least one `RequireScope` attribute was found. This makes the attribute self-contained — developers don't need to remember to add the middleware to the route group. Without this step, `setRequireScopeConfig()` stores metadata that nothing reads, because `RequireScopeMiddleware` only runs if it's in the route's middleware pipeline. The auto-attach is the load-bearing connection between attribute and enforcement.
4. `RequireScopeMiddleware` reads `$route->getRequireScopeConfig()`, iterates each attribute's scope list, and checks that the request's scopes (populated by `ApiKeyAuthenticationProvider` into `request->attributes->get('api_key_scopes')`) satisfy each one (AND across attributes, OR within).
5. Container registers `require_scope` as a middleware alias.

If `RequireScope` were declared **without** `IS_REPEATABLE`, stacking the attribute would either be a parse error or silently keep only the last instance — neither acceptable. The flag is load-bearing.

**Idempotency note:** If the route group already includes `require_scope` middleware manually (legacy or explicit), the auto-attach in step 3 should not double-add. `Route::middleware()` either deduplicates or the loader checks before adding — either approach works; pick whichever matches existing framework patterns for similar attributes.

## CLI commands

```
php glueful apikey:create --user=<uuid> --name="Prod Key" \
    --scopes="read:*,write:posts" \
    --expires=+1year \
    --ips="192.168.1.0/24,203.0.113.42"

php glueful apikey:list --user=<uuid>
php glueful apikey:rotate <api-key-uuid> --grace=24h
php glueful apikey:revoke <api-key-uuid>
```

`create` prints the plaintext key to stdout exactly once, with a clear "save this now — it will not be shown again" warning. Subsequent commands accept the UUID, not the plaintext key.

## Behavior matrix

| Scenario | Outcome |
|---|---|
| Valid new-table key, within expiration, IP allowed, scope sufficient | 200 |
| Valid key but client IP not in `allowed_ips` | 401, message `Invalid API key` (don't leak why) |
| Valid key but past `expires_at` | 401, message `Expired API key` |
| Valid key but `revoked_at` is set | 401, message `Invalid API key` (don't distinguish from unknown) |
| Valid key but scope insufficient for route | 403, `InsufficientScopeException` |
| Unknown prefix or bad hash | 401, `Invalid API key` |
| Both old and rotated key in grace window | Both 200 |

## Non-obvious decisions

1. **SHA-256, not bcrypt.** Explained above. ~190 bits entropy → brute force not a concern.
2. **`key_prefix` indexed for O(1) lookup; lookup is collision-tolerant.** Avoids scanning every row to compare hashes. `verify()` fetches ALL prefix candidates and `hash_equals` each — defensive against the statistically-impossible prefix collision. The `UNIQUE` constraint on `key_hash` is the actual guarantee.
3. **Environment-distinguishing prefix (`gf_live_` / `gf_test_`).** Resolved from `APP_ENV` at creation. Lets apps refuse cross-environment key usage (future enforcement, not in this round).
4. **No DB-level FK to `users`.** Cross-driver portability — SQLite, MySQL, and PostgreSQL all handle FKs differently, and consumer apps may model `users` differently. Application-level integrity is sufficient.
5. **Single-track auth.** Provider verifies via the new `api_keys` table only — no legacy `users.api_key` fallback. The canonical schema (`001_CreateInitialSchema.php`) doesn't have that column; the previous dual-track design referenced dead code and introduced a security hole (a revoked key whose plaintext still existed in a custom column would re-authenticate).
6. **CIDR matching inline.** ~20 lines using `inet_pton` and bit comparison. A library dependency would be over-engineered.
7. **Scope grammar: colon-separated with wildcards.** `read:*` matches `read:users`, `read:posts`. `*` matches anything (admin). `fnmatch`-style matching. No nested scopes beyond two segments in this round.
8. **Rotation creates a new key, expires the old.** `rotate()` doesn't mutate the old key's secret in place. It creates a new row with `rotated_from_id` referencing the old, and sets the old key's `expires_at` to `now + graceHours`. This is the only safe way to roll without downtime.
9. **Default expiration is null (never expires).** Explicit opt-in via `--expires` flag. Forcing expiration would surprise consumers used to the current model.
10. **Migration lives in api-skeleton, not framework.** Follows the established convention where the framework owns code and api-skeleton owns schema. No framework-side `MigrationManager::addMigrationPath()` call needed.

## Provider behavior — exception translation

The framework's auth-provider contract is: catch exceptions, set `$this->lastError`, return `null` so other providers can try. `ApiKeyService::verify()` **throws** on auth failures (`InvalidApiKeyException`, `ApiKeyExpiredException`). The provider translates exceptions back to the `lastError + null` contract — it does not change the provider's external interface.

```php
public function authenticate(Request $request): ?array
{
    $this->lastError = null;
    $apiKey = $this->extractApiKeyFromRequest($request);
    if ($apiKey === null || $apiKey === '') {
        $this->lastError = 'API key not found in request';
        return null;
    }

    try {
        $key = ApiKeyService::verify(
            $this->context,
            $apiKey,
            $request->getClientIp() ?? ''
        );

        $userData = $this->getUserRepository()->find($key->user_id);
        if ($userData === null) {
            $this->lastError = 'API key belongs to no known user';
            return null;
        }

        $request->attributes->set('authenticated', true);
        $request->attributes->set('user_id', $key->user_id);
        $request->attributes->set('user_data', $userData);
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('api_key_scopes', $key->getScopes());

        return $userData;
    } catch (ApiKeyExpiredException) {
        $this->lastError = 'Expired API key';
        return null;
    } catch (InvalidApiKeyException) {
        $this->lastError = 'Invalid API key';
        return null;
    } catch (\Throwable $e) {
        $this->lastError = 'Authentication error: ' . $e->getMessage();
        return null;
    }
}
```

All four `AuthenticationProviderInterface` methods (`authenticate`, `validateToken`, `refreshTokens`, `generateTokens`) are updated to this single-track pattern. Scope enforcement happens later in `RequireScopeMiddleware`, after authentication completes. The provider's job is only to populate `api_key_scopes` on the request.

## Migration strategy for existing data

There is no existing data to migrate. `UserRepository::findByApiKey()` queries `users.api_key` — a column that doesn't exist in the canonical `001_CreateInitialSchema.php`. Any install that adopted the previous `ApiKeyAuthenticationProvider` was either running custom schema or wasn't actually authenticating via API key.

`findByApiKey()` is removed as part of this change (verified zero callers across the framework, all extensions, api-skeleton app code, and other org repos).

## Open questions for review

1. **CLI command namespace** — `apikey:*` vs `auth:keys:*`? Existing commands use both shallow (`migrate:run`) and nested (`security:scan`) patterns. **Recommend `apikey:*`** for brevity and consistency with framework convention.

2. **Should the `apikey:create` CLI print the plaintext key as the last line of output (for shell substitution), or in a labeled box?** **Recommend labeled box** with explicit "save this now" warning to prevent accidental scrollback exposure.

**Resolved during review:**

- **`RequireScope` semantics** — Resolved: OR within a single attribute's scope list, AND across multiple attributes. Attribute marked `IS_REPEATABLE`. See "Route attribute" section.

- **Migration location** — Resolved: `api-skeleton/database/migrations/009_CreateApiKeysTable.php` (consumer-owned schema). No framework-side `addMigrationPath()` call needed.

- **Legacy fallback** — Resolved: dropped entirely. `users.api_key` doesn't exist in the canonical schema; the fallback was dead code and a security hole.

## Testing approach

- **Unit:** `ApiKeyService` (generation determinism, hash comparison, scope matching, IP allowlist), `CidrMatcher` (edge cases including /32, single IP, malformed input).
- **Integration (framework repo):** Booted framework + in-memory SQLite + `api_keys` table created inline via PDO (migration lives in api-skeleton; we don't cross-couple test environments). Covers: service-level (verify, rotate, revoke, IP allowlist, expiration) and provider-level (authenticate, revoked key returns null, unknown key returns null).
- **Migration verification (api-skeleton repo):** `php -l 009_CreateApiKeysTable.php`; ideally run the migration in api-skeleton's test environment.

Reuse the booted-framework harness from the N+1 detection and health probe tests (`Framework::create(...)->boot(allowReboot: true)`, with `RouteManifest::reset()` for tests that dispatch through the router).

## Out of scope (future work)

- **Usage tracking** (`last_used_at`, request counts) — own design pass.
- **Per-key rate limit enforcement** — column reserved; enforcement lives in rate-limit middleware.
- **Audit log of key actions** (created, rotated, revoked) — could ship later if demanded.
- **Cross-environment refusal** (`gf_live_` rejected in non-prod, `gf_test_` rejected in prod) — prefix distinguishes; enforcement is a follow-up.
