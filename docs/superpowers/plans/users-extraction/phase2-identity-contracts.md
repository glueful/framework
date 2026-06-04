# Phase 2 — Identity Contracts: Canonical `UserIdentity`, Provider + Claims Ports

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Status: ✅ Implemented (commit `678e97f`, on `dev`).** Full suite green (1055), phpcs + PHPStan clean.
>
> ### Execution notes (as built) — one deviation
> **Two hidden `AuthenticatedUser` consumers beyond the planned 4.** Task 5 said to grep 4 files, but two more accessed the user object's *properties* without naming `AuthenticatedUser`, so the name-grep missed them: `src/Controllers/Traits/ResponseCachingTrait.php` (`$this->currentUser->uuid` ×3) and `src/Controllers/TwoFactorController.php` (`$user->email`). PHPStan flagged them as private-property access once `UserIdentity` made those fields private; both migrated to method calls (`->uuid()`/`->email()`). The other property-style `$user->...` sites (`AuthToRequestAttributesMiddleware`, `AuthMiddleware`, `UserResponseModel`) operate on the `Models\User` DTO / arrays (public props), not `UserIdentity` — confirmed by a clean PHPStan run, left unchanged. **Lesson for later phases:** after a type swap, rely on PHPStan (private-property access errors) to find consumers, not just a symbol grep.

**Goal:** Introduce the core identity seam — a single canonical, `final`, immutable `UserIdentity`; a `UserProviderInterface` (lookup + credential verification); an `IdentityClaimsProviderInterface` (post-auth enrichment); a null/guest provider; and a claims-composition + status gate — then retire `AuthenticatedUser` onto `UserIdentity`.

**Architecture:** `UserIdentity` keeps its existing 4-arg constructor (so the 12 permission-subsystem call sites and voters keep working) but gains identity facts (`email`/`username`/`status`), runtime fields (`sessionUuid`/`provider`), an open claims bag with typed `roles()`/`scopes()` accessors backed by it, and immutable `with*()` builders. Authentication resolves an identity via `UserProviderInterface`, the core applies a status gate, then folds all registered `IdentityClaimsProviderInterface`s (re-pinning identity facts so providers can only *add* claims). `AuthenticatedUser` is removed and `RequestUserContext` + controller traits expose `UserIdentity`.

**Tech Stack:** PHP 8.3, Glueful `Auth\*`, `Permissions\*`, DI via `Container\Providers\CoreProvider`, PHPUnit.

**Spec:** [`../../specs/2026-06-04-users-extension-extraction-design.md`](../../specs/2026-06-04-users-extension-extraction-design.md) §§3–5.

**Depends on:** Phase 1 merged (not strictly required to compile, but Phase 4 needs both; do Phase 1 first per the README sequencing risk).

---

## File structure

- **Modify** `src/Auth/UserIdentity.php` — evolve into the canonical `final` immutable identity (BC constructor + new facts/claims/builders).
- **Create** `src/Auth/Contracts/UserProviderInterface.php` — lookup + credential verification.
- **Create** `src/Auth/Contracts/IdentityClaimsProviderInterface.php` — post-auth enrichment.
- **Create** `src/Auth/NullUserProvider.php` — fail-closed default binding.
- **Create** `src/Auth/IdentityResolver.php` — status gate + claims-composition fold.
- **Delete** `src/Auth/AuthenticatedUser.php` — folded into `UserIdentity`.
- **Modify** `src/Http/RequestUserContext.php` — cache/return/build `UserIdentity` (was `AuthenticatedUser`).
- **Modify** `src/Controllers/BaseController.php`, `src/Controllers/Traits/CachedUserContextTrait.php`, `src/Controllers/Traits/AuthorizationTrait.php` — type `UserIdentity`.
- **Modify** `src/Container/Providers/CoreProvider.php` — bind `UserProviderInterface` → `NullUserProvider`; register `IdentityResolver`; collect `IdentityClaimsProviderInterface`s.
- **Tests** under `tests/Unit/Auth/` and `tests/Integration/Auth/`.

> **Identity-fact accessor convention:** the canonical `UserIdentity` exposes **methods** (`uuid()`, `email()`, `roles()`), while the retired `AuthenticatedUser` exposed **properties** (`->uuid`, `->email`). Every migrated consumer changes `->uuid` → `->uuid()` etc. Grep for `->uuid`, `->email`, `->username`, `->roles`, `->permissions` on user objects in the 4 consumer files.

