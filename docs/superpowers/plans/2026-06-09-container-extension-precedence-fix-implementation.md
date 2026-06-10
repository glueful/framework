# ContainerFactory Extension-Definition Precedence -- Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make extension-provided service definitions override core defaults for the same id, so that every "core binds a default, an extension overrides it" seam actually works -- while protecting framework-reserved keys.

**Architecture:** `ContainerFactory::create()` merges core-provider definitions, then extension definitions. The extension merge currently uses `+=` (array union), which keeps the existing (core) key on collision and silently drops the extension's binding. This changes the extension merge to `array_replace` (extension-over-core, last-layer-wins) via a small testable helper, and re-pins `ApplicationContext` after the merge so a framework-managed value key cannot be clobbered. Within-extension precedence is unchanged.

**Tech Stack:** PHP 8.3+, Glueful framework, PHPUnit, `Glueful\Container` (`ContainerFactory`, `Container`, `FactoryDefinition`, `ValueDefinition`).

---

## Why this is its own plan

This is a framework **DI correctness fix**, not entitlement plumbing. It affects **every** "core default + extension override" seam: `UserProviderInterface -> NullUserProvider` (users), and the same pattern that CDN, queue-ops, and the upcoming entitlement seam all rely on. Because the bug silently drops overrides, those seams cannot currently be overridden through the normal provider path at all.

Splitting it out keeps review clean and the blast radius explicit: if the precedence change has unexpected effects, it can be shipped, reviewed, or rolled back independently of any feature that depends on it.

## The bug (precise)

In `src/Container/Bootstrap/ContainerFactory.php`, `create()`:

```php
foreach (self::providers($tags, $context) as $provider) {
    $defs += $provider->defs();                 // core providers (line 26)
}
$defs[ApplicationContext::class] = new ValueDefinition(...);  // line 30
$defs += self::loadExtensionDefinitions($tags, $context, $prod);  // line 33  <-- BUG
```

`$defs += $extDefs` (array union) keeps `$defs`'s existing keys; any extension binding for an id a core provider already bound is **discarded**. So `CoreProvider` binding `UserProviderInterface => NullUserProvider` (or any seam) cannot be overridden by an extension's `services()`/`defs()`. The "an extension overrides this binding" comments in core providers are currently aspirational.

The fix is the extension-merge layer only (line 33). It applies to both the runtime and freshly-compiled container paths automatically because the merge happens before `new Container($defs)`. **Caveat:** a container compiled *before* this fix is a stale artifact -- `ContainerFactory` loads a precompiled container in prod (`loadPrecompiledIfAvailable()`, reading `sys_get_temp_dir()/glueful_compiled_container.php`). The new precedence only reaches prod once that artifact is regenerated. See the release-validation step below.

## Scope

- **In:** the core-vs-extension merge at line 33; protecting `ApplicationContext`; regression tests; changelog.
- **Out:** within-extension precedence (lines 182/200, `+=`, first-loaded-wins among extensions) -- unchanged; deciding multi-extension override order is a separate concern. No feature code, no entitlement/tenancy/rate-limit imports.

## File Structure

| File | Create/Modify | Responsibility |
|---|---|---|
| `src/Container/Bootstrap/ContainerFactory.php` | Modify | Add `mergeExtensionDefs()` helper (`array_replace`); use it at line 33; re-pin `ApplicationContext` post-merge. |
| `tests/Unit/Container/ContainerPrecedenceTest.php` | Create | Unit regression: `mergeExtensionDefs` -- extension overrides core; core-only preserved; extension-only added. |
| `tests/Integration/Container/ExtensionOverridesCoreDefaultTest.php` | Create | End-to-end through `ContainerFactory::create()`: a fixture app provider overrides `UserProviderInterface` (wins over `NullUserProvider`) and fails to clobber `ApplicationContext` (re-pin holds). |
| `tests/Fixtures/ContainerPrecedence/SeamOverrideProvider.php` | Create | Fixture app provider: `defs()` overrides `UserProviderInterface` + attempts to clobber `ApplicationContext`. |
| `tests/Fixtures/ContainerPrecedence/FakeUserProvider.php` | Create | Test double implementing `UserProviderInterface` (the collision target). |
| `CHANGELOG.md` | Modify (`[Unreleased]`) | `### Fixed` entry. |

---

## Task 1: Switch the extension merge to extension-over-core

**Files:**
- Modify: `src/Container/Bootstrap/ContainerFactory.php`
- Test: `tests/Unit/Container/ContainerPrecedenceTest.php`

- [ ] **Step 1: Write the failing test** (pure merge semantics -- fully concrete, no discovery infra)

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container;

use Glueful\Container\Bootstrap\ContainerFactory;
use PHPUnit\Framework\TestCase;

