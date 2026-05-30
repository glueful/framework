# Extension System Re-Architecture — Design Note

**Status:** Draft 2026-05-30.
**Date:** 2026-05-30
**Scope:** Framework subsystem re-architecture (`src/Extensions/`), its CLI, the `config/extensions.php` contract, and the migration of the 7 official extensions + api-skeleton.
**Compatibility:** **Clean break, no backward compatibility.** Glueful is pre-1.0 / fast-releasing; all 7 official extensions and the api-skeleton config are in-house and will be migrated. Ships as a versioned breaking change with an upgrade note.

## Goal

Replace the current extension loading model — **four discovery sources** (`enabled`, `dev_only`, local-folder scan, composer scan), **six config keys** (`only`, `enabled`, `dev_only`, `disabled`, `local_path`, `scan_composer`), and a CLI that edits only one of them — with **one explicit, environment-identical resolution model and a coherent control surface**.

This single change fixes three coupled problems in today's system:

1. **Incoherent control surface.** `extensions:enable|disable` edit the `enabled` array via regex, but the framework actually loads extensions from four sources and filters via a separate `disabled` blacklist. So `extensions:disable <x>` is a silent no-op for any extension loaded by the local-folder scan or composer — the command doesn't drive the framework's real levers.
2. **Dev/prod parity hazard.** `local_path` defaults to `'extensions'` in dev and `null` in production, so dropping a folder in `extensions/` auto-loads it in dev but not in prod. `ProviderLocator`'s own header comment says it exists to "prevent mismatches where config-enabled extensions work in dev but break in prod" — yet the local-scan path reintroduces exactly that class of bug.
3. **Opaque mental model.** The effective set of loaded extensions is the product of five config keys + a directory listing + installed packages, overridden by `only`. Answering "why is X loaded?" requires tracing all of it (the existence of an `extensions:why` command is evidence of the opacity).

### Non-goals

- **Auto-enable on install** (Laravel-style "install = active"). Explicitly rejected: an installed package does nothing until enabled. Explicit opt-in matches the framework's security-conscious identity (the deliberate intent behind today's `only` mode) and is the behavior we are optimizing for.
- **Changing the provider authoring contract.** `Glueful\Extensions\ServiceProvider`, the static `services()` array, and composer `extra.glueful.provider` are good and are preserved. "Clean break" applies to discovery/resolution/config/CLI, not to gratuitous churn of a working provider API.
- **An auto-migrate CLI command.** Only ~2 in-house apps consume extensions; a documented upgrade note + the new config template suffice (YAGNI).

## The model: one source, one gate, one compiled output

**Discovery (single source).** `PackageManifest` already reads `vendor/composer/installed.php|installed.json` for packages declaring `extra.glueful.provider`, yielding candidates `package → {provider FQCN, extra.glueful meta}` (where meta includes `requires.glueful` and `requires.extensions`). This becomes the **only** way an extension is discovered. Local development extensions are consumed via **Composer path repositories**, so `extensions/aegis` appears in `installed.php` exactly like a published `glueful/aegis`. The local-folder scan and `scan_composer` toggle are deleted.

**The gate — one list.** Discovery yields *candidates*; a single explicit allow-list, `enabled`, decides what actually loads. The six-key model collapses to **one key**:

```php
// config/extensions.php
return [
    // The single activation allow-list — providers that load, in declared order.
    // Empty = nothing loads (explicit opt-in). This IS "only these load": an installed
    // package does nothing until its provider appears here. To kill everything fast,
    // set this to []. To run a single extension during an incident, list only that one.
    'enabled' => [
        'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
    ],
];
```

**Provider entries are string FQCNs, not `::class`.** Functionally identical — `require`-ing the config resolves `::class` to a string at load time anyway, so the resolver always sees strings. Strings keep the source and the loaded value the same, and let `ExtensionStateWriter` add/remove/sort entries with a simple, safe edit (no PHP-parser needed). A typo'd or non-existent FQCN is caught by candidate validation (below), not by a fatal autoload error.

No second activation list (`lockdown`/`only` are gone — `enabled` already is the allow-list). No `disabled` (remove from `enabled`). No `dev_only`, and **no inline `env()` conditionals in the list** — `enabled` is a flat array of plain string FQCNs, nothing else. Per-environment differences are expressed by what you enable in each environment's deployed config (e.g. a staging config enabling a debug extension), not by conditional logic inside the array. This keeps `enabled` trivially parseable, sortable, and safely editable by `ExtensionStateWriter` (which **refuses to edit** a non-trivial `enabled` — conditionals, function calls, or non-string entries — and tells you to edit it by hand). No env-gated discovery.

