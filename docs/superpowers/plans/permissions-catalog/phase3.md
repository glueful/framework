# Framework Permissions Catalog — Phase 3 (Ergonomics) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the permission system pleasant to extend and to test — extensions can declare Gate voters and resource policies the same way they declare permissions, and tests can authorize with one line.

**Architecture:** `ServiceProvider` gains optional `voters()`/`policies()` hooks (mirroring Phase 1's `permissions()`/`roles()`). A dedicated `ExtensionManager` pass registers provider-contributed voters onto the shared `Gate` and policies into the shared `PolicyRegistry`. For tests, a reusable `InMemoryPermissionProvider` plus `actingWithPermissions()` / `actingWithRoles()` helpers on `Glueful\Testing\TestCase` install a granting provider in one call (roles resolve through the declared catalog).

**Tech Stack:** PHP 8.3, PHPUnit 10, Glueful DI, Gate/Voter/Policy model.

**Prerequisite:** Phase 1 merged (`PermissionRegistry`, `PermissionManager::clearProvider()`, `setPermissionsConfig()`, `setProvider()`, `rolePermissionMap()`). Phase 2 is independent of Phase 3 (either order).

**Scope note:** Phase 3 only. Source of truth: `docs/superpowers/specs/2026-06-03-extension-permissions-dx-design.md` (§10 Phase 3: "testing helpers, policy/voter registration sugar").

**Repo:** All tasks in `glueful/framework`.

**Commit convention:** no `Co-Authored-By` trailer; never stage `CLAUDE.md`.

---

### Task 1: `voters()` / `policies()` hooks on `ServiceProvider`

**Files:**
- Modify: `src/Extensions/ServiceProvider.php` (after the Phase 1 `permissions()`/`roles()` methods, ~line 51)
- Test: `tests/Unit/Extensions/ServiceProviderGateHooksTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\ServiceProvider;
use Glueful\Permissions\{Context, Vote, VoterInterface};
use Glueful\Auth\UserIdentity;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class ServiceProviderGateHooksTest extends TestCase
{
    public function test_base_provider_declares_no_voters_or_policies(): void
    {
        $provider = new class ($this->createMock(ContainerInterface::class)) extends ServiceProvider {};
        self::assertSame([], $provider->voters());
        self::assertSame([], $provider->policies());
    }

    public function test_subclass_can_declare_voters_and_policies(): void
    {
        $voter = new class implements VoterInterface {
            public function supports(string $permission, mixed $resource, Context $ctx): bool
            {
                return true;
            }
            public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
            {
                return new Vote(Vote::ABSTAIN);
            }
            public function priority(): int
            {
                return 50;
            }
        };

        $provider = new class ($this->createMock(ContainerInterface::class), $voter) extends ServiceProvider {
            private object $v;
            public function __construct(ContainerInterface $app, object $v)
            {
                parent::__construct($app);
                $this->v = $v;
            }
            public function voters(): array
            {
                return [$this->v];
            }
            public function policies(): array
            {
                return ['posts' => 'App\\Policies\\PostPolicy'];
            }
        };

        self::assertCount(1, $provider->voters());
        self::assertSame(['posts' => 'App\\Policies\\PostPolicy'], $provider->policies());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ServiceProviderGateHooksTest.php`
Expected: FAIL — `voters()`/`policies()` undefined.

- [ ] **Step 3: Add the methods**

In `src/Extensions/ServiceProvider.php`, after the `roles()` method added in Phase 1:

```php
    /**
     * Declare Gate voters contributed by this provider. Registered onto the shared Gate.
     *
     * @return list<\Glueful\Permissions\VoterInterface>
     */
    public function voters(): array
    {
        return [];
    }

    /**
     * Declare resource policies contributed by this provider.
     * Map of resource slug or FQCN => PolicyInterface class-string.
     *
     * @return array<string, class-string<\Glueful\Permissions\PolicyInterface>>
     */
    public function policies(): array
    {
        return [];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ServiceProviderGateHooksTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Extensions/ServiceProvider.php tests/Unit/Extensions/ServiceProviderGateHooksTest.php
git commit -m "feat(extensions): add voters()/policies() declaration hooks"
```

---

### Task 2: Register provider voters/policies during bootstrap

**Files:**
- Modify: `src/Extensions/ExtensionManager.php` (add `registerProviderGateExtensions()`)
- Modify: `src/Framework.php` (`initializeExtensions()` — call it after the catalog pass)
- Test: `tests/Unit/Extensions/RegisterProviderGateExtensionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\{ExtensionManager, ServiceProvider};
use Glueful\Permissions\{Context, Gate, PolicyRegistry, Vote, VoterInterface};
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class RegisterProviderGateExtensionsTest extends TestCase
{
    public function test_registers_voters_on_gate_and_policies_in_registry(): void
    {
        $gate = new Gate('affirmative', false);
        $policyRegistry = new PolicyRegistry();

        $grantingVoter = new class implements VoterInterface {
            public function supports(string $permission, mixed $resource, Context $ctx): bool
            {
                return true;
            }
            public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
            {
                return new Vote(Vote::GRANT);
            }
            public function priority(): int
            {
                return 1;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use ($gate, $policyRegistry) {
            return match ($id) {
                Gate::class => $gate,
                PolicyRegistry::class => $policyRegistry,
                ApplicationContext::class => $this->createMock(ApplicationContext::class),
                default => throw new \RuntimeException("unexpected get($id)"),
            };
        });
        $container->method('has')->willReturn(true);

        $provider = new class ($container, $grantingVoter) extends ServiceProvider {
            private object $v;
            public function __construct(ContainerInterface $app, object $v)
            {
                parent::__construct($app);
                $this->v = $v;
            }
            public function voters(): array
            {
                return [$this->v];
            }
            public function policies(): array
            {
                return ['posts' => FakePolicy::class];
            }
        };

        $manager = new ExtensionManager($container);
        $ref = new \ReflectionProperty(ExtensionManager::class, 'providers');
        $ref->setAccessible(true);
        $ref->setValue($manager, ['P' => $provider]);

        $manager->registerProviderGateExtensions();

        // Voter is live on the Gate.
        self::assertSame(Vote::GRANT, $gate->decide(new UserIdentity('u1'), 'anything', null, new Context()));
        // Policy is registered.
        self::assertInstanceOf(FakePolicy::class, $policyRegistry->get('posts'));
    }
}

final class FakePolicy implements \Glueful\Permissions\PolicyInterface
{
    public function view(UserIdentity $user, mixed $resource, Context $ctx): ?bool
    {
        return true;
    }
    public function create(UserIdentity $user, mixed $resource, Context $ctx): ?bool
    {
        return true;
    }
    public function update(UserIdentity $user, mixed $resource, Context $ctx): ?bool
    {
        return true;
    }
    public function delete(UserIdentity $user, mixed $resource, Context $ctx): ?bool
    {
        return true;
    }
}
```

> Confirm `PolicyInterface`'s exact method signatures (`src/Permissions/PolicyInterface.php`) and match `FakePolicy` to them — adjust return types/params if they differ. The test's point is registration, not the policy bodies.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Extensions/RegisterProviderGateExtensionsTest.php`
Expected: FAIL — `registerProviderGateExtensions()` undefined.

- [ ] **Step 3: Add the method**

In `src/Extensions/ExtensionManager.php` add (imports for `Gate`/`PolicyRegistry` at top as needed):

```php
    /**
     * Register provider-contributed Gate voters and resource policies onto the shared
     * Gate and PolicyRegistry singletons. Runs after the permission catalog is built.
     */
    public function registerProviderGateExtensions(): void
    {
        $gate = $this->container->has(\Glueful\Permissions\Gate::class)
            ? $this->container->get(\Glueful\Permissions\Gate::class)
            : null;
        $policies = $this->container->has(\Glueful\Permissions\PolicyRegistry::class)
            ? $this->container->get(\Glueful\Permissions\PolicyRegistry::class)
            : null;

        foreach ($this->providers as $provider) {
            if ($gate !== null) {
                foreach ($provider->voters() as $voter) {
                    $gate->registerVoter($voter);
                }
            }
            if ($policies !== null) {
                foreach ($provider->policies() as $resource => $policyClass) {
                    $policies->register($resource, $policyClass);
                }
            }
        }
    }
```

- [ ] **Step 4: Wire it into bootstrap**

In `src/Framework.php::initializeExtensions()`, call it right after the catalog pass (and before/with boot). The catalog pass stays fail-fast; gate-extension registration runs next:

```php
        // Fail-fast: catalog build must NOT be swallowed.
        $extensions->aggregatePermissionCatalog();

        // Register provider-contributed voters/policies onto the Gate/PolicyRegistry.
        $extensions->registerProviderGateExtensions();

        try {
            $extensions->boot();
        } catch (\Throwable $e) {
            error_log("Extensions boot failed: " . $e->getMessage());
        }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Extensions/RegisterProviderGateExtensionsTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Extensions/ExtensionManager.php src/Framework.php tests/Unit/Extensions/RegisterProviderGateExtensionsTest.php
git commit -m "feat(extensions): register provider voters/policies onto Gate and PolicyRegistry"
```

---

### Task 3: `InMemoryPermissionProvider` test double

A real `PermissionProviderInterface` that grants a fixed permission set, satisfying `CORE_PERMISSIONS` so `setProvider()` accepts it.

**Files:**
- Create: `src/Testing/InMemoryPermissionProvider.php`
- Test: `tests/Unit/Testing/InMemoryPermissionProviderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Testing;

use Glueful\Testing\InMemoryPermissionProvider;
use Glueful\Interfaces\Permission\PermissionStandards;
use PHPUnit\Framework\TestCase;

final class InMemoryPermissionProviderTest extends TestCase
{
    public function test_grants_are_user_scoped(): void
    {
        $p = new InMemoryPermissionProvider(['u1' => ['blog.publish']]);
        self::assertTrue($p->can('u1', 'blog.publish', 'system'));
        self::assertFalse($p->can('u1', 'blog.delete', 'system'));
        // Regression: a different user does NOT inherit u1's grants.
        self::assertFalse($p->can('u2', 'blog.publish', 'system'));
    }

    public function test_wildcard_grants_everything_to_that_user_only(): void
    {
        $p = new InMemoryPermissionProvider(['u1' => ['*']]);
        self::assertTrue($p->can('u1', 'anything.at.all', 'system'));
        self::assertFalse($p->can('u2', 'anything.at.all', 'system'));
    }

    public function test_get_user_permissions_returns_resource_keyed_shape(): void
    {
        $p = new InMemoryPermissionProvider(['u1' => ['blog.publish', '*']]);
        // Contract: array<string, string[]> (resource => permissions[]); '*' is excluded.
        self::assertSame(['system' => ['blog.publish']], $p->getUserPermissions('u1'));
        self::assertSame([], $p->getUserPermissions('unknown'));
    }

    public function test_exposes_core_permissions_for_validation(): void
    {
        $available = (new InMemoryPermissionProvider([]))->getAvailablePermissions();
        foreach (PermissionStandards::CORE_PERMISSIONS as $core) {
            self::assertArrayHasKey($core, $available);
        }
    }

    public function test_batch_assign_then_revoke_clears_grant(): void
    {
        $p = new InMemoryPermissionProvider();
        $p->batchAssignPermissions('u1', [['permission' => 'blog.publish', 'resource' => 'system']]);
        self::assertTrue($p->can('u1', 'blog.publish', 'system'));

        $p->batchRevokePermissions('u1', [['permission' => 'blog.publish', 'resource' => 'system']]);
        self::assertFalse($p->can('u1', 'blog.publish', 'system'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Testing/InMemoryPermissionProviderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the provider**

```php
<?php

declare(strict_types=1);

namespace Glueful\Testing;

use Glueful\Interfaces\Permission\{PermissionProviderInterface, PermissionStandards};

/**
 * Test-only permission provider that grants a fixed set of permission slugs.
 * Satisfies CORE_PERMISSIONS so PermissionManager::setProvider() accepts it.
 */
final class InMemoryPermissionProvider implements PermissionProviderInterface
{
    /** @param array<string, string[]> $grantsByUser userUuid => permission slugs ('*' grants everything to that user) */
    public function __construct(private array $grantsByUser = [])
    {
    }

    public function initialize(array $config = []): void
    {
    }

    public function can(string $userUuid, string $permission, string $resource, array $context = []): bool
    {
        $granted = $this->grantsByUser[$userUuid] ?? [];
        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /** @return array<string, string[]> resource => permissions[] (provider contract shape) */
    public function getUserPermissions(string $userUuid): array
    {
        $granted = array_values(array_filter(
            $this->grantsByUser[$userUuid] ?? [],
            fn($p) => $p !== '*'
        ));
        return $granted === [] ? [] : ['system' => $granted];
    }

    public function assignPermission(string $userUuid, string $permission, string $resource, array $options = []): bool
    {
        $this->grantsByUser[$userUuid][] = $permission;
        return true;
    }

    public function revokePermission(string $userUuid, string $permission, string $resource): bool
    {
        if (isset($this->grantsByUser[$userUuid])) {
            $this->grantsByUser[$userUuid] = array_values(array_filter(
                $this->grantsByUser[$userUuid],
                fn($p) => $p !== $permission
            ));
        }
        return true;
    }

    /** @return array<string, string> */
    public function getAvailablePermissions(): array
    {
        $available = [];
        foreach (PermissionStandards::CORE_PERMISSIONS as $core) {
            $available[$core] = $core;
        }
        foreach ($this->grantsByUser as $slugs) {
            foreach ($slugs as $slug) {
                if ($slug !== '*') {
                    $available[$slug] = $slug;
                }
            }
        }
        return $available;
    }

    /** @return array<string, string> */
    public function getAvailableResources(): array
    {
        return [];
    }

    public function batchAssignPermissions(string $userUuid, array $permissions, array $options = []): bool
    {
        foreach ($permissions as $perm) {
            $this->grantsByUser[$userUuid][] = is_array($perm) ? ($perm['permission'] ?? '') : $perm;
        }
        return true;
    }

    public function batchRevokePermissions(string $userUuid, array $permissions): bool
    {
        foreach ($permissions as $perm) {
            $slug = is_array($perm) ? ($perm['permission'] ?? '') : $perm;
            $this->revokePermission($userUuid, $slug, 'system');
        }
        return true;
    }

    public function assignRole(string $userUuid, string $roleSlug, array $options = []): bool
    {
        return true;
    }

    public function revokeRole(string $userUuid, string $roleSlug): bool
    {
        return true;
    }

    public function invalidateUserCache(string $userUuid): void
    {
    }

    public function invalidateAllCache(): void
    {
    }

    /** @return array{name: string, version: string, description: string, capabilities: string[], author: string} */
    public function getProviderInfo(): array
    {
        return [
            'name' => 'in-memory',
            'version' => 'test',
            'description' => 'In-memory permission provider for tests',
            'capabilities' => ['permissions'],
            'author' => 'Glueful',
        ];
    }

    /** @return array{status: string, healthy: bool, details: array<string, mixed>} */
    public function healthCheck(): array
    {
        return ['status' => 'ok', 'healthy' => true, 'details' => []];
    }
}
```

> Match each method signature to `src/Interfaces/Permission/PermissionProviderInterface.php` exactly (parameter names/types, return types). The list above mirrors the interface as of this plan; if any signature differs, follow the interface.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Testing/InMemoryPermissionProviderTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Testing/InMemoryPermissionProvider.php tests/Unit/Testing/InMemoryPermissionProviderTest.php
git commit -m "feat(testing): add InMemoryPermissionProvider test double"
```

---

### Task 4: `actingWithPermissions()` / `actingWithRoles()` helpers

**Files:**
- Modify: `src/Testing/TestCase.php` (add helpers + provider reset in `tearDown()`)
- Test: `tests/Integration/Testing/ActingWithPermissionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Testing;

use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role};
use Glueful\Permissions\Helpers\PermissionHelper;
use Glueful\Testing\TestCase;

final class ActingWithPermissionsTest extends TestCase
{
    public function test_acting_with_permissions_grants_them_to_acting_user_only(): void
    {
        $uuid = $this->actingWithPermissions(['blog.publish'], 'u1');

        self::assertTrue(PermissionHelper::hasPermission($uuid, 'blog.publish', 'system'));
        self::assertFalse(PermissionHelper::hasPermission($uuid, 'blog.delete', 'system'));
        // Regression: a different user is NOT granted the acting user's permissions.
        self::assertFalse(PermissionHelper::hasPermission('someone-else', 'blog.publish', 'system'));
    }

    public function test_acting_with_roles_resolves_declared_grants(): void
    {
        /** @var PermissionRegistry $registry */
        $registry = $this->get(PermissionRegistry::class);
        $registry->register(Permission::define('blog.publish'), 'vendor/blog');
        $registry->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');

        $uuid = $this->actingWithRoles(['blog.editor'], 'u1');

        self::assertTrue(PermissionHelper::hasPermission($uuid, 'blog.publish', 'system'));
    }

    public function test_acting_with_unknown_role_throws(): void
    {
        // A typo'd / undeclared role must surface as an error, not a silent denial.
        $this->expectException(\InvalidArgumentException::class);
        $this->actingWithRoles(['nope.not.declared']);
    }
}
```

> `PermissionHelper::hasPermission()` signature is `hasPermission(string $userUuid, string $permission, string $resource = 'system', array $context = [])` (`src/Permissions/Helpers/PermissionHelper.php:132`) — user uuid first.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Testing/ActingWithPermissionsTest.php`
Expected: FAIL — `actingWithPermissions`/`actingWithRoles` undefined.

- [ ] **Step 3: Add the helpers and reset**

In `src/Testing/TestCase.php`, add the helpers:

```php
    /**
     * Authorize the given permission slugs for the rest of the test by installing an
     * in-memory permission provider in 'replace' mode. Returns the acting user uuid.
     *
     * @param string[] $permissions
     */
    protected function actingWithPermissions(array $permissions, string $userUuid = 'test-user'): string
    {
        $manager = $this->get('permission.manager');
        $manager->setPermissionsConfig(['provider_mode' => 'replace', 'strategy' => 'affirmative']);
        // User-scoped: ONLY $userUuid is granted these permissions; other users are denied.
        $manager->setProvider(new \Glueful\Testing\InMemoryPermissionProvider([$userUuid => $permissions]), []);
        return $userUuid;
    }

    /**
     * Authorize via declared roles: resolves each role's granted permissions from the
     * PermissionRegistry, then grants them to the acting user. Returns the acting user uuid.
     *
     * @param string[] $roleSlugs
     * @throws \InvalidArgumentException if a role is not declared in the catalog (so a typo'd
     *         role surfaces as an error, not a silent denial).
     */
    protected function actingWithRoles(array $roleSlugs, string $userUuid = 'test-user'): string
    {
        /** @var \Glueful\Permissions\Catalog\PermissionRegistry $registry */
        $registry = $this->get(\Glueful\Permissions\Catalog\PermissionRegistry::class);
        $map = $registry->rolePermissionMap();

        $permissions = [];
        foreach ($roleSlugs as $role) {
            if (!array_key_exists($role, $map)) {
                throw new \InvalidArgumentException(sprintf(
                    'actingWithRoles(): role "%s" is not declared in the permission catalog.',
                    $role
                ));
            }
            foreach ($map[$role] as $perm) {
                $permissions[] = $perm;
            }
        }

        return $this->actingWithPermissions(array_values(array_unique($permissions)), $userUuid);
    }
```

Then extend `tearDown()` to reset the static provider so tests stay isolated. Update the existing `tearDown()`:

```php
    protected function tearDown(): void
    {
        if ($this->app !== null && $this->getContainer()->has('permission.manager')) {
            $this->get('permission.manager')->clearProvider();
        }
        $this->resetFrameworkState();
        $this->app = null;
        parent::tearDown();
    }
```

> `clearProvider()` was added in Phase 1 (Task 12). If executing Phase 3 before Phase 1's that step landed, add `clearProvider()` to `PermissionManager` first (sets `self::$activeProvider = null`).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Testing/ActingWithPermissionsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Testing/TestCase.php tests/Integration/Testing/ActingWithPermissionsTest.php
git commit -m "feat(testing): add actingWithPermissions/actingWithRoles helpers"
```

---

### Task 5: Final verification

- [ ] **Framework suite + analysis**

Run: `composer test && composer run analyse:changed`
Expected: PASS; no new analysis errors in the new `Testing`/`Extensions` files.

- [ ] **Docs**

Add a short "Testing permissions" subsection to `docs/API_KEYS.md` or the permissions docs (wherever permission usage is documented) showing `actingWithPermissions(['posts.publish'])` and `actingWithRoles(['editor'])`. Document the `voters()`/`policies()` provider hooks in the extension docs. Commit with the work.

- [ ] **CHANGELOG**

Update framework `[Unreleased]`: `voters()`/`policies()` extension hooks, provider Gate-extension registration, `InMemoryPermissionProvider`, `actingWithPermissions`/`actingWithRoles`. Commit.

---

## Spec coverage (self-review)

- Testing helpers (`actingWith...`) (spec §10 Phase 3) → Tasks 3, 4
- Policy/voter registration sugar for extensions (spec §10 Phase 3, §4.6 "register a VoterInterface / map a PolicyInterface") → Tasks 1, 2
- Roles define grants, not assignments — `actingWithRoles` resolves grants via the registry, consistent with `RegistryRoleVoter` (spec §4.1) → Task 4
- Single-active-provider model preserved — helpers use `setProvider()`/`clearProvider()` (spec §4.6) → Task 4

---

## Execution notes (as built)

- Built as planned. `registerProviderGateExtensions()` runs in `Framework::initializeExtensions()` right after the catalog build; the Phase 1 bootstrap stub test was updated to add the method.
- `actingWithRoles()` throws `InvalidArgumentException` on an undeclared role (typo surfaces as an error, not a silent denial); `InMemoryPermissionProvider` is user-scoped and returns the `resource => permissions[]` contract shape from `getUserPermissions()`.
- **Result:** full framework suite green (1030 tests); PHPStan + phpcs clean on new files. (Phase 3 is framework-only — no Aegis changes.)
