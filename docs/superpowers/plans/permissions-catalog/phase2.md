# Framework Permissions Catalog — Phase 2 (Visibility) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the declarative permission catalog observable and safe to reconcile — list what's declared, diff declared vs enforced vs persisted, and prune only stale extension-managed rows.

**Architecture:** A `PermissionAttributeScanner` reads `Router::getAllRoutes()` and reflects each handler for `#[RequiresPermission]`/`#[RequiresRole]` (the *enforced* set, permissions and roles kept separate). The `PermissionRegistry` gains read-only introspection. Two CLI commands — `permissions:list` (declared catalog) and `permissions:diff` (declared × enforced × persisted-all × managed, for **both** permissions and roles, with unmanaged/hand-created rows reported informationally and never pruned) — surface drift. `permissions:sync --prune` removes only managed, no-longer-declared rows via two **opt-in capability interfaces** — `CatalogPruneInterface` (`pruneCatalog`) and `RoleCatalogSyncInterface` (`getManagedRoles` + `pruneRoles`) — kept separate from `PermissionCatalogSyncInterface` so adding them never breaks existing/third-party providers; commands degrade via `instanceof`. Roles compare via a shared `RoleKey` canonical form so diff never diverges from enforcement. Aegis implements all.

**Scope decision:** Phase 2 is **permissions + roles symmetric** for both diff and prune (not permissions-only). Phase 1 persists roles with `managed_by`, so omitting role staleness/prune would orphan stale managed roles — a correctness gap. See the scope note on Task 4.

**Tech Stack:** PHP 8.3, PHPUnit 10, Symfony Console, Glueful Router.

**Prerequisite:** Phase 1 plan (`phase1.md`) is merged — `PermissionRegistry`, `Permission`/`Role` DTOs, `PermissionCatalogSyncInterface`, `permissions:sync`, and Aegis `managed_by`/`syncCatalog`/`getManagedCatalog` exist.

**Scope note:** This plan is Phase 2 only. Phase 3 (test helpers, voter/policy sugar) is a separate plan. Source of truth: `docs/superpowers/specs/2026-06-03-extension-permissions-dx-design.md` (§4.4, §4.5 attribute-scanning-as-validator, §6 prune rule).

**Repos:** Tasks 1–5 in `glueful/framework`. Tasks 6–7 in `glueful/aegis`.

**Commit convention:** no `Co-Authored-By` trailer; never stage `CLAUDE.md`.

---

### Task 1: Registry introspection (source tracking + grouping)

The Phase 1 `PermissionRegistry` records sources internally but does not expose them. `permissions:list`/`diff` need read access.

**Files:**
- Modify: `src/Permissions/Catalog/PermissionRegistry.php`
- Test: `tests/Unit/Permissions/Catalog/PermissionRegistryIntrospectionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role};
use PHPUnit\Framework\TestCase;

final class PermissionRegistryIntrospectionTest extends TestCase
{
    private function registry(): PermissionRegistry
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.publish')->category('blog'), 'vendor/blog');
        $r->register(Permission::define('blog.delete')->category('blog'), 'vendor/blog');
        $r->register(Permission::define('shop.refund')->category('shop'), 'vendor/shop');
        $r->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');
        return $r;
    }

    public function test_source_of_returns_declaring_package(): void
    {
        self::assertSame('vendor/blog', $this->registry()->sourceOf('blog.publish'));
        self::assertNull($this->registry()->sourceOf('does.not.exist'));
    }

    public function test_permission_slugs_and_role_slugs(): void
    {
        $r = $this->registry();
        self::assertEqualsCanonicalizing(
            ['blog.publish', 'blog.delete', 'shop.refund'],
            $r->permissionSlugs()
        );
        self::assertSame(['blog.editor'], $r->roleSlugs());
    }

    public function test_group_permissions_by_category(): void
    {
        $grouped = $this->registry()->permissionsByCategory();
        self::assertEqualsCanonicalizing(['blog.publish', 'blog.delete'], array_map(
            fn($p) => $p->slug(),
            $grouped['blog']
        ));
        self::assertCount(1, $grouped['shop']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/PermissionRegistryIntrospectionTest.php`
Expected: FAIL — `sourceOf`/`permissionSlugs`/`roleSlugs`/`permissionsByCategory` undefined.

- [ ] **Step 3: Add the introspection methods**

Add to `src/Permissions/Catalog/PermissionRegistry.php` (the `$permissionSources`/`$roleSources` maps already exist from Phase 1):

```php
    public function sourceOf(string $slug): ?string
    {
        return $this->permissionSources[$slug] ?? null;
    }

    /** @return string[] */
    public function permissionSlugs(): array
    {
        return array_keys($this->permissions);
    }

    /** @return string[] */
    public function roleSlugs(): array
    {
        return array_keys($this->roles);
    }

    /** @return array<string, Permission[]> category => permissions (uncategorized under '') */
    public function permissionsByCategory(): array
    {
        $grouped = [];
        foreach ($this->permissions as $perm) {
            $category = $perm->toArray()['category'] ?? '';
            $grouped[$category ?? ''][] = $perm;
        }
        return $grouped;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/PermissionRegistryIntrospectionTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Permissions/Catalog/PermissionRegistry.php tests/Unit/Permissions/Catalog/PermissionRegistryIntrospectionTest.php
git commit -m "feat(permissions): add registry introspection (sources, slugs, grouping)"
```