**Resolution rule** (the entire decision logic, a pure function):

```
candidates  = composer-discovered providers (with meta)
selected    = [p for p in enabled if p ∈ candidates]     # preserve declared order
validate(selected, candidates) → errors[]                # see "Validation" below
ordered     = topological_sort(selected, requires.extensions)
return { providers: ordered, errors }
```

The resolver is pure and **never throws or exits** — it returns the ordered providers plus a list of `errors`. *Callers* decide severity (strict at build, lenient at runtime — see "Cache behavior"). An `enabled` entry absent from candidates is omitted from `providers` and recorded as an error.

### Validation (what `errors[]` captures)

- **Missing provider** — an `enabled` FQCN is not a discovered candidate (package not installed, or typo).
- **Missing dependency** — an enabled extension declares `requires.extensions: [X]` but `X` is not also enabled. **Dependencies are never auto-loaded** (Option A — security-consistent with explicit opt-in); they must be installed *and* explicitly enabled.
- **Framework version mismatch** — an enabled extension's `requires.glueful` constraint is not satisfied by the running framework version.
- **Dependency cycle** — `requires.extensions` forms a cycle (topological sort fails).

`requires.extensions` is used **only** for validation and ordering — it never causes a non-enabled extension to load. Therefore every loaded extension is directly enabled (there is no "pulled as a dependency" state).

**Compiled output (prod).** Resolution compiles to the existing `bootstrap/cache/extensions.php`; production boot reads it directly. Deterministic and fast — the determinism benefit of a lockfile, but it is a regenerated cache (`extensions:cache` / deploy), not a hand-managed file.

### Why this shape

- **"Why is X loaded?"** → it is in `enabled`. One list, one place.
- **Parity by construction** → the same `installed.php` + the same `config/extensions.php` resolve identically in dev and prod; the dev-only scan that caused drift is gone.
- **Explicit opt-in** → matches the framework's security identity; installing a package activates nothing until enabled, and dependencies must be enabled too.

### Deliberate trade-off

Installing a package no longer auto-activates it — you run `extensions:enable <name>` (or edit the list) once, and you must enable an extension's dependencies too. This is the accepted cost of explicit control.

## Components

Single-responsibility units with well-defined boundaries:

| Unit | Responsibility | Depends on | Status |
|---|---|---|---|
| `PackageManifest` | **Discovery** — read composer `installed.php\|json`; return candidates `package → {provider FQCN, meta (incl. requires)}` | filesystem | keep (already does this) |
| `ExtensionResolver` *(new)* | **Resolution** — pure function `(candidates, enabled[]) → {providers: ordered[], errors: []}`. Selects + validates (missing provider/dependency, version, cycle) + topologically orders. No I/O, no `env()`, no scanning; never throws | nothing (pure) | new — replaces `ProviderLocator`'s tangle |
| `ExtensionStateWriter` *(new)* | **Mutation** — the one place that edits `config/extensions.php`'s `enabled` array (add/remove a string FQCN), guarded, with `--dry-run`/`--backup` | filesystem | new — both CLI mutating commands route through it |
| `ExtensionManager` (slimmed) | **Lifecycle** — call resolver, register `services()` into the container, run `register()`/`boot()`, read/write the compiled cache, expose metadata | PackageManifest, Resolver, container | keep + simplify |
| compiled cache | `bootstrap/cache/extensions.php` — resolver output; read directly at prod boot | — | keep |

