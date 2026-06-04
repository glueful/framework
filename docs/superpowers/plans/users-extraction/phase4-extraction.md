# Phase 4 — Extraction: Create `glueful/users`, Move the Store, Re-point Core

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the first-party `glueful/users` extension, move the concrete user store + account lifecycle + canonical schema + account routes into it, re-point every remaining core consumer at `UserProviderInterface::findByUuid()`, and flip the core default back to `NullUserProvider` — so **no core file references `UserRepository` or `Glueful\Models\User`**.

**Architecture:** `glueful/users` is a standard Glueful extension (`type: glueful-extension`, namespace `Glueful\Extensions\Users\`) that registers the real `UserProviderInterface` implementation, ships the identity/auth schema as migrations at `MigrationPriority::IDENTITY`, and owns account-lifecycle routes/controllers/commands. Core keeps only the identity seam (`UserIdentity`, contracts, `IdentityResolver`) and resolves users by uuid/login + credential verification. Rich profile / `TokenManager::getProfile()` is removed from core.

**Tech Stack:** PHP 8.3, Glueful extension system (`Extensions\ServiceProvider`), Phase-1 migration runner, Phase-2 contracts, composer path repository for local dev.

**Spec:** [`../../specs/2026-06-04-users-extension-extraction-design.md`](../../specs/2026-06-04-users-extension-extraction-design.md) §6 (incl. "Core consumers re-pointed at the contract").

**Depends on:** Phases 1, 2, 3 merged.

> **Cross-repo:** `glueful/users` is a new sibling package. For local dev, add it as a composer path repository to the api-skeleton (Phase 5) and to the framework's test app. Develop it against the local framework the same way Aegis does (path symlink).

---

## File structure

New package `glueful/users` (suggested location `../extensions/users` alongside `extensions/aegis`):

```
extensions/users/
├── composer.json                       (type: glueful-extension, psr-4 Glueful\Extensions\Users\)
├── migrations/                          (3-digit prefix — FileFinder requires ^\d{3}_)
│   ├── 001_CreateUsersTable.php         (users; two_factor_enabled folded in)
│   └── 002_CreateProfilesTable.php      (profiles, user_uuid FK -> users.uuid  [intra-package])
│   # NOTE (corrected post-Phase-4): auth_sessions, auth_refresh_tokens, api_keys are NOT
│   # owned here — they are CORE security-spine tables. They ship as framework foundation
│   # migrations (framework/migrations/001..003, source 'glueful/framework'). See the
│   # as-built addendum at the end of this plan.
├── src/
│   ├── UsersServiceProvider.php
│   ├── Models/User.php                  (moved from framework Glueful\Models\User)
│   ├── Repositories/UserRepository.php  (moved from framework Glueful\Repository\UserRepository)
│   ├── UserProvider.php                 (moved from framework src/Auth/UserProvider.php; the real provider)
│   ├── Controllers/AccountController.php (moved account actions)
│   ├── Controllers/TwoFactorController.php (moved from framework src/Controllers)
│   ├── Services/EmailVerification.php   (moved from framework Security/EmailVerification)
│   ├── TwoFactor/TwoFactorService.php   (moved; reads/writes users.two_factor_enabled)
│   ├── Console/ResetPasswordCommand.php (moved)
│   ├── Console/TwoFactor/{Enable,Disable,Status}Command.php (moved)
│   └── routes.php                       (account-lifecycle + 2fa routes)
└── tests/
```

Framework changes:
- **Modify** `src/Container/Providers/CoreProvider.php` — default `UserProviderInterface` → `NullUserProvider` again; remove core `TwoFactor*` DI registrations.
- **Modify** `src/Auth/ApiKeyAuthenticationProvider.php`, `src/Auth/RefreshService.php`, `src/Routing/Middleware/AdminPermissionMiddleware.php`, `src/Notifications/Services/NotificationService.php`, `src/Console/Commands/Security/RevokeTokensCommand.php` — re-point off `UserRepository` (Task 5).
- **Modify** `src/Auth/TokenManager.php` — remove `getProfile()` + rich-profile token embedding; **`src/Auth/LoginResponseShaper.php`** + login-response tests — drop profile-shaped fields (Task 6 Step 1).
- **Modify** `src/Controllers/AuthController.php` — split (token actions stay; account actions move); optional container lookup for login-time 2FA (Task 4 Step 2a).
- **Modify** `src/Security/SecureSerializer.php` (whitelist), `src/Repository/RepositoryFactory.php`, `src/helpers.php`, `src/Console/Commands/Container/{ContainerDebugCommand,ContainerValidateCommand}.php` — remove user-store references (Task 6 Step 3).
- **Delete** from framework (via `git mv` into `glueful/users`): `src/Models/User.php`, `src/Repository/UserRepository.php`, `src/Auth/UserProvider.php`, `src/Security/EmailVerification.php`, `src/Auth/TwoFactor/TwoFactorService.php`, `src/Controllers/TwoFactorController.php`, `src/Console/Commands/Security/ResetPasswordCommand.php`, `src/Console/Commands/TwoFactor/*`. **Stays in core:** `src/Auth/TwoFactor/ChallengeTokenIssuer.php`, `JtiBlocklist.php` (pure token mechanics, no `users` table). Also remove the account portions of `routes/auth.php` and delete `routes/2fa.php`.

---

## Task 1: Scaffold the `glueful/users` package

**Files:** Create `extensions/users/composer.json`, `extensions/users/src/UsersServiceProvider.php`.

- [ ] **Step 1: Create `composer.json`** (mirror `extensions/aegis/composer.json`)

```json
{
    "name": "glueful/users",
    "description": "Users: first-party identity store + account lifecycle for Glueful",
    "type": "glueful-extension",
    "license": "MIT",
    "require": { "php": "^8.3" },
    "autoload": { "psr-4": { "Glueful\\Extensions\\Users\\": "src/" } },
    "autoload-dev": { "psr-4": { "Glueful\\Extensions\\Users\\Tests\\": "tests/" } },
    "extra": {
        "glueful": {
            "name": "Users",
            "provider": "Glueful\\Extensions\\Users\\UsersServiceProvider",
            "requires": { "glueful": ">=1.30.0", "extensions": [] }
        }
    }
}
```

- [ ] **Step 2: Create `UsersServiceProvider`** (registers provider, migrations at IDENTITY priority, routes, commands)

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users;

use Glueful\Extensions\ServiceProvider;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Database\Migrations\MigrationPriority;

final class UsersServiceProvider extends ServiceProvider
{
    /** @return array<class-string, array<string,mixed>> */
    public static function services(): array
    {
        return [
            Repositories\UserRepository::class => [
                'class' => Repositories\UserRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            // UserProvider needs UserRepository injected — the bare ['class' => ...] form would
            // call `new UserProvider()` with no args and fatal. Pass it explicitly (matches the
            // Aegis RoleService pattern: 'arguments' => ['@' . Dep::class]). PasswordHasher
            // defaults to null inside UserProvider, so it need not be listed.
            // The 'alias' key lives on the SERVICE definition (collectAliases() adds the listed
            // ids as aliases OF this service id) — NOT on a separate interface entry. So the
            // interface is declared here, and there is one shared UserProvider instance.
            UserProvider::class => [
                'class' => UserProvider::class,
                'arguments' => ['@' . Repositories\UserRepository::class],
                'shared' => true,
                'alias' => [UserProviderInterface::class],
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
        // Identity/auth schema must migrate before app + dependent extensions.
        $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::IDENTITY, 'glueful/users');
    }

    public function boot(ApplicationContext $context): void
    {
        $this->discoverCommands('Glueful\\Extensions\\Users\\Console', __DIR__ . '/Console');
    }
}
```

> `collectAliases()` registers the ids in a definition's `'alias'` array as aliases **of that service id**, so the alias is declared on the `UserProvider` definition (`'alias' => [UserProviderInterface::class]`), not as a standalone `UserProviderInterface` entry pointing back. Result: one shared `UserProvider`, resolvable as both `UserProvider::class` and `UserProviderInterface::class`.

- [ ] **Step 3: Register the path repository** so composer can resolve `glueful/users` locally (framework test app + api-skeleton in Phase 5). In the consuming `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../extensions/users", "options": { "symlink": true } }
]
```

- [ ] **Step 4: Commit**

```bash
git add extensions/users/composer.json extensions/users/src/UsersServiceProvider.php
git commit -m "feat(users): scaffold glueful/users extension"
```

> Verify the real `ServiceProvider` method signatures (`services()`, `register()`, `boot()`, `loadRoutesFrom`, `loadMigrationsFrom`, `discoverCommands`) against `src/Extensions/ServiceProvider.php` and the CLAUDE.md extension section — the Phase-1 `loadMigrationsFrom($dir,$priority,$source)` signature is required here.

---

## Task 2: Move the store + provide the real `UserProvider`

**Files:** move `User`, `UserRepository`; create `UserProvider`.

- [ ] **Step 1: Move `User` model**

```bash
git mv src/Models/User.php ../extensions/users/src/Models/User.php
```
Change its namespace to `Glueful\Extensions\Users\Models`. Then find core references:
```bash
grep -rn "Glueful\\\\Models\\\\User\b\|use Glueful\\Models\\User;" src --include="*.php"
```
Each remaining core reference must be removed as part of re-pointing (Tasks 5–6) — core should not import the User model at all. (Non-core/test references update to the new FQCN.)

- [ ] **Step 2: Move `UserRepository`**

```bash
git mv src/Repository/UserRepository.php ../extensions/users/src/Repositories/UserRepository.php
```
Change namespace to `Glueful\Extensions\Users\Repositories`; update its `use Glueful\Models\User` → `Glueful\Extensions\Users\Models\User`. It retains all account methods (`create`, `update`, `getProfile`, `setNewPassword`, `findOrCreateFromSaml/Ldap`, etc.) — these are now extension-owned.

- [ ] **Step 3: Move `UserProvider` into the extension** (the *same* class created in Phase 3 — moved, not recreated)

```bash
git mv src/Auth/UserProvider.php ../extensions/users/src/UserProvider.php
```
Change its namespace `Glueful\Auth` → `Glueful\Extensions\Users`, and update its repository import `use Glueful\Repository\UserRepository;` → `use Glueful\Extensions\Users\Repositories\UserRepository;` (matching the Task 2 Step 2 move). The class body (`findByUuid`/`findByLogin`/`verifyCredentials`/`toIdentity`) is unchanged — it was already written in Phase 3.

- [ ] **Step 4: Test** — move/port the Phase-3 `tests/Integration/Auth/UserProviderTest.php` into the extension (`extensions/users/tests/UserProviderTest.php`), updating namespaces. Run via the extension's phpunit (mirror Aegis's `phpunit.xml` + `tests/Support` harness).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(users): move User model, UserRepository, and UserProvider into the extension"
```

---

## Task 3: Author the schema migrations (split by ownership)

> **Ownership (corrected post-Phase-4):** a table's migration belongs to the package whose code reads/writes it. `glueful/users` owns only `users` + `profiles` (the user store). `auth_sessions`, `auth_refresh_tokens`, `api_keys` are core security-spine tables (read/written by `SessionStore`/`TokenManager`/`RefreshTokenStore`/`ApiKeyService`) and ship as **core** migrations. See the as-built addendum.

**Files:** create `extensions/users/migrations/001_CreateUsersTable.php`, `002_CreateProfilesTable.php`; and `framework/migrations/001_CreateAuthSessionsTable.php`, `002_CreateAuthRefreshTokensTable.php`, `003_CreateApiKeysTable.php`.

- [ ] **Step 1 (extension):** Author `001_CreateUsersTable` (users; fold `two_factor_enabled` in) and `002_CreateProfilesTable` (profiles, `user_uuid` FK → `users.uuid`, intra-package). 3-digit prefix (FileFinder requires `^\d{3}_`); cross-source order comes from the `IDENTITY` priority, not the prefix.

- [ ] **Step 2 (core):** Author `framework/migrations/001..003` (namespace `Glueful\Migrations`) for `auth_sessions`, `auth_refresh_tokens`, `api_keys`. Per §2, `*.user_uuid` are **indexed UUIDs, no FK** (external principal id); the only retained FK is intra-core `auth_refresh_tokens.session_uuid → auth_sessions`. Register them automatically: make the container's `MigrationManager` a shared `FactoryDefinition` that calls `addMigrationPath(framework/migrations, FOUNDATION, 'glueful/framework')`.

- [ ] **Step 3: Test** — extension `MigrationsTest` asserts `users`/`profiles` apply; framework `CoreMigrationsTest` resolves `MigrationManager` from the container and asserts the three core tables apply (and that directly-constructed managers stay isolated — `MigrationOrderingTest`/`MigrationSourcesTest`).

- [ ] **Step 4: Commit** — extension: `"feat(db): canonical user-store schema migrations"`; framework: `"feat(db): core foundation migrations for auth_sessions/refresh/api_keys"`.

> Phase 5 removes the corresponding tables from the skeleton. Do **not** run both in the same DB until the skeleton tables are dropped, or table-create will collide.

---

## Task 4: Move account lifecycle (controller, routes, commands, email verification, provisioning)

- [ ] **Step 1: Split `AuthController`** — move the account actions (`verifyEmail`, `verifyOtp`, `resendOtp`, `forgotPassword`, `resetPassword`) into `extensions/users/src/Controllers/AccountController.php` (namespace `Glueful\Extensions\Users\Controllers`). Leave token actions (`login`, `logout`, `validateToken`, `refreshToken`, `refreshPermissions`, `csrfToken`) in core `AuthController`.

- [ ] **Step 2: Move account routes** — create `extensions/users/src/routes.php` with the `/auth/verify-email`, `/auth/verify-otp`, `/auth/resend-otp`, `/auth/forgot-password`, `/auth/reset-password` routes pointing at `AccountController`, plus the `routes/2fa.php` contents. Remove those route blocks from core `routes/auth.php` and delete core `routes/2fa.php`.

- [ ] **Step 2a: Move 2FA (it owns `users`-table state)** — `TwoFactorService` directly reads/writes `users.two_factor_enabled` + `status` (`src/Auth/TwoFactor/TwoFactorService.php:88-246`), so it is user-store code and must move (clean-break, per spec §6). `git mv`:
  - `src/Auth/TwoFactor/TwoFactorService.php` → `extensions/users/src/TwoFactor/TwoFactorService.php`
  - `src/Controllers/TwoFactorController.php` → `extensions/users/src/Controllers/TwoFactorController.php`
  - `src/Console/Commands/TwoFactor/{Enable,Disable,Status}Command.php` → `extensions/users/src/Console/TwoFactor/`
  Update namespaces to `Glueful\Extensions\Users\…`, update its `UserRepository`/`users`-table access to the moved repo, and register them in `UsersServiceProvider` (services + `discoverCommands`). Remove the core `TwoFactor` DI registrations from `CoreProvider`.
  - **Pure token-mechanic helpers stay in core:** `src/Auth/TwoFactor/ChallengeTokenIssuer.php` and `JtiBlocklist.php` do **not** touch the `users` table (confirm with `grep -n "table('users')" src/Auth/TwoFactor/*.php`) — leave them in core; the moved `TwoFactorService` consumes them across the boundary.
  - **Login-time 2FA coupling:** if core `AuthController::login()` triggers a 2FA challenge via `TwoFactorService`, core must not hard-depend on the moved service. Remove the stale core `use Glueful\Auth\TwoFactor\TwoFactorService;` import and resolve it **optionally** from the container by its new extension FQCN. Inside `AuthController` (which holds `ApplicationContext $this->context`, not a factory `$c`), use the container helper:

```php
$twoFactorClass = \Glueful\Extensions\Users\TwoFactor\TwoFactorService::class;
$container = container($this->context); // or $this->context->getContainer()
if ($container->has($twoFactorClass)) {
    $twoFactor = $container->get($twoFactorClass);
    // … issue/verify 2FA challenge …
}
```

so login works with no 2FA when `glueful/users` is absent and enforces 2FA when present. (Do **not** introduce a new core 2FA contract; the optional container lookup is the clean-break-compatible seam.)

- [ ] **Step 3: Move `EmailVerification`** — `git mv src/Security/EmailVerification.php ../extensions/users/src/Services/EmailVerification.php`; namespace `Glueful\Extensions\Users\Services`. Update its `UserRepository` import to the moved FQCN. Update the (now account) callers in `AccountController`. Core `AuthController::__construct(ApplicationContext)` resolves account services on demand, so once the account methods move it no longer references `EmailVerification`/`TwoFactorService` — verify with `grep -n "EmailVerification\|TwoFactorService" src/Controllers/AuthController.php` (expect none after the split).

- [ ] **Step 4: Move `ResetPasswordCommand`** — `git mv src/Console/Commands/Security/ResetPasswordCommand.php ../extensions/users/src/Console/ResetPasswordCommand.php`; namespace `Glueful\Extensions\Users\Console`; keep its `#[AsCommand]` so `discoverCommands()` finds it.

- [ ] **Step 5: Move provisioning + the profile/format path out of core** — `findOrCreateFromSaml/Ldap` already live on the moved `UserRepository` (Task 2). Now remove core's array-format/profile shaping: `formatUserData()` and the `getProfile()` enrichment (`verifyCredentials` lines ~252–256). Profile shaping becomes an extension/account concern. Core `AuthenticationService::verifyCredentials()` is rewritten to build session data from the resolved `UserIdentity` only — **that exact rewrite is Task 5a Step 3** (do them together; this step just deletes the `formatUserData()`/`getProfile()` usage so core no longer calls `UserRepository`). Trace and remove any other `formatUserData()` callers.

- [ ] **Step 6: Verify + commit** — `grep -rn "UserRepository\|EmailVerification\|Models\\\\User" src/Auth src/Controllers routes` should show no account-lifecycle leftovers. Commit: `git commit -am "feat(users): move account lifecycle (controller, routes, 2fa, email verify, reset cmd)"`.

---

## Task 5: Re-point the staying core consumers at `findByUuid()`

Per spec §6 "Core consumers re-pointed at the contract." Do each as its own commit; run `composer test` after each.

- [ ] **Step 1: `ApiKeyAuthenticationProvider`** — inject `UserProviderInterface`; replace `UserRepository` user lookup with `findByUuid()`. Use `UserIdentity` fields (uuid/email/status) where it previously read array rows.
- [ ] **Step 2: `RefreshService`** — same: inject `UserProviderInterface`, replace lookup with `findByUuid()`.
- [ ] **Step 3: `AdminPermissionMiddleware`** — replace user/role/admin reads with `RequestUserContext`/`UserIdentity` claims + `PermissionManager` (it already exists in the container); drop the `UserRepository` dependency.
- [ ] **Step 4: `NotificationService`** — remove `UserRepository` user resolution in core. Minimal cut: accept recipient contact data passed in, or resolve via `UserProviderInterface::findByUuid()` for email. (Full notification-recipient abstraction is deferred per spec; just remove the concrete `UserRepository` coupling.)
- [ ] **Step 5: `RevokeTokensCommand`** — if it only maps a uuid/identifier to revoke tokens, use `UserProviderInterface::findByUuid()`/`findByLogin()`; if it needs account data beyond identity, move it to `glueful/users/src/Console`.
- [ ] **Step 6:** After each, run `composer test`. Commit per consumer, e.g. `git commit -am "refactor(auth): re-point ApiKeyAuthenticationProvider at UserProviderInterface"`.

> Confirm exactly what each consumer reads from the user row today (grep each file for `userRepository->`/`UserRepository`) and ensure `UserIdentity` (uuid/email/username/status) covers it. Anything needing more than identity is a signal it should move to `glueful/users`, not get a wider core contract.

---

## Task 5a: Wire login through `IdentityResolver` (centralized status gate + claims fold)

Until now the `IdentityResolver` built in Phase 2 exists but **nothing calls it** — login still uses `AuthenticationService`'s own inline `allowed_login_statuses` check and never runs claims composition, so Aegis's role claims (Phase 5) would never reach the session. This task makes login route through the resolver, which is what lets enrichment actually land in the session.

**Files:** `src/Auth/AuthenticationService.php`; `src/Container/Providers/CoreProvider.php` (resolver construction); possibly `src/Auth/IdentityResolver.php` (status-config reconciliation, below).

- [ ] **Step 1: Reconcile the status authority.** Phase 2's `IdentityResolver` hardcodes `status === 'active'`, but `AuthenticationService` honors the configurable `security.auth.allowed_login_statuses` (default `['active']`). To make the resolver the *single* status gate, give it the allowed list: add a constructor param `array $allowedStatuses = ['active']` and change `statusAllowsLogin()` to `$status === null || in_array($status, $this->allowedStatuses, true)`. Update the `CoreProvider` factory to pass `config('security.auth.allowed_login_statuses', ['active'])`. (Keep null = "no opinion" = allowed.)

- [ ] **Step 2: Inject the resolver into `AuthenticationService`.** Append `?IdentityResolver $identityResolver = null` to the constructor (same append-at-end discipline as the Phase-3 `userProvider` arg) and resolve it from the container in the factory via a named arg.

- [ ] **Step 3: Route verifyCredentials through the resolver.** Replace the Phase-3 inline status block with a resolver call, and persist the resolved roles/claims into the session payload:

```php
$identity = $this->userProvider->verifyCredentials((string) $identifier, (string) ($credentials['password'] ?? ''));
if ($identity === null) {
    return null;
}
// Centralized status gate + claims fold (Aegis etc.). Null => rejected (e.g. suspended).
$identity = $this->identityResolver->resolve($identity);
if ($identity === null) {
    return null;
}

// Build session user data FROM THE RESOLVED IDENTITY ONLY — no UserRepository, no
// formatUserData() (moved to glueful/users in Task 4 Step 5). Core login operates on
// UserIdentity + claims; rich profile fields are no longer embedded (clean break).
return [
    'uuid' => $identity->uuid(),
    'email' => $identity->email(),
    'username' => $identity->username(),
    'status' => $identity->status(),
    'roles' => $identity->roles(),                       // was [] — now carries enriched claims
    'permissions' => $identity->claim('permissions', []),
    'last_login' => date('Y-m-d H:i:s'),
    'remember_me' => $credentials['remember_me'] ?? false,
];
```

Remove the now-duplicated inline `allowed_login_statuses` check added in Phase 3 (the resolver owns it). There must be **no** `$this->userRepository` / `formatUserData()` call left in `verifyCredentials()` after this step.

- [ ] **Step 4: Test.** With no claims provider: login succeeds, `roles` empty, a non-active status is rejected by the resolver. (The Aegis end-to-end — roles actually populated — is verified in Phase 5 Task 5.) Run `composer test`.

- [ ] **Step 5: Commit** — `git commit -am "feat(auth): route login through IdentityResolver (status gate + claims fold)"`.

---

## Task 6: Remove `getProfile`, flip the default, delete moved core code

- [ ] **Step 1: Remove `TokenManager::getProfile()`** (lines ~395–401 area) and any code embedding the rich profile into the login/token response. **Coordinate with `LoginResponseShaper`** (`src/Auth/LoginResponseShaper.php`) and the login-response tests in the *same* commit: grep the shaper + tests for profile-shaped fields (`name`, `given_name`, `family_name`, `picture`, `profile`) and update them to the identity+claims shape (`uuid`/`email`/`username`/`status`/`roles`/`permissions`) that `verifyCredentials()` now returns (Task 5a). Tokens/session carry identity + claims only.
- [ ] **Step 2: `SecureSerializer`** — remove the `'Glueful\Models\User'` whitelist entry (`src/Security/SecureSerializer.php:37`).
- [ ] **Step 3: Remove all remaining core user-store registrations/helpers** — not just `RepositoryProvider`. Handle each:
  - `src/Container/Providers/RepositoryProvider.php` — drop the `UserRepository` registration/alias.
  - `src/Repository/RepositoryFactory.php` — remove the `UserRepository` factory entry/method (it can no longer construct a core class).
  - `src/helpers.php` — remove or redirect any user-store helper that references `UserRepository`/`Glueful\Models\User`.
  - `src/Console/Commands/Container/ContainerDebugCommand.php` and `ContainerValidateCommand.php` — remove `UserRepository`/`User` from any hard-coded known-service list they reference.
- [ ] **Step 4: Flip the default provider** — in `CoreProvider`, change the `UserProviderInterface` definition back to `NullUserProvider` (the Phase-3 `UserProvider` binding is removed from core; the real provider now comes from `glueful/users`'s `services()`). Update `tests/Integration/Container/IdentityWiringTest.php` to expect `NullUserProvider` again **when no extension is enabled**.
- [ ] **Step 5: Fix the `AuthenticationService` fallback** — `UserProvider` no longer exists in core (moved in Task 2 Step 3), so the Phase-3 constructor fallback `?? new UserProvider($this->userRepository)` no longer compiles. Change it to `?? new NullUserProvider()` so core builds without the store. (There is no throwaway adapter to `git rm` — the single `UserProvider` was relocated, not duplicated.) Also remove the now-dead `?UserRepository $userRepository` constructor param / `$this->userRepository` field if nothing in core uses it anymore.
- [ ] **Step 6: Prove core is clean**

```bash
grep -rn "Glueful\\\\Repository\\\\UserRepository\|Glueful\\\\Models\\\\User\b\|new UserRepository\|TwoFactorService\|EmailVerification" src --include="*.php"
```
Expected: **no matches** (TwoFactorService/EmailVerification moved to `glueful/users`; user store gone). Any hit is an un-migrated consumer — resolve before committing.

- [ ] **Step 7: Commit** — `git commit -am "refactor(core): remove user store from core; default to NullUserProvider"`.

---

## Task 7: Verification

- [ ] **Step 1:** Framework suite with no extensions: `composer test`. Expected PASS — auth fails closed (NullUserProvider), no `UserRepository` in core.
- [ ] **Step 2:** `glueful/users` suite (its own phpunit): provider + migrations green.
- [ ] **Step 3:** `composer run analyse && composer run phpcs` on framework → clean (PHPStan will flag any lingering `User`/`UserRepository` symbol).
- [ ] **Step 4:** Commit fixups.

## Phase 4 done-when

- `glueful/users` exists and provides the real `UserProviderInterface` impl, identity/auth schema migrations (`IDENTITY` priority, source `glueful/users`), account routes/controller/commands, email verification, and SAML/LDAP provisioning.
- **No core file references `UserRepository` or `Glueful\Models\User`** (grep clean); `TokenManager::getProfile()` removed; `SecureSerializer` whitelist updated; core default is `NullUserProvider`.
- Framework suite green (auth fails closed without the extension); `glueful/users` suite green; PHPStan/phpcs clean.
- Ready for Phase 5 to wire the skeleton + Aegis.

---

## Execution notes (as built)

Phase 4 landed across the framework repo (consumer re-points, core deletions, test
moves) and a new standalone `glueful/users` repo. Deviations from the plan as written:

**Sequencing — re-point before move.** The plan ordered "move files, then re-point
consumers." Executed the reverse: re-pointed every core consumer to
`UserProviderInterface::findByUuid()` *first*, ran the suite green, then deleted the
store from core. This kept core compiling at each step (no window where core
referenced a class that no longer existed) and made the deletions a pure no-op
verification.

**2FA via a static factory, mechanics stay in core.** `TwoFactorService` moved to the
extension but its container entry uses a prod-safe static factory
(`TwoFactor\TwoFactorServiceFactory::create($c)`) rather than a closure — closures are
forbidden in the compiled prod container. The token *mechanics*
(`ChallengeTokenIssuer`, `JtiBlocklist`, the 2FA exceptions) stay in core; only the
user-state-bound service moved. `AuthController` resolves 2FA optionally
(`container($ctx)->has(TwoFactorService::class)`) so core runs with or without the
extension.

**Migration filenames must be `\d{3}_`.** `FileFinder::findMigrations()` filters to
exactly three leading digits. The plan's `0001_`-style examples for `glueful/users`
were wrong — renamed to `001_`…`005_`. Cross-source ordering is *not* expressed by the
prefix; it is handled by the `IDENTITY` priority passed to `loadMigrationsFrom()`
(Phase 1). So `glueful/users` `001_` runs before the skeleton's `001_` purely on
priority, and the `source` column keeps same-basename rows distinct.

**Migrations — initially five here, corrected to two (ownership fix).** `two_factor_enabled`
is folded into the `001` users `CREATE TABLE` rather than a separate `ALTER`. Phase 4 first
shipped all five identity/auth tables as `glueful/users` migrations (`001` users … `005`
api_keys). That was **wrong**: `auth_sessions`, `auth_refresh_tokens`, `api_keys` are
read/written exclusively by core (`SessionStore`/`TokenManager`/`RefreshTokenStore`/
`ApiKeyService`), so the user store was shipping the core security spine's schema — coupling
core to the extension and forcing any alternate user store to re-ship it. **Corrected:** those
three moved to **core foundation migrations** (`framework/migrations/001…003`, namespace
`Glueful\Migrations`, source `glueful/framework`, priority `FOUNDATION`), auto-registered by
the container-built `MigrationManager` (CoreProvider `FactoryDefinition` → `addMigrationPath`).
`glueful/users` now ships only `001` users + `002` profiles. Principal references in the core
tables (`*.user_uuid`) are indexed-no-FK (§2); the only retained FK is intra-core
`auth_refresh_tokens.session_uuid → auth_sessions`. Verified: the container-resolved manager
applies the core schema (`CoreMigrationsTest`); directly-constructed managers stay isolated
(`MigrationOrderingTest`/`MigrationSourcesTest` unchanged). No lazy runtime DDL — first-class
versioned migrations only.

**FK policy applied (spec §2).** Intra-package FKs only: profiles/sessions/refresh →
users; refresh → auth_sessions. `profiles.photo_uuid` (blobs live in the skeleton) and
`api_keys.user_uuid` are indexed UUID columns with **no** cross-package FK. `api_keys`
also renamed its owner column `user_id` → `user_uuid` to match the principal-as-
external-reference convention.

**Account lifecycle reimplemented, not relocated wholesale.**
`AuthenticationService`'s account methods (`updatePassword`, `userExists`,
`getUserDataByUuid`, `formatUserData`) were removed from core — their only callers were
the account actions that moved. The new `AccountController` reimplements them locally
against `UserRepository` (`userExists()` via `findByEmail`; reset via `setNewPassword`
+ `PasswordHasher`). The seam (`AuthenticationService`/`RefreshService`) is now
identity-only.

**Test infra and consumption model.** The framework consumes `glueful/users` in its
*own* suite via an `autoload-dev` PSR-4 map to `../extensions/users/src` (no composer
require into core). The extension is a standalone repo with a composer path-repo
symlink back to the framework (`../../framework`) and its own `phpunit.xml` /
`phpstan.neon` (level 6) + `phpstan-baseline.neon` grandfathering 7 inherited items
from the moved code. `UserProviderTest` and `TwoFactorServiceTest` relocated to the
extension suite; the framework's seam/api-key tests stayed in core and inject a real
`UserProvider`. `ApiKeyAuthenticationProvider` gained a `setUserProvider()` injector
for that wiring. New `MigrationsTest` applies the five migrations on file-based SQLite
(`:memory:` is per-connection) and asserts the schema.

**Routes grouped.** Account + 2FA routes live under the extension's `routes/` folder
(`account.php`, `2fa.php`), loaded from `register()`; core's `RouteManifest` no longer
lists `routes/2fa.php`.

**Status authority.** `IdentityResolver` was made status-configurable
(`allowed_login_statuses` config) so it is the single place that gates login by status,
rather than that logic living in the (now-removed) store methods.
