# Framework Permissions: Declarative Catalog (+ Extension DX) — Design Spec

- **Date:** 2026-06-03
- **Status:** Draft (approved for spec write-up; pending implementation plan)
- **Scope:** Cross-repo — primarily `glueful/framework` (new core capability), with `extensions/aegis` as the first persistence consumer
- **Author:** Design session (Glueful maintainers)

## 1. Problem

This is first and foremost a **framework permissions improvement**: the framework has no provider-agnostic, declarative catalog of permissions. Everyone who needs permissions — the framework core, the host application, and extensions alike — hits the same friction. Extensions feel it most acutely, but the gap is the framework's.

Concretely, anyone who wants a feature to be permission-aware faces avoidable friction today:

1. **Permissions have no single home.** The permission *name* is sprinkled into `#[RequiresPermission('posts.publish')]` at the enforcement site, and separately seeded into the database (migration or `PermissionAssignmentService` call). Nothing ties the two together.
2. **Manual, error-prone registration.** To make a permission *exist*, the author must hand-write a migration or call Aegis services in `boot()`. Keeping that list in sync with what routes actually enforce is manual.
3. **Hard coupling to Aegis.** Because declaration goes through `PermissionAssignmentService` (an Aegis service), every permission-aware extension hard-depends on Aegis. Authors must guard with `if ($app->has(PermissionAssignmentService::class))` and have no sane fallback when Aegis is absent.
4. **No discovery.** Aegis does not scan other extensions for permission declarations — there is no manifest, attribute scan, or registration event. A permission referenced in `#[RequiresPermission]` but never seeded simply does not exist, and the check denies silently.
5. **No introspection.** There is no first-class way to list what permissions exist, who declared them, which are orphaned (declared but never enforced), or which are stale (in the DB but no longer declared).

### Root cause

The framework has no provider-agnostic, discoverable permission catalog. Caveats 2–5 are all symptoms of that single gap — and it is a framework-level gap, not an extension-only one.

## 2. Goals / Non-Goals

### Consumers (who declares permissions)

The capability is uniform across three consumers, in priority order of who it helps:

1. **The framework core** — `PermissionStandards::CORE_PERMISSIONS` (`system.access`, `users.*`, …) can be expressed through the same catalog instead of living only as constants plus an Aegis seed migration.
2. **The host application** — `AppServiceProvider` (or any app service provider) declares the app's own permissions/roles the same way, replacing hand-written seed migrations.
3. **Extensions** — the original motivating case; an extension declares its permissions with no hard dependency on any particular provider.

### Goals
- Give the **framework** a first-class, provider-agnostic declarative permission catalog.
- Let **any** service provider (framework, app, or extension) **declare** its permissions and roles in **one place**, that declaration being the canonical source of truth.
- **Auto-discover** declarations from all enabled providers and aggregate them into a framework-level registry.
- **Sync** the registry into the active permission provider (Aegis) idempotently, treated operationally like migrations.
- **Degrade gracefully**: declaring permissions is always safe and never requires Aegis; with no persistent provider, enforcement routes through `PermissionManager::can()` to the Gate, where a registry-backed voter lets *declared* roles/permissions still decide.
- **Unify enforcement**: attribute-based (`#[RequiresPermission]`) and programmatic (`PermissionHelper`/`can()`) checks share one entry point (`PermissionManager::can()`), eliminating the current two-path split that causes fallback drift.
- Provide **tooling** to detect drift between declared, enforced, and persisted permissions.

### Non-Goals
- Replacing the framework `Gate`/`VoterInterface`/`PolicyRegistry` model. This design complements it.
- Changing the `PermissionProviderInterface` single-active-provider model.
- Building a permission *UI* (admin screens). Out of scope.
- Multi-tenant permission scoping changes. Out of scope (existing `Context` mechanics unchanged).