> **Scope boundary — the concrete `UserRepository` dependency is removed in Phase 4, not here, and that is deliberate.** Phase 2 builds the *seam* (contracts, canonical identity, null provider, resolver) but the core stayers still read `UserRepository` after this phase: `AuthenticationService`, `ApiKeyAuthenticationProvider`, `RefreshService`, `TokenManager::getProfile()`, `AdminPermissionMiddleware`, `NotificationService`. They are **not** re-pointed here because Phase 2's default binding is `NullUserProvider` — re-pointing them to `findByUuid()` now would make them resolve `null` and break auth. A working provider only exists from **Phase 3** (the legacy adapter) onward, so the re-pointing lands in **Phase 4 Tasks 5–6**, where: each stayer moves to `UserProviderInterface::findByUuid()`, `TokenManager::getProfile()` is **removed outright (clean break, no deprecated shell, no `UserProfileProviderInterface`)**, and a grep proves zero `UserRepository`/`Glueful\Models\User` references remain in core. The whole-effort done-when (Phase 4) is "no concrete user store in core"; Phase 2's done-when is only "the seam exists."

---

## Task 1: Evolve `UserIdentity` into the canonical identity

**Files:**
- Modify: `src/Auth/UserIdentity.php`
- Test: `tests/Unit/Auth/UserIdentityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\UserIdentity;
use PHPUnit\Framework\TestCase;

final class UserIdentityTest extends TestCase
{
    public function test_legacy_4arg_constructor_still_works(): void
    {
        $id = new UserIdentity('u1', ['role.editor'], ['read'], ['k' => 'v']);

        self::assertSame('u1', $id->id());      // legacy accessor
        self::assertSame('u1', $id->uuid());    // new accessor
        self::assertSame(['role.editor'], $id->roles());
        self::assertSame(['read'], $id->scopes());
        self::assertSame('v', $id->attr('k'));  // legacy bag accessor
        self::assertSame('v', $id->claim('k')); // new claim accessor
    }

    public function test_identity_facts_and_runtime_fields(): void
    {
        $id = new UserIdentity('u1', email: 'a@b.test', username: 'amy', status: 'active');
        self::assertSame('a@b.test', $id->email());
        self::assertSame('amy', $id->username());
        self::assertSame('active', $id->status());
        self::assertNull($id->sessionUuid());
        self::assertNull($id->provider());
    }

    public function test_roles_and_scopes_are_backed_by_claims_bag(): void
    {
        $id = new UserIdentity('u1', ['role.a'], ['s1']);
        self::assertSame(['role.a'], $id->claim('roles'));
        self::assertSame(['s1'], $id->claim('scopes'));
    }

    public function test_with_claims_is_immutable_and_preserves_identity_facts(): void
    {
        $id = new UserIdentity('u1', email: 'a@b.test', status: 'active');
        $enriched = $id->withClaims(['roles' => ['role.admin'], 'x' => 1]);

        // original unchanged (immutable)
        self::assertSame([], $id->roles());
        // new instance carries the set claim keys (withClaims is a key-level array_merge —
        // it REPLACES claim keys; additive/union semantics are IdentityResolver::mergeClaims()'s
        // job, NOT withClaims()'s).
        self::assertSame(['role.admin'], $enriched->roles());
        self::assertSame(1, $enriched->claim('x'));
        // identity facts are preserved — withClaims only touches the claims bag
        self::assertSame('a@b.test', $enriched->email());
        self::assertSame('active', $enriched->status());
        self::assertNotSame($id, $enriched);
    }

    public function test_with_session_sets_runtime_fields_immutably(): void
    {
        $id = new UserIdentity('u1');
        $live = $id->withSession('sess-1', 'jwt');
        self::assertNull($id->sessionUuid());
        self::assertSame('sess-1', $live->sessionUuid());
        self::assertSame('jwt', $live->provider());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/UserIdentityTest.php`
Expected: FAIL — `uuid()`, `email()`, `claim()`, `withClaims()`, `withSession()` undefined.

- [ ] **Step 3: Write the implementation**

Replace `src/Auth/UserIdentity.php` entirely:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth;

/**
 * Canonical authenticated identity plus its runtime claims (NOT a database user row).
 *
 * Final + immutable. All extensibility flows through the open claims bag and the
 * with*() builders — never through subclassing. Identity facts (uuid/email/username/
 * status) are owned by the UserProvider and are NOT overwritable via claims.
 */
final class UserIdentity
{
    /** @var array<string, mixed> Open claims bag; 'roles' and 'scopes' are well-known keys. */
    private array $claims;

