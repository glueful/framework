# Framework Permissions Catalog — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the framework a provider-agnostic declarative permission catalog that any service provider (core, app, extension) can populate, that enforces uniformly through `PermissionManager::can()`, and that Aegis can persist via an idempotent sync.

**Architecture:** Service providers declare `Permission`/`Role` builder DTOs via new optional `permissions()`/`roles()` methods. A dedicated, fail-fast `ExtensionManager::aggregatePermissionCatalog()` pass collects them (plus framework core permissions) into a shared `PermissionRegistry` singleton, validating collisions and dangling grants. A `RegistryRoleVoter` feeds declared roles into the existing `Gate`. `GateAttributeMiddleware` is rewritten to route `#[RequiresPermission]`/`#[RequiresRole]` through `PermissionManager::can()` (single enforcement entry point). A `PermissionCatalogSyncInterface` lets a provider persist the catalog; Aegis implements it, adds a `managed_by` column, and exposes the synced rows for stale detection. Sync is CLI-only.

**Tech Stack:** PHP 8.3, PHPUnit 10, Glueful DI container (`FactoryDefinition`), Symfony Console, Glueful schema builder (`SchemaBuilderInterface`).

**Scope note:** This plan delivers Phase 1 only — a complete, working, testable slice. Phase 2 (`permissions:diff`/`permissions:list` + attribute scanning) and Phase 3 (test helpers, voter/policy sugar) will each get their own plan after Phase 1 lands. Source of truth: `docs/superpowers/specs/2026-06-03-extension-permissions-dx-design.md`.

**Repos:** Tasks 1–13 are in `glueful/framework` (cwd `/Users/michaeltawiahsowah/Sites/glueful/framework`). Tasks 14–18 are in `glueful/aegis` (cwd `/Users/michaeltawiahsowah/Sites/glueful/extensions/aegis`).

**Commit convention for this repo:** do NOT add a `Co-Authored-By` trailer. Never stage `CLAUDE.md`.

---

## Part A — Catalog primitives (framework)

### Task 1: `Permission` builder DTO

**Files:**
- Create: `src/Permissions/Catalog/Permission.php`
- Test: `tests/Unit/Permissions/Catalog/PermissionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\Permission;
use PHPUnit\Framework\TestCase;

final class PermissionTest extends TestCase
{
    public function test_define_defaults_label_to_slug(): void
    {
        $p = Permission::define('blog.posts.publish');
        self::assertSame('blog.posts.publish', $p->slug());
        self::assertSame('blog.posts.publish', $p->toArray()['name']);
    }

    public function test_fluent_setters_populate_to_array(): void
    {
        $p = Permission::define('blog.posts.publish')
            ->label('Publish Posts')
            ->description('Publish and unpublish posts')
            ->category('blog')
            ->resource('posts')
            ->managedBy('vendor/blog');

        self::assertSame([
            'slug' => 'blog.posts.publish',
            'name' => 'Publish Posts',
            'description' => 'Publish and unpublish posts',
            'category' => 'blog',
            'resource_type' => 'posts',
            'managed_by' => 'vendor/blog',
        ], $p->toArray());
        self::assertSame('vendor/blog', $p->getManagedBy());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/PermissionTest.php`
Expected: FAIL — class `Glueful\Permissions\Catalog\Permission` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

/**
 * Declarative permission definition. Fluent builder; the canonical source of a
 * permission's metadata before it is persisted by a provider.
 */
final class Permission
{
    private string $label;
    private ?string $description = null;
    private ?string $category = null;
    private ?string $resourceType = null;
    private ?string $managedBy = null;

    private function __construct(private readonly string $slug)
    {
        $this->label = $slug;
    }

