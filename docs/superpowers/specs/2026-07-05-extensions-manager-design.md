# Extensions Manager — Design Spec

**Date:** 2026-07-05
**Status:** Draft for review
**Repo:** `glueful/framework` (API + logic) · `glueful/lemma` / Thallo admin (UI)

## 1. Goal

Let an operator browse, install, and enable/disable Glueful extensions **from the
admin UI** — no server terminal. Installing an extension currently means SSHing
to the box and running `composer require glueful/<pkg>` by hand, then
`php glueful extensions:enable <pkg>`. This feature brings that workflow into the
browser, the way WordPress/Ghost install add-ons.

## 2. Scope

**In:**
- Framework-core HTTP API under `/extensions/*` (reusable by any Glueful app).
- Browse installable extensions from **Packagist** (vendor `glueful/`,
  type `glueful-extension`).
- Install one via `composer require` run as a **detached background process**,
  with pollable status.
- List installed extensions; **enable/disable** them.
- Thallo admin page (`/developers/extensions`) as the first consumer.

**Out (v1):**
- Remote/curated catalog hosted on thallo.dev (Packagist is the source; loader is
  written so a remote catalog can slot in later).
- Uninstall / `composer remove` from the UI. (Disable is enough operationally;
  removal stays a CLI/terminal action.)
- Version pinning UI, update-in-place, dependency-conflict resolution UI
  (the resolver *preflight* runs, but we surface errors, not a resolver wizard).
- Rollback of a failed `composer require` (we report the failure; the tree is
  left as composer left it).
- A full web terminal / console page (explicitly dropped — the installer subsumes
  the only workflow that motivated it).

## 3. Architecture

```
Thallo admin (Vue)                Framework core (PHP)
──────────────────                ─────────────────────
pages/developers/                 routes/extensions.php ─► ExtensionsController
  extensions/index.vue                                       │
  │ authFetch                       ┌────────────────────────┼───────────────────┐
  ▼                                 ▼                        ▼                   ▼
queries/extensions.ts        ExtensionCatalog        ExtensionInstaller    (enable/disable)
  GET  /extensions           (Packagist + state)     │  spawns detached      ExtensionStateWriter
  GET  /extensions/catalog          │                │  runner, returns id   + writeCacheNow()
  POST /extensions/install     Glueful\Http\Client    ▼
  GET  /extensions/install/{id}  → packagist.org   InstallJobStore (CacheStore)
  POST /extensions/enable                               ▲
  POST /extensions/disable        detached runner ──────┘ writes status
                                  `php glueful extensions:install-run <id> <pkg>`
                                     └► composer require (Symfony Process, array args)
```

**Why detached, not synchronous:** `composer require` takes 30s–minutes and
streams output. The POST returns a `jobId` immediately; the runner is a separate
OS process that outlives the request and writes progress to a shared store the
page polls. We chose a detached process over a queue job so it works on
self-hosted setups with **no queue worker running**.

**Why framework-core, not Thallo-only:** the API + composer logic live in the
framework so every Glueful app inherits the installer; Thallo only builds the UI.
This matches the existing `/email` and `/rbac` split (backend owns the API,
Thallo builds the page).

## 4. Security model (the crux)

Running `composer require` from a web request is remote code execution by design
(`composer require` executes downloaded package scripts as the web user). The
feature *is* the boundary around it. Controls, all required:

1. **Permission tier — `system.config`.** Mutating endpoints call
   `$this->requirePermission('system.config.edit')`; read endpoints call
   `$this->requirePermission('system.config.view')` (via
   `AuthorizationTrait`, the `ConfigController` precedent). Superuser bypass is
   handled by the Gate's `SuperRoleVoter` (`config/permissions.php` →
   `super_roles`). This is the one genuinely new capability the button grants —
   it turns "admin login" into "server code execution", so it sits at the
   highest existing tier, not `content.manage`.

2. **Kill-switch — `EXTENSIONS_INSTALL_ENABLED`.** `config('extensions.install.enabled')`.
   Default: enabled when `APP_ENV !== 'production'`; in production it is **off
   unless explicitly set true**. When off, install/enable/disable return `403`
   with a clear message; browse endpoints still work.