final class ContainerPrecedenceTest extends TestCase
{
    public function test_extension_defs_override_core_defaults(): void
    {
        $core = ['seam' => 'core-default', 'core_only' => 'X'];
        $ext  = ['seam' => 'extension-override', 'ext_only' => 'Y'];

        $merged = ContainerFactory::mergeExtensionDefs($core, $ext);

        self::assertSame('extension-override', $merged['seam']);   // the fix: extension WINS
        self::assertSame('X', $merged['core_only']);               // core-only preserved
        self::assertSame('Y', $merged['ext_only']);                // extension-only added
    }
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `vendor/bin/phpunit --filter=ContainerPrecedenceTest`
Expected: FAIL ("Call to undefined method ...::mergeExtensionDefs()").

- [ ] **Step 3: Add the helper** to `ContainerFactory` (public + `@internal` so the test can call it without reflection):

```php
    /**
     * Merge extension-provided definitions OVER the core defaults.
     *
     * Extension bindings override core bindings for the same id (last-layer-wins),
     * which is what makes core defaults (NullUserProvider, and any seam) real,
     * overridable seams. `array_replace` -- NOT `+=`, which keeps the core key and
     * silently drops the extension's override.
     *
     * @internal
     * @param array<string,mixed> $coreDefs
     * @param array<string,mixed> $extDefs
     * @return array<string,mixed>
     */
    public static function mergeExtensionDefs(array $coreDefs, array $extDefs): array
    {
        return array_replace($coreDefs, $extDefs);
    }
```

- [ ] **Step 4: Use it at the merge site** -- change line 33 from:

```php
        // Merge extension-provided service definitions (typed or DSL)
        $defs += self::loadExtensionDefinitions($tags, $context, $prod);
```

to:

```php
        // Merge extension-provided service definitions (typed or DSL).
        // Extensions OVERRIDE core defaults for the same id (real seams); see mergeExtensionDefs.
        $defs = self::mergeExtensionDefs($defs, self::loadExtensionDefinitions($tags, $context, $prod));

        // Re-pin framework-reserved keys that an extension must not clobber.
        $defs[ApplicationContext::class] = new ValueDefinition(ApplicationContext::class, $context);
```

- [ ] **Step 5: Run it, expect pass**

Run: `vendor/bin/phpunit --filter=ContainerPrecedenceTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Container/Bootstrap/ContainerFactory.php tests/Unit/Container/ContainerPrecedenceTest.php
git commit -m "fix(container): extension definitions override core defaults (array_replace)"
```

---

## Task 2: End-to-end regression -- override a real core seam + protect the reserved key

**Files:**
- Create: `tests/Fixtures/ContainerPrecedence/FakeUserProvider.php`
- Create: `tests/Fixtures/ContainerPrecedence/SeamOverrideProvider.php`
- Create: `tests/Integration/Container/ExtensionOverridesCoreDefaultTest.php`

This proves the user-facing behavior through the real `ContainerFactory::create()` path against a **real** core-bound id (`UserProviderInterface`, bound to `NullUserProvider` by `CoreProvider`), and proves the `ApplicationContext` re-pin actually protects the reserved key. The fixture is registered as an **app provider** via `serviceproviders.enabled` (ungated discovery), exactly as `tests/Unit/Extensions/AppProviderLoaderTest.php` does.

- [ ] **Step 1: Create the test double** (`UserProviderInterface` has three methods, all `?UserIdentity`):

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Fixtures\ContainerPrecedence;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;

final class FakeUserProvider implements UserProviderInterface
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

- [ ] **Step 2: Create the fixture app provider** -- it tries to override two core-bound ids: a real seam (should win) and the reserved key (should be ignored). Extension providers are called statically (`$providerClass::defs()`), so `defs()` is static and returns real `Definition` objects:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Fixtures\ContainerPrecedence;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\FactoryDefinition;
use Psr\Container\ContainerInterface;

/**
 * Fixture app provider for the precedence regression. It attempts to override:
 *  - UserProviderInterface  -- a real core seam; the override SHOULD win.
 *  - ApplicationContext     -- a reserved key; the override SHOULD be ignored (re-pinned).
 */
final class SeamOverrideProvider
{
    /** @return array<string,mixed> */
    public static function defs(): array
    {
        return [
            UserProviderInterface::class => new FactoryDefinition(
                UserProviderInterface::class,
                static fn(ContainerInterface $c) => new FakeUserProvider()
            ),
            ApplicationContext::class => new FactoryDefinition(
                ApplicationContext::class,
                static fn(ContainerInterface $c) => 'CLOBBERED'
            ),
        ];
    }
}
```

- [ ] **Step 3: Write the failing test** (mirrors the context-building harness in `AppProviderLoaderTest::ctxWithConfig`):

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Container;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\NullUserProvider;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Tests\Fixtures\ContainerPrecedence\FakeUserProvider;
use Glueful\Tests\Fixtures\ContainerPrecedence\SeamOverrideProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionOverridesCoreDefaultTest extends TestCase
{
    private function contextWithProvider(string $providerFqcn): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/glueful-precedence-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents(
            $base . '/config/serviceproviders.php',
            "<?php\nreturn " . var_export(['enabled' => [$providerFqcn]], true) . ";\n"
        );
        $ctx = new ApplicationContext($base, 'testing');
        $ctx->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));
        return $ctx;
    }

    public function test_extension_overrides_a_real_core_default(): void
    {
        $ctx = $this->contextWithProvider(SeamOverrideProvider::class);

        $container = ContainerFactory::create($ctx, false);

        $resolved = $container->get(UserProviderInterface::class);
        self::assertInstanceOf(FakeUserProvider::class, $resolved);      // extension override WINS
        self::assertNotInstanceOf(NullUserProvider::class, $resolved);   // core default LOST
    }

    public function test_reserved_application_context_key_cannot_be_clobbered(): void
    {
        $ctx = $this->contextWithProvider(SeamOverrideProvider::class);

        $container = ContainerFactory::create($ctx, false);

        // The fixture tried to bind ApplicationContext::class => 'CLOBBERED';
        // the post-merge re-pin must restore the real context instance.
        self::assertSame($ctx, $container->get(ApplicationContext::class));
    }
}
```

- [ ] **Step 4: Run it -- both should already be meaningful.** Before Task 1's fix, `test_extension_overrides_a_real_core_default` resolves `NullUserProvider` (FAIL) -- confirming the test catches the bug. With Task 1 applied, both PASS.

Run: `vendor/bin/phpunit --filter=ExtensionOverridesCoreDefaultTest`
Expected (post-fix): PASS (both methods).

- [ ] **Step 5: Commit**

```bash
git add tests/Fixtures/ContainerPrecedence tests/Integration/Container/ExtensionOverridesCoreDefaultTest.php
git commit -m "test(container): end-to-end override of UserProviderInterface + ApplicationContext re-pin guard"
```

---

## Task 3: Regression sweep + changelog

**Files:**
- Modify: `CHANGELOG.md` (`[Unreleased]`)

- [ ] **Step 1: Run the container + extensions suites** to surface any test that encoded the old (core-wins) behavior.

Run: `vendor/bin/phpunit tests/Unit/Container tests/Unit/Extensions`
Expected: PASS. If a test fails because it asserted a core binding winning over an extension, that test encoded the bug -- correct it to the new precedence (and note it in the commit).

- [ ] **Step 2: Broader smoke** -- run the full unit suite to catch anything that depended on an extension binding being dropped.

Run: `vendor/bin/phpunit tests/Unit`
Expected: PASS (or triage failures strictly against "did this rely on the precedence bug?").

- [ ] **Step 3: Add a `CHANGELOG.md` `[Unreleased]` `### Fixed` entry**

```markdown
### Fixed
- **Container precedence: extension definitions now override core defaults.** `ContainerFactory` merged extension service definitions with `+=`, which kept the core binding on key collision and silently dropped extension overrides -- making core default bindings (`UserProviderInterface -> NullUserProvider`, and every "core default + extension override" seam) un-overridable through the normal provider path. Now merged with `array_replace` (extension-over-core); `ApplicationContext` is re-pinned post-merge so a framework-managed key cannot be clobbered. Within-extension precedence is unchanged.
```

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs(changelog): container extension-over-core precedence fix"
```

---

## Final verification

- [ ] `vendor/bin/phpunit tests/Unit/Container tests/Integration/Container tests/Unit/Extensions` -- PASS.
- [ ] `composer phpcs` on `src/Container/Bootstrap/ContainerFactory.php` -- clean.
- [ ] `composer analyse` -- no new errors.
- [ ] Grep guard: `grep -nE "entitlement|tenant|subscription|rate" src/Container/Bootstrap/ContainerFactory.php` returns nothing -- this fix is domain-agnostic.
- [ ] Confirm the runtime (`$prod=false`) path sees the merged defs (Task 2 already proves this; the merge precedes `new Container($defs)`).
- [ ] **Release validation -- bust the stale compiled container.** A container compiled before this fix still encodes the old `+=` precedence. During release validation (and in any deploy pipeline that precompiles): delete the cached artifacts (`sys_get_temp_dir()/glueful_compiled_container.php` and `glueful_services_map.json`) and rebuild with `php glueful di:container:compile --force`, then confirm an extension override resolves correctly under the freshly compiled container. Document this in the release notes so deployers regenerate rather than ship a stale compiled container.

## Out of scope (explicit)

- Within-extension precedence (lines 182/200) -- unchanged; first-loaded-wins among extensions.
- The entitlement seam (separate plan; depends on this one).
- Any feature/service code.

## Self-review notes

- Code grounded in verified APIs: `ContainerFactory::create()` merge (line 33), `ValueDefinition`, `ApplicationContext::forTesting()`, `ContainerFactory::create($context, $prod)`, `$container->get()`.
- Task 1's unit test is the infra-free regression on the merge helper; Task 2 is a fully concrete end-to-end proof against a real core seam (`UserProviderInterface` -> `FakeUserProvider`) and the reserved-key guard (`ApplicationContext`), using the proven `AppProviderLoaderTest` context-building harness (`serviceproviders.enabled`). No soft flags.
- Verified APIs: `UserProviderInterface` (3 methods, `?UserIdentity`), static `defs()` on app/extension providers (`loadExtensionDefinitions`), `serviceproviders.enabled` discovery key (`AppProviderLoader`), `new ApplicationContext($base,'testing')` + `setConfigLoader(new ConfigurationLoader(...))`, `ContainerFactory::create()`, `FactoryDefinition`.
- ASCII only.
