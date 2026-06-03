# Framework Permissions Catalog — Plan Index

A framework-level declarative permission catalog that any service provider (framework core,
host app, or extension) populates, that enforces uniformly through `PermissionManager::can()`,
and that an RBAC provider (Aegis, or any alternative) can persist. Aegis is the reference
implementation, not a dependency.

**Spec (source of truth):** [`../../specs/2026-06-03-extension-permissions-dx-design.md`](../../specs/2026-06-03-extension-permissions-dx-design.md)

## Phases

Each phase is an independent, working/testable slice. They depend on Phase 1; Phase 2 and
Phase 3 are independent of each other.

| Phase | Plan | Scope |
|-------|------|-------|
| 1 — Foundation | [`phase1.md`](phase1.md) | `Permission`/`Role` DTOs, `PermissionRegistry`, `ServiceProvider::permissions()/roles()`, fail-fast catalog build pass, `RegistryRoleVoter`, enforcement routed through `PermissionManager::can()` (shared `RoleKey`), `PermissionCatalogSyncInterface`, `permissions:sync`; Aegis `managed_by` + `syncCatalog`/`getManagedCatalog`. |
| 2 — Visibility | [`phase2.md`](phase2.md) | `PermissionAttributeScanner`, registry introspection, `permissions:list` and `permissions:diff` (permissions **and** roles), `--prune` via opt-in `CatalogPruneInterface`/`RoleCatalogSyncInterface`; Aegis prune + managed-role methods. |
| 3 — Ergonomics | [`phase3.md`](phase3.md) | `voters()`/`policies()` provider hooks + bootstrap registration onto `Gate`/`PolicyRegistry`, `InMemoryPermissionProvider` test double, `actingWithPermissions()`/`actingWithRoles()` test helpers. |

## Cross-repo scope

- **`glueful/framework`** — owns the contract and registry (Phases 1–3, framework tasks).
- **`extensions/aegis`** — the first persistence consumer (Phase 1 sync, Phase 2 prune).

## Execution

Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans`
to work each plan task-by-task. Start with `phase1.md`.