**Deleted:** `ProviderLocator` (its decision logic → `ExtensionResolver`; its filesystem scan + runtime PSR-4 registration → gone, since Composer now owns autoloading for every path-repo'd and installed package).

`ExtensionResolver` being pure is the central win. Today `ProviderLocator` interleaves 4 sources + `dev_only` + `disabled` filter + `only` override + filesystem scanning + PSR-4 registration — env-dependent and hard to test. The resolver is the whole select-validate-order decision in one fast, exhaustively testable function with no environment input, returning data (`providers` + `errors`) rather than acting on it.

### Cache behavior (strict build, lenient runtime)

The same resolver feeds two callers with deliberately different severity:

- **`extensions:cache` (build/CI/deploy) — strict.** Resolve, and if `errors[]` is non-empty (missing provider, unsatisfied dependency, framework-version mismatch, cycle) **fail the command with a clear message** and do not write the cache. This is what stops a deploy from silently shipping without a required extension.
- **Runtime boot — lenient.** Load the resolved `providers[]` and **log** any `errors[]`; never white-screen. A missing extension is skipped, not fatal.
- **Production boot** reads `bootstrap/cache/extensions.php` directly. If the cache is **missing, boot fails fast** with a "run `php glueful extensions:cache`" message — it does **not** silently resolve live (which would mask a broken deploy). Deploy is responsible for running `extensions:cache`.
- **Development boot** resolves live when the cache is absent (convenience). Cache **staleness** is handled by deploy always regenerating it — there is no runtime staleness detection (deliberately, to avoid that complexity).

## CLI control surface

With one list and one discovery source, every command governs every extension — eliminating the "disable does nothing" mismatch.

| Command | Behavior |
|---|---|
| `extensions:list` | Every candidate with **state** (`enabled ✓` / `available ○` / `enabled-but-missing ⚠`), version, source. Answers "why loaded?" at a glance — **folds in `extensions:why`** (dropped as a standalone command). |
| `extensions:enable <name\|slug\|class>` | Resolve to FQCN via candidates → `ExtensionStateWriter` adds to `enabled` → regenerate cache (strict; fails on validation errors). Works for any composer-discovered extension. |
| `extensions:disable <name\|slug\|class>` | `ExtensionStateWriter` **removes** from `enabled` → regenerate cache. |
| `extensions:info <name>` | Meta, version, `requires`, current state. |
| `extensions:cache` / `extensions:clear` | (re)build / drop the compiled manifest. **`cache` is strict** — fails on any resolver error (missing provider/dependency, version mismatch, cycle). keep |
| `extensions:diagnose` | Health: provider class loadable, composer present, compiled cache present/readable in production, and all resolver `errors[]` (enabled-but-missing, unsatisfied dependency, version mismatch, cycle). keep |

Both `enable`/`disable` route through the single `ExtensionStateWriter` (no divergent regex paths) and remain **dev-only** — production changes are made by editing `config/extensions.php` and deploying, matching the explicit/reviewable philosophy. `extensions:why` is removed; the `dev_only`/`lockdown`/lockfile-noise concepts are gone.

## App service providers (separate path)

Today `ProviderLocator::all()` resolves **both** app service providers (`config/serviceproviders.php` — `AppServiceProvider`, `EventServiceProvider`) *and* extensions, sharing the `only/enabled/dev_only/disabled` keys. The two are different: app providers are **app-local classes, not composer packages** — no `extra.glueful` meta, no `requires`, nothing to "discover." So they do **not** flow through `ExtensionResolver` (which is about composer candidates).

Decision: **a separate, trivial path, simplified to match.** `config/serviceproviders.php` collapses to a single `enabled` list of string FQCNs, **always loaded in declared order** (no discovery, no candidate validation — they're the app's own code). `ExtensionManager` boots the final set as **`[app providers from serviceproviders.enabled]` ++ `[resolved extension providers]`**, registering `services()` and running `register()`/`boot()` for both. Net: both config files end up single-key (`enabled`) and consistent, but only extensions are discovered/validated.

## `create:extension` — closes the distribution gap

Today the scaffolder emits a folder that only the dev-scan loads, with no clear path to a publishable package. New behavior: scaffold a **real Composer package** —

- `composer.json` with `type: glueful-extension`, `extra.glueful.provider`, and a PSR-4 mapping
- a `ServiceProvider` stub (using `../routes/routes.php` and `../database/migrations` paths consistent with the scaffold layout, plus a `registerMeta()` call)
- `routes/` and `migrations/` directories

— then **register a Composer path repository** in the app's `composer.json` pointing at it, and **print the `composer require glueful/<slug>:@dev` + `extensions:enable <slug>` commands** for the developer to run (the scaffolder does not shell out to Composer itself — running Composer from inside the CLI is fragile). Once required, the scaffolded extension is discovered through the same composer path as a published one; "publish it" later is just a Packagist release. This is what makes the composer-only model ergonomic and keeps dev ≡ prod.

## Migration (clean break)

**The 7 official extensions — zero code change.** All seven already declare `type: glueful-extension` + `extra.glueful.provider` (verified), and the `ServiceProvider`/`services()` contract is preserved, so they are discovered through composer unchanged.

**Framework:**
1. Add `ExtensionResolver` (pure) + `ExtensionStateWriter`.
2. Slim `ExtensionManager` (drop runtime PSR-4 registration and local-scan orchestration).
3. Delete `ProviderLocator`, the local-folder scan, and the existing config keys `local_path` / `scan_composer` / `dev_only` / `disabled` / `only` (leaving only `enabled`).
4. Rewrite `extensions:enable|disable` through `ExtensionStateWriter`; make `extensions:cache` strict; fold `why` into `list`; keep `info`/`clear`/`diagnose`.
5. Update `create:extension` → scaffold composer package + path repo + **printed** `composer require` instructions (does not run Composer).
6. New `config/extensions.php` template: a single `enabled` array of string FQCNs.

**Apps (api-skeleton + consumers):**
1. Add Composer path repositories pointing at local extension dirs (dev).
2. `composer require glueful/<ext>` for each desired extension.
3. Rewrite `config/extensions.php` to the single-key model — key mapping below.
4. `php glueful extensions:cache`.

**Config key migration map (for the upgrade note):**

| Old key | New equivalent |
|---|---|
| `enabled` | `enabled` (now the single gate; entries become string FQCNs) |
| `only` | merge into `enabled` — it was already an exclusive allow-list, which `enabled` now is |
| `lockdown` *(proposed, never shipped)* | not introduced — `enabled` is the single allow-list |
| `dev_only` | enable the extension in the relevant environment's deployed config — **not** via an inline `env()` conditional in the list (the writer requires a flat string list) |
| `disabled` | omit the entry from `enabled` |
| `local_path` | removed — use a Composer path repository instead |
| `scan_composer` | removed — composer discovery is always on (it is now the sole source) |

## Testing strategy

Resolution correctness moves into a pure, exhaustively-testable unit; the parity guarantee is proven structurally.

- **`ExtensionResolver` — pure unit tests (table-driven), `{providers, errors}`:** empty `enabled` → `{[], []}`; `enabled` order preserved when no deps; `enabled` entry not in candidates → omitted from `providers` + a *missing-provider* error; enabled extension requires another that isn't enabled → *missing-dependency* error; `requires.glueful` unsatisfied → *version-mismatch* error; dependency cycle → *cycle* error; valid deps → `providers` topologically ordered (dependency before dependent). Resolver never throws.
- **Parity test:** the resolver takes no environment input. A test flips `APP_ENV` and asserts identical output for the same `(candidates, enabled)` — the structural proof that dev/prod cannot diverge.
- **Strict-vs-lenient test:** the same erroring resolve → `extensions:cache` **fails** (non-zero, no cache written); a runtime-boot path **loads `providers[]` and logs `errors[]`** without throwing.
- **`PackageManifest` — unit tests** with fixture `installed.php` *and* `installed.json` (both shapes): correct candidate extraction incl. `requires` meta; package missing `extra.glueful` → not a candidate.
- **`ExtensionStateWriter` — unit tests:** add (idempotent) / remove a string FQCN from `enabled`; entries stay sorted/stable; `--dry-run` writes nothing; `--backup` creates `.bak`; malformed config → guarded failure, no corruption.
- **`ExtensionManager` — integration** (SQLite `Connection` + `ApplicationContext` harness): a fixture extension package resolves → `services()` registered in the container → `boot()` runs; cache written then re-read yields the same set; **prod-mode boot with missing cache → fails fast**; dev-mode with missing cache → resolves live.
- **CLI — integration:** `enable` adds + recompiles + `list` shows `enabled ✓`; `disable` removes; `enable <unknown>` → friendly error; `enable` of an extension with a missing dependency → `cache` fails with the dependency error; `list` states correct (`enabled ✓` / `available ○` / `enabled-but-missing ⚠`).
- **`create:extension` — integration:** scaffold yields a valid `composer.json` (type + provider + PSR-4), path repo registered, `ServiceProvider` stub loadable.

Pure units (resolver, manifest, writer) need no DB/boot; lifecycle/CLI tests use the framework's SQLite `Connection` / path-constructible `ApplicationContext` harness.

## Risks & mitigations

- **Apps must add path repositories + `composer require` each extension.** Without this, an extension that loaded via the old dev-scan stops loading. Mitigation: the upgrade note documents the path-repo + require steps; `create:extension` does this automatically for new ones; `extensions:diagnose` flags `enabled-but-missing`.
- **"Installed but inactive" surprise.** Operators used to "drop a folder and it works" must now enable explicitly. Mitigation: `extensions:list` shows `available ○` for installed-but-not-enabled candidates, making the next step obvious.
- **First boot after migration on a misconfigured app.** An `enabled` entry whose package wasn't required → skipped with a diagnostic (never fatal), surfaced by `extensions:diagnose`.