3. **Host capability detection — every file the flow mutates.** A single
   `HostCapability` check, reused by all mutating endpoints. **Install** requires
   writability of *all* of: `vendor/`, `composer.json`, `composer.lock`,
   `config/extensions.php`, and the extension cache
   (`bootstrap/cache/extensions.php` + its directory) — plus a resolvable
   composer binary. **Enable/disable** don't run composer but *do* rewrite
   `config/extensions.php` and recompile the cache, so they run their own subset
   preflight (`config/extensions.php` + `bootstrap/cache/`). Without the enable
   subset check, an immutable deploy could pass the install preflight and then
   fail halfway through the enable/cache step. For a target that does not yet
   exist (e.g. no `composer.lock`/`vendor/` on a fresh box), the check walks up to
   the nearest existing ancestor — the directory the file would be created in — so
   a missing target under a read-only parent is caught, not skipped. On a
   read-only/immutable container this fails cleanly: `409` with a specific `reason`
   (`read_only_filesystem` | `composer_missing`), and the UI shows "add this on
   deploy" instead of a broken spinner.

4. **Package allowlist — catalog membership, not substring.** A package is
   installable only if it is a member of the fetched Packagist catalog
   (vendor `glueful/` **and** `type: glueful-extension`). We do **not** use
   `str_contains('glueful')`. Belt-and-braces name validation before it ever
   reaches composer:
   - must match composer's package-name grammar
     `^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$`;
   - must start with `glueful/`;
   - must **not** start with `-` (blocks a name being read as a composer flag);
   - must be present in the catalog.

5. **No shell.** Every external command is invoked with **array arguments** —
   never `shell_exec`, never a concatenated command string, so there is no
   metacharacter/injection surface. The *outer* detached spawn (web → runner)
   uses `proc_open` with an **argv array** (`setsid` prepended on POSIX is also
   argv, not a shell string; see §5.6). The *inner* blocking runs (composer, and
   the fresh-process enable) use Symfony `Process` with an argv array
   (`['composer','require',$package,'--no-interaction','--no-progress']`).

6. **Production guard is deliberately not inherited from the CLI.** The
   `extensions:enable|disable` **console** commands refuse when
   `APP_ENV === 'production'`. The HTTP path does **not** call those commands; it
   calls `ExtensionStateWriter` + `ExtensionManager::writeCacheNow()` directly,
   gated instead by controls 1–3. This is a conscious decision: the CLI guard
   assumes "prod config is edited by hand"; the whole point here is to lift that
   for authorized superusers behind the kill-switch.

7. **Audit — concrete mechanism.** The framework has no dedicated audit service;
   it uses channel-based structured logging (`LogManager->channel('name')->info(msg, context)`).
   Every install/enable/disable writes a structured record to a dedicated
   `audit` channel via `LogManager` (resolve `\Glueful\Logging\LogManager` from
   the container), with context
   `{ action: 'extension.install'|'extension.enable'|'extension.disable',
   actor_id, resource_type: 'extension', resource_id: <package>,
   result: 'succeeded'|'failed', duration_ms }`.

## 5. Components

### 5.1 `ExtensionsController` (framework)
`src/Controllers/ExtensionsController.php` — an `ExtensionsController` already
exists but is **unrouted** (dead `index()`/`summary()`). Repurpose/replace it as
the manager controller. Extends `BaseController`; constructor injects
`ApplicationContext`, `ExtensionCatalog`, `ExtensionInstaller`, `InstallJobStore`,
and the enable/disable collaborators. Uses `success()/created()/forbidden()/notFound()`
response helpers and `requirePermission()`.

### 5.2 Routes
`routes/extensions.php` (new framework route file), registered in
`src/Routing/RouteManifest.php::generate()` under `api_routes` (so it mounts under
the API prefix, e.g. `/api/v1/extensions`). Route file uses the fluent
`$router->group(['prefix' => '/extensions'], …)` pattern (per `routes/health.php`),
each route `->middleware(['auth', 'rate_limit:…'])`.

> **UI path note:** extension/core routes are root-mounted for Thallo consumers in
> the existing code via `loadRoutesFrom` (no prefix — the `/rbac`, `/email`
> precedent). `RouteManifest` mounts core `api_routes` **under** the API prefix.
> The plan must confirm which mount the Thallo `authFetch` client targets and keep
> `queries/extensions.ts` consistent (literal `/extensions/*` vs
> `${apiBase}/extensions/*`). Default assumption: API-prefixed, like other
> `RouteManifest` core routes.

