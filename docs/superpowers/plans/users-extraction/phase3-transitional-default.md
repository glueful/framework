# Phase 3 — Transitional Default: `UserProvider` + Re-point Authn Lookups

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the framework fully working *before* the store is moved out: wrap the existing `UserRepository` in a `UserProviderInterface` adapter, bind it as the default provider (overriding `NullUserProvider`), and re-point `AuthenticationService`'s **credential verification** through the contract — without yet touching the array-based session formatting or the `userExists()`/refresh `findByUuid` lookups (those move in Phase 4).

**Architecture:** A single `UserProvider` adapter — **created in core this phase and `git mv`'d into `glueful/users` in Phase 4 (moved, not recreated; there is no separate throwaway class)** — wraps `UserRepository` → `UserProviderInterface`. `AuthenticationService` gains a `UserProviderInterface` dependency and uses it for **credential verification only** (the `verifyCredentials` path); the existing `formatUserData()`/`getProfile()` array path and the `findByUuid` lookups in `userExists()`/refresh stay intact for now so sessions/tokens are byte-for-byte unchanged. The only *transitional* thing is where `UserProvider` is bound (core default now → the extension's `services()` in Phase 4).

**Tech Stack:** PHP 8.3, Glueful `Auth\*`, DI `CoreProvider`, PHPUnit.

**Spec:** [`../../specs/2026-06-04-users-extension-extraction-design.md`](../../specs/2026-06-04-users-extension-extraction-design.md) §7 phase 3.

**Depends on:** Phase 2 (contracts, `UserIdentity`, `NullUserProvider`, `IdentityResolver`).

> **Phase 3 does NOT route login through `IdentityResolver`.** This phase only swaps credential verification onto the provider seam; login keeps `AuthenticationService`'s existing inline `allowed_login_statuses` check and does not yet centralize the status gate or run claims composition. That is intentional and transitional — there are no claims providers to fold until Aegis (Phase 5), and the array-shaped session path still owns formatting until Phase 4. **Wiring login through `IdentityResolver` (centralized status gate + claims fold, persisting enriched roles/claims into the session) is Phase 4 Task 5a**, which is what makes Phase 5's Aegis role enrichment actually reach the session.

---

## File structure

- **Create** `src/Auth/UserProvider.php` — wraps `UserRepository` as `UserProviderInterface`. Created here, **`git mv`'d** to `glueful/users` in Phase 4 (one class, not recreated).
- **Modify** `src/Container/Providers/CoreProvider.php` — bind `UserProviderInterface` → `UserProvider` (overriding the Phase-2 `NullUserProvider` default for this transitional window).
- **Modify** `src/Auth/AuthenticationService.php` — inject `UserProviderInterface`; use it for **credential verification only** (the `verifyCredentials` path). Keep `formatUserData()`/`getProfile()` and the `userExists()`/refresh `findByUuid` lookups untouched.
- **Tests** under `tests/Unit/Auth/`, `tests/Integration/Auth/`.

> **Why a wrapper and not "make UserRepository implement the interface"?** A thin `UserProvider` keeps persistence (`UserRepository`) and the auth-facing contract as separate responsibilities, and keeps `UserIdentity`-construction out of the repository. It is **not throwaway** — the same class relocates intact to `glueful/users` in Phase 4.

---

## Task 1: `UserProvider` adapter

**Files:**
- Create: `src/Auth/UserProvider.php`
- Test: `tests/Integration/Auth/UserProviderTest.php`

The adapter maps the existing repository methods (`findByUuid`, `findByEmail`, `findByUsername`, password hash on the row) to the three contract methods, returning `UserIdentity` (identity facts only; roles stay empty — Aegis enriches later).

- [ ] **Step 1: Write the failing test**

Mirror the SQLite-app harness from `tests/Integration/Auth/ApiKeyAuthenticationTest.php` (it already seeds users). Seed one user, then assert the adapter resolves it:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Auth\{UserProvider, UserIdentity};
use Glueful\Repository\UserRepository;
use Glueful\Testing\TestCase;

final class UserProviderTest extends TestCase
{
    private function seedUser(): string
    {
        // Use the same user-seeding helper/path ApiKeyAuthenticationTest uses; returns the uuid.
        // Create a user with email 'amy@x.test', username 'amy', password 'secret-123', status 'active'.
        // (Adapt to the repository's create() signature.)
        $repo = $this->get(UserRepository::class);
        // create() returns a STRING uuid (not an array) — do not index ['uuid'].
        // create() does NOT hash the password (it persists as-is), so store a real hash that
        // PasswordHasher::verify() will match — hash via the same PasswordHasher the adapter uses.
        return $repo->create([
            'username' => 'amy',
            'email' => 'amy@x.test',
            'password' => (new \Glueful\Auth\PasswordHasher())->hash('secret-123'),
            'status' => 'active',
        ]);
    }

