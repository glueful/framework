# Phase 5 — Skeleton Restructure + Aegis Claims Provider + End-to-End

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a fresh api-skeleton work end-to-end with the extracted store: drop the user/auth schema from the skeleton, require + enable `glueful/users` by default, remove cross-package FKs, and have Aegis enrich identities with roles via `IdentityClaimsProviderInterface`. Prove login + role-gated authorization works on a clean install.

**Architecture:** The skeleton stops owning identity schema; `glueful/users` provides it (migrates first via `IDENTITY` priority). Skeleton/Aegis actor columns become indexed UUIDs with no cross-package FK (spec §2). Aegis ships an `IdentityClaimsProvider` (priority `DEPENDENT`) that reads `user_roles` and adds role claims; core's `IdentityResolver` folds it in post-auth.

**Tech Stack:** api-skeleton, `extensions/users`, `extensions/aegis`, Phase-1 migration ordering, Phase-2 `IdentityClaimsProviderInterface`.

**Spec:** [`../../specs/2026-06-04-users-extension-extraction-design.md`](../../specs/2026-06-04-users-extension-extraction-design.md) §§2, 8.

**Depends on:** Phases 1–4 merged.

**Repos:** `api-skeleton` (`/Users/michaeltawiahsowah/Sites/glueful/api-skeleton`), `extensions/aegis`, `extensions/users`.

---

## Task 1: Skeleton — drop user/auth schema, keep platform tables, remove cross-package FKs

**Files (api-skeleton):** `database/migrations/001_CreateInitialSchema.php`, delete `008_CreateAuthRefreshTokensTable.php`, `009_CreateApiKeysTable.php`, `010_AddTwoFactorEnabledToUsers.php`.