### 5.3 `ExtensionCatalog` (framework service)
`src/Extensions/ExtensionCatalog.php`. Responsibilities:
- **Fetch** installable packages from Packagist via `Glueful\Http\Client::get()`,
  in **two stages** (the search response alone is insufficient — verified:
  `search.json` returns `name, description, url, repository, downloads, favers`
  but **no `version` and no `type`**):
  1. **List** — `https://packagist.org/search.json?type=glueful-extension&per_page=100`
     (server-side type filter; follow `next` pagination), keep only `name`
     starting `glueful/`. Gives name/description/downloads/repository.
  2. **Hydrate** — for each candidate, fetch `https://repo.packagist.org/p2/<name>.json`
     to obtain the latest stable `version` **and** re-verify
     `type === 'glueful-extension'` (belt-and-braces, not relying on the
     undocumented server-side filter). Only type-verified rows enter the catalog
     — this is what makes the install allowlist (§4.4 "catalog membership")
     genuinely type-checked.
  Fallback if the search type filter returns nothing:
  `https://packagist.org/packages/list.json?vendor=glueful` → the same p2 hydrate
  + type filter. Cache the normalized, hydrated result in `CacheStore` (~1h TTL)
  so the p2 fan-out runs at most hourly, not per request.
- **Cross-reference state** against the local install:
  - installed = keys of `PackageManifest::getCandidates()` (reads
    `vendor/composer/installed.json`).
  - enabled = `EnabledProviders::from($context)` (provider FQCNs).
  - Each catalog row → `state ∈ {available, installed, enabled}` plus
    `description`, `downloads`, `version`, `repository`.
- **List installed** (for `GET /extensions`): the `ListCommand` cross-reference —
  `getCandidates()` × `EnabledProviders::from()` × `ExtensionManager::listMeta()`
  → rows with `state ∈ {enabled, available, enabled_missing}`
  (`enabled_missing` = in allow-list but not a candidate).

### 5.4 `ExtensionInstaller` + `InstallJobStore` (framework)
- `src/Extensions/Install/InstallJobStore.php` — thin wrapper over `CacheStore`
  (service id `cache.store` / `\Glueful\Cache\CacheStore::class`). Key
  `ext_install:<jobId>`, TTL ~1h. Record shape:
  ```
  { id, package,
    status: queued|running|succeeded|failed|installed_not_enabled,
    output: string (tail, capped ~64KB), exitCode: ?int,
    error: ?string,        // composer/spawn failure
    enableError: ?string,  // set with status=installed_not_enabled
    startedAt, finishedAt }
  ```
  `succeeded` = composer installed AND enabled (or auto-enable off, no error);
  `installed_not_enabled` = composer installed but auto-enable failed (not a hard
  failure — the operator can resolve the dependency and enable manually);
  `failed` = composer/spawn failed.
  Methods: `create(package): string jobId`, `get(jobId): ?array`,
  `markRunning(jobId)`, `appendOutput(jobId, chunk)`,
  `finish(jobId, status, exitCode, error=null)`.
  > `CacheStore` must be a **shared** driver (file/redis, not the array driver) so
  > the detached process and the web process see the same record. This is
  > inherent — the runner is a separate process regardless.
- `src/Extensions/Install/ExtensionInstaller.php` — `start(package): string jobId`:
  runs the §4 validation, `InstallJobStore::create()`, spawns the detached runner
  (§6.3), returns the jobId. Does **not** block.