## 3. Key Decisions

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | **Split ownership**: the framework owns the declaration contract + in-memory registry; Aegis consumes it and persists. | Decouples extensions from Aegis (they depend only on the framework, which they already do) and makes graceful degradation possible because the catalog exists independent of any provider. |
| D2 | **Builder DTOs only** (`Permission::define()`, `Role::define()`) as the declaration shape — no plain-array fallback. | DTOs are IDE-discoverable and self-validating; a second array code path adds normalization/validation surface for no real benefit (YAGNI). One typed path. |
| D3 | **Declaration via optional `ServiceProvider` methods** (`permissions()`, `roles()`), defaulting to `[]`. | Opt-in, zero breakage, no new interface to implement, discovered through the existing extension lifecycle. |
| D4 | **Sync is an optional provider capability** (`PermissionCatalogSyncInterface`), driven by an **explicit CLI command** (never during normal boot). | Mirrors the migration safety posture; providers that cannot persist a catalog simply do not implement it. |
| D5 | **Attribute scanning is a validator, not a source of truth.** | The declared registry is canonical; scanning `#[RequiresPermission]` is used only to catch enforce-vs-declare drift. |
| D6 | **Catalog aggregation is a dedicated build phase**, invoked by bootstrap *outside* the `register()`/`boot()` loops, with exceptions propagating (fail-fast). | `ExtensionManager::registerProviders()` and `::boot()` both wrap provider calls in `catch (\Throwable)` → log only (`ExtensionManager.php:101-115`, `:104-117`). Building the catalog there would **swallow** collision/validation errors and continue with an incomplete catalog. Declarations are pure/static, so a separate deterministic pass is safe. |
| D7 | **Single enforcement entry point: `#[RequiresPermission]`/`#[RequiresRole]` route through `PermissionManager::can()`, not `Gate::decide()` directly.** | Today `GateAttributeMiddleware` calls `$this->gate->decide()` directly (`GateAttributeMiddleware.php:47`) while controller/helper checks go through `PermissionManager::can()` — two enforcement paths. `PermissionManager` already owns provider mode (`replace`/`combine`), active-provider state, config, and fallback. Collapsing to one path is what prevents fallback drift from recurring. |
| D8 | **`managed_by` identity is the composer package name** (`glueful/aegis`, `glueful/framework` for core, `app` for the host app). | Stable across class renames; pruning and ownership need a stable identifier, not a FQCN that moves. |

## 4. Architecture

```
  Extension A ─┐ permissions()/roles()
  Extension B ─┤ permissions()/roles()        ┌──────────────────────────┐
  Extension C ─┘ permissions()/roles()        │  Framework               │
                      │                        │                          │
                      ▼ (dedicated build pass) │  PermissionRegistry      │
            ┌───────────────────────┐         │  (in-memory catalog,     │
            │  PermissionRegistry    │◀────────┤   collision detection)   │
            └───────────┬───────────┘         └──────────────────────────┘
                        │
        ┌───────────────┴───────────────┐
        ▼ (provider present)             ▼ (no provider)
  PermissionCatalogSyncInterface    Registry-backed RoleVoter + existing
  ::syncCatalog()  → Aegis DB        Gate voters (declared roles feed the
        │                            Gate; reads registry + config)
        │                                 │
        └─────────────┬───────────────────┘
                      ▼
        PermissionManager::can()  ← single enforcement entry point
                      ▲
                      │
   #[RequiresPermission] / #[RequiresRole]  (middleware = thin adapter)
   controller / PermissionHelper checks     (same path)
```

Both the attribute middleware and programmatic checks funnel through
`PermissionManager::can()`. `PermissionManager` decides provider-vs-Gate
(per `provider_mode`); `Gate` remains the lower-level voter engine, not a
second public enforcement coordinator.

### 4.1 Framework components (new)

- **`Glueful\Permissions\Catalog\Permission`** — immutable builder DTO.
  - `Permission::define(string $slug): self`
  - `->label(string)`, `->description(string)`, `->category(string)`, `->resource(string $resourceType)`
  - `->toArray(): array` for serialization/sync.
- **`Glueful\Permissions\Catalog\Role`** — immutable builder DTO.
  - `Role::define(string $slug): self`
  - `->label(string)`, `->description(string)`, `->grants(array $permissionSlugs)`, `->level(int)`, `->parent(string $roleSlug)`