    public function test_find_and_verify(): void
    {
        $uuid = $this->seedUser();
        $provider = new UserProvider($this->get(UserRepository::class));

        self::assertInstanceOf(UserIdentity::class, $provider->findByUuid($uuid));
        self::assertSame($uuid, $provider->findByLogin('amy@x.test')?->uuid());
        self::assertSame($uuid, $provider->findByLogin('amy')?->uuid());

        self::assertSame($uuid, $provider->verifyCredentials('amy@x.test', 'secret-123')?->uuid());
        self::assertNull($provider->verifyCredentials('amy@x.test', 'wrong'));
        self::assertNull($provider->findByUuid('does-not-exist'));
    }
}
```

> Verified facts: `UserRepository::create(array): string` returns the uuid, and it does **not** hash the password — it validates, dedupes, defaults status, then `parent::create()` persists the value as-is. So the seed must store an already-hashed password (above) for `PasswordHasher::verify()` to match. Hashing is symmetric: seed uses `PasswordHasher::hash()`, the adapter uses `PasswordHasher::verify()`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Auth/UserProviderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\PasswordHasher;
use Glueful\Repository\UserRepository;

/**
 * Adapts UserRepository to UserProviderInterface. Created in core during the extraction
 * transition and bound as the default provider; in Phase 4 this exact class is `git mv`'d
 * into glueful/users (namespace changes; logic unchanged) — it is the canonical provider,
 * not a throwaway. NOTE: this lives at Glueful\Auth\UserProvider until the Phase-4 move.
 */
final class UserProvider implements UserProviderInterface
{
    private PasswordHasher $hasher;

    public function __construct(private UserRepository $users, ?PasswordHasher $hasher = null)
    {
        // Route password verification through the framework's hasher (centralized), not
        // raw password_verify(). Nullable default keeps it usable without the container.
        $this->hasher = $hasher ?? new PasswordHasher();
    }

    public function findByUuid(string $uuid): ?UserIdentity
    {
        return $this->toIdentity($this->users->findByUuid($uuid));
    }

    public function findByLogin(string $identifier): ?UserIdentity
    {
        return $this->toIdentity($this->lookup($identifier));
    }

    public function verifyCredentials(string $identifier, string $password): ?UserIdentity
    {
        $row = $this->lookup($identifier);
        // Guard for a real user row: findByUsername/findByEmail can return a validation-errors
        // array, so check for an actual uuid + password before trusting it.
        if (!is_array($row) || !isset($row['uuid'], $row['password'])) {
            return null;
        }
        if (!$this->hasher->verify($password, (string) $row['password'])) {
            return null;
        }
        return $this->toIdentity($row);
    }

    /** @return array<string,mixed>|null */
    private function lookup(string $identifier): ?array
    {
        $row = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? $this->users->findByEmail($identifier)
            : $this->users->findByUsername($identifier);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed>|null $row */
    private function toIdentity(?array $row): ?UserIdentity
    {
        // Only a row with a real uuid is a user; a validation-errors array (no 'uuid') is not.
        if (!is_array($row) || !isset($row['uuid'])) {
            return null;
        }
        return new UserIdentity(
            uuid: (string) $row['uuid'],
            email: isset($row['email']) ? (string) $row['email'] : null,
            username: isset($row['username']) ? (string) $row['username'] : null,
            status: isset($row['status']) ? (string) $row['status'] : null,
        );
    }
}
```