---

### Task 2: `PermissionAttributeScanner`

Scans registered routes for `#[RequiresPermission]`/`#[RequiresRole]` to compute the *enforced* set. Roles are kept separate from permissions (roles map to `role.{name}` only for enforcement, not for catalog comparison).

**Files:**
- Create: `src/Permissions/Catalog/PermissionAttributeScanner.php`
- Test: `tests/Unit/Permissions/Catalog/PermissionAttributeScannerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\PermissionAttributeScanner;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;

final class PermissionAttributeScannerTest extends TestCase
{
    public function test_scans_enforced_permissions_and_roles_from_handlers(): void
    {
        $router = $this->createMock(Router::class);
        $router->method('getAllRoutes')->willReturn([
            ['method' => 'POST', 'path' => '/posts', 'handler' => [ScanFixtureController::class, 'publish'], 'middleware' => [], 'name' => null, 'type' => 'static'],
            ['method' => 'GET', 'path' => '/admin', 'handler' => [ScanFixtureController::class, 'adminOnly'], 'middleware' => [], 'name' => null, 'type' => 'static'],
            ['method' => 'GET', 'path' => '/closure', 'handler' => fn() => null, 'middleware' => [], 'name' => null, 'type' => 'static'],
        ]);

        $result = (new PermissionAttributeScanner($router))->scan();

        self::assertEqualsCanonicalizing(['blog.publish'], $result['permissions']);
        self::assertEqualsCanonicalizing(['admin'], $result['roles']);
    }
}

final class ScanFixtureController
{
    #[\Glueful\Auth\Attributes\RequiresPermission('blog.publish')]
    public function publish(): void
    {
    }

    #[\Glueful\Auth\Attributes\RequiresRole('admin')]
    public function adminOnly(): void
    {
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/PermissionAttributeScannerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the scanner**

```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

use Glueful\Routing\Router;

/**
 * Computes the set of permissions/roles actually enforced by route attributes.
 * Used by `permissions:diff` to catch enforce-vs-declare drift. NOT a source of truth.
 *
 * Intentionally not `final`: `DiffCommand` depends on it and tests mock it (PHPUnit
 * cannot mock final classes). There is no behavioral reason to seal it.
 */
class PermissionAttributeScanner
{
    public function __construct(private readonly Router $router)
    {
    }

    /** @return array{permissions: string[], roles: string[]} */
    public function scan(): array
    {
        $permissions = [];
        $roles = [];

        foreach ($this->router->getAllRoutes() as $route) {
            [$class, $method] = $this->resolveHandler($route['handler'] ?? null);
            if ($class === null || !class_exists($class)) {
                continue;
            }
            foreach ($this->attributeNames($class, $method, 'Glueful\\Auth\\Attributes\\RequiresPermission') as $name) {
                $permissions[$name] = true;
            }
            foreach ($this->attributeNames($class, $method, 'Glueful\\Auth\\Attributes\\RequiresRole') as $name) {
                $roles[$name] = true;
            }
        }

        return ['permissions' => array_keys($permissions), 'roles' => array_keys($roles)];
    }

    /**
     * @return array{0: ?class-string, 1: ?string} [class, method] or [null, null] for unscannable handlers
     */
    private function resolveHandler(mixed $handler): array
    {
        if (is_array($handler) && isset($handler[0]) && is_string($handler[0])) {
            return [$handler[0], $handler[1] ?? '__invoke'];
        }
        if (is_string($handler)) {
            foreach (['::', '@'] as $sep) {
                if (str_contains($handler, $sep)) {
                    [$c, $m] = explode($sep, $handler, 2);
                    return [$c, $m];
                }
            }
            if (class_exists($handler)) {
                return [$handler, '__invoke'];
            }
        }
        return [null, null]; // closures and unrecognized handlers are not scannable
    }

