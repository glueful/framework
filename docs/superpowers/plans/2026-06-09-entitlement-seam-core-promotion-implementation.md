# Entitlement Seam -- Core Promotion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Depends on:** `2026-06-09-container-extension-precedence-fix-implementation.md` (Plan A). That fix is what makes a core default binding overridable by an extension; without it, `glueful/subscriptions` cannot replace `NullEntitlementChecker`. **Land Plan A first.**

**Goal:** Promote the entitlement contract into Glueful core as a shared extension point -- `EntitlementCheckerInterface` + an allow-all `NullEntitlementChecker` + a default container binding -- WITHOUT teaching any core subsystem about tenants, plans, or subscription vocabulary.

**Architecture:** Contract-only promotion. Core publishes the interface and binds an absent-allow `NullEntitlementChecker` as the default (the `UserProviderInterface -> NullUserProvider` template in `CoreProvider`). `glueful/subscriptions` overrides the binding with its real checker (override mechanics provided by Plan A). Core behavior is unchanged everywhere -- rate limiting still depends only on `TierResolverInterface`; the entitlement<->rate-limit bridge lives extension-side. The payoff is reuse: any extension can type against the core contract without depending on `glueful/subscriptions`.

**Tech Stack:** PHP 8.3+, Glueful framework, PHPUnit, `Glueful\Container` (`FactoryDefinition`).

**Scope guardrail (read before starting):** This plan adds a contract + null default + binding + tests + docs. It does **NOT** add any consumer and **NOT** any container change (that is Plan A). No rate-limiting changes, no tenant awareness, no entitlement keys anywhere in `src/` outside `src/Entitlements/`. If a task tempts you to import a rate-limiter, tenancy, or subscription type into core, stop.

---

## Why this is contract-only

Core owning the entitlement seam does not make core entitlement-aware. The interface + null default is a published extension point, exactly like `Glueful\Auth\Contracts\UserProviderInterface`. The concrete cross-domain wiring (read a tenant, map entitlements to a rate tier) is coupling that belongs in the extension that knows both domains. Rule: **promote the contract to core; keep consumers extension-side unless a consumer is naturally generic and tenancy-free.** No such core consumer exists today, so this plan ships none.

## Sequencing / relationship to the other plans

- **Plan A (container precedence)** lands first -- it makes the default binding overridable. This plan assumes it.
- This is a **framework** change and ships in the next framework **minor** release (after 1.53.0 "Nunki"), together with Plan A. Releasing is a separate `release`-skill step.
- **Subscriptions reconciliation is a separate delta** (after the release): drop its local `EntitlementCheckerInterface`/`NullEntitlementChecker` + the dual PSR-4 root, consume the core contract, add the `EntitlementTierResolver` bridge, and bump its framework floor to the release that ships this seam. Per the release-first rule, that delta lands only **after** this seam is released.
- The boundary note's "Promotion to a core seam" decision flips from *deferred* to *done (contract only)*; update it when this lands.

## File Structure

| File | Create/Modify | Responsibility |
|---|---|---|
| `src/Entitlements/Contracts/EntitlementCheckerInterface.php` | Create | The public contract: `allows()` / `limit()`, explicit `tenantUuid` first. |
| `src/Entitlements/NullEntitlementChecker.php` | Create | Absent-allow default: `allows()=>true`, `limit()=>null`. |
| `src/Container/Providers/CoreProvider.php` | Modify (in `defs()`) | Bind the interface to `NullEntitlementChecker` via `FactoryDefinition`. |
| `tests/Unit/Entitlements/EntitlementContractTest.php` | Create | Reflection-assert the interface shape. |
| `tests/Unit/Entitlements/NullEntitlementCheckerTest.php` | Create | Unit-test the null default (mirrors `tests/Unit/Auth/NullUserProviderTest.php`). |
| `tests/Unit/Container/EntitlementBindingTest.php` | Create | Resolve the interface from a real container -> `NullEntitlementChecker`. |
| `docs/ENTITLEMENTS.md` | Create | Document the seam + the absent-allow default + override + no-core-consumer. |
| `CHANGELOG.md` | Modify (`[Unreleased]`) | `### Added` entry for the seam. |

---

## Task 1: Entitlement contract

**Files:**
- Create: `src/Entitlements/Contracts/EntitlementCheckerInterface.php`
- Test: `tests/Unit/Entitlements/EntitlementContractTest.php`

- [ ] **Step 1: Write the failing test** (reflection on the interface shape)

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Entitlements;