- **`Glueful\Permissions\Catalog\PermissionRegistry`** — aggregates declarations.
  - `register(Permission $perm, string $source): void`, `registerRole(Role $role, string $source): void`
  - `permissions(): Permission[]`, `roles(): Role[]`
  - `has(string $slug): bool`
  - Collision detection: registering a duplicate slug from a *different* source throws `DuplicatePermissionException` with both source extensions named.
  - Records the **declaring extension** for each entry (for introspection / drift).
- **`Glueful\Interfaces\Permission\PermissionCatalogSyncInterface`** — optional provider capability.
  - `syncCatalog(array $permissions, array $roles): SyncResult` — upsert by slug, idempotent. Returns counts (created/updated/unchanged) and a list of *stale* slugs.
  - **Stale = managed rows only.** A slug is stale only if it is present in the provider **with `managed_by IS NOT NULL`** (i.e. previously synced by some declarer) **and** absent from the current registry. Hand-created rows (`managed_by IS NULL`, e.g. created via the Aegis API/UI) are **never** reported stale. This requires a provider method beyond `getAvailablePermissions()` (which returns all rows as `slug => description`); add `getManagedCatalog(): array` (or equivalent) returning managed slugs + their `managed_by` owner.
  - Pruning of stale rows is the caller's explicit decision (`permissions:sync --prune`), never automatic.
- **`Glueful\Permissions\Voters\RegistryRoleVoter`** — Gate voter that maps the user's **already-known roles** to permissions using the **declared** role→permission definitions in `PermissionRegistry`. This is what makes declared roles enforce in the fallback (no-provider) path, where the config-only `RoleVoter` would otherwise never see them.
  - **Role source contract (important):** the catalog defines *what a role grants*; it does **not** assign users to roles. In no-provider mode there is no persistent user↔role store, so this voter only grants when the user's roles are present in the **request identity / `Context` / JWT claims / config** path (the same role sources `PermissionManager::can()`/`RequestUserContext` already use). If no role source yields any roles for the user, the voter **abstains** (returns null) — it never fabricates role membership. Persistent user↔role assignment remains a provider concern (e.g. Aegis `user_roles`).

### 4.2 Framework integration points (modified)

- **`Glueful\Extensions\ServiceProvider`** (base class): add two optional methods returning `[]` by default. Because the host app's `AppServiceProvider` extends this same base, **apps get the identical declaration hook** — no separate mechanism.
  ```php
  /** @return list<Permission> */
  public function permissions(): array { return []; }
  /** @return list<Role> */
  public function roles(): array { return []; }
  ```
- **Framework core permissions**: the dedicated catalog **build pass** (below) adds `PermissionStandards::CORE_PERMISSIONS` to the `PermissionRegistry` before/alongside provider declarations — not via `register()`/`boot()` (which D6 explicitly moves catalog work out of). Core permissions are tagged `managed_by = glueful/framework` and flow through the same catalog as everything else, rather than living only as constants + an Aegis seed migration.
- **Dedicated catalog build phase (not `register()`/`boot()`)**: a new `ExtensionManager` pass — e.g. `aggregatePermissionCatalog()` — runs after providers are registered, iterates each provider's `permissions()`/`roles()`, validates, and feeds the `PermissionRegistry`. **This pass does NOT wrap provider calls in `catch (\Throwable)`** — collision/validation errors propagate and fail startup (per D6). It is invoked explicitly by bootstrap, separate from the two existing swallowing loops. Register the registry as a shared service (e.g. `permission.registry`) in the DI container (alongside the existing `permission.manager` wiring in `CoreProvider`). Because declarations are pure/static, this pass is a candidate to run on the container compile/discovery path so failures are deterministic and cached (see Open Questions).
- **`GateAttributeMiddleware` → thin adapter**: it stops calling `Gate::decide()` directly. It collects `#[RequiresPermission]`/`#[RequiresRole]`, resolves the current user UUID, resource string, and `Context`, then calls `PermissionManager::can()` (per D7). See §4.5.
- **`PermissionManager`**: becomes the single enforcement coordinator and is registry-aware.
  - When a `PermissionProviderInterface` is active, `can()` behaves per `provider_mode` (`replace`/`combine`) — unchanged.
  - When **no** provider is active, `can()` resolves through the `Gate`, whose voter chain now includes `RegistryRoleVoter` so **declared** roles/permissions actually participate in the fallback decision (the existing config `RoleVoter`/`ScopeVoter` alone never see declarations).
  - The `Gate` factory in `CoreProvider` registers `RegistryRoleVoter` (fed from `permission.registry`) alongside the existing voters.