    /**
     * @param class-string $class
     * @return string[]
     */
    private function attributeNames(string $class, ?string $method, string $attributeFqcn): array
    {
        $names = [];
        try {
            $rc = new \ReflectionClass($class);
            foreach ($rc->getAttributes($attributeFqcn) as $a) {
                $names[] = $a->newInstance()->name;
            }
            if ($method !== null && $rc->hasMethod($method)) {
                foreach ($rc->getMethod($method)->getAttributes($attributeFqcn) as $a) {
                    $names[] = $a->newInstance()->name;
                }
            }
        } catch (\Throwable) {
            // Unreadable handler — skip.
        }
        return $names;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/PermissionAttributeScannerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Permissions/Catalog/PermissionAttributeScanner.php tests/Unit/Permissions/Catalog/PermissionAttributeScannerTest.php
git commit -m "feat(permissions): add route attribute scanner for enforced permissions/roles"
```

---

### Task 3: `permissions:list` command

**Files:**
- Create: `src/Console/Commands/Permissions/ListCommand.php`
- Test: `tests/Unit/Console/Permissions/ListCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Permissions;

use Glueful\Console\Commands\Permissions\ListCommand;
use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role};
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

final class ListCommandTest extends TestCase
{
    public function test_lists_declared_catalog_grouped_by_category(): void
    {
        $registry = new PermissionRegistry();
        $registry->register(Permission::define('blog.publish')->category('blog'), 'vendor/blog');
        $registry->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');

        $tester = new CommandTester(new ListCommand($registry));
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('blog.publish', $display);
        self::assertStringContainsString('vendor/blog', $display);
        self::assertStringContainsString('blog.editor', $display);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Console/Permissions/ListCommandTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Permissions;

use Glueful\Console\BaseCommand;
use Glueful\Permissions\Catalog\PermissionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'permissions:list',
    description: 'List the declared permission catalog grouped by category'
)]
final class ListCommand extends BaseCommand
{
    public function __construct(private ?PermissionRegistry $registry = null)
    {
        parent::__construct();
        $this->registry ??= $this->getService(PermissionRegistry::class);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Declared permissions</info>');
        foreach ($this->registry->permissionsByCategory() as $category => $perms) {
            $output->writeln('  <comment>' . ($category !== '' ? $category : 'uncategorized') . '</comment>');
            foreach ($perms as $perm) {
                $slug = $perm->slug();
                $output->writeln(sprintf('    %-40s %s', $slug, $this->registry->sourceOf($slug) ?? ''));
            }
        }

        $roleSlugs = $this->registry->roleSlugs();
        if (count($roleSlugs) > 0) {
            $output->writeln('<info>Declared roles</info>');
            foreach ($this->registry->roles() as $role) {
                $arr = $role->toArray();
                $output->writeln(sprintf('    %-40s grants: %s', $arr['slug'], implode(', ', $arr['grants'])));
            }
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Console/Permissions/ListCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Register and commit**

Register `ListCommand` alongside the Phase 1 `SyncCommand` registration. Then:

```bash
git add src/Console/Commands/Permissions/ListCommand.php tests/Unit/Console/Permissions/ListCommandTest.php
git commit -m "feat(permissions): add permissions:list command"
```

---

### Task 4: `permissions:diff` command (+ role visibility/prune interface methods)

**Files:**
- Create: `src/Interfaces/Permission/CatalogPruneInterface.php` (optional: permission prune)
- Create: `src/Interfaces/Permission/RoleCatalogSyncInterface.php` (optional: role visibility + prune)
- Create: `src/Console/Commands/Permissions/DiffCommand.php`
- Test: `tests/Unit/Console/Permissions/DiffCommandTest.php`

**Scope decision (applies to diff AND prune):** Phase 2 covers **permissions + roles symmetrically**. Phase 1 persists roles with `managed_by` via `syncCatalog()`, so a permissions-only prune would orphan stale managed roles — a correctness gap.

**Interface-segregation decision (P2):** the new capabilities are **separate opt-in interfaces**, NOT additions to `PermissionCatalogSyncInterface`. Adding methods to an interface that providers already implement would break every existing implementer (including third-party RBAC providers — the §4.6 goal). Instead:
- `CatalogPruneInterface` — `pruneCatalog(array $slugs): int`
- `RoleCatalogSyncInterface` — `getManagedRoles(): array`, `pruneRoles(array $roleSlugs): int`

Commands check `instanceof` and degrade gracefully: a permission-only sync provider keeps working without implementing anything new. (The declared/enforced role sections still print — they come from the registry and route scanner, not the provider; only the provider-derived **managed/stale-role** data and **role prune** are skipped.)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Permissions;

use Glueful\Console\Commands\Permissions\DiffCommand;
use Glueful\Interfaces\Permission\{PermissionCatalogSyncInterface, PermissionProviderInterface, RoleCatalogSyncInterface};
use Glueful\Permissions\Catalog\{Permission, PermissionAttributeScanner, PermissionRegistry, Role};
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

final class DiffCommandTest extends TestCase
{
    public function test_classifies_permission_and_role_drift(): void
    {
        $registry = new PermissionRegistry();
        $registry->register(Permission::define('blog.publish'), 'vendor/blog');
        $registry->register(Permission::define('blog.orphan'), 'vendor/blog');
        $registry->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');

        $scanner = $this->createMock(PermissionAttributeScanner::class);
        $scanner->method('scan')->willReturn([
            'permissions' => ['blog.publish', 'blog.ghost'], // ghost = enforced, undeclared
            'roles' => ['admin'],                            // admin = enforced, undeclared role
        ]);

        // The active provider implements all capabilities (like Aegis): getAvailablePermissions()
        // on PermissionProviderInterface, getManagedCatalog() on PermissionCatalogSyncInterface,
        // getManagedRoles() on RoleCatalogSyncInterface.
        $provider = $this->createMockForIntersectionOfInterfaces([
            PermissionProviderInterface::class,
            PermissionCatalogSyncInterface::class,
            RoleCatalogSyncInterface::class,
        ]);
        // persisted-all includes a hand-created (unmanaged) row 'adhoc.keep'
        $provider->method('getAvailablePermissions')->willReturn([
            'blog.publish' => '', 'blog.stale' => '', 'adhoc.keep' => '',
        ]);
        $provider->method('getManagedCatalog')->willReturn([
            'blog.publish' => 'vendor/blog', 'blog.stale' => 'vendor/blog',
        ]);
        $provider->method('getManagedRoles')->willReturn([
            'blog.editor' => 'vendor/blog', 'blog.staleRole' => 'vendor/blog',
        ]);

        $tester = new CommandTester(new DiffCommand($registry, $scanner, $provider));
        $tester->execute([]);
        $out = $tester->getDisplay();

        // Permissions
        self::assertStringContainsString('blog.ghost', $out);     // enforced-but-undeclared
        self::assertStringContainsString('blog.orphan', $out);    // declared-but-unenforced
        self::assertStringContainsString('blog.stale', $out);     // stale managed permission (prunable)
        self::assertStringContainsString('adhoc.keep', $out);     // unmanaged persisted (informational)
        // Roles — enforced #[RequiresRole('admin')] is reported in CANONICAL form (role.admin),
        // never bare. Assert the exact bullet, and that the bare form is not emitted as a drift item.
        self::assertStringContainsString('- role.admin', $out);     // enforced role, undeclared (canonical)
        self::assertStringNotContainsString('- admin', $out);       // bare form must not appear as a bullet
        self::assertStringContainsString('- blog.editor', $out);    // declared role, never enforced
        self::assertStringContainsString('- blog.staleRole', $out); // stale managed role (prunable)
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Console/Permissions/DiffCommandTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the two opt-in capability interfaces, then write the command**

Create `src/Interfaces/Permission/CatalogPruneInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Optional capability: a sync provider that can delete managed permissions.
 * Separate from PermissionCatalogSyncInterface so adding prune never breaks existing
 * permission-only sync providers.
 */
interface CatalogPruneInterface
{
    /**
     * Delete managed permissions by slug (managed_by IS NOT NULL only). Hand-created rows never deleted.
     *
     * @param string[] $slugs
     * @return int rows removed
     */
    public function pruneCatalog(array $slugs): int;
}
```

Create `src/Interfaces/Permission/RoleCatalogSyncInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Optional capability: a sync provider that persists roles (not just permissions) and can
 * report/prune managed roles. Opt-in so permission-only providers are unaffected.
 */
interface RoleCatalogSyncInterface
{
    /**
     * Persisted, extension/app-managed roles (managed_by IS NOT NULL) as slug => managed_by.
     * Hand-created rows are excluded — never stale/prunable.
     *
     * @return array<string, string>
     */
    public function getManagedRoles(): array;

    /**
     * Delete managed roles by slug (managed_by IS NOT NULL only). Hand-created rows never deleted.
     *
     * @param string[] $roleSlugs
     * @return int rows removed
     */
    public function pruneRoles(array $roleSlugs): int;
}
```

Then write the command:

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Permissions;

use Glueful\Console\BaseCommand;
use Glueful\Interfaces\Permission\{PermissionCatalogSyncInterface, PermissionProviderInterface, RoleCatalogSyncInterface};
use Glueful\Permissions\Catalog\{PermissionAttributeScanner, PermissionRegistry, RoleKey};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'permissions:diff',
    description: 'Show drift between declared, enforced, and persisted permissions and roles'
)]
final class DiffCommand extends BaseCommand
{
    private ?PermissionCatalogSyncInterface $sync = null;
    private ?RoleCatalogSyncInterface $roleSync = null;

    public function __construct(
        private ?PermissionRegistry $registry = null,
        private ?PermissionAttributeScanner $scanner = null,
        private ?PermissionProviderInterface $provider = null,
    ) {
        parent::__construct();
        $this->registry ??= $this->getService(PermissionRegistry::class);
        $this->scanner ??= $this->getService(PermissionAttributeScanner::class);
        if ($this->provider === null && $this->getService('permission.manager') !== null) {
            $this->provider = $this->getService('permission.manager')->getProvider();
        }
        // Capabilities are opt-in: managed permissions need the sync interface; managed roles
        // need the role-sync interface; persisted-all needs the base provider.
        $this->sync = $this->provider instanceof PermissionCatalogSyncInterface ? $this->provider : null;
        $this->roleSync = $this->provider instanceof RoleCatalogSyncInterface ? $this->provider : null;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scan = $this->scanner->scan();

        // ---- Permissions ----
        $declared = $this->registry->permissionSlugs();
        $enforced = $scan['permissions'];
        $managed = $this->sync !== null ? array_keys($this->sync->getManagedCatalog()) : [];
        $persistedAll = $this->provider !== null ? array_keys($this->provider->getAvailablePermissions()) : [];
        $unmanaged = array_diff($persistedAll, $managed);

        $output->writeln('<info>Permissions</info>');
        $this->section($output, 'Enforced but undeclared (likely typo / missing declaration)', array_values(array_diff($enforced, $declared)));
        $this->section($output, 'Declared but unenforced (orphan?)', array_values(array_diff($declared, $enforced)));
        $this->section($output, 'Stale managed (declared nowhere — prunable with --prune)', array_values(array_diff($managed, $declared)));
        $this->section($output, 'Unmanaged persisted (hand-created — informational, never pruned)', array_values($unmanaged));

        // ---- Roles ----
        // Enforced-vs-declared uses the SHARED RoleKey canonical form (so bare #[RequiresRole('admin')]
        // matches a declared role 'admin'). Stale detection uses RAW slugs, since managed and declared
        // roles are both stored as Role DTO slugs.
        $declaredRoleSlugs = $this->registry->roleSlugs();
        $declaredRoleKeys = array_map([RoleKey::class, 'canonical'], $declaredRoleSlugs);
        $enforcedRoleKeys = array_map([RoleKey::class, 'canonical'], $scan['roles']);
        $managedRoleSlugs = $this->roleSync !== null ? array_keys($this->roleSync->getManagedRoles()) : [];

        $output->writeln('<info>Roles</info>');
        $this->section($output, 'Enforced but undeclared (role)', array_values(array_diff($enforcedRoleKeys, $declaredRoleKeys)));
        $this->section($output, 'Declared but unenforced (role)', array_values(array_diff($declaredRoleKeys, $enforcedRoleKeys)));
        $this->section($output, 'Stale managed roles (declared nowhere — prunable with --prune)', array_values(array_diff($managedRoleSlugs, $declaredRoleSlugs)));

        return self::SUCCESS;
    }

    /** @param string[] $items */
    private function section(OutputInterface $output, string $title, array $items): void
    {
        if (count($items) === 0) {
            return;
        }
        $output->writeln('  <comment>' . $title . ':</comment>');
        foreach ($items as $slug) {
            $output->writeln('    - ' . $slug);
        }
    }
}
```

> The provider is held as `PermissionProviderInterface` (so `getAvailablePermissions()` is available for persisted-all) and the sync capability is derived via `instanceof`. Unmanaged persisted rows (`managed_by IS NULL`) are reported as **informational only** and are never in the prunable set — matching the spec rule that hand-created rows are never stale.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Console/Permissions/DiffCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Register the scanner service + command, then commit**

Register `PermissionAttributeScanner` as a container service (factory injecting `Router`) in `src/Container/Providers/CoreProvider.php` near the other permission services:

```php
        $defs[\Glueful\Permissions\Catalog\PermissionAttributeScanner::class] = new FactoryDefinition(
            \Glueful\Permissions\Catalog\PermissionAttributeScanner::class,
            fn(\Psr\Container\ContainerInterface $c)
                => new \Glueful\Permissions\Catalog\PermissionAttributeScanner($c->get(\Glueful\Routing\Router::class))
        );
```

Register `DiffCommand` alongside the other `permissions:*` commands. Then:

```bash
git add src/Interfaces/Permission/CatalogPruneInterface.php src/Interfaces/Permission/RoleCatalogSyncInterface.php src/Console/Commands/Permissions/DiffCommand.php src/Container/Providers/CoreProvider.php tests/Unit/Console/Permissions/DiffCommandTest.php
git commit -m "feat(permissions): add permissions:diff command + opt-in prune/role-sync capability interfaces"
```

---

### Task 5: `--prune` on `permissions:sync` (permissions + roles)

Wires the `--prune` option to delete stale managed permissions *and* roles. The interface methods (`pruneCatalog`/`pruneRoles`/`getManagedRoles`) were added in Task 4; Aegis implements them in Task 6.

**Files:**
- Modify: `src/Console/Commands/Permissions/SyncCommand.php` (add `--prune`; prune stale permissions and roles)
- Test: `tests/Unit/Console/Permissions/SyncCommandPruneTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Permissions;

use Glueful\Console\Commands\Permissions\SyncCommand;
use Glueful\Interfaces\Permission\{CatalogPruneInterface, PermissionCatalogSyncInterface, RoleCatalogSyncInterface};
use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role, SyncResult};
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

final class SyncCommandPruneTest extends TestCase
{
    /** A provider with all sync capabilities (like Aegis). */
    private function capableProvider(): object
    {
        return $this->createMockForIntersectionOfInterfaces([
            PermissionCatalogSyncInterface::class,
            CatalogPruneInterface::class,
            RoleCatalogSyncInterface::class,
        ]);
    }

    public function test_prune_removes_stale_managed_permissions_and_roles(): void
    {
        $registry = new PermissionRegistry();
        $registry->register(Permission::define('blog.publish'), 'vendor/blog');
        $registry->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');

        $provider = $this->capableProvider();
        $provider->method('syncCatalog')->willReturn(new SyncResult(0, 0, 1, ['blog.stale']));
        // managed roles: blog.editor (declared) + blog.staleRole (undeclared → stale)
        $provider->method('getManagedRoles')->willReturn([
            'blog.editor' => 'vendor/blog',
            'blog.staleRole' => 'vendor/blog',
        ]);
        $provider->expects(self::once())->method('pruneCatalog')->with(['blog.stale'])->willReturn(1);
        $provider->expects(self::once())->method('pruneRoles')->with(['blog.staleRole'])->willReturn(1);

        $tester = new CommandTester(new SyncCommand($registry, $provider));
        $tester->execute(['--prune' => true]);

        $out = $tester->getDisplay();
        self::assertStringContainsString('pruned permissions: 1', $out);
        self::assertStringContainsString('pruned roles: 1', $out);
    }

    public function test_without_prune_flag_does_not_delete(): void
    {
        $registry = new PermissionRegistry();
        $provider = $this->capableProvider();
        $provider->method('syncCatalog')->willReturn(new SyncResult(0, 0, 0, ['blog.stale']));
        $provider->method('getManagedRoles')->willReturn(['blog.staleRole' => 'vendor/blog']);
        $provider->expects(self::never())->method('pruneCatalog');
        $provider->expects(self::never())->method('pruneRoles');

        $tester = new CommandTester(new SyncCommand($registry, $provider));
        $tester->execute([]);
    }

    public function test_permission_only_provider_prunes_permissions_skips_roles(): void
    {
        // Provider has permission-prune but NOT role-sync. Stale permissions only → prunes them,
        // no role work, no error.
        $registry = new PermissionRegistry();
        $provider = $this->createMockForIntersectionOfInterfaces([
            PermissionCatalogSyncInterface::class,
            CatalogPruneInterface::class,
        ]);
        $provider->method('syncCatalog')->willReturn(new SyncResult(0, 0, 0, ['blog.stale']));
        $provider->expects(self::once())->method('pruneCatalog')->with(['blog.stale'])->willReturn(1);

        $tester = new CommandTester(new SyncCommand($registry, $provider));
        $exit = $tester->execute(['--prune' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('pruned permissions: 1', $tester->getDisplay());
    }

    public function test_prune_without_prune_capability_fails_loudly(): void
    {
        // A sync-only provider (no CatalogPruneInterface) with stale permissions and --prune must
        // NOT print a misleading "Pruned: 0" — it reports unsupported and exits non-zero.
        $registry = new PermissionRegistry();
        $provider = $this->createMock(PermissionCatalogSyncInterface::class); // no prune/role capability
        $provider->method('syncCatalog')->willReturn(new SyncResult(0, 0, 0, ['blog.stale']));

        $tester = new CommandTester(new SyncCommand($registry, $provider));
        $exit = $tester->execute(['--prune' => true]);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('does not support pruning', $tester->getDisplay());
        self::assertStringNotContainsString('Pruned', $tester->getDisplay());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Console/Permissions/SyncCommandPruneTest.php`
Expected: FAIL — `--prune` option not defined and prune calls not wired.

- [ ] **Step 3: Wire `--prune` into `SyncCommand`**

Add a `configure()` with the option:

```php
    protected function configure(): void
    {
        $this->addOption('prune', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE,
            'Delete managed permissions and roles that are no longer declared');
    }
```

Replace the Phase 1 stale-print block (it printed only permission staleness + a "Phase 2" hint) with this capability-guarded symmetric block, placed after the counts are printed and before `return self::SUCCESS;`:

```php
        // Capabilities are opt-in (interface segregation) — a permission-only provider has neither.
        $pruneCap = $this->provider instanceof \Glueful\Interfaces\Permission\CatalogPruneInterface ? $this->provider : null;
        $roleSync = $this->provider instanceof \Glueful\Interfaces\Permission\RoleCatalogSyncInterface ? $this->provider : null;

        $stalePermissions = $result->stale;
        $staleRoles = $roleSync !== null
            ? array_values(array_diff(array_keys($roleSync->getManagedRoles()), $this->registry->roleSlugs()))
            : [];

        if (count($stalePermissions) > 0) {
            $output->writeln('<comment>Stale managed permissions: ' . implode(', ', $stalePermissions) . '</comment>');
        }
        if (count($staleRoles) > 0) {
            $output->writeln('<comment>Stale managed roles: ' . implode(', ', $staleRoles) . '</comment>');
        }

        $hasStale = count($stalePermissions) > 0 || count($staleRoles) > 0;
        if ((bool) $input->getOption('prune') && $hasStale) {
            // --prune is explicit and destructive: if anything stale cannot be pruned because the
            // provider lacks the capability, fail loudly rather than printing a misleading "Pruned: 0".
            $unsupported = [];
            if (count($stalePermissions) > 0 && $pruneCap === null) {
                $unsupported[] = 'permissions';
            }
            if (count($staleRoles) > 0 && $roleSync === null) {
                $unsupported[] = 'roles';
            }
            if ($unsupported !== []) {
                $output->writeln('<error>Provider does not support pruning ' . implode(' and ', $unsupported) . '; nothing pruned.</error>');
                return self::FAILURE;
            }
            $prunedPerms = $pruneCap !== null ? $pruneCap->pruneCatalog($stalePermissions) : 0;
            $prunedRoles = $roleSync !== null ? $roleSync->pruneRoles($staleRoles) : 0;
            $output->writeln(sprintf('<info>Pruned</info> — pruned permissions: %d, pruned roles: %d', $prunedPerms, $prunedRoles));
        } elseif ($hasStale) {
            $output->writeln('<comment>Run with --prune to remove them.</comment>');
        }
```

> The Phase 1 `SyncCommand` types `$provider` as `?PermissionCatalogSyncInterface`. The prune/role methods live on the separate `CatalogPruneInterface`/`RoleCatalogSyncInterface` (Task 4), reached via `instanceof`. Behavior for a sync-only provider: **without** `--prune` it still syncs and reports stale rows with no error; **with** `--prune` and stale rows it cannot delete, the command fails loudly (`self::FAILURE`) instead of pretending to prune. `$this->registry->roleSlugs()` is from Task 1.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Console/Permissions/SyncCommandPruneTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Console/Commands/Permissions/SyncCommand.php tests/Unit/Console/Permissions/SyncCommandPruneTest.php
git commit -m "feat(permissions): add --prune for stale managed permissions and roles"
```

---

### Task 6: Aegis prune + managed-role implementation

Aegis adopts the two new opt-in interfaces from Task 4. Add them to the class `implements` list:

```php
class AegisPermissionProvider implements
    PermissionProviderInterface,          // Phase 1
    PermissionCatalogSyncInterface,       // Phase 1
    \Glueful\Interfaces\Permission\CatalogPruneInterface,       // Phase 2: pruneCatalog()
    \Glueful\Interfaces\Permission\RoleCatalogSyncInterface     // Phase 2: getManagedRoles() + pruneRoles()
```

Then implement `pruneCatalog()` (permissions), `getManagedRoles()` + `pruneRoles()` (roles). `getManagedCatalog()`/`syncCatalog()` already exist from Phase 1.

**Files (cwd `extensions/aegis`):**
- Modify: `src/AegisPermissionProvider.php` (add `pruneCatalog`, `getManagedRoles`, `pruneRoles`)
- Modify: `src/Repositories/PermissionRepository.php` (add `deleteManagedBySlugs()`)
- Modify: `src/Repositories/RoleRepository.php` (add `findManaged()` + `deleteManagedBySlugs()`)
- Test: `tests/Unit/PruneCatalogTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Aegis\Tests\Unit;

use Glueful\Extensions\Aegis\Tests\Support\AegisTestCase;

final class PruneCatalogTest extends AegisTestCase
{
    public function test_prunes_only_managed_permission_slugs(): void
    {
        $this->seedPermission(['slug' => 'blog.stale', 'name' => 'Stale', 'managed_by' => 'vendor/blog']);
        $this->seedPermission(['slug' => 'adhoc.keep', 'name' => 'Keep', 'managed_by' => null]);

        // Even if asked to prune a hand-created slug, it must not be deleted.
        $removed = $this->makeProvider()->pruneCatalog(['blog.stale', 'adhoc.keep']);

        self::assertSame(1, $removed);
        self::assertFalse($this->permissionExists('blog.stale'));
        self::assertTrue($this->permissionExists('adhoc.keep'));
    }

    public function test_get_managed_roles_excludes_hand_created(): void
    {
        $this->seedRole(['slug' => 'blog.editor', 'name' => 'Editor', 'managed_by' => 'vendor/blog']);
        $this->seedRole(['slug' => 'adhoc.role', 'name' => 'Adhoc', 'managed_by' => null]);

        self::assertSame(['blog.editor' => 'vendor/blog'], $this->makeProvider()->getManagedRoles());
    }

    public function test_prunes_only_managed_role_slugs(): void
    {
        $this->seedRole(['slug' => 'blog.staleRole', 'name' => 'Stale', 'managed_by' => 'vendor/blog']);
        $this->seedRole(['slug' => 'adhoc.role', 'name' => 'Adhoc', 'managed_by' => null]);

        $removed = $this->makeProvider()->pruneRoles(['blog.staleRole', 'adhoc.role']);

        self::assertSame(1, $removed);
        self::assertFalse($this->roleExists('blog.staleRole'));
        self::assertTrue($this->roleExists('adhoc.role'));
    }
}
```

> Extend `tests/Support/AegisTestCase.php` (created in Phase 1) with `seedRole(array $row)`, `permissionExists(string $slug): bool`, and `roleExists(string $slug): bool` helpers — thin `Connection` inserts/queries against the `permissions`/`roles` tables (incl. `managed_by`). Asserting via harness helpers avoids widening the provider's public API for tests.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/PruneCatalogTest.php`
Expected: FAIL — `pruneCatalog` not implemented.

- [ ] **Step 3: Add repository delete + provider method**

In `src/Repositories/PermissionRepository.php`:

```php
    /**
     * Delete managed permissions (managed_by IS NOT NULL) whose slug is in $slugs.
     * Hand-created rows (managed_by NULL) are never deleted, even if listed.
     *
     * @param string[] $slugs
     * @return int rows deleted
     */
    public function deleteManagedBySlugs(array $slugs): int
    {
        if (count($slugs) === 0) {
            return 0;
        }
        return $this->db->table($this->table)
            ->whereIn('slug', $slugs)
            ->whereNotNull('managed_by')
            ->delete();
    }
```

> Confirm the query-builder `whereIn`/`whereNotNull`/`delete` method names against sibling repository code; adjust to the established forms. Clear the static slug cache after delete if `PermissionRepository` exposes a cache-reset, so subsequent lookups in the same request see the deletion (the prune path is CLI, so a fresh process usually applies — but reset if a helper exists).

In `src/Repositories/RoleRepository.php` add the symmetric role methods:

```php
    /**
     * Managed (extension/app-synced) roles only: managed_by IS NOT NULL.
     *
     * @return array<string, string> slug => managed_by
     */
    public function findManaged(): array
    {
        $rows = $this->db->table($this->table)
            ->select(['slug', 'managed_by'])
            ->whereNotNull('managed_by')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['slug']] = $row['managed_by'];
        }
        return $out;
    }

    /**
     * Delete managed roles (managed_by IS NOT NULL) whose slug is in $slugs.
     * Hand-created roles (managed_by NULL) are never deleted, even if listed.
     *
     * @param string[] $slugs
     * @return int rows deleted
     */
    public function deleteManagedBySlugs(array $slugs): int
    {
        if (count($slugs) === 0) {
            return 0;
        }
        return $this->db->table($this->table)
            ->whereIn('slug', $slugs)
            ->whereNotNull('managed_by')
            ->delete();
    }
```

> `role_permissions` rows reference `roles.uuid` with `cascadeOnDelete()` — that FK is defined in `002_CreatePermissionsTables.php` (the `role_permissions` table lives there, not in `001`). Deleting a stale role therefore removes its grant rows. Confirm cascade behavior on the target DB driver (SQLite/MySQL); if cascade is not guaranteed in the test harness, delete `role_permissions` for the role uuids first.

In `src/AegisPermissionProvider.php` add all three methods:

```php
    public function pruneCatalog(array $slugs): int
    {
        return $this->getPermissionRepository()->deleteManagedBySlugs($slugs);
    }

    /** @return array<string, string> */
    public function getManagedRoles(): array
    {
        return $this->getRoleRepository()->findManaged();
    }

    public function pruneRoles(array $roleSlugs): int
    {
        return $this->getRoleRepository()->deleteManagedBySlugs($roleSlugs);
    }
```

> `getRoleRepository()` was added in the Phase 1 plan (Task 17 role sync). Reuse it.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/PruneCatalogTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/AegisPermissionProvider.php src/Repositories/PermissionRepository.php src/Repositories/RoleRepository.php tests/Unit/PruneCatalogTest.php tests/Support/AegisTestCase.php
git commit -m "feat(aegis): implement prune + managed-role methods for stale catalog rows"
```

---

### Task 7: Final verification

- [ ] **Framework suite + analysis**

Run (in `glueful/framework`): `composer test && composer run analyse:changed`
Expected: PASS; no new analysis errors in the new `Catalog`/`Console` files.

- [ ] **Aegis suite**

Run (in `extensions/aegis`): `composer test`
Expected: PASS.

- [ ] **Manual smoke (optional, against a real app with Aegis enabled)**

Run: `php glueful permissions:list` then `php glueful permissions:diff` then `php glueful permissions:sync --prune`
Expected: list shows declared catalog; diff shows drift sections; sync reports created/updated/unchanged and prunes only stale managed rows.

- [ ] **CHANGELOG**

Update framework `[Unreleased]` (permissions:list/diff, attribute scanner, --prune) and Aegis `[Unreleased]` (pruneCatalog). Commit with the work.

---

## Spec coverage (self-review)

- `permissions:list` (spec §4.4) → Task 3
- `permissions:diff` declared × enforced × **persisted-all** × **managed** (spec §4.4) → Tasks 2, 4
- Unmanaged persisted reported separately, informational, never pruned (spec §4.4) → Task 4
- Role drift (enforced/declared/stale roles) — roles are first-class declarations (spec §4.1, Phase 1) → Tasks 2, 4
- Canonical role key shared by enforcement + diff (`RoleKey`, introduced Phase 1) so they never diverge; bare `admin` ↔ `role.admin`, dotted passes through → Phase 1 Task 11, Phase 2 Tasks 2/4
- Capability interfaces are opt-in/segregated so adding prune/role support never breaks existing or third-party providers (spec §4.6 third-party RBAC providers) → Tasks 4, 5, 6
- Attribute scanning as validator, not source of truth (spec D5, §4.5) → Task 2
- Registry introspection (spec §4.1 "records declaring extension") → Task 1
- Stale = managed-only; unmanaged never pruned (spec §4.1, §6) → Tasks 4, 5, 6
- `--prune` explicit + non-automatic, for permissions **and** roles (spec §6) → Tasks 4, 5, 6

---

## Execution notes (deviations from plan as built)

- **Commands are parameterless + self-aggregating** (matching the Phase 1 `SyncCommand` reality), not constructor-injected: `list`/`diff`/`sync` resolve services from their own container and run `discover()` + `aggregatePermissionCatalog()` at execute-time.
- **`DiffCommand` exposes a pure static `classify()`** that does the declared×enforced×persisted×managed set math; it is unit-tested directly (`tests/Unit/Console/Permissions/DiffCommandTest.php`), with a separate integration smoke test for wiring. This sidesteps mocking final/DI-heavy command internals.
- **`permissions` gained a `deleted_at` column.** The framework QueryBuilder soft-deletes by default and rejects `IN (...)` conditions for DELETE, so prune soft-deletes **per slug**; `findManaged()` SELECTs auto-exclude soft-deleted rows. Roles already had `deleted_at`.
- **Result:** full framework suite green (1019 tests); Aegis suite green (9 tests); PHPStan + phpcs clean on new files.