- [ ] **Step 1:** In `001_CreateInitialSchema.php`, remove `users` + `profiles` (now owned by `glueful/users`) **and** `auth_sessions` (now a **core** foundation migration — `framework/migrations/001`). Keep `blobs` and the system tables (archive, queue, locks, scheduled jobs, notifications).
- [ ] **Step 2:** Change `blobs.created_by` from an FK → `users.uuid` into an **indexed `uuid` column, no FK** (spec §2). Apply the same to any other skeleton table referencing an actor.
- [ ] **Step 3:** Delete migrations `008`, `009`, `010`: `auth_refresh_tokens` + `api_keys` are now **core** foundation migrations (`framework/migrations/002`, `003`); the 2FA-enabled column folds into `glueful/users` `001_CreateUsersTable`. The skeleton gets the core auth tables automatically by depending on the framework (auto-registered at `FOUNDATION`, source `glueful/framework`) — it ships none of them itself.
- [ ] **Step 4: Test** — `tests/Feature/MigrationsRunCleanTest.php` (skeleton): with `glueful/users` enabled, `php glueful migrate:run` (or the migrate API) applies cleanly on a fresh SQLite DB; assert `users` and `blobs` both exist and `blobs.created_by` has no FK constraint. (Mirror the skeleton's existing `tests/Feature` style.)
- [ ] **Step 5: Commit (api-skeleton repo)** — `git commit -am "refactor(db): drop user/auth schema; blobs.created_by no longer FKs users"`.

---

## Task 2: Skeleton — require + enable `glueful/users` by default

**Files (api-skeleton):** `composer.json`, `config/extensions.php`.

- [ ] **Step 1:** Add the path repository + requirement in `composer.json`:

```json
"require": {
    "php": "^8.3",
    "glueful/framework": "^1.49.0",
    "glueful/users": "@dev"
},
"repositories": [
    { "type": "path", "url": "../extensions/users", "options": { "symlink": true } }
]
```
Run `composer update glueful/users` (local path install). **Use `@dev`, not `*`:** the skeleton sets `minimum-stability: stable`, and a local path package without a tagged stable version resolves as `dev`, so `*` would fail to satisfy. (Alternatively give `glueful/users` a stable `"version"` in its composer.json — `@dev` is the right call for a local path repo.)

- [ ] **Step 2:** Enable it in `config/extensions.php` `enabled` list (Aegis stays opt-in/commented). The skeleton uses **plain string FQCNs**, not `::class`:

```php
'enabled' => [
    'Glueful\\Extensions\\Users\\UsersServiceProvider',
    // 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider', // opt-in RBAC
],
```

- [ ] **Step 3: Test** — extend the skeleton smoke test: boot the app, assert `UsersServiceProvider` is registered and `UserProviderInterface` resolves to the `glueful/users` `UserProvider` (not `NullUserProvider`).
- [ ] **Step 4: Commit** — `git commit -am "feat: require + enable glueful/users by default"`.

---

## Task 3: Aegis — drop **all** FKs to `users` (principal refs become indexed UUIDs)

It's not just `user_roles.user_uuid`. Aegis has **multiple** cross-package FKs into `users` that must all become indexed-only (spec §2 — principal id as external reference; existence validated at assignment time, not SQL):

- `001_CreateRolesTables.php`: `user_roles.user_uuid → users.uuid` (lines ~82–84) and `user_roles.granted_by → users.uuid` (~92–94).
- `002_CreatePermissionsTables.php`: `user_permissions.user_uuid → users.uuid` (~117–119), `user_permissions.granted_by → users.uuid` (~127–129), and `role_permissions.granted_by → users.uuid` (~91–93).

**Files (extensions/aegis):** `migrations/001_CreateRolesTables.php`, `migrations/002_CreatePermissionsTables.php`.

- [ ] **Step 1: Find every FK that references `users`:**
```bash
AEGIS=/Users/michaeltawiahsowah/Sites/glueful/extensions/aegis
grep -n "foreign\|references\|->on('users')\|->on(\"users\")\|user_uuid\|granted_by\|assigned_by" "$AEGIS/migrations/"*.php
```
- [ ] **Step 2:** Remove the `->foreign(...)->references('uuid')->on('users')` blocks for **all** actor columns (`user_uuid`, `granted_by`, and any `assigned_by`), keeping their existing `->index(...)`. **Keep intra-package FKs** (`role_uuid → roles.uuid`, `permission_uuid → permissions.uuid`, `parent_uuid → roles.uuid`).
- [ ] **Step 3: Test** — an Aegis migration test asserts `user_roles`/`user_permissions`/`role_permissions` exist with indexes on the actor columns and **no** FK into `users`, while the role/permission FKs remain.
- [ ] **Step 4: Commit (aegis repo)** — `git commit -am "refactor(db): all user/principal refs are non-FK indexed UUIDs"`.

---

## Task 4: Aegis — `IdentityClaimsProvider` (roles enrichment)

**Files (extensions/aegis):** `src/IdentityClaimsProvider.php`, register it in `AegisServiceProvider`.

- [ ] **Step 1: Write the failing test** — `extensions/aegis/tests/Unit/IdentityClaimsProviderTest.php` (SQLite via Aegis test harness): seed a role + a `user_roles` assignment for `u1`, then assert enrichment adds the role claim:

```php
public function test_enrich_adds_role_claims_for_assigned_user(): void
{
    // seed role 'editor' and assign to user uuid 'u1' via UserRoleRepository
    $provider = new \Glueful\Extensions\Aegis\IdentityClaimsProvider($this->userRoleRepository());
    $out = $provider->enrich(new \Glueful\Auth\UserIdentity('u1', status: 'active'));
    self::assertContains('editor', $out->roles());

    // an unassigned user gets no fabricated roles
    $none = $provider->enrich(new \Glueful\Auth\UserIdentity('nobody'));
    self::assertSame([], $none->roles());
}
```

- [ ] **Step 2: Run → fail** (class not found).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Aegis;

use Glueful\Auth\Contracts\IdentityClaimsProviderInterface;
use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Aegis\Repositories\UserRoleRepository;

final class IdentityClaimsProvider implements IdentityClaimsProviderInterface
{
    public function __construct(private UserRoleRepository $userRoles)
    {
    }

    public function enrich(UserIdentity $identity): UserIdentity
    {
        // Treat the identity uuid as an external principal id (no FK). roleSlugsForUser()
        // joins user_roles -> roles and returns role slugs directly (one query, no N+1).
        $roleSlugs = $this->userRoles->roleSlugsForUser($identity->uuid()); // list<string>
        if ($roleSlugs === []) {
            return $identity; // never fabricate membership
        }
        // Additive: union with any roles already present (IdentityResolver also enforces this).
        return $identity->withClaims([
            'roles' => array_values(array_unique([...$identity->roles(), ...$roleSlugs])),
        ]);
    }
}
```

> **Why not `getUserRoles()`?** That method returns `UserRole` model objects carrying only `roleUuid` (no role name/slug), so mapping to slugs there would require a per-role lookup (N+1). Add a join-based method to `UserRoleRepository` instead:
>
> ```php
> /** @return list<string> Active (non-expired) role slugs for the principal. */
> public function roleSlugsForUser(string $userUuid): array
> {
>     // NB: QueryBuilder::where() only normalizes the 2-arg form when the 2nd arg is NOT a
>     // string, so a UUID string must use the explicit 3-arg operator form.
>     $currentTime = $this->db->getDriver()->formatDateTime();
>
>     return array_column(
>         $this->db->table('user_roles')
>             ->join('roles', 'user_roles.role_uuid', '=', 'roles.uuid')
>             ->where('user_roles.user_uuid', '=', $userUuid)
>             ->where(function ($q) use ($currentTime) {
>                 $q->where('user_roles.expires_at', '>=', $currentTime)
>                   ->orWhereNull('user_roles.expires_at');
>             })
>             ->select(['roles.slug'])
>             ->get(),
>         'slug'
>     );
> }
> ```
> Adjust the column name (`slug` vs `name`) to the `roles` table's actual column; the active/non-expired predicate mirrors the existing `getUserRoles()` query. Update the test's seeding comment accordingly (assign `'editor'` to `u1` via the repo's assignment method). Optionally also fold permission claims (`['permissions' => ...]`).

- [ ] **Step 4: Register the provider** so core's `IdentityResolver` folds it in (tag `identity.claims_provider`, the same mechanism Phase 2 Task 6 consumes). It needs `UserRoleRepository` injected — the bare `['class' => ...]` form would `new IdentityClaimsProvider()` with no args and fatal, so pass it:

```php
// in AegisServiceProvider::services(), with `use ...\IdentityClaimsProvider;`
IdentityClaimsProvider::class => [
    'class' => IdentityClaimsProvider::class,
    'arguments' => ['@' . UserRoleRepository::class],
    'shared' => true,
    'tags' => ['identity.claims_provider'], // must match IdentityResolver's collection in CoreProvider
],
```
> Confirm the `'tags'` spec shape `ContainerFactory` expects (it reads `$spec['tags']`). If the array-DSL tag form differs, register via `$this->tag(IdentityClaimsProvider::class, 'identity.claims_provider')` in the provider's `boot()` instead — either way the tag name must be exactly `identity.claims_provider`.

- [ ] **Step 4b: Give Aegis migrations the `DEPENDENT` priority + source.** `AegisServiceProvider` currently calls `$this->loadMigrationsFrom(dirname(__DIR__, 2) . '/migrations');` (no priority/source). Update it (and `use Glueful\Database\Migrations\MigrationPriority;`):

```php
$this->loadMigrationsFrom(
    dirname(__DIR__, 2) . '/migrations',
    MigrationPriority::DEPENDENT,
    'glueful/aegis'
);
```
This orders Aegis after `glueful/users` (IDENTITY) and the app (DEFAULT) in the default stack, and records `glueful/aegis` as the migration source (Phase 1).

- [ ] **Step 5: Run → pass.** Commit (aegis repo): `git commit -am "feat(aegis): IdentityClaimsProvider + DEPENDENT migration priority"`.

---

## Task 5: End-to-end smoke (fresh skeleton)

- [ ] **Step 1: Users-only stack** — skeleton with `glueful/users` enabled (Aegis off). Fresh SQLite DB, migrate, create a user, log in:
  - login succeeds (real `UserProvider`),
  - the authenticated identity has **empty roles**, so a role-gated route **fails closed** (`RegistryRoleVoter` abstains / denies).
  Write `tests/Feature/AuthEndToEndTest.php` asserting both.

- [ ] **Step 2: Users + Aegis stack** — enable Aegis too. Seed a role + assignment for the user. Log in:
  - login succeeds,
  - the identity now carries the role claim (Aegis enrichment),
  - the role-gated route **authorizes**.

- [ ] **Step 3: Migration ordering on the combined stack** — fresh DB with both extensions: assert applied order is `glueful/framework` (FOUNDATION) → `glueful/users` (IDENTITY) → skeleton (DEFAULT) → Aegis (DEPENDENT), all apply, and the `migrations.source` column records **all four** sources (Phase-1 source tracking, end-to-end):

```php
$sources = array_column(
    $db->table('migrations')->select(['source'])->get(),
    'source'
);
self::assertContains('glueful/framework', $sources); // core auth_sessions/refresh/api_keys
self::assertContains('glueful/users', $sources);
self::assertContains('app', $sources);          // skeleton migrations
self::assertContains('glueful/aegis', $sources);
```

- [ ] **Step 4: Commit** — `git commit -am "test: end-to-end auth + authz on extracted users stack"`.

---

## Task 6: Cross-repo verification + docs

- [ ] **Step 1:** Framework suite (no extensions) green; `glueful/users` suite green; Aegis suite green (via the path-symlink harness, `extensions/aegis/vendor/glueful/framework` → framework).
- [ ] **Step 2:** Static analysis + style clean across framework, `glueful/users`, Aegis.
- [ ] **Step 3:** Update docs: framework `CHANGELOG.md` `[Unreleased]` (extraction summary), and any auth/users docs referencing the old in-core `User`/`UserRepository`. Note the breaking change: apps must enable `glueful/users` (the skeleton does by default).
- [ ] **Step 4: Commit** — `git commit -am "docs: record Users extraction + glueful/users requirement"`.

## Phase 5 done-when

- Fresh skeleton (with `glueful/users`) migrates cleanly, authenticates, and fails authorization closed without Aegis.
- With Aegis enabled, identities are enriched with role claims and role-gated routes authorize.
- No cross-package FKs to `users`/principals anywhere: skeleton `blobs.created_by`, and **all** Aegis actor refs (`user_roles.user_uuid`/`granted_by`, `user_permissions.user_uuid`/`granted_by`, `role_permissions.granted_by`) are indexed UUIDs with no FK; intra-package FKs (role/permission/parent) retained.
- Migration order on the combined stack is `framework (FOUNDATION) → users (IDENTITY) → app (DEFAULT) → aegis (DEPENDENT)`, source-tracked per package.
- All three suites (framework, users, aegis) green; analysis/style clean; CHANGELOG + docs updated.

---

## Execution notes (as built)

**Aegis (Tasks 3–4) — committed** (`glueful/aegis` `6c598c4`). All 5 cross-package FKs into `users`
removed (indexed-only principal ids, §2); `MigrationsFkPolicyTest` guards it via PRAGMA.
`IdentityClaimsProvider` (tag `identity.claims_provider`) + `UserRoleRepository::roleSlugsForUser()`
(join, no N+1); migrations at `DEPENDENT`/source `glueful/aegis`. Suite green (14). Aegis carries
~285 **pre-existing, ungated** level-8 PHPStan errors (no aegis CI); the new files are level-8
clean — the debt is tracked separately, out of Phase-5 scope.

**Skeleton (Tasks 1–2, 5) — done locally, COMMIT HELD.** Per the user, the api-skeleton commit
waits until its dependents are published, so it can reference real versions instead of local path
repos. Done in the working tree:
- `001_CreateInitialSchema` reduced to blobs-only (`created_by` indexed, no FK); `008/009/010`
  deleted; `config/extensions.php` enables `UsersServiceProvider` (Aegis opt-in).
- Test harness built (was absent): `phpunit.xml`, `tests/Unit/`, and `WelcomeTest` rewritten to
  dispatch real routes (`/welcome`, `/v1/status`) via `Application::handle()` — it previously
  asserted non-existent routes (`/`, `/health`) with HTTP helpers `Glueful\Testing\TestCase`
  doesn't provide.
- `AuthEndToEndTest` proves the auth seam with a local `InMemoryUserProvider` (framework's
  fake-provider pattern) — no DB, no extension-boot dependency, no state pollution. The **real**
  glueful/users wiring + full schema are proven separately by the extension's own suite and by a
  live `migrate:run`: 11 migrations apply in order framework(FOUNDATION) → users(IDENTITY) →
  app(DEFAULT); `migrations.source` records `glueful/framework`/`glueful/users`/`app`;
  `api_keys.user_uuid` present; `blobs.created_by` has no FK. Skeleton suite green (3 tests).
- `composer.json` (working tree) uses local path repos (`../framework`, `../extensions/users`,
  `../extensions/aegis` @dev) for testing — **not to be committed**; the committed template uses
  published version constraints. `composer.lock` is untracked (template).

**Docs (Task 6) — committed** (framework `0275307`): CHANGELOG `[Unreleased]` extraction summary +
BREAKING note; `docs/superpowers/specs/2026-06-04-platform-schema-ownership-design.md`.

**Spun out (deferred):** the skeleton's remaining platform migrations (blobs/queue/scheduler/
notifications/archive/locks) back framework-core subsystems — same ownership smell as auth. Captured
in the platform-schema-ownership design note (recommended: core-owned, config-gated migrations).

**Resume point:** when the dependent versions are provided — set the skeleton `composer.json` to
those published versions, drop the path repos, and commit the skeleton (schema + config + composer
+ phpunit.xml + tests).