### 4.3 Aegis components (modified)

- **`AegisPermissionProvider`**: implement `PermissionCatalogSyncInterface::syncCatalog()`.
  - Upsert into `permissions` and `role_permissions` (and create roles in `roles`) by slug.
  - Mark synced rows as extension-managed: `is_system = false` plus a `managed_by` column/tag identifying the declaring extension (new column — see Migrations below).
  - Idempotent: re-running produces no changes when nothing changed.
  - Never auto-deletes; returns stale slugs (managed rows only) for the CLI to surface.
- **`AegisServiceProvider::boot()`**: does **not** sync. Sync is migration-like and CLI-only (`permissions:sync`); running it on every web/CLI boot would mutate permission tables on normal requests and races with the existing `tablesExist()` guard (`AegisServiceProvider.php:136-140`). An optional convenience flag `rbac.auto_sync_dev` (default **off**) may trigger sync **only** in a console context, **after** migrations have run, and **never** on web requests. Provider wiring (`setProvider`) is unchanged.

### 4.4 CLI (new)

Framework-level commands (work against whichever provider is active):

- `php glueful permissions:sync` — push the registry into the active provider via `syncCatalog()`. Prints created/updated/unchanged counts and a warning listing stale slugs.
- `php glueful permissions:diff` — read-only. Cross-checks:
  - **declared** (registry),
  - **enforced** (`#[RequiresPermission]` slugs scanned from routes/controllers),
  - **persisted — all** (provider's `getAvailablePermissions()`), and
  - **persisted — managed** (provider's `getManagedCatalog()`, i.e. `managed_by IS NOT NULL`).
  Reports:
  - *enforced-but-undeclared* (likely typo / missing declaration),
  - *declared-but-unenforced* (orphan),
  - *stale* — **managed** rows absent from the registry → safe to `--prune` (only these are pruned),
  - *unmanaged persisted* — rows present in the provider but `managed_by IS NULL` (hand-created via API/UI): listed separately as informational and **never** treated as stale or pruned.
- `php glueful permissions:list` — print the declared catalog grouped by extension/category.

### 4.5 Enforcement path (attribute middleware → PermissionManager)

`GateAttributeMiddleware` becomes a thin adapter and is the only behavioral change to the live enforcement path:

1. Collect `#[RequiresPermission]` and `#[RequiresRole]` from handler metadata (as today).
2. Resolve the current **user UUID** from the request (as today).
3. Build the **`Context`** (tenant, route params, JWT claims) — as today.
4. Determine the **resource string** (see resource convention below).
5. Call `PermissionManager::can($userUuid, $permission, $resource, $contextArray)` per required item; deny (403) on the first non-grant.

`PermissionManager` — not the middleware — chooses provider vs. Gate fallback. `Gate` stays the lower-level voter engine.

**Resource convention (do not silently change semantics).** `RequiresPermission` has `resource: ?string` (`RequiresPermission.php`), and the *current* middleware passes a `null` resource object into `Gate::decide()`. `PermissionManager::can()` requires a non-null `string $resource` (`PermissionManager.php:118`). To avoid divergence, when the attribute omits `resource` the adapter passes the literal **`'system'`**, matching the established default used throughout `PermissionHelper` (`:33,:135,:153,:176`) and `RoleHelper` (`:51`). When the attribute supplies `resource`, that value is passed through verbatim.

**Role attributes.** `#[RequiresRole('editor')]` continues to use the existing `role.{name}` permission convention (current middleware maps non-dotted names to `role.{name}`), routed through the same `can()` call for consistency — preserving today's behavior. A dedicated `PermissionManager::hasRole()` path is deferred to Phase 3 and only added if tests show the `role.{name}` mapping is lossy.

### 4.6 Implementing an alternative RBAC provider (provider-agnostic contract)

Aegis is the **reference** implementation, not a dependency. Everything in §4.1–§4.5 is framework-owned; nothing in the framework imports Aegis. Any RBAC extension (e.g. a hypothetical "RbacPro") gets the identical benefits — declarations from *every* enabled extension flow to it — by implementing framework interfaces only. Proof of the seam: the framework's own tests drive these interfaces with generic fakes/mocks, never Aegis.

**The two contracts an RBAC extension implements** (both live in `src/Interfaces/Permission/`, framework-owned):

| Capability | Interface | Required? | What it provides |
|---|---|---|---|
| Enforcement backend | `PermissionProviderInterface` | Required to be a provider | Becomes the active provider; `PermissionManager::can()` and therefore `#[RequiresPermission]`/`#[RequiresRole]` resolve through it (per `provider_mode`). Must satisfy `PermissionStandards::CORE_PERMISSIONS`. |
| Catalog persistence | `PermissionCatalogSyncInterface` | Optional | Receives the aggregated declared catalog via `permissions:sync` (`syncCatalog()`), and reports its synced rows for stale detection (`getManagedCatalog()`). A provider that cannot persist a catalog simply does not implement it; `permissions:sync` reports that cleanly. |

Registration is identical to Aegis: the extension's `ServiceProvider` resolves `permission.manager` and calls `setProvider($provider, $config)` (typically in `boot()`).

**Two constraints to be explicit about:**

1. **Single active provider (by design).** `PermissionManager` holds exactly one active provider. An alternative RBAC extension is therefore a **replacement** for Aegis, not a co-tenant — you run one RBAC backend at a time, not two simultaneously. This is the existing single-active-provider model, unchanged.
2. **The DTO is a shared vocabulary, not a schema mandate.** `Permission::toArray()` / `Role::toArray()` emit a standard field set (`slug`, `name`, `description`, `category`, `resource_type`, `grants`, `level`, `parent`, `managed_by`). These are general RBAC metadata. A provider with a different storage shape maps or ignores fields inside *its own* `syncCatalog()` implementation — the framework never assumes a particular table layout. `managed_by` (the declarer's composer package name) is the one field a sync provider must persist if it wants stale detection to work, since that is how synced rows are distinguished from hand-created ones.

**What a non-provider feature extension gets, regardless of which RBAC backend (or none) is installed:** it declares permissions/roles via `permissions()`/`roles()`, they enter the framework `PermissionRegistry`, and enforcement works through `PermissionManager::can()` → Gate `RegistryRoleVoter` even with no provider at all. Installing Aegis/RbacPro adds persistence and management on top; it is never a prerequisite for declaring or enforcing.

## 5. Declaration Example (target DX)

```php
use Glueful\Permissions\Catalog\{Permission, Role};

class BlogServiceProvider extends ServiceProvider
{
    public function permissions(): array
    {
        return [
            Permission::define('blog.posts.publish')
                ->label('Publish Posts')
                ->category('blog')
                ->resource('posts')
                ->description('Publish and unpublish posts'),
            Permission::define('blog.posts.delete')
                ->label('Delete Posts')->category('blog'),
        ];
    }

    public function roles(): array
    {
        return [
            Role::define('blog.editor')
                ->label('Blog Editor')
                ->grants(['blog.posts.publish', 'blog.posts.delete'])
                ->level(40),
        ];
    }
}
```

Enforcement is unchanged:

```php
#[RequiresPermission(name: 'blog.posts.publish', resource: 'posts')]
public function publish(int $id): Response { /* ... */ }
```

No migration, no `boot()`-time Aegis call, no `if ($app->has(...))` guard. Running `php glueful permissions:sync` persists the catalog into Aegis. With Aegis absent, the declaration is still valid and enforcement falls back through `PermissionManager::can()` to the Gate — where `RegistryRoleVoter` can honor a user's existing `blog.editor` role and its declared grants.

## 6. Conventions & Rules

- **Slug namespacing:** extensions SHOULD prefix slugs with an extension key (`blog.posts.publish`). The registry treats cross-source slug collisions as fatal.
- **Idempotency:** `syncCatalog()` must be safe to run repeatedly.
- **No destructive auto-sync:** stale permissions are reported, never silently deleted. Pruning requires explicit `permissions:sync --prune`.
- **Graceful degradation:** declaring is always safe; only persistence needs a provider.

## 7. Data Model Changes (Aegis)

- Add `managed_by` (nullable string) to the `permissions` and `roles` tables, storing the **composer package name** of the declarer (`glueful/aegis`, `glueful/framework`, or `app`) per D8. Hand-created (API/UI) rows leave it `null` — this nullability is what distinguishes synced rows from hand-created ones for stale detection. New migration in `extensions/aegis/migrations/`.
- The declarer's package name is resolved during the catalog build pass (from the provider's composer manifest) and carried on each `Permission`/`Role` registry entry, then written through `syncCatalog()`.
- No changes to `user_roles`, `role_permissions`, `user_permissions`, `permission_audit` semantics.

## 8. Error Handling

| Condition | Behavior |
|-----------|----------|
| Duplicate slug across two declarers | `DuplicatePermissionException` thrown in the **dedicated catalog build pass** (per D6), naming both sources. Because this pass is outside the `register()`/`boot()` `catch (\Throwable)` loops, it is **not swallowed** — startup fails fast. |
| `permissions()` returns malformed array entry | Validation error in the build pass naming the offending declarer + entry (also not swallowed). |
| Role grants a permission slug not present in the aggregated registry | **Fatal catalog error** in the build pass (same class as a collision). Aegis `role_permissions.permission_uuid` is an FK to `permissions.uuid` (`002_CreatePermissionsTables.php:53-77`), so a dangling grant cannot be persisted without inventing a placeholder row — "warn and persist anyway" is rejected by design. (If a role must reference a permission owned by a currently-disabled extension, the operator enables that extension or removes the grant.) |
| Sync called with no active provider | Command exits with a clear message ("no persistent permission provider installed; declarations remain in-registry only"). Not an error. |
| Provider does not implement `PermissionCatalogSyncInterface` | `permissions:sync` reports the provider cannot persist a catalog and exits cleanly. |

## 9. Testing Strategy

- **Framework unit:** `Permission`/`Role` builders; `PermissionRegistry` aggregation + collision detection + source tracking; dangling-grant rejection in the build pass.
- **Build-pass propagation (regression):** a provider that declares a colliding/dangling/malformed catalog causes the dedicated build pass to **throw** and fail startup — explicitly asserting the error is *not* swallowed the way `register()`/`boot()` errors are.
- **Enforcement routing (regression — the D7 change):**
  - **Aegis active:** attribute enforcement (`#[RequiresPermission]`) calls the **provider** path via `PermissionManager::can()` (assert provider consulted, not just Gate).
  - **No provider, roles present:** attribute enforcement falls back through `PermissionManager` to the **Gate**, and `RegistryRoleVoter` lets a declared role (sourced from request identity/`Context`/JWT/config) grant access.
  - **No provider, no role source:** `RegistryRoleVoter` **abstains** (no fabricated membership) → request is denied. Confirms the catalog defines grants, not assignments.
  - **`provider_mode=combine`:** attribute enforcement preserves combine semantics (provider + Gate), identical to programmatic `can()`.
  - **Resource default:** an attribute with no `resource` results in `can(..., 'system', ...)`; an attribute with `resource` passes it through verbatim.
  - **Role attributes:** `#[RequiresRole('editor')]` maps to `role.editor` and decides consistently with a direct `can()` call.
- **Framework integration:** booting an app with mock extensions that declare permissions → registry populated; `permissions:diff` classification (undeclared/orphan/stale) against a fake provider.
- **Aegis unit/integration:** `syncCatalog()` idempotency (run twice → second is all-unchanged); upsert correctness; `managed_by` package-name tagging; **stale detection ignores `managed_by IS NULL` (hand-created) rows**; `--prune` behavior. Use the lightweight SQLite `Connection` harness per existing Aegis tests.
- **Phase 3:** `actingWithPermissions([...])` test helper.

## 10. Phasing

- **Phase 1 — Foundation** (includes the D7 enforcement-routing contract — built and tested heavily up front, not deferred)
  - Framework: `Permission`/`Role` DTOs, `PermissionRegistry`, `ServiceProvider::permissions()/roles()`, dedicated catalog **build pass** in `ExtensionManager` (non-swallowed), registry wiring in `CoreProvider`, `RegistryRoleVoter`, `GateAttributeMiddleware` rewritten as a thin adapter over `PermissionManager::can()`, `PermissionManager` registry-aware fallback, `PermissionCatalogSyncInterface`.
  - Aegis: implement `syncCatalog()` (+ `getManagedCatalog()`), `managed_by` migration, `permissions:sync` command. **No** boot-time sync.
- **Phase 2 — Visibility**
  - `permissions:diff` (+ attribute scanning as validator), `permissions:list`, introspection API on the registry.
- **Phase 3 — Ergonomics**
  - Testing helpers (`actingWithPermissions`), policy/voter registration sugar for extensions.

## 11. Open Questions

- **Catalog build location (resolved → revisit only if needed):** the build pass runs as a dedicated, non-swallowed phase after registration; preferred placement is the container compile/discovery path so failures are deterministic and the catalog is cached with the compiled container. If declarations ever need runtime data, fall back to a post-register pass — still outside the `catch (\Throwable)` loops.
- **`managed_by` identity (resolved):** composer **package name** (D8), not provider FQCN — stable across renames for pruning/ownership.
- Should attribute scanning (Phase 2) reuse the router's existing reflection cache to avoid a separate scan pass? (Lean: yes.)
- Should `permissions:sync` run automatically as part of `migrate:run`, or stay a separate command? (Lean: separate, but document running both.)
- Do roles belong in the same registry as permissions, or a sibling `RoleRegistry`? (Current design: one registry, two collections — revisit if it grows.)

## 12. Affected Files (indicative, not exhaustive)

**Framework (`glueful/framework`)**
- `src/Permissions/Catalog/Permission.php` (new)
- `src/Permissions/Catalog/Role.php` (new)
- `src/Permissions/Catalog/PermissionRegistry.php` (new)
- `src/Permissions/Catalog/DuplicatePermissionException.php` (new)
- `src/Permissions/Voters/RegistryRoleVoter.php` (new)
- `src/Interfaces/Permission/PermissionCatalogSyncInterface.php` (new)
- `src/Extensions/ServiceProvider.php` (add `permissions()`/`roles()`)
- `src/Extensions/ExtensionManager.php` (new non-swallowed `aggregatePermissionCatalog()` pass)
- `src/Container/Providers/CoreProvider.php` (register `permission.registry`; add `RegistryRoleVoter` to Gate factory)
- `src/Permissions/PermissionManager.php` (registry-aware; single enforcement coordinator)
- `src/Permissions/Middleware/GateAttributeMiddleware.php` (rewrite as thin adapter over `PermissionManager::can()`; `'system'` resource default)
- `src/Console/Commands/Permissions/*` (new: `sync`, `diff`, `list`)

**Aegis (`extensions/aegis`)**
- `src/AegisPermissionProvider.php` (implement `syncCatalog()` + `getManagedCatalog()`)
- `src/Services/AegisServiceProvider.php` (no boot-time sync; optional console-only `rbac.auto_sync_dev`)
- `migrations/00X_AddManagedByToPermissions.php` (new — `managed_by` on `permissions` and `roles`)
