# Users Extension Extraction — Plan Index

Extract the concrete user store out of framework core into a first-party
`glueful/users` extension, keeping the security spine (auth middleware,
token/session mechanics, provider contracts, fail-closed defaults) in core.

**Spec (source of truth):** [`../../specs/2026-06-04-users-extension-extraction-design.md`](../../specs/2026-06-04-users-extension-extraction-design.md)

## Sequencing risk (read first)

The phases are **strictly ordered** and must be executed in sequence:

```
Phase 1  migration runner       ← MUST land first
Phase 2  identity contracts      ← depends on nothing risky; safe once P1 is green
Phase 3  transitional default    ← tiny; folds onto P2
Phase 4  extraction (move out)   ← depends on P1 ordering + P2 contracts
Phase 5  skeleton + Aegis        ← depends on P4
```

**Why this order:** Phase 4 (moving the users schema into an extension) is only
safe once the migration runner can guarantee ordering and package-scoped
tracking (Phase 1), and once the identity contract exists for the moved store to
implement (Phase 2). Doing the migration-runner change first keeps every later
phase from being built on shaky migration behavior. Do **not** start Phase 4
until Phases 1–2 are merged and green.

## Phases

Each phase is an independent, working/testable slice that builds on the prior.

| Phase | Plan | Scope |
|-------|------|-------|
| 1 — Migration runner | [`phase1-migration-ordering.md`](phase1-migration-ordering.md) | `MigrationPriority` tiers; `loadMigrationsFrom($dir, $priority, $source)`; `(priority, basename)` ordering; package-scoped applied tracking (`source` column); `app` source for skeleton, composer name for packages. |
| 2 — Identity contracts | [`phase2-identity-contracts.md`](phase2-identity-contracts.md) | Canonical `final` immutable `UserIdentity` (claims bag + typed `roles()`/`scopes()` + `with*()`); `UserProviderInterface`; `IdentityClaimsProviderInterface`; null/guest provider; claims-composition fold + status gate; retire `AuthenticatedUser`; `RequestUserContext` onto `UserIdentity`. |
| 3 — Transitional default | [`phase3-transitional-default.md`](phase3-transitional-default.md) | Core temporarily binds existing `UserRepository` as the default `UserProvider`; re-point `AuthenticationService`/`TokenManager` credential + profile lookups at the contract. Keeps everything green before the move. |
| 4 — Extraction | [`phase4-extraction.md`](phase4-extraction.md) | Create `glueful/users`: move `User` model, `UserRepository` (as `UserProviderInterface` impl), credential/registration/provisioning, `ResetPasswordCommand`, account-lifecycle routes/controller, canonical schema migrations (priority `IDENTITY`). Core default flips to null/guest. Drop `User` from `SecureSerializer`. |
| 5 — Skeleton + Aegis | [`phase5-skeleton-and-aegis.md`](phase5-skeleton-and-aegis.md) | Skeleton: drop user/auth migrations, require + enable `glueful/users`, drop cross-package FKs (`blobs.created_by` → indexed uuid), normalize `api_keys.user_uuid`. Aegis: `IdentityClaimsProvider` impl (priority `DEPENDENT`), `user_roles.user_uuid` non-FK. End-to-end smoke. |

## Cross-repo scope

- **`glueful/framework`** — Phases 1–3 (migration runner, contracts, transitional default) + the core half of Phase 4.
- **new `glueful/users`** — the extension created in Phase 4.
- **`api-skeleton`** + **`extensions/aegis`** — Phase 5.

Aegis is developed against the local framework via the existing composer path
symlink (`extensions/aegis/vendor/glueful/framework` → `../../../../framework`).

## Referential-integrity invariant (applies across all phases)

No hard cross-package FK constraints to `users`/principals. Intra-package FKs
only; cross-package actor references are indexed UUIDs validated in the service
layer. See spec §2.

## Execution

Per phase, via `superpowers:subagent-driven-development` (recommended) or
`superpowers:executing-plans`, committed directly on `dev` (no feature branch).
Run the framework + Aegis suites green at each phase boundary before advancing.
