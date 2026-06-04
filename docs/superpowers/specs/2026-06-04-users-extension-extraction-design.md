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

## 3. Canonical identity type

Today there are two overlapping identity objects:

- `Glueful\Auth\UserIdentity` — used by permission voters/policies: `uuid, roles[], scopes[], attributes{}`. Not `final`. Accessors `id()/roles()/scopes()/attr()`.
- `Glueful\Auth\AuthenticatedUser` — `final`, used by `RequestUserContext`: `uuid, sessionUuid, provider, username, email, roles[], permissions[]`.

**Decision: collapse to one canonical runtime identity.** We evolve `UserIdentity` into the single type that `UserProviderInterface` returns, that `IdentityClaimsProviderInterface` decorates, and that `RequestUserContext` exposes. `AuthenticatedUser` is retired (its `sessionUuid`/`provider` runtime fields fold into the canonical type as nullable fields populated at the session layer). This is a pre-release framework, so we migrate consumers directly rather than maintaining a long-lived shim; the plan may keep a short-lived deprecated alias if consumer churn warrants it.

### Shape

```php
final class UserIdentity
{
    // Identity facts — owned by the UserProvider, immutable across enrichment
    public function uuid(): string;
    public function email(): ?string;        // nullable: SSO-first identities may lack one
    public function username(): ?string;     // nullable
    public function status(): string;        // 'active' default; the one identity fact core auth acts on

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
  → core checks status (reject if not active)
  → core folds IdentityClaimsProviders → enriched UserIdentity (roles/scopes/claims)
  → session/token layer attaches sessionUuid + provider, persists identity + claims
  → RequestUserContext exposes the canonical UserIdentity
```

Degraded-deployment behavior:

| Missing | Result |
|---|---|
| No `UserProvider` bound (no Users ext, no app provider) | Core binds a **null/guest provider**; `verifyCredentials` returns `null` → login **fails closed**. |
| No claims provider (no Aegis) | Authentication **succeeds**; identity carries empty roles → `RegistryRoleVoter` abstains → permission-gated routes **fail closed**. Scope/claim checks contributed by any other provider still work. |
| Account `status` not active | Core rejects at the status check, before claims composition. |

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
- The `'Glueful\Models\User'` entry in `SecureSerializer` whitelist (extension registers its own).
- The users/sessions migrations (live with the extension / skeleton, not core).

**Implemented by `glueful/aegis`:**
- `IdentityClaimsProviderInterface` — reads `user_roles`, injects role (and optionally permission) claims into the identity at auth time.

## 7. Phasing

In scope for this spec: **phases 1–4** (the extraction + fail-closed defaults). Phase 5 is a separate follow-up spec.

1. **Core contract + adapt + canonical identity.** Add `UserProviderInterface`, `IdentityClaimsProviderInterface`, the claims-composition fold + status gate, and the canonical `UserIdentity` (retire `AuthenticatedUser`, migrate `RequestUserContext` and voters). Re-point `AuthenticationService`/`TokenManager` credential + profile lookups at `UserProviderInterface`.
2. **Transitional default.** Core temporarily binds the existing `UserRepository` as the default `UserProvider` so nothing breaks. (Folds into phase 1's commit.)
3. **Move storage out.** Relocate the user store (§6) into `glueful/users`. Core's default binding flips to the **null/guest fail-closed** provider. Aegis ships its `IdentityClaimsProviderInterface` implementation.
4. **Skeleton default.** api-skeleton requires + enables `glueful/users` by default; users/sessions migrations ship with it. Verify a fresh skeleton authenticates and (with Aegis) authorizes end-to-end.

**Out of scope (phase 5, follow-up spec):** extensibility *inside* Users — `UserProfileProviderInterface`/profile mapper, app-specific attribute bag/table, configurable identity fields (email/username/phone/external-id), lifecycle events (`UserRegistered`, `UserAuthenticated`, `UserUpdated`, `UserProvisioned`). Added only after the extraction is stable.

## 8. Testing strategy

- **Core, no extensions:** a fake `UserProvider` + fake `IdentityClaimsProvider` (mirrors `InMemoryPermissionProvider` from the catalog work). Assert:
  - login fails closed with the null/guest provider,
  - authentication succeeds but authorization fails closed with no claims provider,
  - status gate rejects a non-active account,
  - the enrich trust rule holds (a claims provider cannot alter `uuid`/`email`/`status`).
- **Claims composition:** multiple registered claim providers compose (fold) correctly; ordering is deterministic.
- **Aegis (cross-repo):** Aegis's `IdentityClaimsProvider` populates roles from `user_roles`; verified via the existing path-symlink harness (`extensions/aegis/vendor/glueful/framework` → `../../../../framework`). Aegis suite stays green.
- **Skeleton smoke:** fresh skeleton with `glueful/users` enabled authenticates; with Aegis enabled, a role-gated route authorizes.

## 9. Risks & mitigations

- **Identity-type churn.** Retiring `AuthenticatedUser` touches `RequestUserContext` and any consumer reading its fields. Mitigation: pre-release, migrate directly; optionally a short-lived deprecated alias during phase 1.
- **Two-extension web (Users + Aegis).** Mitigated by the §2 invariants: Users owns identity facts, Aegis owns role/permission claims, they never own the same fact. Users does **not** depend on Aegis; Aegis enriches via the core contract.
- **SAML/LDAP extensions.** After phase 3 they depend on `glueful/users`' write API. Acceptable: provisioning is genuinely a user-store concern, not a core one.
- **Open-fail regression.** The null/guest provider + status gate ensure missing pieces fail closed, never open.

## 10. Open questions

- Final name of the canonical type (`UserIdentity` retained vs. a new name). Defaulting to `UserIdentity`.
- Whether to keep a deprecated `AuthenticatedUser` alias through phase 1 or migrate consumers in one pass — decide during planning based on consumer count.