### 5.5 `extensions:install-run` console command (the runner)
`src/Console/Commands/Extensions/InstallRunCommand.php` (`#[AsCommand('extensions:install-run')]`,
extends `BaseCommand`). Args: `jobId`, `package`. This is the process the outer
spawn (§5.6) launches detached. Flow:
1. `InstallJobStore::markRunning(jobId)`.
2. `new Process(['composer','require',$package,'--no-interaction','--no-progress'], base_path($ctx))`
   with `setTimeout((float) config('extensions.install.timeout', 600))`; run
   (blocking — correct here, we're already detached) with a callback that
   `appendOutput`s each buffer chunk. On non-zero →
   `finish(jobId, failed, exitCode, stderrTail)` and stop.
3. On exit 0 and `config('extensions.install.auto_enable', true)`: **hand the
   enable+cache step to a fresh PHP subprocess** (§5.5b) via
   `new Process([PHP_BINARY, base_path($ctx,'glueful'), 'extensions:enable-installed', $package], base_path($ctx))` →
   `->run()` (blocking; we want its result). Merge its outcome into the job
   record.

   > **Why a fresh process (P1 fix).** The runner started *before* composer wrote
   > the new package, so its in-memory autoloader can't see the new provider
   > class. `ExtensionManager::writeCacheNow()` instantiates providers
   > (`class_exists`/construct), so calling it in the runner would silently drop
   > or fail on the just-installed provider. A fresh `php` process loads the
   > freshly-generated `vendor/autoload.php` and resolves the provider correctly.
4. `InstallJobStore::finish(jobId, succeeded|failed, exitCode, error?)`. If
   composer succeeded but enable-installed reported a resolver error, the terminal
   state is `succeeded` with an `enable_error` note (installed-but-not-enabled is
   valid).

Injecting both `Process` calls via a small factory keeps the command testable
without hitting real composer/network.

### 5.5b `extensions:enable-installed` console command (fresh-process enabler)
`src/Console/Commands/Extensions/EnableInstalledCommand.php`
(`#[AsCommand('extensions:enable-installed')]`). Arg: `package`. Runs in its own
PHP process (fresh autoloader). Flow: resolve provider FQCN via
`PackageManifest::getCandidates()`; preflight the proposed enabled set with
`(new ExtensionResolver())->resolve($candidates, $proposed, Version::VERSION)`;
on no errors, `(new ExtensionStateWriter())->enable(config_path($ctx,'extensions.php'), $provider)`
then `$this->getService(ExtensionManager::class)->writeCacheNow()`. Prints a
structured result (JSON line) the runner parses. **No `APP_ENV=production`
guard** — unlike the interactive `extensions:enable`, this internal command is
only reachable via the runner, which is gated upstream by the HTTP kill-switch +
`system.config.edit` + host-capability checks. Reusable as the shared
enable-after-install seam.

### 5.6 `DetachedRunner` helper (framework) — the true non-blocking spawn
`src/Support/Process/DetachedRunner.php`. Spawns the runner so the install POST
**returns immediately** and the child **outlives the request**.

> **P1 correction — do NOT use Symfony `Process` here.** `setsid <program>` does
> not daemonize on its own (it `exec`s the program in a new session, keeping the
> same child PID), and `Process::run()` *waits* for that child — so
> `Process(['setsid', …])->run()` would block for the entire composer run,
> breaking §3's contract. `Process::start()` doesn't fix it either: Symfony
> `Process::__destruct()` calls `stop()`, which SIGKILLs the child when the
> object is GC'd at request end.

**Mechanism — `proc_open` with an argv array, no wait, stdio redirected to the
job log:**
```php
$cmd = ['setsid', PHP_BINARY, base_path($ctx,'glueful'),
        'extensions:install-run', $jobId, $package];   // setsid dropped in fallback
$log = storage_path($ctx, "logs/ext-install-$jobId.log");
$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $log, 'w'],
    2 => ['file', $log, 'a'],
];
$proc = proc_open($cmd, $descriptors, $pipes, base_path($ctx));
// return NOW — no proc_close()/wait. proc_open children are NOT reaped by PHP on
// shutdown (unlike Symfony Process), so the child survives the request.
```
- **`proc_open` gives the non-blocking property** (it returns as soon as the child
  is spawned; we never wait on it).
- **`setsid` gives the survival property** on POSIX — the child runs in a new
  session, so a signal to the FPM worker's process group on recycle won't reach
  it. Still pure argv (no shell), so no injection surface.
- **Fallback (macOS dev — `setsid` verified absent on the dev box):** same
  `proc_open` without the `setsid` prefix. Still non-blocking and not reaped by
  PHP; the child shares the session, acceptable outside production. Strategy chosen
  by probing for a `setsid` binary on `PATH`, defaulting to the no-setsid path.
- The runner streams composer output to `InstallJobStore` (the poll source); the
  `$log` file is a redundant on-disk transcript for debugging.

**Testability / acceptance:** `DetachedRunner` takes an injectable spawn callable
(default wraps `proc_open`) so tests assert (a) the exact argv is built,
(b) `setsid` is present/absent per the probe, and (c) **the call returns without
waiting** — e.g. spawn a marker runner that sleeps and assert `DetachedRunner`
returns before the marker completes. This directly guards the "returns
immediately" contract.

### 5.7 Request DTOs (framework)
`src/DTOs/ExtensionInstallData.php`, `ExtensionToggleData.php` implementing
`Glueful\Validation\Contracts\RequestData`:
```php
final class ExtensionInstallData implements RequestData {
    public function __construct(
        #[Rule('required|string|max:150')] public readonly string $package,
    ) {}
}
```
(The strict allowlist/grammar check in §4 runs in `ExtensionInstaller`, not the
DTO — it needs the live catalog.) `enable`/`disable` take
`ExtensionToggleData { package }`.

### 5.8 Config + env
`config/extensions.php` gains a sibling key to `enabled` (keep `enabled` a pure
literal list — the doc comment forbids `env()` calls *inside* it):
```php
'install' => [
    'enabled'     => env('EXTENSIONS_INSTALL_ENABLED', env('APP_ENV') !== 'production'),
    'auto_enable' => env('EXTENSIONS_INSTALL_AUTO_ENABLE', true),
    'timeout'     => (int) env('EXTENSIONS_INSTALL_TIMEOUT', 600),
    'vendor'      => 'glueful/',   // allowlist prefix
],
```
Add `EXTENSIONS_INSTALL_ENABLED`, `EXTENSIONS_INSTALL_AUTO_ENABLE`,
`EXTENSIONS_INSTALL_TIMEOUT` to `.env.example` with comments. Mirror the `install`
block into the api-skeleton's shadow `config/extensions.php` on release
(skeleton config parity).

### 5.9 Thallo UI
- `admin/src/queries/extensions.ts` — `authFetch` wrappers + TS types
  (`ExtensionRow`, `CatalogRow`, `InstallJob`) for the six endpoints, following
  `queries/email.ts` conventions.
- `admin/src/pages/developers/extensions/index.vue` — two sections:
  **Installed** (list with enable/disable toggles, `state` badges) and
  **Browse / catalog** (cards with Install buttons). Install → POST → poll
  `GET /extensions/install/{jobId}` every ~1.5s, show streaming output in a
  disclosure, flip to "enabled" on success. `403` (kill-switch/permission) hides
  the install affordance with an explanatory note; `409 read_only_filesystem`
  shows "install on deploy". No CodeMirror here.
- No new Thallo controller (API is framework); page is frontend-only.

## 6. API contract

| Method | Path | Permission | Body | Returns |
|---|---|---|---|---|
| GET  | `/extensions` | `system.config.view` | — | `{ installed: ExtensionRow[] }` |
| GET  | `/extensions/catalog` | `system.config.view` | — | `{ catalog: CatalogRow[] }` (cached) |
| POST | `/extensions/install` | `system.config.edit` | `{ package }` | `201 { jobId, status:'queued' }` / `403` (switch/perm) / `409 { reason }` (host) / `422` (bad package) |
| GET  | `/extensions/install/{jobId}` | `system.config.view` | — | `{ id, package, status, output, exitCode, error, startedAt, finishedAt }` / `404` |
| POST | `/extensions/enable` | `system.config.edit` | `{ package }` | `{ package, provider, state:'enabled' }` / `409 { reason }` (config/cache not writable) / `422` (resolver error) |
| POST | `/extensions/disable` | `system.config.edit` | `{ package }` | `{ package, provider, state:'available' }` / `409 { reason }` (config/cache not writable) / `422` (dependency block) |

`ExtensionRow = { package, provider, version, label, state: 'enabled'|'available'|'enabled_missing' }`
`CatalogRow  = { package, description, version, downloads, repository, state: 'available'|'installed'|'enabled' }`

## 7. Data flow — install

1. UI `POST /extensions/install { package: 'glueful/aegis' }`.
2. Controller `requirePermission('system.config.edit')` → `ExtensionInstaller::start()`.
3. Installer validates: kill-switch on, host writable, name grammar + `glueful/`
   prefix + catalog membership. Fail → `403`/`409`/`422` (no job created).
4. `InstallJobStore::create()` → jobId; `DetachedRunner` spawns
   `php glueful extensions:install-run <jobId> glueful/aegis`; controller returns
   `201 { jobId }`.
5. Runner (detached via `proc_open`+`setsid`, §5.6): `markRunning` →
   `composer require …` (Symfony `Process`, streaming `appendOutput`) → on 0 +
   auto_enable: spawn a **fresh** `php glueful extensions:enable-installed glueful/aegis`
   process (§5.5b — fresh autoloader sees the new provider) which does resolver
   preflight → `ExtensionStateWriter::enable` → `writeCacheNow`; merge its result
   → `finish(succeeded)` (or `finish(installed_not_enabled)` + `enableError` if the
   preflight blocked enabling). On composer non-zero: `finish(failed, exitCode, stderrTail)`.
6. UI polls `GET /extensions/install/{jobId}` until `status ∈ {succeeded, failed}`,
   renders output, refreshes the installed list.

## 8. Error handling

- **Bad/foreign/flag-like package** → `422` before any job; never reaches composer.
- **Kill-switch off / missing permission** → `403`.
- **Read-only host** → `409 { reason }` with the specific failing capability:
  install checks all five mutated paths; enable/disable check the config + cache
  subset (so an immutable deploy is rejected up front, not mid-enable).
  `reason ∈ { read_only_filesystem, composer_missing }`.
- **composer non-zero** → job `failed` with captured stderr tail + exitCode; tree
  left as-is (no auto-rollback in v1); UI shows the failure and output.
- **Resolver preflight error post-install** (e.g. missing dependency) → package
  stays installed but **not enabled**; job ends with the distinct terminal status
  `installed_not_enabled` and `enableError` set; UI shows "installed, needs a
  dependency" (a warning, not a failure) rather than silently enabling.
- **Timeout** → Process killed at `install.timeout`; job `failed` with a timeout
  note.
- **Poll after TTL expiry** → `404`; UI treats a lost job as "unknown — refresh".

## 9. Testing

**Framework (PHPUnit):**
- Unit: package-name validator (rejects non-`glueful/`, `--flag`, invalid grammar,
  non-catalog); `ExtensionCatalog` state cross-reference with a stubbed
  `Http\Client` + fake `installed.json`/enabled list; `InstallJobStore`
  round-trip on a file/array-backed `CacheStore`; `DetachedRunner` builds the
  correct argv for setsid vs fallback (probe stubbed).
- Integration: controller endpoints with a fake `ExtensionInstaller` (no real
  composer): `403` without `system.config`, `403` when kill-switch off,
  `409` when host not writable, `201 { jobId }` on happy path, `422` on bad
  package; `enable`/`disable` call `ExtensionStateWriter` + `writeCacheNow`
  (spy), return `409` when `config/extensions.php`/cache aren't writable
  (the enable-subset preflight), and surface resolver errors as `422`.
- `HostCapability`: reports the exact unwritable path/`reason` for each of the
  five install paths and the two enable/disable paths (temp-dir fixtures with
  chmod-toggled writability).
- `DetachedRunner`: injected spawn callable → asserts the exact argv,
  `setsid` present/absent per the probe, and **that it returns without waiting**
  (spawn a sleeping marker runner; assert the call returns before the marker
  finishes) — the guard for the §3 non-blocking contract.
- Runner: `InstallRunCommand` with an injected fake `Process` factory (scripted
  exit code + output for both the composer call and the fresh
  `extensions:enable-installed` call) → asserts job transitions, that composer
  failure short-circuits before enable, and that the auto-enable path invokes the
  **fresh-process** command (not an in-process `writeCacheNow`); **never** shells
  out in tests.
- `EnableInstalledCommand`: with a fake candidate set → resolver-clean path calls
  `ExtensionStateWriter::enable` + `writeCacheNow` (spies) and emits the structured
  result line; resolver-error path emits the error without writing.

**Thallo (vitest):** mock `queries/extensions.ts`; assert installed list +
catalog render, install → poll → enabled transition, `403` hides install with a
note, `409` shows "install on deploy". Stub any editor; none needed here.

## 10. Open items for the plan
- Confirm the Thallo mount (API-prefixed `RouteManifest` vs root-mounted) and pin
  `queries/extensions.ts` base accordingly.
- Confirm the `Version` import path used for the resolver preflight (the CLI
  commands reference `Version::VERSION`).
- Decide the catalog cache TTL + a manual "refresh catalog" affordance.
- Confirm `storage_path()`/log location helper for the runner's on-disk transcript.