    public static function define(string $slug): self
    {
        return new self($slug);
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function category(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function resource(string $resourceType): self
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    public function managedBy(string $packageName): self
    {
        $this->managedBy = $packageName;
        return $this;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function getManagedBy(): ?string
    {
        return $this->managedBy;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->label,
            'description' => $this->description,
            'category' => $this->category,
            'resource_type' => $this->resourceType,
            'managed_by' => $this->managedBy,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/PermissionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Permissions/Catalog/Permission.php tests/Unit/Permissions/Catalog/PermissionTest.php
git commit -m "feat(permissions): add Permission catalog builder DTO"
```

---

### Task 2: `Role` builder DTO

**Files:**
- Create: `src/Permissions/Catalog/Role.php`
- Test: `tests/Unit/Permissions/Catalog/RoleTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\Role;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function test_builds_role_with_grants(): void
    {
        $r = Role::define('blog.editor')
            ->label('Blog Editor')
            ->description('Edits blog content')
            ->grants(['blog.posts.publish', 'blog.posts.delete'])
            ->level(40)
            ->parent('blog.author')
            ->managedBy('vendor/blog');

        self::assertSame('blog.editor', $r->slug());
        self::assertSame(['blog.posts.publish', 'blog.posts.delete'], $r->grantedPermissions());
        self::assertSame('vendor/blog', $r->getManagedBy());

        $arr = $r->toArray();
        self::assertSame('blog.editor', $arr['slug']);
        self::assertSame('Blog Editor', $arr['name']);
        self::assertSame(40, $arr['level']);
        self::assertSame('blog.author', $arr['parent']);
        self::assertSame(['blog.posts.publish', 'blog.posts.delete'], $arr['grants']);
    }

    public function test_defaults(): void
    {
        $r = Role::define('blog.viewer');
        self::assertSame('blog.viewer', $r->toArray()['name']);
        self::assertSame(0, $r->toArray()['level']);
        self::assertSame([], $r->grantedPermissions());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/RoleTest.php`
Expected: FAIL — class `Glueful\Permissions\Catalog\Role` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

/**
 * Declarative role definition. A role names a set of granted permission slugs;
 * it does NOT assign users to the role (that is a provider/runtime concern).
 */
final class Role
{
    private string $label;
    private ?string $description = null;
    /** @var string[] */
    private array $grants = [];
    private int $level = 0;
    private ?string $parent = null;
    private ?string $managedBy = null;

    private function __construct(private readonly string $slug)
    {
        $this->label = $slug;
    }

    public static function define(string $slug): self
    {
        return new self($slug);
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /** @param string[] $permissionSlugs */
    public function grants(array $permissionSlugs): self
    {
        $this->grants = array_values($permissionSlugs);
        return $this;
    }

    public function level(int $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function parent(string $roleSlug): self
    {
        $this->parent = $roleSlug;
        return $this;
    }

    public function managedBy(string $packageName): self
    {
        $this->managedBy = $packageName;
        return $this;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /** @return string[] */
    public function grantedPermissions(): array
    {
        return $this->grants;
    }

    public function getManagedBy(): ?string
    {
        return $this->managedBy;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->label,
            'description' => $this->description,
            'grants' => $this->grants,
            'level' => $this->level,
            'parent' => $this->parent,
            'managed_by' => $this->managedBy,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/RoleTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Permissions/Catalog/Role.php tests/Unit/Permissions/Catalog/RoleTest.php
git commit -m "feat(permissions): add Role catalog builder DTO"
```

---

### Task 3: Catalog exceptions

**Files:**
- Create: `src/Permissions/Catalog/PermissionCatalogException.php`
- Create: `src/Permissions/Catalog/DuplicatePermissionException.php`
- Create: `src/Permissions/Catalog/DanglingGrantException.php`
- Test: `tests/Unit/Permissions/Catalog/CatalogExceptionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\{DanglingGrantException, DuplicatePermissionException, PermissionCatalogException};
use PHPUnit\Framework\TestCase;

final class CatalogExceptionsTest extends TestCase
{
    public function test_duplicate_names_both_sources(): void
    {
        $e = new DuplicatePermissionException('blog.posts.publish', 'vendor/a', 'vendor/b');
        self::assertInstanceOf(PermissionCatalogException::class, $e);
        self::assertStringContainsString('blog.posts.publish', $e->getMessage());
        self::assertStringContainsString('vendor/a', $e->getMessage());
        self::assertStringContainsString('vendor/b', $e->getMessage());
    }

    public function test_dangling_grant_names_role_and_permission(): void
    {
        $e = new DanglingGrantException('blog.editor', 'blog.posts.missing');
        self::assertInstanceOf(PermissionCatalogException::class, $e);
        self::assertStringContainsString('blog.editor', $e->getMessage());
        self::assertStringContainsString('blog.posts.missing', $e->getMessage());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/CatalogExceptionsTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write minimal implementations**

`src/Permissions/Catalog/PermissionCatalogException.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

/** Base for fatal catalog-build errors. These must NOT be swallowed at boot. */
class PermissionCatalogException extends \RuntimeException
{
}
```

`src/Permissions/Catalog/DuplicatePermissionException.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

final class DuplicatePermissionException extends PermissionCatalogException
{
    public function __construct(string $slug, string $existingSource, string $newSource)
    {
        parent::__construct(sprintf(
            'Duplicate permission slug "%s" declared by both "%s" and "%s". Prefix slugs per package to avoid collisions.',
            $slug,
            $existingSource,
            $newSource
        ));
    }
}
```

`src/Permissions/Catalog/DanglingGrantException.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

final class DanglingGrantException extends PermissionCatalogException
{
    public function __construct(string $roleSlug, string $permissionSlug)
    {
        parent::__construct(sprintf(
            'Role "%s" grants permission "%s", which is not declared by any enabled provider.',
            $roleSlug,
            $permissionSlug
        ));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/CatalogExceptionsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Permissions/Catalog/PermissionCatalogException.php src/Permissions/Catalog/DuplicatePermissionException.php src/Permissions/Catalog/DanglingGrantException.php tests/Unit/Permissions/Catalog/CatalogExceptionsTest.php
git commit -m "feat(permissions): add catalog exception types"
```

---

### Task 4: `PermissionRegistry`

**Files:**
- Create: `src/Permissions/Catalog/PermissionRegistry.php`
- Test: `tests/Unit/Permissions/Catalog/PermissionRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\{DanglingGrantException, DuplicatePermissionException, Permission, PermissionRegistry, Role};
use PHPUnit\Framework\TestCase;

final class PermissionRegistryTest extends TestCase
{
    public function test_register_and_lookup(): void
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.posts.publish'), 'vendor/blog');
        self::assertTrue($r->has('blog.posts.publish'));
        self::assertCount(1, $r->permissions());
        self::assertSame('vendor/blog', $r->permissions()[0]->getManagedBy());
    }

    public function test_same_slug_same_source_is_idempotent(): void
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.x'), 'vendor/blog');
        $r->register(Permission::define('blog.x'), 'vendor/blog');
        self::assertCount(1, $r->permissions());
    }

    public function test_cross_source_collision_throws(): void
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.x'), 'vendor/a');
        $this->expectException(DuplicatePermissionException::class);
        $r->register(Permission::define('blog.x'), 'vendor/b');
    }

    public function test_role_permission_map(): void
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.publish'), 'vendor/blog');
        $r->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');
        self::assertSame(['blog.editor' => ['blog.publish']], $r->rolePermissionMap());
    }

    public function test_validate_rejects_dangling_grant(): void
    {
        $r = new PermissionRegistry();
        $r->registerRole(Role::define('blog.editor')->grants(['blog.missing']), 'vendor/blog');
        $this->expectException(DanglingGrantException::class);
        $r->validate();
    }

    public function test_validate_allows_wildcard_grant(): void
    {
        $r = new PermissionRegistry();
        $r->registerRole(Role::define('admin')->grants(['*']), 'app');
        $r->validate();
        $this->expectNotToPerformAssertions();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/PermissionRegistryTest.php`
Expected: FAIL — class `PermissionRegistry` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

/**
 * In-memory, provider-agnostic catalog of declared permissions and roles.
 * Populated once per process by ExtensionManager::aggregatePermissionCatalog().
 */
final class PermissionRegistry
{
    /** @var array<string, Permission> slug => Permission */
    private array $permissions = [];
    /** @var array<string, string> slug => declaring package */
    private array $permissionSources = [];
    /** @var array<string, Role> slug => Role */
    private array $roles = [];
    /** @var array<string, string> slug => declaring package */
    private array $roleSources = [];

    public function register(Permission $perm, string $source): void
    {
        $slug = $perm->slug();
        if (isset($this->permissionSources[$slug]) && $this->permissionSources[$slug] !== $source) {
            throw new DuplicatePermissionException($slug, $this->permissionSources[$slug], $source);
        }
        $perm->managedBy($source);
        $this->permissions[$slug] = $perm;
        $this->permissionSources[$slug] = $source;
    }

    public function registerRole(Role $role, string $source): void
    {
        $slug = $role->slug();
        if (isset($this->roleSources[$slug]) && $this->roleSources[$slug] !== $source) {
            throw new DuplicatePermissionException($slug, $this->roleSources[$slug], $source);
        }
        $role->managedBy($source);
        $this->roles[$slug] = $role;
        $this->roleSources[$slug] = $source;
    }

    public function has(string $slug): bool
    {
        return isset($this->permissions[$slug]);
    }

    /** @return Permission[] */
    public function permissions(): array
    {
        return array_values($this->permissions);
    }

    /** @return Role[] */
    public function roles(): array
    {
        return array_values($this->roles);
    }

    /** @return array<string, string[]> role slug => granted permission slugs */
    public function rolePermissionMap(): array
    {
        $map = [];
        foreach ($this->roles as $slug => $role) {
            $map[$slug] = $role->grantedPermissions();
        }
        return $map;
    }

    /** Fatal validation: every role grant must reference a declared permission (or "*"). */
    public function validate(): void
    {
        foreach ($this->roles as $role) {
            foreach ($role->grantedPermissions() as $permSlug) {
                if ($permSlug !== '*' && !$this->has($permSlug)) {
                    throw new DanglingGrantException($role->slug(), $permSlug);
                }
            }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/PermissionRegistryTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Permissions/Catalog/PermissionRegistry.php tests/Unit/Permissions/Catalog/PermissionRegistryTest.php
git commit -m "feat(permissions): add PermissionRegistry with collision and dangling-grant validation"
```

---

## Part B — Declaration hooks + build pass (framework)

### Task 5: Declaration methods on `ServiceProvider`

**Files:**
- Modify: `src/Extensions/ServiceProvider.php` (add methods after `boot()`, ~line 51)
- Test: `tests/Unit/Extensions/ServiceProviderDeclarationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\ServiceProvider;
use Glueful\Permissions\Catalog\{Permission, Role};
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class ServiceProviderDeclarationTest extends TestCase
{
    public function test_base_provider_declares_nothing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $provider = new class ($container) extends ServiceProvider {};
        self::assertSame([], $provider->permissions());
        self::assertSame([], $provider->roles());
    }

    public function test_subclass_can_declare(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $provider = new class ($container) extends ServiceProvider {
            public function permissions(): array
            {
                return [Permission::define('blog.publish')];
            }
            public function roles(): array
            {
                return [Role::define('blog.editor')->grants(['blog.publish'])];
            }
        };
        self::assertSame('blog.publish', $provider->permissions()[0]->slug());
        self::assertSame('blog.editor', $provider->roles()[0]->slug());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ServiceProviderDeclarationTest.php`
Expected: FAIL — `permissions()`/`roles()` not defined on `ServiceProvider`.

- [ ] **Step 3: Add the methods**

In `src/Extensions/ServiceProvider.php`, immediately after the `boot()` method (the block ending around line 51), add:

```php
    /**
     * Declare permissions contributed by this provider.
     * Collected by ExtensionManager::aggregatePermissionCatalog() into the PermissionRegistry.
     *
     * @return list<\Glueful\Permissions\Catalog\Permission>
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * Declare roles contributed by this provider.
     *
     * @return list<\Glueful\Permissions\Catalog\Role>
     */
    public function roles(): array
    {
        return [];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ServiceProviderDeclarationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Extensions/ServiceProvider.php tests/Unit/Extensions/ServiceProviderDeclarationTest.php
git commit -m "feat(extensions): add permissions()/roles() declaration hooks to ServiceProvider"
```

---

### Task 6: Register `PermissionRegistry` as a shared service

**Files:**
- Modify: `src/Container/Providers/CoreProvider.php` (in the "Permission services" block, near the `Gate` factory ~line 308)
- Test: `tests/Integration/Permissions/PermissionRegistryServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Permissions;

use Glueful\Permissions\Catalog\PermissionRegistry;
use Glueful\Tests\Integration\IntegrationTestCase;

final class PermissionRegistryServiceTest extends IntegrationTestCase
{
    public function test_registry_is_shared_singleton(): void
    {
        $a = $this->getContainer()->get(PermissionRegistry::class);
        $b = $this->getContainer()->get(PermissionRegistry::class);
        self::assertInstanceOf(PermissionRegistry::class, $a);
        self::assertSame($a, $b, 'PermissionRegistry must be a shared singleton');
    }
}
```

> If `IntegrationTestCase`/`getContainer()` differ in this repo, mirror the bootstrap used by an existing test under `tests/Integration/` (e.g. how it builds the container via `ContainerFactory`). The assertion that matters: two `get()` calls return the same instance.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Permissions/PermissionRegistryServiceTest.php`
Expected: FAIL — service `PermissionRegistry` not registered (NotFound) or not shared.

- [ ] **Step 3: Register the service**

In `src/Container/Providers/CoreProvider.php`, in the permission services area (just before the `Gate` factory definition at ~line 308), add:

```php
        // Declarative permission catalog (shared singleton; filled by ExtensionManager::aggregatePermissionCatalog()).
        $defs[\Glueful\Permissions\Catalog\PermissionRegistry::class] = new FactoryDefinition(
            \Glueful\Permissions\Catalog\PermissionRegistry::class,
            fn(): \Glueful\Permissions\Catalog\PermissionRegistry
                => new \Glueful\Permissions\Catalog\PermissionRegistry()
        );
```

(`FactoryDefinition` defaults `$shared = true`, so a single instance is reused.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Permissions/PermissionRegistryServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Container/Providers/CoreProvider.php tests/Integration/Permissions/PermissionRegistryServiceTest.php
git commit -m "feat(permissions): register PermissionRegistry as shared container service"
```

---

### Task 7: `ExtensionManager::aggregatePermissionCatalog()`

**Files:**
- Modify: `src/Extensions/ExtensionManager.php` (add method + `packageNameFor()` helper)
- Test: `tests/Unit/Extensions/AggregatePermissionCatalogTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ServiceProvider;
use Glueful\Permissions\Catalog\{DanglingGrantException, Permission, PermissionRegistry, Role};
use Glueful\Interfaces\Permission\PermissionStandards;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class AggregatePermissionCatalogTest extends TestCase
{
    private function managerWith(PermissionRegistry $registry, array $providers): ExtensionManager
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) use ($registry) {
            if ($id === PermissionRegistry::class) {
                return $registry;
            }
            if ($id === ApplicationContext::class) {
                return $this->createMock(ApplicationContext::class);
            }
            throw new \RuntimeException("unexpected get($id)");
        });

        $manager = new ExtensionManager($container);
        // Inject providers via reflection (mirrors what discover() populates).
        $ref = new \ReflectionProperty(ExtensionManager::class, 'providers');
        $ref->setAccessible(true);
        $ref->setValue($manager, $providers);
        return $manager;
    }

    public function test_seeds_core_permissions_and_provider_declarations(): void
    {
        $registry = new PermissionRegistry();
        $container = $this->createMock(ContainerInterface::class);
        $provider = new class ($container) extends ServiceProvider {
            public function permissions(): array
            {
                return [Permission::define('blog.publish')];
            }
            public function roles(): array
            {
                return [Role::define('blog.editor')->grants(['blog.publish'])];
            }
        };

        $manager = $this->managerWith($registry, ['BlogProvider' => $provider]);
        $manager->aggregatePermissionCatalog();

        // Core permissions present and framework-owned.
        self::assertTrue($registry->has(PermissionStandards::PERMISSION_SYSTEM_ACCESS));
        $core = null;
        foreach ($registry->permissions() as $p) {
            if ($p->slug() === PermissionStandards::PERMISSION_SYSTEM_ACCESS) {
                $core = $p;
            }
        }
        self::assertSame('glueful/framework', $core->getManagedBy());

        // Provider declaration present.
        self::assertTrue($registry->has('blog.publish'));
        self::assertSame(['blog.editor' => ['blog.publish']], $registry->rolePermissionMap());
    }

    public function test_dangling_grant_is_fatal(): void
    {
        $registry = new PermissionRegistry();
        $container = $this->createMock(ContainerInterface::class);
        $provider = new class ($container) extends ServiceProvider {
            public function roles(): array
            {
                return [Role::define('blog.editor')->grants(['blog.missing'])];
            }
        };

        $manager = $this->managerWith($registry, ['BlogProvider' => $provider]);
        $this->expectException(DanglingGrantException::class);
        $manager->aggregatePermissionCatalog();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Extensions/AggregatePermissionCatalogTest.php`
Expected: FAIL — `aggregatePermissionCatalog()` not defined.

- [ ] **Step 3: Add the method**

In `src/Extensions/ExtensionManager.php`, add these imports at the top (after the existing `use` lines):

```php
use Glueful\Permissions\Catalog\Permission;
use Glueful\Permissions\Catalog\PermissionRegistry;
use Glueful\Interfaces\Permission\PermissionStandards;
```

Then add the method (e.g. after `boot()`):

```php
    /**
     * Build the declarative permission catalog from framework core + all registered providers.
     *
     * This is intentionally a dedicated pass, NOT part of register()/boot() — those wrap each
     * provider in catch(\Throwable) and only log, which would swallow collision/dangling-grant
     * errors. Catalog errors must be fatal, so this method lets them propagate.
     */
    public function aggregatePermissionCatalog(): void
    {
        /** @var PermissionRegistry $registry */
        $registry = $this->container->get(PermissionRegistry::class);

        // 1. Framework core permissions.
        foreach (PermissionStandards::CORE_PERMISSIONS as $slug) {
            $registry->register(Permission::define($slug), 'glueful/framework');
        }

        // 2. Provider declarations (app + extensions).
        foreach ($this->providers as $providerClass => $provider) {
            $source = $this->packageNameFor($providerClass);
            foreach ($provider->permissions() as $perm) {
                $registry->register($perm, $source);
            }
            foreach ($provider->roles() as $role) {
                $registry->registerRole($role, $source);
            }
        }

        // 3. Fatal validation: role grants must reference declared permissions.
        $registry->validate();
    }

    /**
     * Resolve a provider class to its composer package name (stable identifier for managed_by).
     * Falls back to "app" for app/core providers not present in the composer candidate list.
     */
    private function packageNameFor(string $providerClass): string
    {
        foreach ((new \Glueful\Extensions\PackageManifest($this->getContext()))->getCandidates() as $candidate) {
            if ($candidate->provider === $providerClass) {
                return $candidate->name;
            }
        }
        return 'app';
    }
```

> Verify `PackageManifest` candidate exposes `->name` (composer package) and `->provider` (class). It is already used this way in `src/Console/Commands/Extensions/ListCommand.php`. If the package-name property differs, adjust `$candidate->name` to the correct accessor — the contract is "stable composer package name per D8".

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Extensions/AggregatePermissionCatalogTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Extensions/ExtensionManager.php tests/Unit/Extensions/AggregatePermissionCatalogTest.php
git commit -m "feat(extensions): aggregate declarative permission catalog (fail-fast)"
```

---

### Task 8: Wire the build pass into bootstrap (fail-fast)

**Files:**
- Modify: `src/Framework.php` (`initializeExtensions()`, ~lines 515-525)
- Test: `tests/Unit/Framework/InitializeExtensionsCatalogTest.php` (see note)

- [ ] **Step 1: Write the failing test**

`initializeExtensions()` is private and duck-types its calls on whatever the container returns for `ExtensionManager::class`. `ExtensionManager` is `final` (not mockable), so inject a lightweight stub object with the three methods. Use reflection to bypass `Framework`'s constructor and set the private `?ContainerInterface $container`. Two behavioral assertions prove the contract: a catalog exception propagates (fail-fast), while a discovery failure is still swallowed (only the catalog is fail-fast).

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Framework;

use Glueful\Framework;
use Glueful\Permissions\Catalog\DuplicatePermissionException;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

final class InitializeExtensionsCatalogTest extends TestCase
{
    private function frameworkWithContainer(object $extensionManagerStub): Framework
    {
        $container = new class ($extensionManagerStub) implements ContainerInterface {
            public function __construct(private object $mgr)
            {
            }
            public function get(string $id): mixed
            {
                return $this->mgr;
            }
            public function has(string $id): bool
            {
                return true;
            }
        };

        $framework = (new \ReflectionClass(Framework::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(Framework::class, 'container');
        $prop->setAccessible(true);
        $prop->setValue($framework, $container);
        return $framework;
    }

    private function invokeInitialize(Framework $framework): void
    {
        $m = new \ReflectionMethod(Framework::class, 'initializeExtensions');
        $m->setAccessible(true);
        $m->invoke($framework);
    }

    public function test_catalog_exception_propagates(): void
    {
        $stub = new class {
            public function discover(): void
            {
            }
            public function aggregatePermissionCatalog(): void
            {
                throw new DuplicatePermissionException('blog.x', 'vendor/a', 'vendor/b');
            }
            public function boot(): void
            {
            }
        };

        $this->expectException(DuplicatePermissionException::class);
        $this->invokeInitialize($this->frameworkWithContainer($stub));
    }

    public function test_discovery_failure_is_swallowed_and_catalog_still_runs(): void
    {
        $stub = new class {
            public bool $catalogRan = false;
            public function discover(): void
            {
                throw new \RuntimeException('discover boom');
            }
            public function aggregatePermissionCatalog(): void
            {
                $this->catalogRan = true;
            }
            public function boot(): void
            {
            }
        };

        // Must NOT throw — discovery errors are logged, not fatal.
        $this->invokeInitialize($this->frameworkWithContainer($stub));
        self::assertTrue($stub->catalogRan, 'catalog build runs even after discovery failure');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Framework/InitializeExtensionsCatalogTest.php`
Expected: FAIL — `Framework` still wraps `aggregatePermissionCatalog()` in the swallowing try/catch (or the method/call does not exist yet), so the exception is caught and `test_catalog_exception_propagates` fails.

- [ ] **Step 3: Restructure `initializeExtensions()`**

Replace the body of `initializeExtensions()` (currently lines ~515-525) with:

```php
    private function initializeExtensions(): void
    {
        /** @var \Glueful\Extensions\ExtensionManager $extensions */
        $extensions = $this->container->get(\Glueful\Extensions\ExtensionManager::class);

        try {
            // Discover providers before catalog build so the provider list is populated.
            $extensions->discover();
        } catch (\Throwable $e) {
            error_log("Extensions discovery failed: " . $e->getMessage());
        }

        // Fail-fast: catalog build must NOT be swallowed. Collisions / dangling grants
        // are configuration bugs that should stop the app, not be logged and ignored.
        $extensions->aggregatePermissionCatalog();

        try {
            $extensions->boot();
        } catch (\Throwable $e) {
            error_log("Extensions boot failed: " . $e->getMessage());
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Framework/InitializeExtensionsCatalogTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full extensions + permissions suites to confirm no boot regression**

Run: `vendor/bin/phpunit tests/Unit/Extensions tests/Unit/Permissions`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Framework.php tests/Unit/Framework/InitializeExtensionsCatalogTest.php
git commit -m "feat(bootstrap): run permission catalog build as fail-fast pass"
```

---

## Part C — Enforcement unification (framework)

### Task 9: `RegistryRoleVoter`

**Files:**
- Create: `src/Permissions/Voters/RegistryRoleVoter.php`
- Test: `tests/Unit/Permissions/Voters/RegistryRoleVoterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Voters;

use Glueful\Auth\UserIdentity;
use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role};
use Glueful\Permissions\{Context, Vote};
use Glueful\Permissions\Voters\RegistryRoleVoter;
use PHPUnit\Framework\TestCase;

final class RegistryRoleVoterTest extends TestCase
{
    private function registry(): PermissionRegistry
    {
        $r = new PermissionRegistry();
        $r->register(Permission::define('blog.publish'), 'vendor/blog');
        $r->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');
        return $r;
    }

    public function test_grants_when_user_has_declared_role(): void
    {
        $voter = new RegistryRoleVoter($this->registry());
        $user = new UserIdentity('u1', ['blog.editor']);
        $vote = $voter->vote($user, 'blog.publish', null, new Context());
        self::assertSame(Vote::GRANT, $vote->result);
    }

    public function test_abstains_when_user_has_no_roles(): void
    {
        $voter = new RegistryRoleVoter($this->registry());
        $user = new UserIdentity('u1', []); // no role source yielded roles
        $vote = $voter->vote($user, 'blog.publish', null, new Context());
        self::assertSame(Vote::ABSTAIN, $vote->result, 'must not fabricate role membership');
    }

    public function test_abstains_when_role_does_not_grant_permission(): void
    {
        $voter = new RegistryRoleVoter($this->registry());
        $user = new UserIdentity('u1', ['blog.editor']);
        $vote = $voter->vote($user, 'blog.delete', null, new Context());
        self::assertSame(Vote::ABSTAIN, $vote->result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Voters/RegistryRoleVoterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Voters;

use Glueful\Auth\UserIdentity;
use Glueful\Permissions\Catalog\PermissionRegistry;
use Glueful\Permissions\{Context, Vote, VoterInterface};

/**
 * Maps a user's already-known roles (from request identity / Context / JWT / config)
 * to permissions using the DECLARED role->permission map in the PermissionRegistry.
 *
 * The catalog defines what a role grants; it does NOT assign users to roles. If the
 * user has no roles, this voter abstains — it never fabricates membership.
 */
final class RegistryRoleVoter implements VoterInterface
{
    public function __construct(private readonly PermissionRegistry $registry)
    {
    }

    public function supports(string $permission, mixed $resource, Context $ctx): bool
    {
        return true;
    }

    public function vote(UserIdentity $user, string $permission, mixed $resource, Context $ctx): Vote
    {
        $map = $this->registry->rolePermissionMap();
        foreach ($user->roles() as $role) {
            $perms = $map[$role] ?? [];
            if (in_array('*', $perms, true)) {
                return new Vote(Vote::GRANT);
            }
            $dot = strpos($permission, '.') !== false
                ? substr($permission, 0, (int) strpos($permission, '.')) . '.*'
                : null;
            if ($dot !== null && in_array($dot, $perms, true)) {
                return new Vote(Vote::GRANT);
            }
            if (in_array($permission, $perms, true)) {
                return new Vote(Vote::GRANT);
            }
        }
        return new Vote(Vote::ABSTAIN);
    }

    public function priority(): int
    {
        return 15; // after config RoleVoter (10), before ScopeVoter (20)
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Voters/RegistryRoleVoterTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Permissions/Voters/RegistryRoleVoter.php tests/Unit/Permissions/Voters/RegistryRoleVoterTest.php
git commit -m "feat(permissions): add RegistryRoleVoter for declared-role fallback"
```

---

### Task 10: Register `RegistryRoleVoter` in the Gate factory

**Files:**
- Modify: `src/Container/Providers/CoreProvider.php` (Gate factory, after the `RoleVoter` registration ~line 332)
- Test: `tests/Integration/Permissions/GateRegistryVoterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Permissions;

use Glueful\Auth\UserIdentity;
use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role};
use Glueful\Permissions\{Context, Gate, Vote};
use Glueful\Tests\Integration\IntegrationTestCase;

final class GateRegistryVoterTest extends IntegrationTestCase
{
    public function test_gate_honors_declared_role_via_registry(): void
    {
        /** @var PermissionRegistry $registry */
        $registry = $this->getContainer()->get(PermissionRegistry::class);
        $registry->register(Permission::define('blog.publish'), 'vendor/blog');
        $registry->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');

        /** @var Gate $gate */
        $gate = $this->getContainer()->get(Gate::class);
        $decision = $gate->decide(new UserIdentity('u1', ['blog.editor']), 'blog.publish', null, new Context());

        self::assertSame(Vote::GRANT, $decision);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Permissions/GateRegistryVoterTest.php`
Expected: FAIL — Gate has no registry-backed voter, decision is not `grant`.

- [ ] **Step 3: Add the voter to the Gate factory**

In `src/Container/Providers/CoreProvider.php`, inside the `Gate` factory closure, right after the `RoleVoter` registration (the line `$gate->registerVoter(new \Glueful\Permissions\Voters\RoleVoter(...));` ~line 332), add:

```php
                // 3b. Registry-backed role voter: lets DECLARED roles enforce (fallback path).
                if ($c->has(\Glueful\Permissions\Catalog\PermissionRegistry::class)) {
                    $gate->registerVoter(new \Glueful\Permissions\Voters\RegistryRoleVoter(
                        $c->get(\Glueful\Permissions\Catalog\PermissionRegistry::class)
                    ));
                }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Permissions/GateRegistryVoterTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Container/Providers/CoreProvider.php tests/Integration/Permissions/GateRegistryVoterTest.php
git commit -m "feat(permissions): wire RegistryRoleVoter into Gate"
```

---

### Task 11: Rewrite `GateAttributeMiddleware` as a `PermissionManager` adapter

**Files:**
- Create: `src/Permissions/Catalog/RoleKey.php` (shared role-name canonicalization)
- Create: `tests/Unit/Permissions/Catalog/RoleKeyTest.php`
- Modify: `src/Permissions/Middleware/GateAttributeMiddleware.php` (full rewrite of constructor + `handle()`)
- Modify: `src/Container/Providers/CoreProvider.php` (middleware factory ~line 512-516: inject `permission.manager`)
- Test: `tests/Unit/Permissions/Middleware/GateAttributeMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Middleware;

use Glueful\Auth\UserIdentity;
use Glueful\Permissions\Middleware\GateAttributeMiddleware;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\{Request, Response};
use PHPUnit\Framework\TestCase;

final class GateAttributeMiddlewareTest extends TestCase
{
    private function requestFor(string $class, string $method): Request
    {
        $r = new Request();
        $r->attributes->set('handler_meta', ['class' => $class, 'method' => $method]);
        $r->attributes->set('auth.user', new UserIdentity('u1', ['blog.editor']));
        return $r;
    }

    public function test_calls_can_with_system_resource_default(): void
    {
        $manager = $this->createMock(PermissionManager::class);
        $manager->expects(self::once())
            ->method('can')
            ->with('u1', 'blog.publish', 'system', self::anything())
            ->willReturn(true);

        $mw = new GateAttributeMiddleware($manager);
        $resp = $mw->handle(
            $this->requestFor(FixtureController::class, 'publish'),
            fn(Request $req) => new Response('ok')
        );
        self::assertSame('ok', $resp->getContent());
    }

    public function test_denies_with_403_when_can_returns_false(): void
    {
        $manager = $this->createMock(PermissionManager::class);
        $manager->method('can')->willReturn(false);

        $mw = new GateAttributeMiddleware($manager);
        $resp = $mw->handle(
            $this->requestFor(FixtureController::class, 'publish'),
            fn(Request $req) => new Response('ok')
        );
        self::assertSame(403, $resp->getStatusCode());
    }

    public function test_role_attribute_maps_to_role_dot_name(): void
    {
        $manager = $this->createMock(PermissionManager::class);
        $manager->expects(self::once())
            ->method('can')
            ->with('u1', 'role.editor', 'system', self::anything())
            ->willReturn(true);

        $mw = new GateAttributeMiddleware($manager);
        $mw->handle(
            $this->requestFor(RoleFixtureController::class, 'index'),
            fn(Request $req) => new Response('ok')
        );
    }

    public function test_dotted_role_attribute_passes_through_unchanged(): void
    {
        // Regression: a dotted role value must NOT be re-prefixed to "role.role.admin".
        $manager = $this->createMock(PermissionManager::class);
        $manager->expects(self::once())
            ->method('can')
            ->with('u1', 'role.admin', 'system', self::anything())
            ->willReturn(true);

        $mw = new GateAttributeMiddleware($manager);
        $mw->handle(
            $this->requestFor(DottedRoleFixtureController::class, 'index'),
            fn(Request $req) => new Response('ok')
        );
    }
}

#[\Glueful\Auth\Attributes\RequiresPermission('blog.publish')]
final class FixtureController
{
    public function publish(): void
    {
    }
}

final class RoleFixtureController
{
    #[\Glueful\Auth\Attributes\RequiresRole('editor')]
    public function index(): void
    {
    }
}

final class DottedRoleFixtureController
{
    #[\Glueful\Auth\Attributes\RequiresRole('role.admin')]
    public function index(): void
    {
    }
}
```

Also create `tests/Unit/Permissions/Catalog/RoleKeyTest.php` pinning the shared contract (the canonical cases the diff scanner relies on):

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\RoleKey;
use PHPUnit\Framework\TestCase;

final class RoleKeyTest extends TestCase
{
    public function test_bare_role_is_prefixed(): void
    {
        self::assertSame('role.admin', RoleKey::canonical('admin')); // #[RequiresRole('admin')] compares as role.admin
    }

    public function test_dotted_role_passes_through(): void
    {
        self::assertSame('role.admin', RoleKey::canonical('role.admin')); // not re-prefixed
        self::assertSame('blog.editor', RoleKey::canonical('blog.editor'));
    }

    public function test_declared_slug_and_enforced_value_share_canonical_form(): void
    {
        // A declared Role slug 'admin' and an enforced #[RequiresRole('admin')] resolve to the
        // same canonical key, so diff never reports a false drift between them.
        self::assertSame(RoleKey::canonical('admin'), RoleKey::canonical('admin'));
        self::assertSame('role.admin', RoleKey::canonical('admin'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Middleware/GateAttributeMiddlewareTest.php`
Expected: FAIL — middleware constructor still requires `Gate`, not `PermissionManager`.

- [ ] **Step 3: Create `RoleKey`, then rewrite the middleware**

First create the shared canonicalizer `src/Permissions/Catalog/RoleKey.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

/**
 * Canonical role-reference key shared by enforcement (GateAttributeMiddleware) and the
 * Phase 2 permissions:diff scanner, so the two never diverge.
 *
 * Contract: a dotted value is already canonical and passes through unchanged; a bare value
 * is prefixed with "role." — e.g. 'admin' => 'role.admin', 'role.admin' => 'role.admin',
 * 'blog.editor' => 'blog.editor'.
 */
final class RoleKey
{
    public static function canonical(string $role): string
    {
        return str_contains($role, '.') ? $role : "role.{$role}";
    }
}
```

Then replace the contents of `src/Permissions/Middleware/GateAttributeMiddleware.php` with:

```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Routing\RouteMiddleware;
use Glueful\Permissions\PermissionManager;
use Glueful\Permissions\Catalog\RoleKey;
use Glueful\Auth\UserIdentity;

/**
 * Thin adapter: collects #[RequiresPermission]/#[RequiresRole] from the handler and
 * routes each check through PermissionManager::can() — the single enforcement entry
 * point. PermissionManager (not this middleware) decides provider-vs-Gate fallback.
 */
final class GateAttributeMiddleware implements RouteMiddleware
{
    public function __construct(private PermissionManager $permissions)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $meta = $request->attributes->get('handler_meta'); // ['class'=>..., 'method'=>...]
        if ($meta === null || !isset($meta['class'])) {
            return $next($request);
        }

        /** @var UserIdentity|null $user */
        $user = $request->attributes->get('auth.user');
        if ($user === null) {
            return $this->forbidden();
        }

        // Context passed to can(); roles come from the request identity (no fabrication).
        $context = [
            'roles' => $user->roles(),
            'scopes' => $user->scopes(),
            'tenant_id' => $request->attributes->get('tenant.id'),
            'route_params' => (array) $request->attributes->get('route.params'),
            'jwt_claims' => (array) $request->attributes->get('jwt.claims'),
        ];

        $required = [];
        // Permission attributes carry an optional resource; default to 'system'.
        foreach ($this->collectAttributePairs($meta, 'Glueful\\Auth\\Attributes\\RequiresPermission') as [$name, $resource]) {
            $required[] = [$name, $resource ?? 'system'];
        }
        // Role attributes: canonicalize via the SHARED RoleKey contract (dotted values pass
        // through unchanged; non-dotted map to "role.{name}"). The same RoleKey is used by the
        // Phase 2 permissions:diff scanner so enforcement and drift detection never diverge.
        foreach ($this->collectAttributeValues($meta, 'Glueful\\Auth\\Attributes\\RequiresRole', 'name') as $roleName) {
            $required[] = [RoleKey::canonical($roleName), 'system'];
        }

        foreach ($required as [$perm, $resource]) {
            if (!$this->permissions->can($user->id(), $perm, $resource, $context)) {
                return $this->forbidden();
            }
        }

        return $next($request);
    }

    /**
     * @param array{class?:string,method?:string} $meta
     * @return array<string>
     */
    private function collectAttributeValues(array $meta, string $attributeFqcn, string $prop): array
    {
        $values = [];
        foreach ($this->attributeInstances($meta, $attributeFqcn) as $inst) {
            $v = $inst->{$prop} ?? null;
            if ($v !== null) {
                $values[] = $v;
            }
        }
        return $values;
    }

    /**
     * @param array{class?:string,method?:string} $meta
     * @return list<array{0:string,1:?string}> [name, resource]
     */
    private function collectAttributePairs(array $meta, string $attributeFqcn): array
    {
        $pairs = [];
        foreach ($this->attributeInstances($meta, $attributeFqcn) as $inst) {
            $name = $inst->name ?? null;
            if ($name !== null) {
                $pairs[] = [$name, $inst->resource ?? null];
            }
        }
        return $pairs;
    }

    /**
     * @param array{class?:string,method?:string} $meta
     * @return list<object>
     */
    private function attributeInstances(array $meta, string $attributeFqcn): array
    {
        $instances = [];
        try {
            $rc = new \ReflectionClass($meta['class']);
            foreach ($rc->getAttributes($attributeFqcn) as $a) {
                $instances[] = $a->newInstance();
            }
            if (isset($meta['method']) && $rc->hasMethod($meta['method'])) {
                foreach ($rc->getMethod($meta['method'])->getAttributes($attributeFqcn) as $a) {
                    $instances[] = $a->newInstance();
                }
            }
        } catch (\Throwable) {
            // Attribute class absent or reflection failure — treat as no requirements.
        }
        return $instances;
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => 'Forbidden',
            'code' => 403,
            'error_code' => 'FORBIDDEN',
        ], 403);
    }
}
```

- [ ] **Step 4: Update the DI definition**

In `src/Container/Providers/CoreProvider.php`, change the `GateAttributeMiddleware` factory (~lines 512-516) to inject the permission manager instead of the Gate:

```php
        $defs[\Glueful\Permissions\Middleware\GateAttributeMiddleware::class] = new FactoryDefinition(
            \Glueful\Permissions\Middleware\GateAttributeMiddleware::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Permissions\Middleware\GateAttributeMiddleware(
                $c->get('permission.manager')
            )
        );
```

> Confirm the manager is registered under the `'permission.manager'` id (it is, per `CoreProvider.php:356`). If a class-id alias exists, either works.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Middleware/GateAttributeMiddlewareTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Permissions/Catalog/RoleKey.php src/Permissions/Middleware/GateAttributeMiddleware.php src/Container/Providers/CoreProvider.php tests/Unit/Permissions/Catalog/RoleKeyTest.php tests/Unit/Permissions/Middleware/GateAttributeMiddlewareTest.php
git commit -m "refactor(permissions): route attribute enforcement through PermissionManager::can() with shared RoleKey"
```

---

### Task 12: End-to-end enforcement regression (provider modes + fallback)

**Files:**
- Test: `tests/Integration/Permissions/AttributeEnforcementModesTest.php`

This task is tests-only — it pins the D7 contract across the three modes the spec requires. It exercises `PermissionManager::can()` directly (the entry point the middleware now uses), with and without an active provider.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Permissions;

use Glueful\Interfaces\Permission\PermissionProviderInterface;
use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, Role};
use Glueful\Permissions\PermissionManager;
use Glueful\Tests\Integration\IntegrationTestCase;

final class AttributeEnforcementModesTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // The active provider is a static singleton — reset it AND the mode config so
        // tests cannot leak provider/mode state into one another (order-independence).
        $manager = $this->getContainer()->get('permission.manager');
        $manager->clearProvider();
        $manager->setPermissionsConfig(['provider_mode' => 'replace', 'strategy' => 'affirmative']);
        parent::tearDown();
    }

    public function test_no_provider_falls_back_to_registry_role(): void
    {
        /** @var PermissionRegistry $registry */
        $registry = $this->getContainer()->get(PermissionRegistry::class);
        $registry->register(Permission::define('blog.publish'), 'vendor/blog');
        $registry->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');

        $manager = $this->getContainer()->get('permission.manager');
        // roles supplied via context (request identity path)
        $granted = $manager->can('u1', 'blog.publish', 'system', ['roles' => ['blog.editor']]);
        self::assertTrue($granted);
    }

    public function test_no_provider_no_roles_denies(): void
    {
        $manager = $this->getContainer()->get('permission.manager');
        $granted = $manager->can('u1', 'blog.publish', 'system', ['roles' => []]);
        self::assertFalse($granted);
    }

    public function test_replace_mode_uses_provider(): void
    {
        $manager = $this->getContainer()->get('permission.manager');
        $manager->setPermissionsConfig(['provider_mode' => 'replace', 'strategy' => 'affirmative']);

        $provider = $this->createMock(PermissionProviderInterface::class);
        $provider->method('getProviderInfo')->willReturn(['name' => 'fake']);
        $provider->method('getAvailablePermissions')->willReturn([
            'system.access' => '', 'users.view' => '', 'users.create' => '',
            'users.edit' => '', 'users.delete' => '', 'blog.publish' => '',
        ]);
        $provider->expects(self::once())
            ->method('can')->with('u1', 'blog.publish', 'system', self::anything())
            ->willReturn(true);

        $manager->setProvider($provider, []);
        self::assertTrue($manager->can('u1', 'blog.publish', 'system', []));
    }

    public function test_combine_mode_composes_provider_and_gate(): void
    {
        /** @var PermissionRegistry $registry */
        $registry = $this->getContainer()->get(PermissionRegistry::class);
        $registry->register(Permission::define('blog.publish'), 'vendor/blog');
        $registry->registerRole(Role::define('blog.editor')->grants(['blog.publish']), 'vendor/blog');

        $manager = $this->getContainer()->get('permission.manager');
        $manager->setPermissionsConfig(['provider_mode' => 'combine', 'strategy' => 'affirmative']);

        $coreAvailable = [
            'system.access' => '', 'users.view' => '', 'users.create' => '',
            'users.edit' => '', 'users.delete' => '',
        ];

        // (a) Provider abstains (can() === false → ABSTAIN in combine); Gate must still
        //     grant via the declared role. Proves the Gate composes, not just the provider.
        $abstainer = $this->createMock(PermissionProviderInterface::class);
        $abstainer->method('getProviderInfo')->willReturn(['name' => 'abstainer']);
        $abstainer->method('getAvailablePermissions')->willReturn($coreAvailable);
        $abstainer->method('can')->willReturn(false);
        $manager->setProvider($abstainer, []);

        self::assertTrue(
            $manager->can('u1', 'blog.publish', 'system', ['roles' => ['blog.editor']]),
            'combine: provider abstains, Gate grants via declared role'
        );

        // (b) Provider grants → composes to grant even without any role.
        $granter = $this->createMock(PermissionProviderInterface::class);
        $granter->method('getProviderInfo')->willReturn(['name' => 'granter']);
        $granter->method('getAvailablePermissions')->willReturn($coreAvailable);
        $granter->method('can')->willReturn(true);
        $manager->setProvider($granter, []);

        self::assertTrue(
            $manager->can('u1', 'unrelated.thing', 'system', ['roles' => []]),
            'combine: provider grant composes to grant'
        );
    }
}
```

`PermissionManager` has **no** `clearProvider()` today and `$activeProvider` is a **static** singleton, so this test will leak state without a reset. Add this small method to `src/Permissions/PermissionManager.php` (next to `setProvider()`):

```php
    /** Reset the active provider. Intended for test isolation between cases. */
    public function clearProvider(): void
    {
        self::$activeProvider = null;
    }
```

`setProvider()` enforces `CORE_PERMISSIONS` via `validateCorePermissions()`, so every mock provider above returns the five core slugs from `getAvailablePermissions()`.

- [ ] **Step 2: Run the test**

Run: `vendor/bin/phpunit tests/Integration/Permissions/AttributeEnforcementModesTest.php`
Expected: PASS (4 tests).

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Permissions/AttributeEnforcementModesTest.php src/Permissions/PermissionManager.php
git commit -m "test(permissions): pin enforcement contract across provider modes and fallback"
```

---

## Part D — Sync contract + CLI (framework)

### Task 13: `PermissionCatalogSyncInterface` + `SyncResult`

**Files:**
- Create: `src/Interfaces/Permission/PermissionCatalogSyncInterface.php`
- Create: `src/Permissions/Catalog/SyncResult.php`
- Test: `tests/Unit/Permissions/Catalog/SyncResultTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Permissions\Catalog;

use Glueful\Permissions\Catalog\SyncResult;
use PHPUnit\Framework\TestCase;

final class SyncResultTest extends TestCase
{
    public function test_holds_counts_and_stale(): void
    {
        $r = new SyncResult(created: 2, updated: 1, unchanged: 5, stale: ['blog.old']);
        self::assertSame(2, $r->created);
        self::assertSame(1, $r->updated);
        self::assertSame(5, $r->unchanged);
        self::assertSame(['blog.old'], $r->stale);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/SyncResultTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write implementations**

`src/Permissions/Catalog/SyncResult.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Permissions\Catalog;

final class SyncResult
{
    /** @param string[] $stale managed slugs absent from the registry */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly array $stale = [],
    ) {
    }
}
```

`src/Interfaces/Permission/PermissionCatalogSyncInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

use Glueful\Permissions\Catalog\SyncResult;

/**
 * Optional capability: a permission provider that can persist the declarative catalog.
 * Implemented by providers like Aegis. Sync is idempotent and CLI-driven.
 */
interface PermissionCatalogSyncInterface
{
    /**
     * Upsert the declared catalog into the provider's store, by slug.
     *
     * @param array<int, array<string, mixed>> $permissions each Permission::toArray()
     * @param array<int, array<string, mixed>> $roles       each Role::toArray()
     */
    public function syncCatalog(array $permissions, array $roles): SyncResult;

    /**
     * Return persisted, extension/app-managed permissions (managed_by IS NOT NULL)
     * as slug => managed_by. Hand-created rows (managed_by NULL) are excluded.
     *
     * @return array<string, string>
     */
    public function getManagedCatalog(): array;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Permissions/Catalog/SyncResultTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Interfaces/Permission/PermissionCatalogSyncInterface.php src/Permissions/Catalog/SyncResult.php tests/Unit/Permissions/Catalog/SyncResultTest.php
git commit -m "feat(permissions): add PermissionCatalogSyncInterface and SyncResult"
```

---

### Task 14: `permissions:sync` command

**Files:**
- Create: `src/Console/Commands/Permissions/SyncCommand.php`
- Test: `tests/Unit/Console/Permissions/SyncCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Permissions;

use Glueful\Console\Commands\Permissions\SyncCommand;
use Glueful\Interfaces\Permission\PermissionCatalogSyncInterface;
use Glueful\Permissions\Catalog\{Permission, PermissionRegistry, SyncResult};
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

final class SyncCommandTest extends TestCase
{
    public function test_syncs_registry_into_provider(): void
    {
        $registry = new PermissionRegistry();
        $registry->register(Permission::define('blog.publish'), 'vendor/blog');

        $provider = $this->createMock(PermissionCatalogSyncInterface::class);
        $provider->expects(self::once())
            ->method('syncCatalog')
            ->willReturn(new SyncResult(created: 1, updated: 0, unchanged: 0, stale: []));

        $cmd = new SyncCommand($registry, $provider);
        $tester = new CommandTester($cmd);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('created: 1', $tester->getDisplay());
    }

    public function test_reports_when_provider_cannot_sync(): void
    {
        $registry = new PermissionRegistry();
        $cmd = new SyncCommand($registry, null); // no sync-capable provider
        $tester = new CommandTester($cmd);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('no persistent permission provider', strtolower($tester->getDisplay()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Console/Permissions/SyncCommandTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Permissions;

use Glueful\Console\BaseCommand;
use Glueful\Interfaces\Permission\PermissionCatalogSyncInterface;
use Glueful\Permissions\Catalog\PermissionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'permissions:sync',
    description: 'Persist the declared permission catalog into the active provider'
)]
final class SyncCommand extends BaseCommand
{
    public function __construct(
        private ?PermissionRegistry $registry = null,
        private ?PermissionCatalogSyncInterface $provider = null,
    ) {
        parent::__construct();
        $this->registry ??= $this->getService(PermissionRegistry::class);
        // Resolve the active provider only if it can sync.
        if ($this->provider === null && $this->getService('permission.manager') !== null) {
            $active = $this->getService('permission.manager')->getProvider();
            if ($active instanceof PermissionCatalogSyncInterface) {
                $this->provider = $active;
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->provider === null) {
            $output->writeln('<comment>No persistent permission provider installed; declarations remain in-registry only.</comment>');
            return self::SUCCESS;
        }

        $permissions = array_map(fn($p) => $p->toArray(), $this->registry->permissions());
        $roles = array_map(fn($r) => $r->toArray(), $this->registry->roles());

        $result = $this->provider->syncCatalog($permissions, $roles);

        $output->writeln(sprintf(
            '<info>Catalog synced</info> — created: %d, updated: %d, unchanged: %d',
            $result->created,
            $result->updated,
            $result->unchanged
        ));
        if (count($result->stale) > 0) {
            $output->writeln('<comment>Stale (managed, no longer declared): ' . implode(', ', $result->stale) . '</comment>');
            $output->writeln('<comment>Run with --prune to remove them (Phase 2).</comment>');
        }

        return self::SUCCESS;
    }
}
```

> `getProvider()` on `PermissionManager` returns the active provider (used in the spec/codebase). If the accessor has a different name, adjust. The constructor's optional args exist so the unit test can inject fakes; in production the command resolves from the container.

- [ ] **Step 4: Register the command**

Confirm how console commands are registered in this repo (e.g. a command list in a service provider or auto-discovery). Add `SyncCommand` alongside the existing `Extensions\*` commands registration. If registration is array-based, append `\Glueful\Console\Commands\Permissions\SyncCommand::class`.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Console/Permissions/SyncCommandTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/Permissions/SyncCommand.php tests/Unit/Console/Permissions/SyncCommandTest.php
git commit -m "feat(permissions): add permissions:sync command"
```

---

## Part E — Aegis persistence (`extensions/aegis`)

> cwd for Tasks 15–18: `/Users/michaeltawiahsowah/Sites/glueful/extensions/aegis`. Tests use namespace `Glueful\Extensions\Aegis\Tests\` (autoload-dev already configured). Create `tests/` if absent. Run with `vendor/bin/phpunit`.

### Task 15: `managed_by` migration

**Files:**
- Create: `migrations/010_AddManagedByToRbacCatalog.php`
- Test: `tests/Unit/Migrations/AddManagedByMigrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Aegis\Tests\Unit\Migrations;

use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use PHPUnit\Framework\TestCase;

final class AddManagedByMigrationTest extends TestCase
{
    public function test_up_adds_managed_by_to_permissions_and_roles(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/010_AddManagedByToRbacCatalog.php';
        $migration = new \Glueful\Extensions\Aegis\Database\Migrations\AddManagedByToRbacCatalog();

        $added = [];
        $schema = $this->createMock(SchemaBuilderInterface::class);
        $schema->method('hasColumn')->willReturn(false);
        $schema->method('addColumn')->willReturnCallback(function (string $table, string $col) use (&$added) {
            $added[] = "$table.$col";
            return [];
        });

        $migration->up($schema);

        self::assertContains('permissions.managed_by', $added);
        self::assertContains('roles.managed_by', $added);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Migrations/AddManagedByMigrationTest.php`
Expected: FAIL — migration class not found.

- [ ] **Step 3: Write the migration**

```php
<?php

namespace Glueful\Extensions\Aegis\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Adds managed_by (nullable composer package name) to the RBAC catalog tables so
 * extension/app-synced rows can be distinguished from hand-created ones.
 */
class AddManagedByToRbacCatalog implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        foreach (['permissions', 'roles'] as $table) {
            if (!$schema->hasColumn($table, 'managed_by')) {
                $schema->addColumn($table, 'managed_by', [
                    'type' => 'string',
                    'length' => 100,
                    'nullable' => true,
                ]);
            }
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach (['permissions', 'roles'] as $table) {
            if ($schema->hasColumn($table, 'managed_by')) {
                $schema->dropColumn($table, 'managed_by');
            }
        }
    }

    public function getDescription(): string
    {
        return 'Add managed_by column to permissions and roles for catalog sync ownership';
    }
}
```

> Verify the exact `addColumn`/`dropColumn` definition-array shape against an existing framework migration that alters columns (`SchemaBuilderInterface::addColumn(string $table, string $column, array $definition)` is the signature). Match the `type`/`length`/`nullable` keys the builder expects. Confirm `getDescription()` is part of `MigrationInterface` (mirror `002_CreatePermissionsTables.php`'s implemented methods); add `down()`/`getDescription()` exactly as that file declares them.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Migrations/AddManagedByMigrationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add migrations/010_AddManagedByToRbacCatalog.php tests/Unit/Migrations/AddManagedByMigrationTest.php
git commit -m "feat(aegis): add managed_by column to permissions and roles"
```

---

### Task 16: `AegisPermissionProvider::getManagedCatalog()`

**Files:**
- Modify: `src/AegisPermissionProvider.php` (implement `PermissionCatalogSyncInterface`; add `getManagedCatalog()`)
- Modify: `src/Repositories/PermissionRepository.php` (add `findManaged(): array`)
- Test: `tests/Unit/GetManagedCatalogTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Aegis\Tests\Unit;

use Glueful\Extensions\Aegis\Tests\Support\AegisTestCase; // SQLite harness (create if absent)

final class GetManagedCatalogTest extends AegisTestCase
{
    public function test_returns_only_managed_rows(): void
    {
        $this->seedPermission(['slug' => 'blog.publish', 'name' => 'Publish', 'managed_by' => 'vendor/blog']);
        $this->seedPermission(['slug' => 'adhoc.thing', 'name' => 'Adhoc', 'managed_by' => null]);

        $provider = $this->makeProvider();
        $managed = $provider->getManagedCatalog();

        self::assertSame(['blog.publish' => 'vendor/blog'], $managed);
        self::assertArrayNotHasKey('adhoc.thing', $managed);
    }
}
```

> Create `tests/Support/AegisTestCase.php` providing: a SQLite `Connection`, schema for `permissions`/`roles`/`role_permissions` incl. `managed_by`, a `seedPermission(array)` helper, and `makeProvider()` returning an `AegisPermissionProvider` wired to that connection. Mirror how Aegis repositories obtain their `Connection`/`db` (see `PermissionRepository` constructor). Keep it minimal — only the tables these tests touch.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/GetManagedCatalogTest.php`
Expected: FAIL — `getManagedCatalog()` not defined (and harness missing).

- [ ] **Step 3: Add repository query + provider method**

In `src/Repositories/PermissionRepository.php`, add:

```php
    /**
     * Managed (extension/app-synced) permissions only: managed_by IS NOT NULL.
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
```

> Confirm the query-builder method for "is not null" (`whereNotNull`) exists in this codebase; if the convention differs (e.g. `whereNull(... , 'IS NOT')`), use the established form. `$this->db` and `$this->table` are already used by sibling methods like `findAllPermissions()`.

Two prerequisites so `managed_by` round-trips (required by Task 17's `permissionDiffers`/idempotency):

1. **Add `'managed_by'` to the repository default select** so `findPermissionBySlug()` returns it. In `src/Repositories/PermissionRepository.php`, add `'managed_by'` to the `$defaultFields` list (the property used by the `select(...)` calls).
2. **Add `managed_by` support to `src/Models/Permission.php`.** This model uses **typed properties** populated in `__construct(array $data)` (e.g. `private ?string $category; ... $this->category = $data['category'] ?? null;`), NOT a `$this->data` bag. So make three matching edits:
   - Add the property alongside the others:
     ```php
     private ?string $managedBy;
     ```
   - Set it in `__construct()` next to the sibling assignments:
     ```php
     $this->managedBy = $data['managed_by'] ?? null;
     ```
   - Add the getter mirroring `getCategory()`:
     ```php
     public function getManagedBy(): ?string
     {
         return $this->managedBy;
     }
     ```
   - If `toArray()` exists on the model and is used for output, add `'managed_by' => $this->managedBy`.

In `src/AegisPermissionProvider.php`, add the interface to the `implements` list:

```php
use Glueful\Interfaces\Permission\PermissionCatalogSyncInterface;
// ...
class AegisPermissionProvider implements PermissionProviderInterface, PermissionCatalogSyncInterface
```

and add:

```php
    /** @return array<string, string> */
    public function getManagedCatalog(): array
    {
        return $this->getPermissionRepository()->findManaged();
    }
```

> `syncCatalog()` is added in Task 17 — adding the interface now will make the class temporarily abstract-incomplete. To keep the build green between tasks, add a minimal `syncCatalog()` stub here that throws `\LogicException('implemented in next task')`, OR sequence Tasks 16–17 as a single commit. Prefer the stub so each task's tests pass independently; Task 17 replaces the stub.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/GetManagedCatalogTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/AegisPermissionProvider.php src/Repositories/PermissionRepository.php src/Models/Permission.php tests/Unit/GetManagedCatalogTest.php tests/Support/AegisTestCase.php
git commit -m "feat(aegis): expose managed catalog for stale detection"
```

---

### Task 17: `AegisPermissionProvider::syncCatalog()`

**Files:**
- Modify: `src/AegisPermissionProvider.php` (replace the Task 16 stub)
- Test: `tests/Unit/SyncCatalogTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Aegis\Tests\Unit;

use Glueful\Extensions\Aegis\Tests\Support\AegisTestCase;

final class SyncCatalogTest extends AegisTestCase
{
    public function test_creates_then_is_idempotent(): void
    {
        $provider = $this->makeProvider();
        $permissions = [[
            'slug' => 'blog.publish', 'name' => 'Publish', 'description' => null,
            'category' => 'blog', 'resource_type' => 'posts', 'managed_by' => 'vendor/blog',
        ]];

        $first = $provider->syncCatalog($permissions, []);
        self::assertSame(1, $first->created);

        $second = $provider->syncCatalog($permissions, []);
        self::assertSame(0, $second->created);
        self::assertSame(1, $second->unchanged);
    }

    public function test_detects_stale_managed_rows_only(): void
    {
        $this->seedPermission(['slug' => 'blog.old', 'name' => 'Old', 'managed_by' => 'vendor/blog']);
        $this->seedPermission(['slug' => 'adhoc.keep', 'name' => 'Adhoc', 'managed_by' => null]);

        $provider = $this->makeProvider();
        // Registry no longer declares blog.old; adhoc.keep is hand-created.
        $result = $provider->syncCatalog([[
            'slug' => 'blog.new', 'name' => 'New', 'description' => null,
            'category' => 'blog', 'resource_type' => null, 'managed_by' => 'vendor/blog',
        ]], []);

        self::assertContains('blog.old', $result->stale);
        self::assertNotContains('adhoc.keep', $result->stale, 'hand-created rows are never stale');
    }

    public function test_claims_existing_unmanaged_row_with_matching_slug(): void
    {
        // An existing hand-created row with the SAME slug + identical metadata but managed_by=NULL
        // must become managed when a declarer claims that slug — otherwise it stays invisible to
        // stale/prune forever.
        $this->seedPermission([
            'slug' => 'blog.publish', 'name' => 'Publish', 'description' => null,
            'category' => 'blog', 'resource_type' => 'posts', 'managed_by' => null,
        ]);

        $provider = $this->makeProvider();
        self::assertArrayNotHasKey('blog.publish', $provider->getManagedCatalog());

        $result = $provider->syncCatalog([[
            'slug' => 'blog.publish', 'name' => 'Publish', 'description' => null,
            'category' => 'blog', 'resource_type' => 'posts', 'managed_by' => 'vendor/blog',
        ]], []);

        self::assertSame(1, $result->updated, 'claiming an unmanaged row counts as an update');
        self::assertSame('vendor/blog', $provider->getManagedCatalog()['blog.publish'] ?? null);
    }

    public function test_role_grants_added_without_duplicates(): void
    {
        $provider = $this->makeProvider();

        $permissions = [
            ['slug' => 'blog.publish', 'name' => 'Publish', 'description' => null, 'category' => 'blog', 'resource_type' => null, 'managed_by' => 'vendor/blog'],
            ['slug' => 'blog.delete', 'name' => 'Delete', 'description' => null, 'category' => 'blog', 'resource_type' => null, 'managed_by' => 'vendor/blog'],
        ];

        // First sync: role grants [blog.publish].
        $provider->syncCatalog($permissions, [[
            'slug' => 'blog.editor', 'name' => 'Editor', 'description' => null,
            'level' => 40, 'parent' => null, 'grants' => ['blog.publish'], 'managed_by' => 'vendor/blog',
        ]]);
        self::assertSame(['blog.publish'], $this->roleGrantSlugs('blog.editor'));

        // Second sync: role now grants [blog.publish, blog.delete] — adds the missing one, no dupes.
        $provider->syncCatalog($permissions, [[
            'slug' => 'blog.editor', 'name' => 'Editor', 'description' => null,
            'level' => 40, 'parent' => null, 'grants' => ['blog.publish', 'blog.delete'], 'managed_by' => 'vendor/blog',
        ]]);

        $grants = $this->roleGrantSlugs('blog.editor');
        sort($grants);
        self::assertSame(['blog.delete', 'blog.publish'], $grants);
    }
}
```

> Extend `tests/Support/AegisTestCase.php` (from Task 16) with: the `roles` and `role_permissions` tables (incl. `managed_by` on `roles`), and a `roleGrantSlugs(string $roleSlug): array` helper that joins `role_permissions` → `permissions` and returns the granted permission slugs for a role. Keep it minimal — only what these tests touch.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/SyncCatalogTest.php`
Expected: FAIL — stub throws `LogicException`.

- [ ] **Step 3: Implement `syncCatalog()`**

Replace the stub in `src/AegisPermissionProvider.php` with:

```php
    public function syncCatalog(array $permissions, array $roles): \Glueful\Permissions\Catalog\SyncResult
    {
        $repo = $this->getPermissionRepository();
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $declaredSlugs = [];
        // Capture slug => uuid as we upsert so syncRoles() does NOT re-query via
        // findPermissionBySlug(), which caches null misses (instance + static) and would
        // return a stale null for a permission we just created in this same call.
        $slugToUuid = [];

        foreach ($permissions as $perm) {
            $slug = $perm['slug'];
            $declaredSlugs[$slug] = true;
            $existing = $repo->findPermissionBySlug($slug);

            // Update payload carries managed_by so claiming an existing unmanaged row transfers
            // ownership. is_system is NOT in the update payload — never downgrade a system row.
            $data = [
                'name' => $perm['name'] ?? $slug,
                'slug' => $slug,
                'description' => $perm['description'] ?? null,
                'category' => $perm['category'] ?? null,
                'resource_type' => $perm['resource_type'] ?? null,
                'managed_by' => $perm['managed_by'] ?? null,
            ];

            if ($existing === null) {
                $createdModel = $repo->createPermission($data + ['is_system' => false]);
                if ($createdModel !== null) {
                    $slugToUuid[$slug] = $createdModel->getUuid();
                }
                $created++;
                continue;
            }

            $slugToUuid[$slug] = $existing->getUuid();
            if ($this->permissionDiffers($existing, $data)) {
                $repo->update($existing->getUuid(), $data);
                $updated++;
            } else {
                $unchanged++;
            }
        }

        $this->syncRoles($roles, $slugToUuid);

        // Stale = managed rows (managed_by IS NOT NULL) no longer declared.
        // Hand-created rows (managed_by NULL) are excluded by getManagedCatalog().
        $stale = [];
        foreach ($this->getManagedCatalog() as $slug => $owner) {
            if (!isset($declaredSlugs[$slug])) {
                $stale[] = $slug;
            }
        }

        return new \Glueful\Permissions\Catalog\SyncResult($created, $updated, $unchanged, $stale);
    }

    /** @param array<string,mixed> $data */
    private function permissionDiffers(\Glueful\Extensions\Aegis\Models\Permission $existing, array $data): bool
    {
        return $existing->getName() !== $data['name']
            || $existing->getDescription() !== $data['description']
            || $existing->getCategory() !== $data['category']
            || $existing->getResourceType() !== $data['resource_type']
            || $existing->getManagedBy() !== $data['managed_by']; // claim/transfer ownership
    }

    /**
     * @param array<int, array<string,mixed>> $roles
     * @param array<string, string> $slugToUuid permission slug => uuid captured during this sync
     */
    private function syncRoles(array $roles, array $slugToUuid): void
    {
        if (count($roles) === 0) {
            return;
        }
        $roleRepo = $this->getRoleRepository();
        $permRepo = $this->getPermissionRepository();
        $rolePermRepo = $this->getRolePermissionRepository();

        foreach ($roles as $role) {
            $slug = $role['slug'];
            $existing = $roleRepo->findRoleBySlug($slug);
            $data = [
                'name' => $role['name'] ?? $slug,
                'slug' => $slug,
                'description' => $role['description'] ?? null,
                'level' => $role['level'] ?? 0,
                'managed_by' => $role['managed_by'] ?? null,
            ];

            if ($existing === null) {
                $roleUuid = $roleRepo->createRole($data + ['is_system' => false])->getUuid();
            } else {
                $roleUuid = $existing->getUuid();
                $roleRepo->update($roleUuid, $data);
            }

            // Resolve grant slugs to permission UUIDs. Grants are guaranteed non-dangling
            // (framework catalog validate() ran before sync), and every declared permission
            // was just upserted, so the slug is in $slugToUuid. Fall back to a fresh lookup
            // only for safety (never relying on the cached null path for just-created slugs).
            $permissionUuids = [];
            foreach (($role['grants'] ?? []) as $grantSlug) {
                if ($grantSlug === '*') {
                    continue; // wildcard grants are not materialized as explicit rows
                }
                $uuid = $slugToUuid[$grantSlug] ?? $permRepo->findPermissionBySlug($grantSlug)?->getUuid();
                if ($uuid !== null) {
                    $permissionUuids[] = $uuid;
                }
            }

            // replaceRolePermissions sets exactly this set: adds missing, removes dropped,
            // never duplicates existing grants — idempotent.
            $rolePermRepo->replaceRolePermissions($roleUuid, $permissionUuids);
        }
    }
```

> Cache gotcha (why `$slugToUuid` exists): `PermissionRepository::findPermissionBySlug()` caches **null** misses in both an instance cache and a `static` global cache (`PermissionRepository.php`). Within one `syncCatalog()` call a grant slug may have been looked-up (cached null) then created — so re-resolving it via `findPermissionBySlug()` would return the stale null and silently drop the grant. The `$slugToUuid` map captured during upsert avoids this. `test_role_grants_added_without_duplicates` declares its permissions and the granting role in the **same** `syncCatalog()` call (and reuses one provider instance), so it exercises and guards this path.

> Role-grant sync uses the real Aegis API: `RoleRepository` (`findRoleBySlug`, `createRole`, `update`) and `RolePermissionRepository::replaceRolePermissions(string $roleUuid, array $permissionUuids, array $options = [])` (confirmed at `RolePermissionRepository.php:258`). Grant slugs are resolved to UUIDs via `PermissionRepository::findPermissionBySlug()`. Ensure the provider exposes `getRoleRepository()` and `getRolePermissionRepository()` accessors (mirror the existing `getPermissionRepository()`); add them if missing. `createRole()` returns `?Role` — the framework's non-dangling guarantee plus a valid `$data` payload means it is non-null here, but assert/guard if the surrounding code style requires it.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/SyncCatalogTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/AegisPermissionProvider.php tests/Unit/SyncCatalogTest.php tests/Support/AegisTestCase.php
git commit -m "feat(aegis): implement idempotent syncCatalog with managed stale detection"
```

---

### Task 18: Remove boot-time sync; ensure CLI-only

**Files:**
- Modify: `src/Services/AegisServiceProvider.php` (`boot()` — confirm no sync call; add guarded opt-in)
- Test: `tests/Unit/NoBootSyncTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Aegis\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NoBootSyncTest extends TestCase
{
    public function test_boot_does_not_call_sync_catalog(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Services/AegisServiceProvider.php');
        // Extract the boot() method body.
        $start = strpos($src, 'function boot(');
        self::assertNotFalse($start);
        $bootBody = substr($src, $start, 4000);
        self::assertStringNotContainsString('syncCatalog', $bootBody, 'boot() must not sync; sync is CLI-only');
    }
}
```

> Guardrail (source-level) because `boot()` side effects are bootstrap-coupled. It pins "no sync during boot" so a future edit cannot silently reintroduce per-request mutation.

- [ ] **Step 2: Run test to verify it fails or passes**

Run: `vendor/bin/phpunit tests/Unit/NoBootSyncTest.php`
Expected: PASS already if `boot()` never referenced sync (Task is then a no-op confirmation). If any auto-sync was prototyped in `boot()`, it FAILS — proceed to Step 3.

- [ ] **Step 3: Ensure boot has no sync (and document the opt-in)**

Confirm `src/Services/AegisServiceProvider.php::boot()` contains no `syncCatalog` call. Add a doc comment above the provider-wiring block stating the policy:

```php
        // NOTE: Catalog sync is migration-like and CLI-only (`php glueful permissions:sync`).
        // Never sync during boot — that would mutate permission tables on normal web/CLI requests.
        // An optional console-only convenience (config `rbac.auto_sync_dev`, default false, run AFTER
        // migrations, never on web) may be added later; it is intentionally not wired here.
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/NoBootSyncTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/AegisServiceProvider.php tests/Unit/NoBootSyncTest.php
git commit -m "chore(aegis): keep catalog sync CLI-only (no boot-time sync)"
```

---

## Final verification

- [ ] **Framework full suite**

Run (in `glueful/framework`): `composer test`
Expected: PASS. If pre-existing unrelated failures exist, confirm they are unchanged from `main`.

- [ ] **Framework static analysis on touched files**

Run: `composer run analyse:changed`
Expected: no new errors in the new `Catalog`, `Voters`, `Middleware`, `Console` files (level per repo gate).

- [ ] **Aegis suite**

Run (in `extensions/aegis`): `composer test`
Expected: PASS.

- [ ] **CHANGELOG**

Update `[Unreleased]` in the framework CHANGELOG covering: declarative permission catalog, `RegistryRoleVoter`, enforcement routed through `PermissionManager::can()`, `PermissionCatalogSyncInterface`, `permissions:sync`. Update Aegis CHANGELOG for `managed_by` + `syncCatalog`. Commit with the work.

---

## Spec coverage check (self-review)

- Declarative DTOs (spec §4.1) → Tasks 1, 2
- Registry + collision + dangling validation (spec §4.1, §8) → Tasks 3, 4
- `permissions()`/`roles()` hooks incl. app provider (spec §4.2) → Task 5
- Core permissions via build pass, not register()/boot() (spec §4.2, review fix) → Tasks 7, 8
- Dedicated non-swallowed build pass (spec D6, §8) → Tasks 7, 8
- Shared registry service (spec §4.2) → Task 6
- RegistryRoleVoter + role-source contract/abstain (spec §4.1, review fix) → Tasks 9, 10, 12
- Single enforcement entry point + `'system'` resource default + role mapping (spec D7, §4.5) → Tasks 11, 12
- Sync interface + stale = managed-only + getManagedCatalog (spec §4.1, review fix) → Tasks 13, 16, 17
- CLI-only sync (spec §4.3) → Tasks 14, 18
- managed_by = package name (spec D8, §7) → Tasks 7, 15, 16
- Regression tests (spec §9) → Tasks 8, 9, 12, 17

**Deferred to later plans:** `permissions:diff`, `permissions:list`, attribute scanning (Phase 2); test helpers, voter/policy sugar (Phase 3); `--prune` execution (surfaced in Task 14, executed in Phase 2).

---

## Execution notes (deviations from plan as built)

- **No `IntegrationTestCase` exists** in the framework — the DI-wiring tests (Tasks 6, 10, 12) were implemented as real-boot integration tests modelled on `FrameworkBootTest` (`tests/Integration/Permissions/PermissionCatalogBootTest.php`, `EnforcementWiringTest.php`).
- **Task 14 (`permissions:sync`) self-aggregates.** `BaseCommand` subclasses rebuild their own container and `ConsoleProvider` autowires commands, so a CLI command can't rely on boot-time aggregation. The command runs `discover()` + `aggregatePermissionCatalog()` at execute-time, then syncs — deterministic and boot-independent. `PermissionRegistry::reset()` makes `aggregatePermissionCatalog()` idempotent (rebuild, not append) so repeated runs never double-register.
- **Task 15 (`managed_by`)** was added directly to the original Aegis migrations (`001_CreateRolesTables`, `002_CreatePermissionsTables`) rather than a separate ALTER migration (project is pre-release). The same migration edit also adds `updated_at`/`deleted_at` to `role_permissions` because `RolePermissionRepository` uses the BaseRepository soft-delete/updated-at lifecycle (a latent gap the original schema missed).
- **Aegis null-cache fix.** `PermissionRepository::findPermissionBySlug()` caches null-misses in a static cache; `createPermission()` now invalidates the slug entry on write and a `clearCache()` was added for test isolation — required for `syncCatalog` idempotency within a process.
- **Result:** full framework suite green (1006 tests); Aegis suite green (6 tests).