use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class EntitlementContractTest extends TestCase
{
    public function test_interface_shape(): void
    {
        $rc = new ReflectionClass(EntitlementCheckerInterface::class);
        self::assertTrue($rc->isInterface());

        $allows = $rc->getMethod('allows');
        self::assertSame(
            ['tenantUuid', 'entitlement', 'context'],
            array_map(fn($p) => $p->getName(), $allows->getParameters())
        );
        self::assertSame('bool', (string) $allows->getReturnType());
        self::assertTrue($allows->getParameters()[2]->isDefaultValueAvailable());

        $limit = $rc->getMethod('limit');
        self::assertInstanceOf(ReflectionNamedType::class, $limit->getReturnType());
        self::assertTrue($limit->getReturnType()->allowsNull());
        self::assertSame('int', $limit->getReturnType()->getName());
    }
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `vendor/bin/phpunit --filter=EntitlementContractTest`
Expected: FAIL ("Class EntitlementCheckerInterface not found").

- [ ] **Step 3: Create the interface**

```php
<?php

declare(strict_types=1);

namespace Glueful\Entitlements\Contracts;

/**
 * Commercial entitlement gate: "does this tenant's plan include this capability?"
 *
 * Entitlements are paywall/capability gates, NOT security boundaries -- they are
 * absent-allow (see NullEntitlementChecker), the opposite of authorization (aegis)
 * and tenancy, which fail closed. The checker takes an explicit tenant uuid so it
 * works in jobs, CLI, webhooks, and admin flows outside a request.
 */
interface EntitlementCheckerInterface
{
    /**
     * @param array<string,mixed> $context optional extras (e.g. a resource id)
     */
    public function allows(string $tenantUuid, string $entitlement, array $context = []): bool;

    /**
     * @param array<string,mixed> $context optional extras (e.g. a resource id)
     */
    public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int;
}
```

- [ ] **Step 4: Run it, expect pass**

Run: `vendor/bin/phpunit --filter=EntitlementContractTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Entitlements/Contracts/EntitlementCheckerInterface.php tests/Unit/Entitlements/EntitlementContractTest.php
git commit -m "feat(entitlements): EntitlementCheckerInterface core contract"
```

---

## Task 2: Null (absent-allow) default

**Files:**
- Create: `src/Entitlements/NullEntitlementChecker.php`
- Test: `tests/Unit/Entitlements/NullEntitlementCheckerTest.php` (mirror `tests/Unit/Auth/NullUserProviderTest.php`)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Entitlements;

use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use Glueful\Entitlements\NullEntitlementChecker;
use PHPUnit\Framework\TestCase;

final class NullEntitlementCheckerTest extends TestCase
{
    public function test_allows_everything_and_limits_are_unlimited(): void
    {
        $checker = new NullEntitlementChecker();

        self::assertInstanceOf(EntitlementCheckerInterface::class, $checker);
        self::assertTrue($checker->allows('tenant-1', 'reports.export'));
        self::assertTrue($checker->allows('tenant-1', 'anything.at.all', ['resource' => 9]));
        self::assertNull($checker->limit('tenant-1', 'projects.limit'));
        self::assertNull($checker->limit('tenant-1', 'api.monthly', ['x' => 1]));
    }
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `vendor/bin/phpunit --filter=NullEntitlementCheckerTest`
Expected: FAIL ("Class NullEntitlementChecker not found").

- [ ] **Step 3: Create the null default**

```php
<?php

declare(strict_types=1);

namespace Glueful\Entitlements;

use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;

/**
 * Absent-allow default bound by core when no entitlements provider is installed.
 *
 * Entitlements are commercial gates, not security boundaries, so the absence of a
 * subscriptions/entitlements extension must never lock an app out of its own routes.
 * glueful/subscriptions (or any provider) overrides the container binding with a real
 * tenant-aware checker.
 */
final class NullEntitlementChecker implements EntitlementCheckerInterface
{
    public function allows(string $tenantUuid, string $entitlement, array $context = []): bool
    {
        return true;
    }

    public function limit(string $tenantUuid, string $entitlement, array $context = []): ?int
    {
        return null;
    }
}
```

- [ ] **Step 4: Run it, expect pass**

Run: `vendor/bin/phpunit --filter=NullEntitlementCheckerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Entitlements/NullEntitlementChecker.php tests/Unit/Entitlements/NullEntitlementCheckerTest.php
git commit -m "feat(entitlements): NullEntitlementChecker absent-allow default"
```

---

## Task 3: Default container binding in CoreProvider

**Files:**
- Modify: `src/Container/Providers/CoreProvider.php` (inside `defs()`, alongside the `UserProviderInterface` binding around line 363)
- Test: `tests/Unit/Container/EntitlementBindingTest.php`

- [ ] **Step 1: Write the failing test** -- resolve the interface from a real container built from `CoreProvider::defs()` (concrete harness, mirrors `tests/Unit/Container/Providers/StorageProviderImageValidatorTest.php`):

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Providers\CoreProvider;
use Glueful\Container\Providers\TagCollector;
use Glueful\Entitlements\Contracts\EntitlementCheckerInterface;
use Glueful\Entitlements\NullEntitlementChecker;
use PHPUnit\Framework\TestCase;

final class EntitlementBindingTest extends TestCase
{
    public function test_core_binds_entitlement_checker_to_null_default(): void
    {
        // tests/Unit/Container -> framework root is dirname(__DIR__, 3)
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $provider = new CoreProvider(new TagCollector(), $context);

        $container = new Container($provider->defs());

        $checker = $container->get(EntitlementCheckerInterface::class);

        self::assertInstanceOf(NullEntitlementChecker::class, $checker);
    }
}
```

(This proves only the core *default*. The *override* path is the responsibility of Plan A's `ContainerPrecedenceTest` / `ExtensionOverridesCoreDefaultTest`.)

- [ ] **Step 2: Run it, expect failure**

Run: `vendor/bin/phpunit --filter=EntitlementBindingTest`
Expected: FAIL (no binding for `EntitlementCheckerInterface`).

- [ ] **Step 3: Add the binding** in `CoreProvider::defs()`, immediately after the `UserProviderInterface` binding (same absent-default pattern):

```php
        // Absent-allow default: entitlements are commercial gates, not security boundaries,
        // so the absence of a subscriptions/entitlements extension must never lock an app out
        // of its own routes (the opposite of UserProviderInterface, which fails closed).
        // glueful/subscriptions (or any provider) overrides this with a real tenant-aware checker.
        $defs[\Glueful\Entitlements\Contracts\EntitlementCheckerInterface::class] = new FactoryDefinition(
            \Glueful\Entitlements\Contracts\EntitlementCheckerInterface::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Entitlements\NullEntitlementChecker()
        );
```

- [ ] **Step 4: Run it, expect pass**

Run: `vendor/bin/phpunit --filter=EntitlementBindingTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Container/Providers/CoreProvider.php tests/Unit/Container/EntitlementBindingTest.php
git commit -m "feat(entitlements): bind EntitlementCheckerInterface to NullEntitlementChecker default"
```

---

## Task 4: Docs + changelog

**Files:**
- Create: `docs/ENTITLEMENTS.md`
- Modify: `CHANGELOG.md` (`[Unreleased]`)

- [ ] **Step 1: Write `docs/ENTITLEMENTS.md`** covering: what the seam is (commercial capability gate, NOT authorization -- contrast with aegis/permissions and tenancy, both fail-closed); the absent-allow default and why; the contract (`allows`/`limit`, explicit `tenantUuid`); how to override the binding (any provider binds `EntitlementCheckerInterface` and overrides the core default -- enabled by Plan A); and an explicit note that **core ships no consumer** (consumers, e.g. an entitlement-driven rate-limit `TierResolver`, live in extensions that know both domains). Reference `glueful/subscriptions` as the reference provider without depending on it.

- [ ] **Step 2: Add a `CHANGELOG.md` `[Unreleased]` `### Added` entry**

```markdown
### Added
- **Entitlement seam (`Glueful\Entitlements`).** New core extension point: `EntitlementCheckerInterface` (`allows()` / `limit()`, explicit tenant uuid) with an absent-allow `NullEntitlementChecker` default bound in `CoreProvider`. Lets extensions and app code gate commercial capabilities without depending on any specific subscriptions package. Core ships the contract only -- no consumer, no tenant/plan awareness. `glueful/subscriptions` provides the real checker. (Override of the default relies on the container precedence fix in the same release.)
```

- [ ] **Step 3: Commit**

```bash
git add docs/ENTITLEMENTS.md CHANGELOG.md
git commit -m "docs(entitlements): document the core entitlement seam"
```

---

## Final verification

- [ ] Run `vendor/bin/phpunit tests/Unit/Entitlements tests/Unit/Container/EntitlementBindingTest.php` -- all PASS.
- [ ] Run `composer phpcs` (PSR-12) on `src/Entitlements` and the edited `CoreProvider` -- clean.
- [ ] Run `composer analyse` (PHPStan) -- no new errors in `src/Entitlements` / `CoreProvider`.
- [ ] Grep guard: `grep -rniE "tenant|subscription|plan|rate" src/Entitlements` returns only doc-comment prose, never an import or behavioral dependency. Core stays domain-ignorant.
- [ ] Confirm nothing in `src/` outside `src/Entitlements/` imports `EntitlementCheckerInterface` (no core consumer was added).
- [ ] With Plan A landed, sanity-check that a stub extension binding `EntitlementCheckerInterface` resolves over the null default (covered by Plan A's tests; no new test needed here).

## Out of scope (explicit)

- Container precedence -- Plan A.
- The `EntitlementTierResolver` rate-limit bridge -- lives in `glueful/subscriptions`.
- Any change to `src/Api/RateLimiting/*` -- untouched.
- Tenant resolution in core.
- Cutting the framework release (separate `release`-skill step).

## Self-review notes

- Every step has concrete code grounded in verified APIs: `ApplicationContext::forTesting()`, `new CoreProvider(new TagCollector(), $context)`, `new Container($defs)`, `$container->get()`, `FactoryDefinition` (lazy `resolve()`), the `UserProviderInterface` binding template.
- Type/method names consistent: `EntitlementCheckerInterface::{allows,limit}`, `NullEntitlementChecker`, namespace `Glueful\Entitlements(\Contracts)`.
- Hard dependency on Plan A is stated up front, not hidden in a task.
- ASCII only.