    /**
     * @param array<int,string>   $roles      Folded into claims['roles'] (legacy positional arg).
     * @param array<int,string>   $scopes     Folded into claims['scopes'] (legacy positional arg).
     * @param array<string,mixed> $attributes Legacy attribute bag, folded into the claims bag.
     */
    public function __construct(
        private string $uuid,
        array $roles = [],
        array $scopes = [],
        array $attributes = [],
        private ?string $email = null,
        private ?string $username = null,
        private ?string $status = null,
        private ?string $sessionUuid = null,
        private ?string $provider = null,
    ) {
        $this->claims = $attributes;
        $this->claims['roles'] = array_values($roles);
        $this->claims['scopes'] = array_values($scopes);
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    /** Legacy alias for uuid(); kept so existing permission voters/policies keep working. */
    public function id(): string
    {
        return $this->uuid;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function sessionUuid(): ?string
    {
        return $this->sessionUuid;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    /** @return array<int,string> */
    public function roles(): array
    {
        $roles = $this->claims['roles'] ?? [];
        return is_array($roles) ? array_values($roles) : [];
    }

    /** @return array<int,string> */
    public function scopes(): array
    {
        $scopes = $this->claims['scopes'] ?? [];
        return is_array($scopes) ? array_values($scopes) : [];
    }

    /** @return array<string,mixed> */
    public function claims(): array
    {
        return $this->claims;
    }

    public function claim(string $key, mixed $default = null): mixed
    {
        return $this->claims[$key] ?? $default;
    }

    /** Legacy alias for claim(); kept for existing attribute-bag callers. */
    public function attr(string $key, mixed $default = null): mixed
    {
        return $this->claim($key, $default);
    }

    /**
     * Return a copy with $claims merged on top (key-level array_merge: new keys added,
     * existing keys REPLACED). Identity facts (uuid/email/username/status) are never touched.
     * NOTE: this is not union/additive at the key level — additive composition across claims
     * providers is enforced by IdentityResolver::mergeClaims(), not here.
     *
     * @param array<string,mixed> $claims
     */
    public function withClaims(array $claims): self
    {
        $clone = clone $this;
        $clone->claims = array_merge($this->claims, $claims);
        return $clone;
    }

    /** Return a copy with runtime session context attached. */
    public function withSession(string $sessionUuid, string $provider): self
    {
        $clone = clone $this;
        $clone->sessionUuid = $sessionUuid;
        $clone->provider = $provider;
        return $clone;
    }

    /** @return array<string,mixed> Stable shape for session/token persistence + logging. */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'session_uuid' => $this->sessionUuid,
            'provider' => $this->provider,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $this->status,
            'roles' => $this->roles(),
            'permissions' => $this->claim('permissions', []),
            'claims' => $this->claims,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Auth/UserIdentityTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Auth/UserIdentity.php tests/Unit/Auth/UserIdentityTest.php
git commit -m "feat(auth): evolve UserIdentity into canonical immutable identity"
```

---

## Task 2: `UserProviderInterface` + `IdentityClaimsProviderInterface`

**Files:**
- Create: `src/Auth/Contracts/UserProviderInterface.php`
- Create: `src/Auth/Contracts/IdentityClaimsProviderInterface.php`
- Test: `tests/Unit/Auth/ContractsExistTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\Contracts\{UserProviderInterface, IdentityClaimsProviderInterface};
use Glueful\Auth\UserIdentity;
use PHPUnit\Framework\TestCase;

final class ContractsExistTest extends TestCase
{
    public function test_user_provider_contract_shape(): void
    {
        $p = new class implements UserProviderInterface {
            public function findByUuid(string $uuid): ?UserIdentity
            {
                return $uuid === 'u1' ? new UserIdentity('u1') : null;
            }
            public function findByLogin(string $identifier): ?UserIdentity
            {
                return null;
            }
            public function verifyCredentials(string $identifier, string $password): ?UserIdentity
            {
                return null;
            }
        };
        self::assertInstanceOf(UserIdentity::class, $p->findByUuid('u1'));
        self::assertNull($p->findByUuid('nope'));
    }

    public function test_claims_provider_contract_shape(): void
    {
        $c = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $identity): UserIdentity
            {
                return $identity->withClaims(['roles' => ['role.admin']]);
            }
        };
        self::assertSame(['role.admin'], $c->enrich(new UserIdentity('u1'))->roles());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/ContractsExistTest.php`
Expected: FAIL — interfaces not found.

- [ ] **Step 3: Write the implementations**

`src/Auth/Contracts/UserProviderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Contracts;

use Glueful\Auth\UserIdentity;

/**
 * Resolves identities and verifies credentials. Implemented by glueful/users (or any
 * app user store). Authentication-only: registration/provisioning/profile writes are
 * NOT part of this contract (core never provisions users).
 */
interface UserProviderInterface
{
    public function findByUuid(string $uuid): ?UserIdentity;

    /** Identifier-agnostic lookup (email/username/etc.) — rules live in the implementation. */
    public function findByLogin(string $identifier): ?UserIdentity;

    /** Returns the identity on success, null on invalid credentials. */
    public function verifyCredentials(string $identifier, string $password): ?UserIdentity;
}
```

`src/Auth/Contracts/IdentityClaimsProviderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth\Contracts;

use Glueful\Auth\UserIdentity;

/**
 * Decorates an authenticated identity with claims (roles/permissions/scopes) post-auth.
 * Implemented by authorization providers (e.g. glueful/aegis). MUST only ADD claims —
 * the core re-pins identity facts after each call, so enrich() can never change who the
 * user is, only what they can do.
 */
interface IdentityClaimsProviderInterface
{
    public function enrich(UserIdentity $identity): UserIdentity;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Auth/ContractsExistTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Auth/Contracts/ tests/Unit/Auth/ContractsExistTest.php
git commit -m "feat(auth): add UserProviderInterface + IdentityClaimsProviderInterface"
```

---

## Task 3: `NullUserProvider` (fail-closed default)

**Files:**
- Create: `src/Auth/NullUserProvider.php`
- Test: `tests/Unit/Auth/NullUserProviderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\NullUserProvider;
use PHPUnit\Framework\TestCase;

final class NullUserProviderTest extends TestCase
{
    public function test_everything_resolves_to_null_fail_closed(): void
    {
        $p = new NullUserProvider();
        self::assertNull($p->findByUuid('u1'));
        self::assertNull($p->findByLogin('a@b.test'));
        self::assertNull($p->verifyCredentials('a@b.test', 'secret'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/NullUserProviderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Contracts\UserProviderInterface;

/**
 * Fail-closed default user provider. Bound when no app/extension provider is registered:
 * every lookup and credential check returns null, so authentication cannot succeed.
 */
final class NullUserProvider implements UserProviderInterface
{
    public function findByUuid(string $uuid): ?UserIdentity
    {
        return null;
    }

    public function findByLogin(string $identifier): ?UserIdentity
    {
        return null;
    }

    public function verifyCredentials(string $identifier, string $password): ?UserIdentity
    {
        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Auth/NullUserProviderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Auth/NullUserProvider.php tests/Unit/Auth/NullUserProviderTest.php
git commit -m "feat(auth): add fail-closed NullUserProvider default"
```

---

## Task 4: `IdentityResolver` — status gate + claims-composition fold

**Files:**
- Create: `src/Auth/IdentityResolver.php`
- Test: `tests/Unit/Auth/IdentityResolverTest.php`

The resolver takes a verified identity, applies the status gate (reject only on an explicit non-active status; `null` = allowed), then folds every registered claims provider, **re-pinning identity facts after each** so enrichment is add-only.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Auth;

use Glueful\Auth\Contracts\IdentityClaimsProviderInterface;
use Glueful\Auth\{IdentityResolver, UserIdentity};
use PHPUnit\Framework\TestCase;

final class IdentityResolverTest extends TestCase
{
    public function test_active_and_null_status_pass_the_gate(): void
    {
        $resolver = new IdentityResolver([]);
        self::assertInstanceOf(UserIdentity::class, $resolver->resolve(new UserIdentity('u1', status: 'active')));
        self::assertInstanceOf(UserIdentity::class, $resolver->resolve(new UserIdentity('u1', status: null)));
    }

    public function test_explicit_non_active_status_is_rejected(): void
    {
        $resolver = new IdentityResolver([]);
        self::assertNull($resolver->resolve(new UserIdentity('u1', status: 'suspended')));
        self::assertNull($resolver->resolve(new UserIdentity('u1', status: 'disabled')));
    }

    public function test_claims_providers_are_folded_in_order(): void
    {
        $a = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $i): UserIdentity
            {
                return $i->withClaims(['roles' => ['role.a']]);
            }
        };
        $b = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $i): UserIdentity
            {
                return $i->withClaims(['scopes' => ['s1']]);
            }
        };
        $resolver = new IdentityResolver([$a, $b]);
        $out = $resolver->resolve(new UserIdentity('u1', status: 'active'));
        self::assertSame(['role.a'], $out->roles());
        self::assertSame(['s1'], $out->scopes());
    }

    public function test_empty_contribution_does_not_wipe_prior_claims(): void
    {
        $sets = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $i): UserIdentity
            {
                return $i->withClaims(['roles' => ['role.a'], 'scopes' => ['s1']]);
            }
        };
        // A naive provider returns a FRESH identity whose roles/scopes default to [].
        // The union merge must NOT let those empties erase role.a / s1.
        $naive = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $i): UserIdentity
            {
                return new UserIdentity($i->uuid(), status: 'active');
            }
        };
        $resolver = new IdentityResolver([$sets, $naive]);
        $out = $resolver->resolve(new UserIdentity('u1', status: 'active'));
        self::assertSame(['role.a'], $out->roles());
        self::assertSame(['s1'], $out->scopes());
    }

    public function test_provider_cannot_overwrite_identity_facts(): void
    {
        $evil = new class implements IdentityClaimsProviderInterface {
            public function enrich(UserIdentity $i): UserIdentity
            {
                // Attempt to impersonate by returning a different uuid/email/status.
                return new UserIdentity('attacker', email: 'evil@x.test', status: 'suspended');
            }
        };
        $resolver = new IdentityResolver([$evil]);
        $out = $resolver->resolve(new UserIdentity('u1', email: 'real@x.test', status: 'active'));

        self::assertNotNull($out);
        self::assertSame('u1', $out->uuid());           // re-pinned
        self::assertSame('real@x.test', $out->email()); // re-pinned
        self::assertSame('active', $out->status());     // re-pinned (so it still passed the gate)
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Auth/IdentityResolverTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Auth\Contracts\IdentityClaimsProviderInterface;

/**
 * Applies the account-status gate and composes claims from all registered providers.
 * Two invariants (spec §4 trust rule):
 *  - identity facts (uuid/email/username/status) are never changed by a provider;
 *  - claims are ADDED, never wiped — list claims (roles/scopes/permissions) are UNIONed,
 *    so a provider returning empty/default lists cannot erase an earlier provider's claims.
 */
final class IdentityResolver
{
    /** @param list<IdentityClaimsProviderInterface> $claimsProviders */
    public function __construct(private array $claimsProviders)
    {
    }

    /**
     * @return UserIdentity|null Enriched identity, or null if the status gate rejects it.
     */
    public function resolve(UserIdentity $identity): ?UserIdentity
    {
        if (!$this->statusAllowsLogin($identity->status())) {
            return null;
        }

        foreach ($this->claimsProviders as $provider) {
            $contributed = $provider->enrich($identity)->claims();
            // withClaims() preserves identity facts (re-pin); mergeClaims() makes it additive.
            $identity = $identity->withClaims($this->mergeClaims($identity->claims(), $contributed));
        }

        return $identity;
    }

    /** Reject only explicit non-active statuses; null = "store has no opinion" = allowed. */
    private function statusAllowsLogin(?string $status): bool
    {
        return $status === null || $status === 'active';
    }

    /**
     * Non-destructive merge. List claims (roles/scopes + list-style permissions) are UNIONed
     * so empty contributions never wipe earlier claims; map-style permissions merge per-resource;
     * any other claim takes the incoming value when present.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    private function mergeClaims(array $base, array $incoming): array
    {
        $merged = array_merge($base, $incoming);

        foreach (['roles', 'scopes'] as $key) {
            $merged[$key] = $this->unionList($base[$key] ?? [], $incoming[$key] ?? []);
        }

        $bp = is_array($base['permissions'] ?? null) ? $base['permissions'] : [];
        $ip = is_array($incoming['permissions'] ?? null) ? $incoming['permissions'] : [];
        if ($bp !== [] || $ip !== []) {
            if (array_is_list($bp) && array_is_list($ip)) {
                $merged['permissions'] = $this->unionList($bp, $ip);
            } else {
                // map shape e.g. ['system' => ['a','b']] — union each resource's list.
                $out = $bp;
                foreach ($ip as $resource => $perms) {
                    $out[$resource] = $this->unionList($out[$resource] ?? [], is_array($perms) ? $perms : [$perms]);
                }
                $merged['permissions'] = $out;
            }
        }

        return $merged;
    }

    /**
     * @return list<mixed>
     */
    private function unionList(mixed $a, mixed $b): array
    {
        $a = is_array($a) ? array_values($a) : [];
        $b = is_array($b) ? array_values($b) : [];
        return array_values(array_unique([...$a, ...$b]));
    }
}
```

> `withClaims()` preserves identity facts by construction (it only touches the claims bag), so even a provider returning a wholly different `UserIdentity` contributes only its `claims()`. `mergeClaims()` then guarantees additivity: union for `roles`/`scopes`/list-`permissions`, per-resource union for map-`permissions`. The status check is an allow-list (`=== 'active'`/null) — keep it an allow-list, never a deny-list, to stay fail-closed.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Auth/IdentityResolverTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Auth/IdentityResolver.php tests/Unit/Auth/IdentityResolverTest.php
git commit -m "feat(auth): add IdentityResolver (status gate + additive claims fold)"
```

---

## Task 5: Retire `AuthenticatedUser` onto `UserIdentity`

This is the churn task: 18 references across 4 files. The canonical type is method-accessed (`->uuid()`), the old one property-accessed (`->uuid`).

**Files:**
- Delete: `src/Auth/AuthenticatedUser.php`
- Modify: `src/Http/RequestUserContext.php`
- Modify: `src/Controllers/BaseController.php`
- Modify: `src/Controllers/Traits/CachedUserContextTrait.php`
- Modify: `src/Controllers/Traits/AuthorizationTrait.php`
- Test: `tests/Integration/Http/RequestUserContextIdentityTest.php`

- [ ] **Step 1: Confirm the consumer surface**

Run:
```bash
grep -rn "AuthenticatedUser" src --include="*.php"
grep -rn "AuthenticatedUser" /Users/michaeltawiahsowah/Sites/glueful/api-skeleton /Users/michaeltawiahsowah/Sites/glueful/extensions/aegis 2>/dev/null
```
Expected: matches only in the 5 framework files above; **zero** in skeleton/aegis. If any external consumer appears, add it to this task's file list before proceeding.

- [ ] **Step 2: Write the failing test**

`tests/Integration/Http/RequestUserContextIdentityTest.php` — boot a SQLite app (mirror `tests/Integration/Testing/ActingWithPermissionsTest.php` setup) and assert `getUser()` returns a `UserIdentity`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Http\RequestUserContext;
use Glueful\Testing\TestCase;

final class RequestUserContextIdentityTest extends TestCase
{
    public function test_get_user_returns_user_identity_type(): void
    {
        $ctx = RequestUserContext::getInstance('req-test');
        // With no token in the test request, getUser() is null — but the return TYPE must be
        // ?UserIdentity, proving AuthenticatedUser is gone from the signature.
        // NB: a nullable named type stringifies as "?Glueful\Auth\UserIdentity", so inspect
        // the ReflectionNamedType rather than comparing the stringified form.
        $type = (new \ReflectionMethod(RequestUserContext::class, 'getUser'))->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(UserIdentity::class, $type->getName());
        self::assertTrue($type->allowsNull());
        self::assertNull($ctx->getUser());
    }
}
```

> If `getBasePath()`/SQLite bootstrapping is needed for `RequestUserContext` construction in your harness, copy the temp-config `setUp()` from `ActingWithPermissionsTest`. The reflection assertion is the core check; the `getUser()` null call just exercises the path.

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Http/RequestUserContextIdentityTest.php`
Expected: FAIL — return type is `AuthenticatedUser|null`.

- [ ] **Step 4: Migrate `RequestUserContext`**

In `src/Http/RequestUserContext.php`:

- Remove `use Glueful\Auth\AuthenticatedUser;` (line 8).
- Add `use Glueful\Auth\UserIdentity;` (it currently uses the FQCN inline at line 768).
- Change the cached field type: `private ?UserIdentity $user = null;` (line 69).
- Change `getUser(): ?UserIdentity` (line 180) and the `getCachedUser`/docblocks.
- `getUserUuid()` (line 193): `return $this->getUser()?->uuid();` (method, not property).
- `getAuditContext()` (line 511): `'user_uuid' => $user?->uuid(),`.
- `isAdmin()` (line 246): `$userData = $user->toArray();` still works (`UserIdentity::toArray()` exists).
- Rewrite `buildUserFromSession()` (lines 566–589) to build a `UserIdentity` with claims carrying roles/permissions and runtime session fields:

```php
/** @param array<string, mixed> $sessionData */
private function buildUserFromSession(array $sessionData): ?UserIdentity
{
    $sessionUser = is_array($sessionData['user'] ?? null) ? $sessionData['user'] : [];
    $userUuid = isset($sessionUser['uuid'])
        ? (string) $sessionUser['uuid']
        : (string) ($sessionData['user_uuid'] ?? '');
    if ($userUuid === '') {
        return null;
    }

    $roles = is_array($sessionUser['roles'] ?? null) ? array_values($sessionUser['roles']) : [];
    $permissions = is_array($sessionUser['permissions'] ?? null) ? $sessionUser['permissions'] : [];

    $identity = new UserIdentity(
        uuid: $userUuid,
        roles: $roles,
        scopes: [],
        attributes: ['permissions' => $permissions],
        email: isset($sessionUser['email']) ? (string) $sessionUser['email'] : null,
        username: isset($sessionUser['username']) ? (string) $sessionUser['username'] : null,
        status: isset($sessionUser['status']) ? (string) $sessionUser['status'] : null,
    );

    $sessionUuid = isset($sessionData['id']) ? (string) $sessionData['id'] : null;
    $provider = isset($sessionData['provider']) ? (string) $sessionData['provider'] : null;
    if ($sessionUuid !== null && $provider !== null) {
        $identity = $identity->withSession($sessionUuid, $provider);
    }

    return $identity;
}
```

- Rewrite `buildUserFromToken()` (lines 591–613) to return a `UserIdentity`:

```php
private function buildUserFromToken(string $token): ?UserIdentity
{
    try {
        $jwtPayload = \Glueful\Auth\JWTService::decode($token);
        if ($jwtPayload === null) {
            return null;
        }
        $userUuid = isset($jwtPayload['sub'])
            ? (string) $jwtPayload['sub']
            : (string) ($jwtPayload['uuid'] ?? '');
        if ($userUuid === '') {
            return null;
        }
        $identity = new UserIdentity($userUuid);
        if (isset($jwtPayload['sid'])) {
            $identity = $identity->withSession((string) $jwtPayload['sid'], 'jwt');
        }
        return $identity;
    } catch (\Exception $e) {
        error_log("Failed to build user from token: " . $e->getMessage());
        return null;
    }
}
```

- Delete the now-redundant `buildUserIdentity()` (lines 767–777): `hasRoleBasedPermission()` (line 467) should use the cached `$this->user` directly. Replace `$identity = $this->buildUserIdentity();` with:

```php
        $identity = $this->getUser() ?? new \Glueful\Auth\UserIdentity('anonymous');
```

- [ ] **Step 5: Migrate the controller files**

For each of `BaseController.php`, `Traits/CachedUserContextTrait.php`, `Traits/AuthorizationTrait.php`:
- Replace `use Glueful\Auth\AuthenticatedUser;` with `use Glueful\Auth\UserIdentity;`.
- Replace every `AuthenticatedUser` type hint / docblock with `UserIdentity` (`$currentUser`, `getCachedUser(): ?UserIdentity`, `getCurrentUser(): ?UserIdentity`).
- Replace property access on the user object: `->uuid` → `->uuid()`, `->email` → `->email()`, `->username` → `->username()`, `->roles` → `->roles()`, `->permissions` → `->claim('permissions', [])`.

Run after editing each file:
```bash
grep -n "AuthenticatedUser\|->uuid\b\|->email\b\|->roles\b\|->permissions\b" \
  src/Controllers/BaseController.php src/Controllers/Traits/CachedUserContextTrait.php \
  src/Controllers/Traits/AuthorizationTrait.php
```
Expected: no `AuthenticatedUser`, no property-style access on the identity object remaining.

- [ ] **Step 6: Delete `AuthenticatedUser`**

```bash
git rm src/Auth/AuthenticatedUser.php
grep -rn "AuthenticatedUser" src --include="*.php"   # expect: no matches
```

- [ ] **Step 7: Run targeted + full tests**

Run: `vendor/bin/phpunit tests/Integration/Http/RequestUserContextIdentityTest.php && composer test`
Expected: PASS. Fix any remaining `->uuid`-style access the grep missed.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(auth): retire AuthenticatedUser; expose canonical UserIdentity"
```

---

## Task 6: DI wiring — bind provider, resolver, and claims providers

**Files:**
- Modify: `src/Container/Providers/CoreProvider.php`
- Test: `tests/Integration/Container/IdentityWiringTest.php`

- [ ] **Step 1: Inspect current registrations**

Run:
```bash
grep -n "PermissionRegistry\|Gate\|GateAttributeMiddleware\|permission.manager\|RegistryRoleVoter\|IdentityClaimsProvider\|UserProviderInterface" src/Container/Providers/CoreProvider.php
```
Note how the permissions factory enumerates providers (the Phase-permissions work added `RegistryRoleVoter` to the Gate factory) — mirror that pattern to collect `IdentityClaimsProviderInterface` implementations from registered extension service providers.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Container;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\{IdentityResolver, NullUserProvider};
use Glueful\Testing\TestCase;

final class IdentityWiringTest extends TestCase
{
    public function test_default_user_provider_is_null_provider(): void
    {
        self::assertInstanceOf(NullUserProvider::class, $this->get(UserProviderInterface::class));
    }

    public function test_identity_resolver_is_resolvable(): void
    {
        self::assertInstanceOf(IdentityResolver::class, $this->get(IdentityResolver::class));
    }
}
```

> Uses `Glueful\Testing\TestCase::get()` (container accessor) as in `ActingWithPermissionsTest`. Confirm the accessor name in that base class.

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Container/IdentityWiringTest.php`
Expected: FAIL — `UserProviderInterface` / `IdentityResolver` not bound.

- [ ] **Step 4: Register in `CoreProvider`**

Add definitions in the file's actual style — `$defs[Id::class] = new FactoryDefinition(...)` (matches the existing `Gate` factory at ~line 323; `FactoryDefinition` is already imported):

```php
// Fail-closed default; glueful/users overrides this binding when installed (Phase 4).
$defs[UserProviderInterface::class] = new FactoryDefinition(
    UserProviderInterface::class,
    fn(\Psr\Container\ContainerInterface $c) => new NullUserProvider()
);

// IdentityResolver folds every service tagged 'identity.claims_provider'.
$defs[IdentityResolver::class] = new FactoryDefinition(
    IdentityResolver::class,
    function (\Psr\Container\ContainerInterface $c): IdentityResolver {
        // ContainerFactory auto-registers a TaggedIteratorDefinition under the tag NAME, so
        // get('identity.claims_provider') returns an array of provider instances (priority-sorted).
        // Same consumption pattern as 'console.commands' (see Console/Application.php:66-68).
        $providers = $c->has('identity.claims_provider') ? $c->get('identity.claims_provider') : [];
        return new IdentityResolver(is_array($providers) ? array_values($providers) : []);
    }
);
```

with `use` imports for `UserProviderInterface`, `NullUserProvider`, `IdentityResolver` (and `FactoryDefinition` if not already imported).

> **Concrete discovery mechanism (locked, do not improvise):** the framework container has a first-class tag system. Producers register a claims provider with the tag `identity.claims_provider` via `BaseServiceProvider::tag(MyClaimsProvider::class, 'identity.claims_provider', $priority)` in a service provider, **or** `'tags' => [...]` in a service definition — exactly how `console.commands`, `cache.pool`, and `http.request` are tagged today (`grep -rn "->tag(" src/Container`). `ContainerFactory` turns the tag into a `TaggedIteratorDefinition` resolvable as `get('identity.claims_provider')`. Phase 5's Aegis `IdentityClaimsProvider` uses this exact tag — the tag name is the contract between producer and consumer, so it must match verbatim.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Container/IdentityWiringTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Container/Providers/CoreProvider.php tests/Integration/Container/IdentityWiringTest.php
git commit -m "feat(container): bind NullUserProvider + IdentityResolver"
```

---

## Task 7: Verification + static analysis

- [ ] **Step 1:** Run `vendor/bin/phpunit tests/Unit/Auth tests/Integration/Http tests/Integration/Container` → PASS.
- [ ] **Step 2:** Run `composer test` → PASS (full suite; catches any missed `AuthenticatedUser` consumer).
- [ ] **Step 3:** Run `composer run analyse:changed && composer run phpcs` → clean. Fix claims-bag generics (`array<string,mixed>`) and nullable returns as needed.
- [ ] **Step 4:** Commit fixups: `git commit -am "test(auth): phase 2 verification + analysis fixups"`.

## Phase 2 done-when

- Canonical `final` `UserIdentity` with BC constructor, identity facts, claims bag, typed `roles()/scopes()`, immutable `with*()`.
- `UserProviderInterface` + `IdentityClaimsProviderInterface` exist; `NullUserProvider` bound as default; `IdentityResolver` composes status gate + additive claims fold (provider cannot rewrite identity facts).
- `AuthenticatedUser` deleted; `RequestUserContext` + controller traits expose `UserIdentity`; zero remaining references.
- `composer test`, `analyse:changed`, `phpcs` green.