> `UserRepository::findByEmail/findByUsername/findByUuid` return an array (or null). Two real gotchas handled above: (1) these can return a **validation-errors array** rather than a user row, so `toIdentity()` requires `$row['uuid']`; (2) password verification goes through `PasswordHasher::verify()` (the framework's centralized hasher, matching `AuthenticationService` line 247), not raw `password_verify()`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Auth/UserProviderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Auth/UserProvider.php tests/Integration/Auth/UserProviderTest.php
git commit -m "feat(auth): add transitional UserProvider adapter"
```

---

## Task 2: Bind the adapter as the default provider

**Files:**
- Modify: `src/Container/Providers/CoreProvider.php` — change the `UserProviderInterface` binding from `NullUserProvider` (Phase 2) to `UserProvider` for this transitional window.

- [ ] **Step 1: Update the failing wiring test from Phase 2**

In `tests/Integration/Container/IdentityWiringTest.php`, change the default-provider expectation:

```php
    public function test_default_user_provider_is_user_provider(): void
    {
        self::assertInstanceOf(
            \Glueful\Auth\UserProvider::class,
            $this->get(\Glueful\Auth\Contracts\UserProviderInterface::class)
        );
    }
```

(Replace the Phase-2 `test_default_user_provider_is_null_provider` assertion; the null default returns in Phase 4.)

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Container/IdentityWiringTest.php`
Expected: FAIL — still `NullUserProvider`.

- [ ] **Step 3: Update the binding**

In `CoreProvider`, replace the Phase-2 `UserProviderInterface` definition (`FactoryDefinition` returning `NullUserProvider`) with one returning `UserProvider` — same `$defs[...] = new FactoryDefinition(...)` style as the rest of the file:

```php
$defs[UserProviderInterface::class] = new FactoryDefinition(
    UserProviderInterface::class,
    // TRANSITIONAL (Phase 3): wrap the core UserRepository. Phase 4 flips this back to
    // NullUserProvider and glueful/users registers the real provider.
    fn(\Psr\Container\ContainerInterface $c) =>
        new \Glueful\Auth\UserProvider($c->get(\Glueful\Repository\UserRepository::class))
);
```

> Confirm how `UserRepository` is constructed in the container (it may need explicit deps; `AuthenticationService` line 91 shows a no-arg `new UserRepository()` fallback, so `new UserProvider(new \Glueful\Repository\UserRepository())` is acceptable if the container can't autowire it).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Container/IdentityWiringTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Container/Providers/CoreProvider.php tests/Integration/Container/IdentityWiringTest.php
git commit -m "feat(container): bind UserProvider as transitional default"
```

---

## Task 3: Re-point `AuthenticationService` credential verification at the contract

Inject `UserProviderInterface` and use it for **credential verification only** (the `verifyCredentials()` path). Everything else stays on `UserRepository` for now and moves in Phase 4: `formatUserData()`, `getProfile()`/`setNewPassword()`, and the `findByUuid` array-row lookups at lines ~525/~583 (`userExists()` / refresh-profile paths). Do **not** touch those `findByUuid` call sites in this phase.

**Files:**
- Modify: `src/Auth/AuthenticationService.php` — constructor (lines ~73–91) and `verifyCredentials()` (lines ~201–262) only.
- Modify: `src/Container/Providers/CoreProvider.php` — pass the new `userProvider` arg into the `AuthenticationService` factory (lines ~420–431).

- [ ] **Step 1: Write the failing/guard test**

Add an integration test asserting login still works end-to-end through the seam (mirror `ApiKeyAuthenticationTest` harness; seed a user):

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Auth;

use Glueful\Auth\AuthenticationService;
use Glueful\Testing\TestCase;

final class AuthenticationServiceSeamTest extends TestCase
{
    public function test_verify_credentials_still_authenticates_a_valid_user(): void
    {
        // Seed user amy@x.test / secret-123 / active (same helper as UserProviderTest).
        $this->seedActiveUser('amy', 'amy@x.test', 'secret-123');

        $svc = $this->get(AuthenticationService::class);
        $userData = $svc->verifyCredentials(['email' => 'amy@x.test', 'password' => 'secret-123']);

        self::assertNotNull($userData);
        self::assertSame('amy@x.test', $userData['email'] ?? null);

        self::assertNull($svc->verifyCredentials(['email' => 'amy@x.test', 'password' => 'wrong']));
    }
}
```

> This test should PASS before and after the refactor — it's a regression guard proving the re-point doesn't change observable behavior. There is **no** existing `seedActiveUser()` helper — define it in this test class (or a shared base) using the same `create()`-with-hashed-password approach as `UserProviderTest::seedUser()`:
>
> ```php
> private function seedActiveUser(string $username, string $email, string $password): string
> {
>     return $this->get(\Glueful\Repository\UserRepository::class)->create([
>         'username' => $username,
>         'email' => $email,
>         'password' => (new \Glueful\Auth\PasswordHasher())->hash($password), // create() does not hash
>         'status' => 'active',
>     ]);
> }
> ```

- [ ] **Step 2: Run the guard test (baseline green)**

Run: `vendor/bin/phpunit tests/Integration/Auth/AuthenticationServiceSeamTest.php`
Expected: PASS (current code path). Keep it green through the edit.

- [ ] **Step 3: Refactor `AuthenticationService`**

Constructor: **append** `?UserProviderInterface $userProvider = null` as the **last** parameter (after `$refreshService`). Appending — not inserting — is important: the constructor has 8 positional params and `CoreProvider`'s factory passes 7 positionally, so inserting mid-list would shift those bindings onto the wrong parameters. Default it to a `UserProvider` wrapping the existing repo + hasher so direct `new AuthenticationService()` still works:

```php
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserProvider;
// ...
// constructor signature — appended AFTER ?RefreshService $refreshService = null:
//     ?UserProviderInterface $userProvider = null
private UserProviderInterface $userProvider;
// in __construct, after $this->userRepository and $this->passwordHasher are set:
$this->userProvider = $userProvider ?? new UserProvider($this->userRepository, $this->passwordHasher);
```

Then update `CoreProvider`'s `AuthenticationService` factory (lines ~420–431) to pass the provider via a **named argument** (the factory currently stops at the 7th positional arg, so a named arg avoids having to fill the 8th):

```php
fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\AuthenticationService(
    $c->get(\Glueful\Auth\Interfaces\SessionStoreInterface::class),
    $c->get(\Glueful\Auth\SessionCacheManager::class),
    null,
    null,
    $this->context,
    $c->get(\Glueful\Auth\AuthenticationManager::class),
    $c->get(\Glueful\Auth\TokenManager::class),
    userProvider: $c->get(\Glueful\Auth\Contracts\UserProviderInterface::class),
)
```

In `verifyCredentials()` (lines ~208–250), replace the email/username branch + status + password block with a single contract call, then keep the existing array formatting using a fresh repo row for profile (the formatting path is unchanged):

```php
$identifier = $credentials['username'] ?? $credentials['email'] ?? null;
if ($identifier === null) {
    return null;
}

// Validate password format first (unchanged).
try {
    PasswordDTO::from(['password' => $credentials['password'] ?? '']);
} catch (\Glueful\Validation\ValidationException $e) {
    return null;
}

$identity = $this->userProvider->verifyCredentials((string) $identifier, (string) ($credentials['password'] ?? ''));
if ($identity === null) {
    return null;
}

// Enforce allowed login statuses (default ['active']) against the resolved identity.
$allowedStatuses = (array) $this->getConfig('security.auth.allowed_login_statuses', ['active']);
if ($allowedStatuses !== [] && !in_array((string) $identity->status(), $allowedStatuses, true)) {
    return null; // fail silently
}

// Existing array-shaped formatting path stays (moves to glueful/users in Phase 4).
$user = $this->userRepository->findByUuid($identity->uuid());
if (!is_array($user)) {
    return null;
}
$userData = $this->formatUserData($user);
$userData['profile'] = $this->userRepository->getProfile($identity->uuid()) ?? null;
$userData['roles'] = []; // Roles managed by RBAC extension
$userData['last_login'] = date('Y-m-d H:i:s');
$userData['remember_me'] = $credentials['remember_me'] ?? false;

return $userData;
```

Leave `findByUuid` profile lookups at lines ~525/583 as-is for now (Phase 4 moves them).

- [ ] **Step 4: Run the guard test + full suite**

Run: `vendor/bin/phpunit tests/Integration/Auth/AuthenticationServiceSeamTest.php && composer test`
Expected: PASS — behavior unchanged, now flowing through `UserProviderInterface`.

- [ ] **Step 5: Commit**

```bash
git add src/Auth/AuthenticationService.php tests/Integration/Auth/AuthenticationServiceSeamTest.php
git commit -m "refactor(auth): route AuthenticationService lookups through UserProviderInterface"
```

---

## Task 4: Verification + static analysis

- [ ] **Step 1:** `vendor/bin/phpunit tests/Unit/Auth tests/Integration/Auth tests/Integration/Container` → PASS.
- [ ] **Step 2:** `composer test` → PASS.
- [ ] **Step 3:** `composer run analyse:changed && composer run phpcs` → clean.
- [ ] **Step 4:** Commit fixups: `git commit -am "test(auth): phase 3 verification + analysis fixups"`.

## Phase 3 done-when

- `UserProvider` adapts `UserRepository` to `UserProviderInterface` and is bound as the default provider.
- `AuthenticationService` resolves identities + verifies credentials through `UserProviderInterface`; sessions/tokens unchanged (guard test green).
- `composer test`, `analyse:changed`, `phpcs` green.
- Everything still works with the store still in core — ready for Phase 4 to move it out.
