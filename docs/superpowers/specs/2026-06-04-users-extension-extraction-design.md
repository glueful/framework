# Users Extension Extraction — Design Spec

**Date:** 2026-06-04
**Status:** Approved for planning
**Related:** [`2026-06-03-extension-permissions-dx-design.md`](2026-06-03-extension-permissions-dx-design.md) (the permissions catalog this design reuses the "contract-in-core / impl-in-extension" pattern from)

## 1. Motivation

Glueful ships a concrete `User` model and `UserRepository` inside framework core (`src/Models/User.php`, `src/Repository/UserRepository.php`). User identity storage is application-domain concern that varies per project, yet it currently lives in the framework's spine. We want a **lean, swappable core** where the concrete user store is a first-party extension (`glueful/users`), while the **security pipeline stays in core** so every app is safe-by-default without depending on an extension.

The codebase already points this way:

- Routing, middleware, controllers, sessions, events, and the permissions subsystem operate on UUIDs + lightweight identity abstractions, **not** the concrete `User` model.
- `UserRepository::create()` already returns `roles => []` with the comment *"Roles managed by RBAC extension."*
- Aegis already owns user→role assignment (`user_roles` table, `UserRoleRepository`, `UserRoleController`).
- No user/session migrations live in framework core (they're in the api-skeleton).

The entanglement is concentrated in **one seam**: `AuthenticationService` (and `TokenManager` profile lookup) hard-depend on the concrete `UserRepository`. There is no `UserProviderInterface`. This spec introduces that seam and moves the concrete store out.

## 2. Principle: extract the user store, not the security spine

We do **not** extract authentication wholesale. The security pipeline is what most needs to be uniform across every app; externalizing it would force every project to depend on an extension just to be safe-by-default and would fragment auth behavior.

### Ownership boundary

| Concern | Owner |
|---|---|
| Auth middleware, token/session mechanics, `RequestUserContext`, security defaults, provider **contracts**, guest/null fail-closed behavior, **claims composition** | **core** (`glueful/framework`) |
| User model & storage, credentials/password verification, registration, login-identifier rules, external identity provisioning (SAML/LDAP user creation), profile, non-authorization identity facts (`status`, `is_verified`, `account_type`, `tenant_id`) | **`glueful/users`** |
| `user_roles`, roles, permissions, authorization resolution, **identity claims enrichment** (role/permission claims) | **`glueful/aegis`** (reference authz provider) |

**Invariants:**

- Core never assumes Users owns roles.
- Users never returns roles/permissions from persistence (it returns identity with empty claims).
- Aegis (or any authz provider) is the authority for role/permission claims.
- If Users owns a fact and Aegis owns a fact, they must be *different* facts. "Coarse claims" that Users may own are **non-authorization identity facts** (`is_verified`, `account_type`, `tenant_id`, `status`) — never role names like `admin`/`editor`, which belong to Aegis.

### Referential-integrity policy: principal id as an external reference

Hard database FK constraints are kept **inside an ownership boundary only**. References to a user/principal that cross a package boundary are stored as **indexed UUIDs and validated in service/application logic**, never via a cross-package FK constraint.

| Reference | Treatment |
|---|---|
| `glueful/users`: `profiles.user_uuid` → `users.uuid` | **Hard FK** (intra-package) |
| `glueful/framework` (core): `auth_refresh_tokens.session_uuid` → `auth_sessions.uuid` | **Hard FK** (intra-package — both are core-owned) |
| `glueful/framework` (core): `auth_sessions.user_uuid`, `auth_refresh_tokens.user_uuid`, `api_keys.user_uuid` → a principal | **Indexed UUID, no FK** — external principal id (the user store is a separate package) |
| skeleton: `blobs.created_by` and any app table referencing an actor | **Indexed UUID, no FK** (nullable or not as appropriate) |
| `glueful/aegis`: `user_roles.user_uuid` → a principal | **Indexed UUID, no FK**; treated as an external principal id, with existence enforced at assignment time via the identity provider rather than SQL |

Rationale: once `users` is extension-owned, any cross-package table with a DB FK into it carries a hidden hard dependency on that extension's schema and assumes the user source is always a local SQL table. That breaks down for LDAP/SAML/external identity backends and undermines the "users can be swapped/extracted" goal. We trade DB-enforced referential integrity at package boundaries for a clean, swappable extension boundary; integrity for those columns moves to the service layer. Aegis can still be commonly paired with Users in the default stack, but treating `user_uuid` as an external principal id keeps Aegis usable with alternate identity providers.

Consequence for ordering: with no cross-package FK constraints, migration ordering is no longer load-bearing for cross-package schema correctness. It still matters for intra-package FK order, seeders, and deterministic default installs (see §7).

## 3. Canonical identity type

Today there are two overlapping identity objects:

- `Glueful\Auth\UserIdentity` — used by permission voters/policies: `uuid, roles[], scopes[], attributes{}`. Not `final`. Accessors `id()/roles()/scopes()/attr()`.
- `Glueful\Auth\AuthenticatedUser` — `final`, used by `RequestUserContext`: `uuid, sessionUuid, provider, username, email, roles[], permissions[]`.

**Decision: collapse to one canonical runtime identity — `UserIdentity`.** We evolve `UserIdentity` into the single type that `UserProviderInterface` returns, that `IdentityClaimsProviderInterface` decorates, and that `RequestUserContext` exposes. We keep the name `UserIdentity` because it is already the authorization language (12 permission-subsystem files use it; Aegis uses neither identity type), so making it canonical minimizes churn — only the 4 HTTP/controller files that read `AuthenticatedUser` are migrated onto it. Document it as **"the authenticated identity plus its runtime claims," not a database user row** (it carries session/provider runtime context and authz claims, not just persisted identity fields).

The canonical `UserIdentity` is **`final` and immutable**. All extensibility flows through the open **claims bag** and the `with*()` builders (which return new instances) — never through subclassing. (Today's class is non-`final`; that is an implementation detail being replaced. Making it `final` is the cleaner contract precisely *because* enrichment is additive-via-claims, not inheritance.)

`AuthenticatedUser` is **retired in one pass, no deprecated alias** (its `sessionUuid`/`provider` runtime fields fold into `UserIdentity` as nullable fields populated at the session layer). With only 18 references across 4 controller-layer files and zero external consumers (Aegis/skeleton use neither), a clean cutover is warranted pre-release; `RequestUserContext` exposes `UserIdentity` directly.

### Shape

```php
final class UserIdentity
{
    // Identity facts — owned by the UserProvider, immutable across enrichment
    public function uuid(): string;
    public function email(): ?string;        // nullable: SSO-first identities may lack one
    public function username(): ?string;     // nullable
    public function status(): ?string;       // nullable; the one identity fact core auth acts on (see gate semantics §5)

    // Runtime auth context — null until a session is established
    public function sessionUuid(): ?string;
    public function provider(): ?string;

    // Open claims bag — populated by IdentityClaimsProviders (Aegis etc.)
    /** @return array<string,mixed> */
    public function claims(): array;
    public function claim(string $key, mixed $default = null): mixed;

    // Typed accessors BACKED BY the claims bag (not separate state)
    /** @return list<string> */ public function roles(): array;   // = claims['roles'] ?? []
    /** @return list<string> */ public function scopes(): array;  // = claims['scopes'] ?? []

    // Immutable builders
    public function withClaims(array $claims): self;              // merge claims; identity facts unchanged
    public function withSession(string $sessionUuid, string $provider): self;
}
```

Design points (these answer the review of the proposed shape):

1. **One type.** `UserProvider` returns it, `enrich()` decorates it, `RequestUserContext` exposes it. No fourth flavor of "user".
2. **`status` is first-class**, not a claim — it is the single identity fact the *core* auth pipeline legitimately acts on (reject a suspended/disabled account without understanding any app's claim vocabulary).
3. **`claims` is an open bag, but `roles()`/`scopes()` are typed accessors backed by it.** Consumers (e.g. `RegistryRoleVoter`, which calls `$user->roles()`) keep type safety; the bag stays extensible for arbitrary authz claims. No magic-string keys leak into common-path consumers.
4. **`email`/`username` are nullable and are *not* the lookup API.** `findByLogin($identifier)` is the identifier-agnostic door. We do **not** add `findByEmail`/`findByPhone`; phone/external-id live in the claims/attribute surface. This keeps the contract from rotting as identity fields become configurable (phase 5).
5. **The old `attributes{}` bag merges into `claims`** — one bag, not two. `attr($k)` becomes an alias of `claim($k)` (or is dropped if churn is low).

## 4. Core contracts

```php
namespace Glueful\Auth\Contracts;

/** Identity lookup + credential verification. Implemented by glueful/users (or any app store). */
interface UserProviderInterface
{
    public function findByUuid(string $uuid): ?UserIdentity;
    public function findByLogin(string $identifier): ?UserIdentity;          // login-identifier rules live in the impl
    public function verifyCredentials(string $identifier, string $password): ?UserIdentity; // null = invalid
}

/** Post-authentication decoration. Implemented by glueful/aegis (and any authz provider). */
interface IdentityClaimsProviderInterface
{
    public function enrich(UserIdentity $identity): UserIdentity;            // returns identity + claims
}
```

**Authentication-only by design.** The provider contract is read + verify only. Registration, profile updates, and external (SAML/LDAP) provisioning are **not** core contracts — core never provisions users. Verified: `findOrCreateFromSaml/Ldap` exist only on `UserRepository` and are not called anywhere in core's auth path. Once `UserRepository` moves into `glueful/users`, the SAML/LDAP auth extensions depend on the Users extension's own write API, not on a core contract.

### Claims composition (core)

After a successful authentication, core folds **all** registered `IdentityClaimsProviderInterface`s over the identity:

```
$identity = $userProvider->verifyCredentials($id, $pw);   // identity, empty claims
foreach ($claimsProviders as $p) {
    $identity = $reassertIdentityFacts($p->enrich($identity), $identity);
}
```

**Trust rule (enforced, not just documented):** claim providers may **add claims only**. After each `enrich()`, core re-pins the identity facts (`uuid`, `email`, `username`, `status`) from the pre-enrichment identity, so a misbehaving authz extension can change *what a user can do* but never *who the user is*. The fold merges claims; it never replaces identity.

## 5. Auth flow & fail-closed semantics

```
login
  → UserProvider::verifyCredentials()  → UserIdentity (empty claims)  [or null → reject]
  → core status gate (reject on an explicit non-active status; null = no opinion = allowed)
  → core folds IdentityClaimsProviders → enriched UserIdentity (roles/scopes/claims)
  → session/token layer attaches sessionUuid + provider, persists identity + claims
  → RequestUserContext exposes the canonical UserIdentity
```

Degraded-deployment behavior:

| Missing | Result |
|---|---|
| No `UserProvider` bound (no Users ext, no app provider) | Core binds a **null/guest provider**; `verifyCredentials` returns `null` → login **fails closed**. |
| No claims provider (no Aegis) | Authentication **succeeds**; identity carries empty roles → `RegistryRoleVoter` abstains → permission-gated routes **fail closed**. Scope/claim checks contributed by any other provider still work. |
| Account `status` is an explicit non-active value (e.g. `suspended`, `disabled`, `deactivated`, `banned`) | Core rejects at the status gate, before claims composition. A `null` status = "store has no opinion" = allowed (so stores without a status concept aren't locked out); the gate fires only on explicitly bad statuses. |

**`status` is account lifecycle, not email verification.** The core status gate concerns whether the *account* is usable — `active` vs `suspended`/`disabled`/`deactivated`/`banned`. **Email verification is a separate identity fact** (`is_verified`/`email_verified`), owned by Users (§2), and **does not block login at the core gate by default** — it is surfaced as a fact/claim for app policy to act on (e.g. block selected actions until verified). If an app wants hard *verify-before-login*, that is enforced in the Users credential flow (or by the store setting an explicit non-active `status` such as `pending_verification`), **not** by overloading the core gate. This keeps "can this account authenticate at all" (status) distinct from "is this address confirmed" (a fact).

This formalizes the seam that today is just `$session['user']['roles'] = []`.

## 6. What moves where

**Stays in core:**
- `UserProviderInterface`, `IdentityClaimsProviderInterface`, canonical `UserIdentity`.
- Claims-composition step + status gate.
- Null/guest fail-closed `UserProvider` (default binding).
- All session/token machinery, `AuthenticationManager`, JWT + API-key providers, auth middleware, `RequestUserContext`.

**Moves to `glueful/users`:**
- `User` model, `UserRepository` (renamed/refactored to implement `UserProviderInterface`).
- Credential/password verification, registration, profile flows, login-identifier rules.
- External identity provisioning (`findOrCreateFromSaml/Ldap`) + the write API the SAML/LDAP extensions consume.
- `ResetPasswordCommand` (`src/Console/Commands/Security/ResetPasswordCommand.php`).
- `Security/EmailVerification` (email OTP / verify-email) — account-lifecycle, not security-spine.
- **Rich profile + account API.** `TokenManager::getProfile()` is **removed** from core; the account/profile API in `glueful/users` owns profile behavior. Core no longer preserves a rich profile shape in the login/token response — profile is a user-store concern fetched on demand via the account API, not baked into the auth pipeline.
- The `'Glueful\Models\User'` entry in `SecureSerializer` whitelist (extension registers its own).
- **User-store schema only** (see §7): `users`, `profiles`, password-reset state, 2FA user state — shipped as the extension's own migrations via `loadMigrationsFrom(..., priority)`. **Correction (post-Phase-4):** the security-spine tables `auth_sessions`, `auth_refresh_tokens`, `api_keys` are **owned by framework core, not this extension** — the code that reads/writes them (`SessionStore`/`SessionRepository`/`TokenManager`/`RefreshTokenStore`/`ApiKeyService`) all lives in core, so co-locating their migrations with the user store was wrong (it coupled the core spine to an extension and made an alternate user store re-ship core schema). They now ship as **core foundation migrations** (see §7). The extension owns only what it is the authority for.
- **Account-lifecycle routes + controller actions** (see §8): the `AuthController` actions `verify-email`, `verify-otp`, `resend-otp`, `forgot-password`, `reset-password` and any user-CRUD/profile routes move to a controller in `glueful/users`. The `routes/auth.php` and `routes/2fa.php` account portions move with them.

**Stays in core (auth pipeline routes):**
- The token/session `AuthController` actions remain: `login`, `logout`, `validate-token`, `refresh-token`, `refresh-permissions`, `csrf-token`. `login` calls `UserProviderInterface::verifyCredentials()`; the rest are pure token mechanics. `AuthController` is split — token actions stay, account actions move (§8).

**Implemented by `glueful/aegis`:**
- `IdentityClaimsProviderInterface` — reads `user_roles`, injects role (and optionally permission) claims into the identity at auth time.
- Depends on the **core identity-provider contract**, not a hard package dependency on `glueful/users` (`user_roles.user_uuid` is an external principal id per §2, so Aegis stays usable with alternate identity providers). Its migrations use the **`DEPENDENT` priority** so that *in the default stack* (where it is commonly paired with `glueful/users`) install/seeding is deterministic; **correctness comes from contract validation at assignment time, not from SQL FK order** (see §7).

### Core consumers re-pointed at the contract (no concrete `UserRepository` in core)

Tracing reveals the user store is referenced by more core files than a naïve "move the store" implies. **No core file may reference `Glueful\Repository\UserRepository` or `Glueful\Models\User` after extraction.** Each staying consumer is re-pointed at the canonical `UserIdentity` via `UserProviderInterface::findByUuid()` only — the contract stays at three methods; no `UserProfileProviderInterface` is frozen this early (rich profile is a user-store concern, not a security-spine one).

| Core consumer | Handling |
|---|---|
| `Auth/ApiKeyAuthenticationProvider` | Replace `UserRepository` lookup with `UserProviderInterface::findByUuid()`. |
| `Auth/RefreshService` | Replace `UserRepository` lookup with `UserProviderInterface::findByUuid()`. |
| `Routing/Middleware/AdminPermissionMiddleware` | Replace user/role/admin reads with `RequestUserContext`/`UserIdentity` claims + `PermissionManager`; no `UserRepository`. |
| `Auth/TokenManager::getProfile()` | **Removed.** Profile behavior is owned by the `glueful/users` account API; tokens no longer embed a rich profile. |
| `Notifications/Services/NotificationService` | Stop resolving users via `UserRepository` in core. Move user-recipient behavior to `glueful/users`, or introduce a notification-recipient abstraction later (deferred — do not freeze it now). |
| `Console/.../RevokeTokensCommand` | If it only resolves a uuid, use `findByUuid()`; if it needs account data, it moves to `glueful/users`. Decide during Phase 4 tracing. |
| `Container/Providers/RepositoryProvider` | Drop the core `UserRepository` registration; `glueful/users` registers the real `UserProviderInterface` implementation. |
| `Auth/AuthenticationService` | Already routed through the contract (Phase 3); its remaining array-format/profile path moves to `glueful/users` in Phase 4. |

This firmly scopes core to "**resolve a `UserIdentity` by uuid/login + verify credentials**"; everything needing rich profile/account data lives in `glueful/users`.

## 7. Migration-system change (prerequisite)

The extraction requires the framework's migration runner to support **ordered migration sources**. Today `MigrationManager::getPendingMigrations()` merges core + all enabled-extension migrations into one list, sorts **lexicographically by `basename`**, and tracks applied migrations **by `basename` only** (`src/Database/Migrations/MigrationManager.php`). There is no foundation-first phase and no dependency graph, so a foundational extension whose schema/seeders other packages build on cannot be guaranteed to run first, and two packages shipping the same filename (e.g. `001_create_tables.php`) collide in the version table.

Two changes, both prerequisites for moving the users schema out:

1. **Priority-ordered sources.** `ServiceProvider::loadMigrationsFrom(string $dir, int $priority = 0)` (and the underlying `MigrationManager` registration) gains a priority. The pending-migrations sort becomes **`(priority ASC, basename ASC)`**. Lower priority runs first. Resulting global order:

   Exposed as named tiers (with a raw `int` escape hatch so finer ordering stays possible):

   ```php
   MigrationPriority::FOUNDATION = -200;  // core's own foundation schema (auth_sessions, auth_refresh_tokens, api_keys)
   MigrationPriority::IDENTITY   = -100;  // glueful/users identity/auth schema
   MigrationPriority::DEFAULT    =    0;  // app / skeleton + ordinary feature migrations
   MigrationPriority::DEPENDENT  =  100;  // aegis & co (commonly paired, ordered for seeders)
   ```

   **Priority is for deterministic ordering, not dependency correctness.** Lower runs first; within a priority, basename breaks ties. It does not replace a declared dependency where one genuinely exists.

2. **Package-scoped applied tracking.** Record applied migrations by **`source` + `basename`**, not basename alone, so two packages can each ship `001_*.php` without conflating. The `source` for **package migrations is the composer package name** (e.g. `glueful/users`, `glueful/aegis`) — consistent with the `managed_by` convention from the permissions catalog work — while **app/skeleton migrations use a single stable source `app`** (not a path or per-app name), so application migrations are unambiguous regardless of where the app lives. Migration: add a `source` column to the version table; existing rows backfill to `app`. *(Pre-release: a clean migration-history reset is acceptable; note in the plan.)*

**What ordering does and doesn't guarantee.** With no cross-package FK constraints (§2), ordering is not load-bearing for cross-package schema correctness. It still matters for: intra-package FK order (handled naturally — same source, basename order), **seeders** that insert rows referencing principals, and deterministic default installs. Where a real dependency exists, discovery/boot order and migration priority should agree so a dependent extension does not boot or migrate before what it builds on.

## 8. api-skeleton impact

The skeleton stops owning user/auth schema and gains the Users extension by default.

- **Migrations.** Remove `users`, `profiles`, `auth_sessions` from `001_CreateInitialSchema.php`; remove `008_CreateAuthRefreshTokensTable.php`, `009_CreateApiKeysTable.php`, `010_AddTwoFactorEnabledToUsers.php`. Split by ownership: `users`/`profiles`/2FA-user-state become **`glueful/users`** migrations (priority `IDENTITY`/`-100`); `auth_sessions`/`auth_refresh_tokens`/`api_keys` become **core foundation migrations** owned by `glueful/framework` (priority `FOUNDATION`/`-200`, auto-registered by core — see §7), so the skeleton drops them entirely and gets them just by depending on the framework. The skeleton **keeps** genuinely app/platform tables (`blobs` and the system tables: archive, queue, locks, scheduled jobs, notifications). Per the §2 policy, skeleton tables that reference an actor keep an **indexed `uuid` with no FK into `users`** — `blobs.created_by` drops its FK constraint (becomes an indexed uuid). App-layer logic validates principal existence via the identity provider.
- **composer.json.** Skeleton adds `glueful/users` to `require` (and Aegis stays optional/commented as today).
- **config/extensions.php.** `glueful/users` is enabled by default in the skeleton's `enabled` list; Aegis remains opt-in.
- **Routes/controllers.** Account-lifecycle routes move into the extension (§6); the skeleton continues to ship no auth routes of its own.
- **Seeders.** Unchanged (skeleton has none). Aegis's `003_SeedDefaultRoles` seeds roles/permissions and is ordered after the users schema via migration priority in the default stack; any principal-referencing seed validates existence via the identity provider rather than a DB FK.

## 9. Phasing

In scope for this spec: **phases 1–5** (migration-system change + extraction + fail-closed defaults + skeleton). The Users-internal extensibility work is a separate follow-up spec.

1. **Migration-system change (prerequisite).** Implement §7: priority-ordered sources + package-scoped applied tracking. Land first, with tests, since the extraction depends on it.
2. **Core contract + adapt + canonical identity.** Add `UserProviderInterface`, `IdentityClaimsProviderInterface`, the claims-composition fold + status gate, and the canonical `UserIdentity` (retire `AuthenticatedUser`, migrate `RequestUserContext` and voters). Re-point `AuthenticationService`/`TokenManager` credential + profile lookups at `UserProviderInterface`. Split `AuthController` (token actions stay; account actions earmarked to move).
3. **Transitional default.** Core temporarily binds the existing `UserRepository` as the default `UserProvider` so nothing breaks. (Folds into phase 2's commit.)
4. **Move storage + schema + routes out.** Relocate the user store, canonical schema migrations, and account-lifecycle routes/controller (§6) into `glueful/users`. Core's default binding flips to the **null/guest fail-closed** provider. Aegis ships its `IdentityClaimsProviderInterface` impl against the core identity-provider contract (no hard `glueful/users` dependency; `user_roles.user_uuid` is a non-FK principal id per §2); its migrations use the `DEPENDENT` priority for deterministic install/seeding in the default stack.
5. **Skeleton default.** Restructure the skeleton per §8 (drop user/auth migrations, require + enable `glueful/users`). Verify a fresh skeleton migrates cleanly, authenticates, and (with Aegis) authorizes end-to-end.

**Out of scope (follow-up spec):** extensibility *inside* Users — `UserProfileProviderInterface`/profile mapper, app-specific attribute bag/table, configurable identity fields (email/username/phone/external-id), lifecycle events (`UserRegistered`, `UserAuthenticated`, `UserUpdated`, `UserProvisioned`). Added only after the extraction is stable.

## 10. Testing strategy

- **Core, no extensions:** a fake `UserProvider` + fake `IdentityClaimsProvider` (mirrors `InMemoryPermissionProvider` from the catalog work). Assert:
  - login fails closed with the null/guest provider,
  - authentication succeeds but authorization fails closed with no claims provider,
  - status gate rejects a non-active account,
  - the enrich trust rule holds (a claims provider cannot alter `uuid`/`email`/`status`).
- **Claims composition:** multiple registered claim providers compose (fold) correctly; ordering is deterministic.
- **Aegis (cross-repo):** Aegis's `IdentityClaimsProvider` populates roles from `user_roles`; verified via the existing path-symlink harness (`extensions/aegis/vendor/glueful/framework` → `../../../../framework`). Aegis suite stays green.
- **Migration ordering:** with `glueful/users` (priority `IDENTITY`) + skeleton + Aegis (priority `DEPENDENT`) all enabled, migrations apply in the §7 order on a fresh DB and Aegis seeds after the users schema. Assert package-scoped tracking: two packages each shipping `001_*.php` both apply (no basename collision). Assert the §2 policy: skeleton/Aegis actor columns are indexed UUIDs with **no** cross-package FK constraint into `users`.

## 11. Risks & mitigations

- **Migration-history reset.** Package-scoped tracking changes the version-table schema. Mitigation: pre-release, so a clean reset is acceptable; document it in the plan and provide the schema migration with core/app backfill for any existing dev DBs.
- **Seeder ordering in the default stack.** If Aegis migrates/seeds before `glueful/users` in a paired install, principal-referencing seeds run too early. Mitigation: the `DEPENDENT` priority orders Aegis after `IDENTITY`; seeds validate principals via the identity provider (no FK to satisfy); the §10 ordering test covers it.
- **Identity-type churn.** Retiring `AuthenticatedUser` touches `RequestUserContext` and any consumer reading its fields. Mitigation: pre-release, only 18 refs in 4 files, zero external consumers — one-pass migration, no alias (decision §3).
- **Two-extension web (Users + Aegis).** Mitigated by the §2 invariants: Users owns identity facts, Aegis owns role/permission claims, they never own the same fact. Users does **not** depend on Aegis; Aegis depends on the **core identity-provider contract** (not the `glueful/users` package) and enriches via it — keeping Aegis usable with alternate identity providers.
- **SAML/LDAP extensions.** After phase 4 they depend on `glueful/users`' write API. Acceptable: provisioning is genuinely a user-store concern, not a core one.
- **Open-fail regression.** The null/guest provider + status gate ensure missing pieces fail closed, never open.

## 12. Resolved decisions

- **Canonical type name:** keep `UserIdentity` (already the authorization language; minimizes churn). Documented as "authenticated identity plus runtime claims," not a DB user row. (§3)
- **`AuthenticatedUser` removal:** one-pass migration, no deprecated alias — 18 refs / 4 controller files, zero external consumers. `RequestUserContext` exposes `UserIdentity`. (§3)
- **Migration priorities:** raw `int $priority = 0` **plus** named tiers (`MigrationPriority::FOUNDATION/IDENTITY/DEFAULT/DEPENDENT`); priority is deterministic ordering, not dependency correctness. (§7)
- **Referential integrity:** no hard cross-package FKs to users/principals — intra-package uses FKs; cross-package references store indexed UUIDs validated in the service layer (principal-id-as-external-reference, including Aegis `user_roles`). (§2)

### Deferred (not blocking)

- Whether `blobs` and other app/platform tables become their own capability extensions (e.g. `glueful/storage`) later — kept in the skeleton for now (§8). Core now **uses** the `FOUNDATION` tier for its own security-spine schema (`auth_sessions`, `auth_refresh_tokens`, `api_keys`), auto-registered via the container-built `MigrationManager` (source `glueful/framework`) — so the framework ships first-class, versioned, source-tracked migrations rather than creating those tables lazily at runtime.
