# Extensions Manager Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an in-admin extension installer — a framework-core `/extensions/*` API to browse (Packagist), install (`composer require`, detached), and enable/disable Glueful extensions, plus the Thallo admin UI that consumes it.

**Architecture:** Framework-core HTTP surface (`ExtensionsController` + `routes/extensions.php`) over reusable seams (`PackageManifest`, `EnabledProviders`, `ExtensionResolver`, `ExtensionStateWriter`, `ExtensionManager::writeCacheNow()`). Install runs `composer require` in a **detached** OS process (`proc_open`, argv array, `setsid` on POSIX) whose status lands in `CacheStore`; the autoload-sensitive enable+cache step runs in a **fresh PHP subprocess**. Thallo builds the page as an `authFetch` consumer.

**Tech Stack:** PHP 8.3, Symfony Process/Console, `Glueful\Http\Client` (symfony/http-client), `Glueful\Cache\CacheStore`, Vue 3 + Nuxt UI + vitest (Thallo).

**Spec:** `docs/superpowers/specs/2026-07-05-extensions-manager-design.md`

## Global Constraints

- **No shell.** Every external command uses an **argv array**. Outer detached spawn = `proc_open` with an array command. Inner blocking runs (composer, fresh enable) = Symfony `Process` with an array command. `shell_exec`/concatenated command strings are forbidden.
- **String-command fallback is guarded.** `proc_open` uses array argv on all supported PHP (8.3+). If a platform ever needs a string command, it MUST be built by mapping `escapeshellarg()` over **every** argv segment (`DetachedRunner::toShellCommand()`), and that helper MUST stay covered by a unit test.
- **Permission tier:** mutating endpoints require `system.config.edit`; read endpoints require `system.config.view` (via `AuthorizationTrait`).
- **Kill-switch:** `config('extensions.install.enabled')` — default `APP_ENV !== 'production'`. When off, mutating endpoints return `403`.
- **Host capability:** install checks writability of `vendor/`, `composer.json`, `composer.lock`, `config/extensions.php`, `bootstrap/cache/` (+ composer binary); enable/disable check `config/extensions.php` + `bootstrap/cache/`. Failure → `409 { reason }`.
- **Allowlist:** a package is installable only if it is in the fetched, type-verified Packagist catalog (vendor `glueful/`, `type: glueful-extension`); plus a name-grammar check that rejects non-`glueful/`, flag-like (`-…`), or malformed names before composer sees them.
- **Audit:** `LogManager->channel('audit')->info(...)` with `{ action, actor_id, resource_type:'extension', resource_id, result, duration_ms }`.
- **Framework version constant:** `Glueful\Support\Version::VERSION`.
- **Work on `dev` directly. No feature branch. Commit at phase boundaries (not per task). No attribution trailers in commit messages. Do not stage `CLAUDE.md`.**

---

## File Structure

**Framework** (`/Users/michaeltawiahsowah/Sites/glueful/framework`):
- `config/extensions.php` — add `install` block (Task 1)
- `.env.example` — add `EXTENSIONS_INSTALL_*` keys (Task 1)
- `src/Support/Process/ComposerBinaryResolver.php` — single composer-binary source, shared by preflight + runner (Task 2)
- `src/Extensions/Install/HostCapability.php` — writability + composer probe (Task 2)
- `src/Extensions/Install/InstallJobStore.php` — CacheStore-backed job status (Task 3)
- `src/Support/Process/DetachedRunner.php` — detached spawn + escape guard (Task 4)
- `src/Support/Process/ProcessRunner.php` + `SymfonyProcessRunner.php` — testable blocking runner (Task 5)
- `src/Console/Commands/Extensions/EnableInstalledCommand.php` — fresh-process enabler (Task 5)
- `src/Console/Commands/Extensions/InstallRunCommand.php` — the detached runner (Task 6)
- `src/Extensions/ExtensionCatalog.php` — Packagist fetch/hydrate + state (Task 7)
- `src/Extensions/Install/ExtensionInstaller.php` + install exceptions (Task 8)
- `src/DTOs/ExtensionInstallData.php`, `src/DTOs/ExtensionToggleData.php` (Task 9)
- `src/Controllers/ExtensionsController.php` — repurpose the unrouted controller (Task 10)
- `src/Container/Providers/CoreProvider.php` — register services (Task 10)
- `routes/extensions.php` + `src/Routing/RouteManifest.php` (Task 11)

**Thallo** (`/Users/michaeltawiahsowah/Sites/glueful/lemma`) — separable follow-on phase:
- `admin/src/queries/extensions.ts` (Task 12)
- `admin/src/pages/developers/extensions/index.vue` (Task 13)
- `admin/src/__tests__/extensionsPage.spec.ts` (Task 14)

---

## Phase 1 — Foundations

### Task 1: `install` config block + env keys

**Files:**
- Modify: `config/extensions.php`
- Modify: `.env.example`

**Interfaces:**
- Produces: config keys `extensions.install.{enabled,auto_enable,timeout,vendor}`.

- [ ] **Step 1: Add the `install` block to `config/extensions.php`** (keep `enabled` a pure literal list — the file's doc comment forbids `env()` inside it; put reads in the sibling key):

```php
return [
    'enabled' => [
        // ... existing literal FQCN list, untouched ...
    ],
    'install' => [
        'enabled'     => env('EXTENSIONS_INSTALL_ENABLED', env('APP_ENV') !== 'production'),
        'auto_enable' => (bool) env('EXTENSIONS_INSTALL_AUTO_ENABLE', true),
        'timeout'     => (int) env('EXTENSIONS_INSTALL_TIMEOUT', 600),
        'vendor'      => 'glueful/',
    ],
];
```

- [ ] **Step 2: Append documented keys to `.env.example`** (near other feature flags):

```dotenv
# Extensions installer (in-admin composer require). Off in production unless set.
EXTENSIONS_INSTALL_ENABLED=
EXTENSIONS_INSTALL_AUTO_ENABLE=true
EXTENSIONS_INSTALL_TIMEOUT=600
```

- [ ] **Step 3: Verify config loads** — Run: `php glueful config:show extensions.install` (or `php -r` bootstrap). Expected: array with `enabled`, `auto_enable`, `timeout`, `vendor` keys; no fatal.

*(No commit yet — commit at end of Phase 1.)*

---

### Task 2: `HostCapability` service

**Files:**
- Create: `src/Extensions/Install/HostCapability.php`
- Test: `tests/Unit/Extensions/Install/HostCapabilityTest.php`

**Interfaces:**
- Produces:
  - `ComposerBinaryResolver::resolve(): ?string` — the **single** source of the composer binary (honors `COMPOSER_BINARY`), reused by preflight AND the runner.
  - `HostCapability::__construct(ApplicationContext $context, ComposerBinaryResolver $composer)`
  - `forInstall(): ?array` — `null` when OK, else `['reason' => string, 'detail' => string]`
  - `forToggle(): ?array` — same shape
  - `composerBinary(): ?string` — delegates to the resolver

- [ ] **Step 1: Write the failing tests** — a read-only *existing* path, AND (P1 fix) a **missing** path under a read-only parent:

```php
namespace Glueful\Tests\Unit\Extensions\Install;

use Glueful\Extensions\Install\HostCapability;
use Glueful\Support\Process\ComposerBinaryResolver;
use PHPUnit\Framework\TestCase;

final class HostCapabilityTest extends TestCase
{
    public function test_flags_a_readonly_existing_path(): void
    {
        $dir = $this->tempDir(0555);            // read+execute, not writable
        $cap = new HostCapability(FakeContext::withBasePath($dir), new ComposerBinaryResolver());
        $result = $cap->forToggle();
        $this->assertSame('read_only_filesystem', $result['reason']);
    }

    public function test_flags_missing_vendor_under_unwritable_base(): void
    {
        // base exists but is read-only; vendor/ and composer.lock do NOT exist yet.
        $base = $this->tempDir(0555);
        $cap = new HostCapability(FakeContext::withBasePath($base), new ComposerBinaryResolver());
        $result = $cap->forInstall();           // would `composer require` into a read-only base
        $this->assertIsArray($result, 'missing vendor/composer.lock under a read-only base must fail preflight');
        $this->assertSame('read_only_filesystem', $result['reason']);
    }
}
```
*(`FakeContext` is a tiny in-test double whose `base_path()`/`config_path()` return under a temp dir — reuse the suite's context helper if one exists; else add a minimal fake. `tempDir($mode)` mkdirs a unique temp dir with the given mode and registers teardown cleanup that chmods 0755 first.)*

- [ ] **Step 2: Run it to verify it fails** — Run: `vendor/bin/phpunit tests/Unit/Extensions/Install/HostCapabilityTest.php` → FAIL (class not found / missing-path case not handled).

- [ ] **Step 3a: Create the shared `ComposerBinaryResolver`** (both preflight and the runner MUST use this — never a second `ExecutableFinder` call):

```php
<?php
declare(strict_types=1);

namespace Glueful\Support\Process;

use Symfony\Component\Process\ExecutableFinder;

final class ComposerBinaryResolver
{
    /** Honors COMPOSER_BINARY, else finds `composer` on PATH. Null when absent. */
    public function resolve(): ?string
    {
        $candidate = getenv('COMPOSER_BINARY') ?: 'composer';
        // Absolute/relative explicit path → accept if executable; else search PATH.
        if (str_contains($candidate, '/') && is_executable($candidate)) {
            return $candidate;
        }
        return (new ExecutableFinder())->find($candidate) ?? null;
    }
}
```

- [ ] **Step 3b: Implement `HostCapability`** — delegate composer resolution, and (P1 fix) check the path if it exists, otherwise the **nearest existing ancestor** (the directory the file/dir would be created in):

```php
<?php
declare(strict_types=1);

namespace Glueful\Extensions\Install;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Support\Process\ComposerBinaryResolver;

final class HostCapability
{
    public function __construct(
        private ApplicationContext $context,
        private ComposerBinaryResolver $composer,
    ) {}

    /** @return array{reason:string,detail:string}|null null = installable */
    public function forInstall(): ?array
    {
        if ($this->composerBinary() === null) {
            return ['reason' => 'composer_missing', 'detail' => 'composer binary not found on PATH'];
        }
        return $this->firstUnwritable($this->installPaths());
    }

    /** @return array{reason:string,detail:string}|null null = toggleable */
    public function forToggle(): ?array
    {
        return $this->firstUnwritable($this->togglePaths());
    }

    public function composerBinary(): ?string
    {
        return $this->composer->resolve();
    }

    /** @return list<string> */
    private function installPaths(): array
    {
        return [
            base_path($this->context, 'vendor'),
            base_path($this->context, 'composer.json'),
            base_path($this->context, 'composer.lock'),
            config_path($this->context, 'extensions.php'),
            base_path($this->context, 'bootstrap/cache'),
        ];
    }

    /** @return list<string> */
    private function togglePaths(): array
    {
        return [
            config_path($this->context, 'extensions.php'),
            base_path($this->context, 'bootstrap/cache'),
        ];
    }

    /**
     * @param list<string> $paths
     * @return array{reason:string,detail:string}|null
     */
    private function firstUnwritable(array $paths): ?array
    {
        foreach ($paths as $path) {
            // Check the path itself if present; otherwise the nearest existing
            // ancestor — the dir the tool will create the file/dir in. A missing
            // vendor/composer.lock under a read-only base MUST fail here.
            if (!is_writable($this->writabilityTarget($path))) {
                return ['reason' => 'read_only_filesystem', 'detail' => $path];
            }
        }
        return null;
    }

    private function writabilityTarget(string $path): string
    {
        $current = $path;
        while (!file_exists($current)) {
            $parent = dirname($current);
            if ($parent === $current) {
                return $current; // reached filesystem root
            }
            $current = $parent;
        }
        return $current;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass** — Run: `vendor/bin/phpunit tests/Unit/Extensions/Install/HostCapabilityTest.php` → PASS (both the existing-readonly and missing-under-readonly cases).

---

### Task 3: `InstallJobStore`

**Files:**
- Create: `src/Extensions/Install/InstallJobStore.php`
- Test: `tests/Unit/Extensions/Install/InstallJobStoreTest.php`

**Interfaces:**
- Consumes: `Glueful\Cache\CacheStore` (container id `cache.store`).
- Produces:
  - `create(string $package): string` (jobId)
  - `get(string $jobId): ?array`
  - `markRunning(string $jobId): void`
  - `appendOutput(string $jobId, string $chunk): void`
  - `finish(string $jobId, string $status, ?int $exitCode = null, ?string $error = null, ?string $enableError = null): void`
  - Record: `{ id, package, status, output, exitCode, error, enableError, startedAt, finishedAt }`
  - **Status values:** `queued | running | succeeded | failed | installed_not_enabled` — `succeeded` = composer installed AND enabled (or auto-enable off, no error); `installed_not_enabled` = composer installed but auto-enable failed (`enableError` set); `failed` = composer/spawn failed.

- [ ] **Step 1: Write the failing test** (backed by an in-memory `CacheStore` fake / array driver):

```php
public function test_lifecycle_create_run_finish(): void
{
    $store = new InstallJobStore(new ArrayCacheStore());
    $id = $store->create('glueful/aegis');
    $this->assertSame('queued', $store->get($id)['status']);

    $store->markRunning($id);
    $store->appendOutput($id, "Resolving...\n");
    $store->appendOutput($id, "Installing glueful/aegis\n");
    $this->assertSame('running', $store->get($id)['status']);
    $this->assertStringContainsString('Installing glueful/aegis', $store->get($id)['output']);

    $store->finish($id, 'succeeded', 0, null, null);
    $rec = $store->get($id);
    $this->assertSame('succeeded', $rec['status']);
    $this->assertSame(0, $rec['exitCode']);
    $this->assertNotNull($rec['finishedAt']);
}
```

- [ ] **Step 2: Run it to verify it fails** — FAIL (class not found).

- [ ] **Step 3: Implement `InstallJobStore`**

```php
<?php
declare(strict_types=1);

namespace Glueful\Extensions\Install;

use Glueful\Cache\CacheStore;

final class InstallJobStore
{
    private const PREFIX = 'ext_install:';
    private const TTL = 3600;
    private const OUTPUT_CAP = 65536; // ~64KB tail

    /** @param CacheStore<mixed> $cache */
    public function __construct(private CacheStore $cache) {}

    public function create(string $package): string
    {
        $id = bin2hex(random_bytes(12));
        $this->put($id, [
            'id' => $id, 'package' => $package, 'status' => 'queued',
            'output' => '', 'exitCode' => null, 'error' => null, 'enableError' => null,
            'startedAt' => date(DATE_ATOM), 'finishedAt' => null,
        ]);
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function get(string $jobId): ?array
    {
        $rec = $this->cache->get(self::PREFIX . $jobId);
        return is_array($rec) ? $rec : null;
    }

    public function markRunning(string $jobId): void
    {
        $this->patch($jobId, ['status' => 'running']);
    }

    public function appendOutput(string $jobId, string $chunk): void
    {
        $rec = $this->get($jobId);
        if ($rec === null) {
            return;
        }
        $out = (string) $rec['output'] . $chunk;
        if (strlen($out) > self::OUTPUT_CAP) {
            $out = substr($out, -self::OUTPUT_CAP);
        }
        $this->patch($jobId, ['output' => $out]);
    }

    public function finish(
        string $jobId,
        string $status,
        ?int $exitCode = null,
        ?string $error = null,
        ?string $enableError = null,
    ): void {
        $this->patch($jobId, [
            'status' => $status, 'exitCode' => $exitCode,
            'error' => $error, 'enableError' => $enableError,
            'finishedAt' => date(DATE_ATOM),
        ]);
    }

    /** @param array<string,mixed> $changes */
    private function patch(string $jobId, array $changes): void
    {
        $rec = $this->get($jobId);
        if ($rec === null) {
            return;
        }
        $this->put($jobId, array_merge($rec, $changes));
    }

    /** @param array<string,mixed> $rec */
    private function put(string $jobId, array $rec): void
    {
        $this->cache->set(self::PREFIX . $jobId, $rec, self::TTL);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass** — PASS.

- [ ] **Step 5: Commit Phase 1**

```bash
git add config/extensions.php .env.example src/Support/Process/ComposerBinaryResolver.php \
        src/Extensions/Install/HostCapability.php src/Extensions/Install/InstallJobStore.php \
        tests/Unit/Extensions/Install/
git commit -m "extensions installer: config, host-capability checks, install job store"
```

---

## Phase 2 — Process runner (the risky path first)

### Task 4: `DetachedRunner` — true non-blocking spawn

**Files:**
- Create: `src/Support/Process/DetachedRunner.php`
- Test: `tests/Unit/Support/Process/DetachedRunnerTest.php`

**Interfaces:**
- Produces:
  - `DetachedRunner::__construct(ApplicationContext $context, ?\Closure $spawn = null)` — `$spawn(array $argv, string $cwd, string $log): void` injectable for tests; default wraps `proc_open`.
  - `spawnInstall(string $jobId, string $package): void`
  - `buildArgv(string $jobId, string $package): list<string>` (public for assertion)
  - `static toShellCommand(list<string> $argv): string` — the escaped string-fallback guard.

- [ ] **Step 1: Write the failing tests** — argv build, setsid probe, escape guard, and the **non-blocking** contract:

```php
public function test_buildArgv_is_pure_array_and_targets_the_runner(): void
{
    $r = new DetachedRunner(FakeContext::withBasePath('/srv/app'));
    $argv = $r->buildArgv('job123', 'glueful/aegis');
    $this->assertContains('extensions:install-run', $argv);
    $this->assertContains('glueful/aegis', $argv);
    $this->assertSame(PHP_BINARY, $argv[array_search('extensions:install-run', $argv) - 2]);
}

public function test_toShellCommand_escapes_every_segment(): void
{
    $s = DetachedRunner::toShellCommand(['php', 'glueful', 'extensions:install-run', 'j', 'a b; rm -rf /']);
    $this->assertStringContainsString(escapeshellarg('a b; rm -rf /'), $s);
    $this->assertStringNotContainsString('; rm -rf /', str_replace(escapeshellarg('a b; rm -rf /'), '', $s));
}

public function test_spawn_returns_immediately_without_waiting(): void
{
    $started = null; $returned = null;
    $spy = function (array $argv, string $cwd, string $log) use (&$started, &$returned): void {
        $started = microtime(true);      // a real detached spawn would return now
    };
    $r = new DetachedRunner(FakeContext::withBasePath(sys_get_temp_dir()), $spy);
    $t0 = microtime(true);
    $r->spawnInstall('job1', 'glueful/aegis');
    $returned = microtime(true);
    $this->assertLessThan(0.5, $returned - $t0, 'spawnInstall must not block');
    $this->assertNotNull($started);
}

public function test_real_detached_spawn_returns_before_child_completes(): void
{
    if (!function_exists('proc_open')) {
        $this->markTestSkipped('proc_open unavailable');
    }
    $marker = sys_get_temp_dir() . '/mark_' . bin2hex(random_bytes(4));
    $log = sys_get_temp_dir() . '/log_' . bin2hex(random_bytes(4));

    // Real detached child: sleeps 400ms, then writes the marker. Pure argv, no shell.
    $t0 = microtime(true);
    DetachedRunner::detachedSpawn(
        [PHP_BINARY, '-r', 'usleep(400000); file_put_contents($argv[1], "x");', $marker],
        sys_get_temp_dir(),
        $log,
    );
    $elapsed = microtime(true) - $t0;

    // Non-blocking: returned well before the child's 400ms sleep elapsed...
    $this->assertLessThan(0.3, $elapsed, 'detachedSpawn must return before the child finishes');
    $this->assertFileDoesNotExist($marker, 'child should still be running when spawn returns');

    // ...and the detached child completes independently afterward.
    $deadline = microtime(true) + 3.0;
    while (!file_exists($marker) && microtime(true) < $deadline) {
        usleep(20000);
    }
    $this->assertFileExists($marker, 'detached child ran to completion after spawn returned');
    @unlink($marker);
    @unlink($log);
}
```

- [ ] **Step 2: Run to verify failure** — FAIL (class not found).

- [ ] **Step 3: Implement `DetachedRunner`**

```php
<?php
declare(strict_types=1);

namespace Glueful\Support\Process;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Fire-and-forget spawn for the extensions install runner.
 *
 * Non-blocking property comes from proc_open (we never wait/proc_close).
 * Survival across FPM recycle comes from `setsid` (new session) on POSIX.
 * Always pure argv — no shell. See toShellCommand() for the guarded string form.
 */
final class DetachedRunner
{
    private \Closure $spawn;

    public function __construct(private ApplicationContext $context, ?\Closure $spawn = null)
    {
        $this->spawn = $spawn ?? self::defaultSpawn();
    }

    public function spawnInstall(string $jobId, string $package): void
    {
        $argv = $this->buildArgv($jobId, $package);
        $log = storage_path($this->context, "logs/ext-install-{$jobId}.log");
        ($this->spawn)($argv, base_path($this->context), $log);
    }

    /** @return list<string> */
    public function buildArgv(string $jobId, string $package): array
    {
        $argv = [PHP_BINARY, base_path($this->context, 'glueful'), 'extensions:install-run', $jobId, $package];
        if ($this->hasSetsid()) {
            array_unshift($argv, 'setsid');
        }
        return $argv;
    }

    /**
     * Guarded string fallback — only for a platform that cannot take array argv.
     * Every segment is escapeshellarg'd. Kept covered by a unit test.
     * @param list<string> $argv
     */
    public static function toShellCommand(array $argv): string
    {
        return implode(' ', array_map('escapeshellarg', $argv));
    }

    private function hasSetsid(): bool
    {
        return (new ExecutableFinder())->find('setsid') !== null;
    }

    private static function defaultSpawn(): \Closure
    {
        return static fn(array $argv, string $cwd, string $log) => self::detachedSpawn($argv, $cwd, $log);
    }

    /**
     * The real detached spawn: proc_open with array argv (no shell), stdio to a
     * file, and NO proc_close() — so it returns immediately and the child is not
     * reaped by PHP at shutdown. Public + static so the real path is testable.
     * @param list<string> $argv
     */
    public static function detachedSpawn(array $argv, string $cwd, string $log): void
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'w'],
            2 => ['file', $log, 'a'],
        ];
        $pipes = [];
        $proc = proc_open($argv, $descriptors, $pipes, $cwd); // array argv → no shell
        if (is_resource($proc)) {
            foreach ($pipes as $p) {
                if (is_resource($p)) {
                    fclose($p);
                }
            }
            // Deliberately NO proc_close() — that would wait. Detach and return.
        }
    }
}
```

- [ ] **Step 4: Run to verify pass** — PASS.

---

### Task 5: `ProcessRunner` seam + `EnableInstalledCommand` (fresh-process enabler)

**Files:**
- Create: `src/Support/Process/ProcessRunner.php` (interface), `src/Support/Process/SymfonyProcessRunner.php`
- Create: `src/Console/Commands/Extensions/EnableInstalledCommand.php`
- Test: `tests/Unit/Console/Commands/Extensions/EnableInstalledCommandTest.php`

**Interfaces:**
- Produces:
  - `interface ProcessRunner { public function run(array $cmd, string $cwd, float $timeout, ?callable $onOutput = null): array; }` → `array{exitCode:int,output:string}`
  - `EnableInstalledCommand` = `extensions:enable-installed <package>`, prints a single JSON line `{"ok":bool,"provider"?:string,"error"?:string}`.

- [ ] **Step 1: Write the failing tests** — both the not-a-candidate contract AND (P2 fix) the **happy path**, since this command is the autoloader-freshness fix and must be proven to write config + recompile. Harness: build a temp app dir with `vendor/composer/installed.json` containing one `type: glueful-extension` package whose `extra.glueful.provider` is a real dummy provider class defined in the test namespace (so `class_exists` succeeds), and a temp `config/extensions.php` with `['enabled' => []]`; point the command's `ApplicationContext` at that base/config dir; register a **spy** `ExtensionManager` so `writeCacheNow()` calls are observable.

```php
public function test_emits_ok_false_json_when_package_not_a_candidate(): void
{
    $tester = $this->runCommand(['package' => 'glueful/does-not-exist']); // no candidate
    $decoded = json_decode(trim($tester->getDisplay()), true);
    $this->assertFalse($decoded['ok']);
    $this->assertNotEmpty($decoded['error']);
}

public function test_happy_path_writes_config_calls_writeCacheNow_and_emits_ok_true(): void
{
    $base = $this->makeTempAppWithCandidate(
        package: 'glueful/aegis',
        provider: \Glueful\Tests\Support\DummyAegisProvider::class, // real class the test defines
    );
    $spy = new SpyExtensionManager();
    $tester = $this->runCommand(['package' => 'glueful/aegis'], base: $base, extensions: $spy);

    // 1) JSON contract
    $decoded = json_decode(trim($tester->getDisplay()), true);
    $this->assertTrue($decoded['ok']);
    $this->assertSame(\Glueful\Tests\Support\DummyAegisProvider::class, $decoded['provider']);

    // 2) config/extensions.php now lists the provider FQCN
    $enabled = (require $base . '/config/extensions.php')['enabled'];
    $this->assertContains(\Glueful\Tests\Support\DummyAegisProvider::class, $enabled);

    // 3) the manifest cache was recompiled
    $this->assertSame(1, $spy->writeCacheNowCalls);
}
```

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement `ProcessRunner` + `SymfonyProcessRunner`**

```php
<?php
declare(strict_types=1);

namespace Glueful\Support\Process;

interface ProcessRunner
{
    /**
     * @param list<string> $cmd
     * @return array{exitCode:int,output:string}
     */
    public function run(array $cmd, string $cwd, float $timeout, ?callable $onOutput = null): array;
}
```

```php
<?php
declare(strict_types=1);

namespace Glueful\Support\Process;

use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $cmd, string $cwd, float $timeout, ?callable $onOutput = null): array
    {
        $process = new Process($cmd, $cwd);
        $process->setTimeout($timeout);
        $process->run(function (string $type, string $buffer) use ($onOutput): void {
            if ($onOutput !== null) {
                $onOutput($buffer);
            }
        });
        return [
            'exitCode' => $process->getExitCode() ?? 1,
            'output' => $process->getOutput() . $process->getErrorOutput(),
        ];
    }
}
```

- [ ] **Step 4: Implement `EnableInstalledCommand`** (no `APP_ENV=production` guard — gated upstream; mirrors `EnableCommand`'s resolve→preflight→write→recompile, printing JSON):

```php
<?php
declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionResolver;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\PackageManifest;
use Glueful\Support\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'extensions:enable-installed',
    description: 'Enable a just-installed extension (internal; runs in a fresh PHP process)'
)]
final class EnableInstalledCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('package', InputArgument::REQUIRED, 'Composer package, e.g. glueful/aegis');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = (string) $input->getArgument('package');
        $context = $this->getContext();

        $candidates = (new PackageManifest($context))->getCandidates();
        $candidate = $candidates[$package] ?? null;
        if ($candidate === null) {
            return $this->emit($output, false, error: "Package not installed or not a Glueful extension: {$package}");
        }
        $provider = $candidate->provider;

        $current = EnabledProviders::from($context);
        if (in_array($provider, $current, true)) {
            return $this->emit($output, true, provider: $provider);
        }

        $proposed = [...$current, $provider];
        $result = (new ExtensionResolver())->resolve($candidates, $proposed, Version::VERSION);
        if ($result->hasErrors()) {
            $msg = implode('; ', array_map(static fn($e) => "[{$e->kind}] {$e->message}", $result->errors));
            return $this->emit($output, false, error: $msg);
        }

        try {
            (new ExtensionStateWriter())->enable(config_path($context, 'extensions.php'), $provider);
            $this->getService(ExtensionManager::class)->writeCacheNow();
        } catch (\Throwable $e) {
            return $this->emit($output, false, error: $e->getMessage());
        }

        return $this->emit($output, true, provider: $provider);
    }

    private function emit(OutputInterface $output, bool $ok, ?string $provider = null, ?string $error = null): int
    {
        $output->writeln((string) json_encode(array_filter(
            ['ok' => $ok, 'provider' => $provider, 'error' => $error],
            static fn($v) => $v !== null,
        )));
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
```

- [ ] **Step 5: Run tests** — PASS.

---

### Task 6: `InstallRunCommand` — the detached runner

**Files:**
- Create: `src/Console/Commands/Extensions/InstallRunCommand.php`
- Test: `tests/Unit/Console/Commands/Extensions/InstallRunCommandTest.php`

**Interfaces:**
- Consumes: `InstallJobStore`, `ProcessRunner` (injected; default `SymfonyProcessRunner`).
- Command: `extensions:install-run <jobId> <package>`.

- [ ] **Step 1: Write the failing tests** (inject a **fake `ProcessRunner`** scripting exit codes/output for the composer call and the enable call; assert job transitions and that composer-failure short-circuits before enable):

```php
public function test_success_path_runs_composer_then_fresh_enable_and_finishes_succeeded(): void
{
    $store = new InstallJobStore(new ArrayCacheStore());
    $jobId = $store->create('glueful/aegis');
    $calls = [];
    $runner = new FakeProcessRunner(function (array $cmd) use (&$calls) {
        $calls[] = $cmd;
        if (in_array('require', $cmd, true)) return ['exitCode' => 0, 'output' => "Installing\n"];
        return ['exitCode' => 0, 'output' => json_encode(['ok' => true, 'provider' => 'X']) . "\n"];
    });
    $this->runCommand($store, $runner, ['jobId' => $jobId, 'package' => 'glueful/aegis']);

    $this->assertSame('succeeded', $store->get($jobId)['status']);
    $this->assertTrue(in_array('require', $calls[0], true));                    // composer first
    $this->assertContains('extensions:enable-installed', $calls[1]);            // fresh enable second
    $this->assertNull($store->get($jobId)['enableError']);
}

public function test_composer_failure_short_circuits_before_enable(): void
{
    $store = new InstallJobStore(new ArrayCacheStore());
    $jobId = $store->create('glueful/aegis');
    $calls = [];
    $runner = new FakeProcessRunner(function (array $cmd) use (&$calls) {
        $calls[] = $cmd;
        return ['exitCode' => 1, 'output' => "Could not resolve\n"];
    });
    $this->runCommand($store, $runner, ['jobId' => $jobId, 'package' => 'glueful/aegis']);

    $this->assertSame('failed', $store->get($jobId)['status']);
    $this->assertCount(1, $calls); // enable never ran
}

public function test_composer_ok_but_enable_fails_ends_installed_not_enabled(): void
{
    $store = new InstallJobStore(new ArrayCacheStore());
    $jobId = $store->create('glueful/aegis');
    $runner = new FakeProcessRunner(function (array $cmd) {
        if (in_array('require', $cmd, true)) return ['exitCode' => 0, 'output' => "Installing\n"];
        return ['exitCode' => 1, 'output' => json_encode(['ok' => false, 'error' => 'missing dependency']) . "\n"];
    });
    $this->runCommand($store, $runner, ['jobId' => $jobId, 'package' => 'glueful/aegis']);

    $rec = $store->get($jobId);
    $this->assertSame('installed_not_enabled', $rec['status']);       // NOT 'failed', NOT plain 'succeeded'
    $this->assertSame('missing dependency', $rec['enableError']);
}
```

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement `InstallRunCommand`**

```php
<?php
declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Install\InstallJobStore;
use Glueful\Support\Process\ComposerBinaryResolver;
use Glueful\Support\Process\ProcessRunner;
use Glueful\Support\Process\SymfonyProcessRunner;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'extensions:install-run',
    description: 'Run a detached extension install (composer require + fresh-process enable)'
)]
final class InstallRunCommand extends BaseCommand
{
    private ComposerBinaryResolver $composer;

    public function __construct(
        ?ContainerInterface $container = null,
        ?ApplicationContext $context = null,
        private ?ProcessRunner $runner = null,
        ?ComposerBinaryResolver $composer = null,
    ) {
        parent::__construct($container, $context);
        $this->runner ??= new SymfonyProcessRunner();
        $this->composer = $composer ?? new ComposerBinaryResolver();
    }

    protected function configure(): void
    {
        $this->addArgument('jobId', InputArgument::REQUIRED);
        $this->addArgument('package', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = (string) $input->getArgument('jobId');
        $package = (string) $input->getArgument('package');
        $context = $this->getContext();
        $store = $this->getService(InstallJobStore::class);
        $cwd = base_path($context);

        $store->markRunning($jobId);

        $composer = $this->composer->resolve(); // SAME resolver as preflight (honors COMPOSER_BINARY)
        if ($composer === null) {
            $store->finish($jobId, 'failed', null, 'composer binary not found');
            return self::FAILURE;
        }
        $timeout = (float) config('extensions.install.timeout', 600);
        $install = $this->runner->run(
            [$composer, 'require', $package, '--no-interaction', '--no-progress'],
            $cwd,
            $timeout,
            static fn(string $chunk) => $store->appendOutput($jobId, $chunk),
        );

        if ($install['exitCode'] !== 0) {
            $store->finish($jobId, 'failed', $install['exitCode'], 'composer require failed');
            return self::FAILURE;
        }

        $enableError = null;
        if ((bool) config('extensions.install.auto_enable', true)) {
            $enable = $this->runner->run(
                [PHP_BINARY, base_path($context, 'glueful'), 'extensions:enable-installed', $package],
                $cwd,
                120.0,
                static fn(string $chunk) => $store->appendOutput($jobId, $chunk),
            );
            $decoded = $this->lastJsonLine($enable['output']);
            if (($decoded['ok'] ?? false) !== true) {
                $enableError = $decoded['error'] ?? 'enable failed';
            }
        }

        // Distinct terminal states: `succeeded` = installed AND enabled (or auto-enable
        // off with no error); `installed_not_enabled` = composer installed but the
        // auto-enable step failed (e.g. a missing dependency) — not a hard failure.
        if ($enableError !== null) {
            $store->finish($jobId, 'installed_not_enabled', 0, null, $enableError);
            return self::SUCCESS;
        }
        $store->finish($jobId, 'succeeded', 0);
        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function lastJsonLine(string $output): array
    {
        foreach (array_reverse(array_filter(array_map('trim', explode("\n", $output)))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}
```

- [ ] **Step 4: Run tests** — PASS.

- [ ] **Step 5: Commit Phase 2**

```bash
git add src/Support/Process/ src/Console/Commands/Extensions/EnableInstalledCommand.php \
        src/Console/Commands/Extensions/InstallRunCommand.php tests/Unit/Support/Process/ \
        tests/Unit/Console/Commands/Extensions/
git commit -m "extensions installer: detached runner, fresh-process enabler, install-run command"
```

---

## Phase 3 — Catalog + installer orchestration

### Task 7: `ExtensionCatalog`

**Files:**
- Create: `src/Extensions/ExtensionCatalog.php`
- Test: `tests/Unit/Extensions/ExtensionCatalogTest.php`

**Interfaces:**
- Consumes: `Glueful\Http\Client`, `Glueful\Cache\CacheStore`, `Glueful\Extensions\ExtensionManager`, `ApplicationContext`.
- Produces:
  - `catalog(bool $refresh = false): array` — `list<array{package,description,version,downloads,repository,state}>`
  - `installed(): array` — `list<array{package,provider,version,label,state}>`
  - `state ∈ {available, installed, enabled}` (catalog) / `{enabled, available, enabled_missing}` (installed)

- [ ] **Step 1: Write the failing test** — stub `Client` to return a search payload then a p2 payload; assert only `glueful/` + `type: glueful-extension` rows survive, `version` is hydrated, and state reflects installed/enabled cross-reference (inject a fake candidates+enabled source). Representative assertion:

```php
public function test_catalog_filters_to_glueful_extension_type_and_hydrates_version(): void
{
    $http = new FakeClient([
        'https://packagist.org/search.json?type=glueful-extension&per_page=100' => [
            'results' => [
                ['name' => 'glueful/aegis', 'description' => 'Auth', 'downloads' => 10, 'repository' => 'r'],
                ['name' => 'other/thing',  'description' => 'No',   'downloads' => 1,  'repository' => 'r'],
            ],
        ],
        'https://repo.packagist.org/p2/glueful/aegis.json' => [
            'packages' => ['glueful/aegis' => [['version' => '1.2.0', 'type' => 'glueful-extension']]],
        ],
    ]);
    $catalog = $this->makeCatalog($http, installed: [], enabled: []);
    $rows = $catalog->catalog(refresh: true);
    $this->assertCount(1, $rows);
    $this->assertSame('glueful/aegis', $rows[0]['package']);
    $this->assertSame('1.2.0', $rows[0]['version']);
    $this->assertSame('available', $rows[0]['state']);
}
```

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement `ExtensionCatalog`** (two-stage fetch → hydrate → cache → cross-reference). Key methods:

```php
<?php
declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Http\Client;

final class ExtensionCatalog
{
    private const CACHE_KEY = 'ext_catalog:glueful';
    private const CACHE_TTL = 3600;
    private const VENDOR = 'glueful/';

    public function __construct(
        private ApplicationContext $context,
        private Client $http,
        private CacheStore $cache,
        private ExtensionManager $extensions,
    ) {}

    /** @return list<array{package:string,description:string,version:?string,downloads:int,repository:string,state:string}> */
    public function catalog(bool $refresh = false): array
    {
        $packages = $refresh ? null : $this->cache->get(self::CACHE_KEY);
        if (!is_array($packages)) {
            $packages = $this->fetchFromPackagist();
            $this->cache->set(self::CACHE_KEY, $packages, self::CACHE_TTL);
        }
        $installed = $this->installedMap();       // package => provider
        $enabled = EnabledProviders::from($this->context);

        return array_map(function (array $p) use ($installed, $enabled): array {
            $state = 'available';
            if (isset($installed[$p['package']])) {
                $state = in_array($installed[$p['package']], $enabled, true) ? 'enabled' : 'installed';
            }
            return [...$p, 'state' => $state];
        }, $packages);
    }

    /** @return list<array{package:string,provider:string,version:?string,label:string,state:string}> */
    public function installed(): array
    {
        $candidates = (new PackageManifest($this->context))->getCandidates();
        $enabled = EnabledProviders::from($this->context);
        $meta = $this->extensions->listMeta();

        $rows = [];
        foreach ($candidates as $package => $c) {
            $rows[] = [
                'package' => $package,
                'provider' => $c->provider,
                'version' => $c->version,
                'label' => $meta[$c->provider]['name'] ?? $package,
                'state' => in_array($c->provider, $enabled, true) ? 'enabled' : 'available',
            ];
        }
        // enabled-but-missing: in allow-list but not an installed candidate.
        $known = array_map(static fn($c) => $c->provider, $candidates);
        foreach ($enabled as $provider) {
            if (!in_array($provider, $known, true)) {
                $rows[] = ['package' => $provider, 'provider' => $provider, 'version' => null,
                           'label' => $provider, 'state' => 'enabled_missing'];
            }
        }
        return $rows;
    }

    /** @return array<string,string> package => provider (installed candidates) */
    private function installedMap(): array
    {
        $out = [];
        foreach ((new PackageManifest($this->context))->getCandidates() as $package => $c) {
            $out[$package] = $c->provider;
        }
        return $out;
    }

    /** @return list<array{package:string,description:string,version:?string,downloads:int,repository:string}> */
    private function fetchFromPackagist(): array
    {
        $names = $this->searchNames();       // stage 1: names + summary
        $rows = [];
        foreach ($names as $name => $summary) {
            $meta = $this->hydrate($name);   // stage 2: version + type re-verify
            if ($meta === null) {
                continue;                    // not type glueful-extension
            }
            $rows[] = [
                'package' => $name,
                'description' => (string) ($summary['description'] ?? ''),
                'version' => $meta['version'],
                'downloads' => (int) ($summary['downloads'] ?? 0),
                'repository' => (string) ($summary['repository'] ?? ''),
            ];
        }
        return $rows;
    }

    /** @return array<string,array<string,mixed>> name => summary, vendor-filtered */
    private function searchNames(): array
    {
        $url = 'https://packagist.org/search.json?type=glueful-extension&per_page=100';
        $out = [];
        while ($url !== null) {
            $json = $this->http->get($url)->json();
            foreach (($json['results'] ?? []) as $r) {
                $name = (string) ($r['name'] ?? '');
                if (str_starts_with($name, self::VENDOR)) {
                    $out[$name] = $r;
                }
            }
            $url = isset($json['next']) ? (string) $json['next'] : null;
        }
        return $out;
    }

    /** @return array{version:?string}|null null when not a glueful-extension */
    private function hydrate(string $name): ?array
    {
        $json = $this->http->get("https://repo.packagist.org/p2/{$name}.json")->json();
        $versions = $json['packages'][$name] ?? [];
        $latestStable = null;
        foreach ($versions as $v) {
            if (($v['type'] ?? null) !== 'glueful-extension') {
                return null;
            }
            $ver = (string) ($v['version'] ?? '');
            if ($ver !== '' && !str_contains($ver, 'dev') && $latestStable === null) {
                $latestStable = $ver;    // p2 lists newest first
            }
        }
        return $versions === [] ? null : ['version' => $latestStable];
    }
}
```

- [ ] **Step 4: Run tests** — PASS.

---

### Task 8: `ExtensionInstaller` + install exceptions

**Files:**
- Create: `src/Extensions/Install/ExtensionInstaller.php`
- Create: `src/Extensions/Install/InstallDisabledException.php`, `HostNotWritableException.php`, `PackageNotAllowedException.php`
- Test: `tests/Unit/Extensions/Install/ExtensionInstallerTest.php`

**Interfaces:**
- Consumes: `ApplicationContext`, `ExtensionCatalog`, `InstallJobStore`, `HostCapability`, `DetachedRunner`.
- Produces:
  - `start(string $package): array{jobId:string,status:string}`
  - Throws `InstallDisabledException` (kill-switch), `HostNotWritableException` (has `->reason`/`->detail`), `PackageNotAllowedException`.

- [ ] **Step 1: Write the failing tests** — validation order + happy path (fake `DetachedRunner` records the spawn; no real process):

```php
public function test_rejects_flag_like_and_non_glueful_and_non_catalog_packages(): void
{
    $inst = $this->makeInstaller(catalog: ['glueful/aegis']);
    foreach (['--evil', 'evil/thallo', 'glueful/not-in-catalog', 'GLUEFUL/Aegis'] as $bad) {
        try { $inst->start($bad); $this->fail("accepted $bad"); }
        catch (PackageNotAllowedException) { $this->addToCount(1); }
    }
}

public function test_happy_path_creates_job_and_spawns_detached(): void
{
    $spawned = [];
    $runner = new FakeDetachedRunner(function (string $jobId, string $pkg) use (&$spawned) { $spawned[] = [$jobId, $pkg]; });
    $inst = $this->makeInstaller(catalog: ['glueful/aegis'], runner: $runner, killSwitch: true, hostOk: true);
    $res = $inst->start('glueful/aegis');
    $this->assertSame('queued', $res['status']);
    $this->assertSame('glueful/aegis', $spawned[0][1]);
}

public function test_kill_switch_off_throws(): void
{
    $inst = $this->makeInstaller(catalog: ['glueful/aegis'], killSwitch: false);
    $this->expectException(InstallDisabledException::class);
    $inst->start('glueful/aegis');
}
```

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement the exceptions + installer**

```php
<?php
declare(strict_types=1);

namespace Glueful\Extensions\Install;

final class HostNotWritableException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $detail)
    {
        parent::__construct("Host not writable ({$reason}): {$detail}");
    }
}
```
*(`InstallDisabledException` and `PackageNotAllowedException` are trivial `\RuntimeException` subclasses.)*

```php
<?php
declare(strict_types=1);

namespace Glueful\Extensions\Install;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionCatalog;
use Glueful\Support\Process\DetachedRunner;

final class ExtensionInstaller
{
    private const NAME_GRAMMAR = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9]([_.-]?[a-z0-9]+)*$/';

    public function __construct(
        private ApplicationContext $context,
        private ExtensionCatalog $catalog,
        private InstallJobStore $jobs,
        private HostCapability $host,
        private DetachedRunner $runner,
    ) {}

    /** @return array{jobId:string,status:string} */
    public function start(string $package): array
    {
        if (!(bool) config('extensions.install.enabled', false)) {
            throw new InstallDisabledException('Extension installation is disabled.');
        }
        if (($cap = $this->host->forInstall()) !== null) {
            throw new HostNotWritableException($cap['reason'], $cap['detail']);
        }
        $this->assertAllowed($package);

        $jobId = $this->jobs->create($package);
        $this->runner->spawnInstall($jobId, $package);
        return ['jobId' => $jobId, 'status' => 'queued'];
    }

    private function assertAllowed(string $package): void
    {
        $vendor = (string) config('extensions.install.vendor', 'glueful/');
        if (
            !str_starts_with($package, $vendor)
            || $package[0] === '-'
            || preg_match(self::NAME_GRAMMAR, $package) !== 1
        ) {
            throw new PackageNotAllowedException("Invalid or non-{$vendor} package: {$package}");
        }
        $inCatalog = array_filter($this->catalog->catalog(), static fn($r) => $r['package'] === $package);
        if ($inCatalog === []) {
            throw new PackageNotAllowedException("Not an installable Glueful extension: {$package}");
        }
    }
}
```

- [ ] **Step 4: Run tests** — PASS.

- [ ] **Step 5: Commit Phase 3**

```bash
git add src/Extensions/ExtensionCatalog.php src/Extensions/Install/ExtensionInstaller.php \
        src/Extensions/Install/*Exception.php tests/Unit/Extensions/
git commit -m "extensions installer: packagist catalog + installer orchestration"
```

---

## Phase 4 — HTTP surface

### Task 9: Request DTOs

**Files:**
- Create: `src/DTOs/ExtensionInstallData.php`, `src/DTOs/ExtensionToggleData.php`
- Test: `tests/Unit/DTOs/ExtensionDataTest.php`

**Interfaces:**
- Produces: `ExtensionInstallData { string $package }`, `ExtensionToggleData { string $package }` (both `RequestData` + `#[Rule]`).

- [ ] **Step 1: Write the failing test** — a `422`-triggering empty payload validates false via the framework validator harness; a valid payload hydrates `$package`. (Follow the existing `RefreshTokenData` test pattern in the suite.)

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class ExtensionInstallData implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:150')]
        public readonly string $package,
    ) {}
}
```
*(`ExtensionToggleData` is identical with the same single `package` field.)*

- [ ] **Step 4: Run tests** — PASS.

---

### Task 10: `ExtensionsController` + service registration

**Files:**
- Modify: `src/Controllers/ExtensionsController.php` (repurpose the unrouted controller)
- Modify: `src/Container/Providers/CoreProvider.php` (register new services)
- Test: `tests/Feature/Extensions/ExtensionsControllerTest.php`

**Interfaces:**
- Consumes: `ExtensionCatalog`, `ExtensionInstaller`, `InstallJobStore`, `HostCapability`, `EnabledProviders`/`ExtensionStateWriter`/`ExtensionManager` (for enable/disable), `LogManager`.
- Produces: controller actions `index`, `catalog`, `install`, `installStatus`, `enable`, `disable`.

- [ ] **Step 1: Write the failing integration tests** — with the installer/catalog faked (no real composer): `403` without `system.config`, `403` kill-switch off, `409` host unwritable, `201 {jobId}` happy path, `422` bad package, `installStatus` 200/404, `enable`/`disable` call `ExtensionStateWriter`+`writeCacheNow` (spy) and `409` when config/cache unwritable, `422` on resolver error. Representative:

```php
public function test_install_returns_201_with_jobId_on_happy_path(): void
{
    $this->actingAsSystemConfigAdmin();
    $this->swap(ExtensionInstaller::class, new FakeInstaller(['jobId' => 'abc', 'status' => 'queued']));
    $res = $this->postJson('/api/v1/extensions/install', ['package' => 'glueful/aegis']);
    $res->assertStatus(201)->assertJsonPath('data.jobId', 'abc');
}

public function test_install_403_when_kill_switch_off(): void
{
    $this->actingAsSystemConfigAdmin();
    $this->swap(ExtensionInstaller::class, new FakeInstaller(throws: new InstallDisabledException('off')));
    $this->postJson('/api/v1/extensions/install', ['package' => 'glueful/aegis'])->assertStatus(403);
}
```

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement the controller** (repurpose the existing `ExtensionsController`, keeping `BaseController` conventions; map installer exceptions to HTTP):

```php
<?php
declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\DTOs\ExtensionInstallData;
use Glueful\DTOs\ExtensionToggleData;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionCatalog;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionResolver;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\Install\ExtensionInstaller;
use Glueful\Extensions\Install\HostCapability;
use Glueful\Extensions\Install\HostNotWritableException;
use Glueful\Extensions\Install\InstallDisabledException;
use Glueful\Extensions\Install\InstallJobStore;
use Glueful\Extensions\Install\PackageNotAllowedException;
use Glueful\Extensions\PackageManifest;
use Glueful\Http\Response;
use Glueful\Logging\LogManager;
use Glueful\Support\Version;

final class ExtensionsController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private ExtensionCatalog $catalog,
        private ExtensionInstaller $installer,
        private InstallJobStore $jobs,
        private HostCapability $host,
        private ExtensionManager $extensions,
        private LogManager $log,
    ) {
        parent::__construct($context);
    }

    public function index(): Response
    {
        $this->requirePermission('system.config.view');
        return $this->success(['installed' => $this->catalog->installed()]);
    }

    public function catalog(): Response
    {
        $this->requirePermission('system.config.view');
        return $this->success(['catalog' => $this->catalog->catalog()]);
    }

    public function install(ExtensionInstallData $data): Response
    {
        $this->requirePermission('system.config.edit');
        try {
            $result = $this->installer->start($data->package);
        } catch (InstallDisabledException $e) {
            return $this->forbidden($e->getMessage());
        } catch (HostNotWritableException $e) {
            return Response::error($e->getMessage(), 409, ['reason' => $e->reason]);
        } catch (PackageNotAllowedException $e) {
            return $this->validationError(['package' => [$e->getMessage()]]);
        }
        $this->audit('extension.install', $data->package, 'queued');
        return $this->created($result, 'Install started');
    }

    public function installStatus(string $jobId): Response
    {
        $this->requirePermission('system.config.view');
        $rec = $this->jobs->get($jobId);
        return $rec === null ? $this->notFound('Unknown install job') : $this->success($rec);
    }

    public function enable(ExtensionToggleData $data): Response
    {
        return $this->toggle($data->package, enable: true);
    }

    public function disable(ExtensionToggleData $data): Response
    {
        return $this->toggle($data->package, enable: false);
    }

    private function toggle(string $package, bool $enable): Response
    {
        $this->requirePermission('system.config.edit');
        if (($cap = $this->host->forToggle()) !== null) {
            return Response::error('Host not writable', 409, ['reason' => $cap['reason']]);
        }
        $candidates = (new PackageManifest($this->context))->getCandidates();
        $candidate = $candidates[$package] ?? null;
        if ($candidate === null) {
            return $this->notFound("Not an installed extension: {$package}");
        }
        $provider = $candidate->provider;
        $current = EnabledProviders::from($this->context);
        $proposed = $enable
            ? [...$current, $provider]
            : array_values(array_filter($current, static fn($p) => $p !== $provider));

        $result = (new ExtensionResolver())->resolve($candidates, $proposed, Version::VERSION);
        if ($result->hasErrors()) {
            return $this->validationError(['extension' => array_map(
                static fn($e) => "[{$e->kind}] {$e->message}",
                $result->errors,
            )]);
        }
        $writer = new ExtensionStateWriter();
        $configPath = config_path($this->context, 'extensions.php');
        $enable ? $writer->enable($configPath, $provider) : $writer->disable($configPath, $provider);
        $this->extensions->writeCacheNow();

        $this->audit($enable ? 'extension.enable' : 'extension.disable', $package, 'succeeded');
        return $this->success(['package' => $package, 'provider' => $provider,
                               'state' => $enable ? 'enabled' : 'available']);
    }

    private function audit(string $action, string $package, string $result): void
    {
        $this->log->channel('audit')->info($action, [
            'action' => $action,
            'actor_id' => $this->getCurrentUserUuid(),   // BaseController/CachedUserContextTrait helper
            'resource_type' => 'extension',
            'resource_id' => $package,
            'result' => $result,
        ]);
    }
}
```
*(Confirm the exact `Response::error(...)` signature and the current-user accessor name from `BaseController`/`CachedUserContextTrait`; adjust if the helper is `getUserUuid()`.)*

- [ ] **Step 4: Register services in `CoreProvider::defs()`** — most autowire cleanly (all ctor deps are container-registered: `ApplicationContext`, `Client`, `CacheStore`, `ExtensionManager`, `LogManager`):

```php
$defs[\Glueful\Support\Process\ComposerBinaryResolver::class] = $this->autowire(\Glueful\Support\Process\ComposerBinaryResolver::class);
$defs[\Glueful\Extensions\Install\HostCapability::class]   = $this->autowire(\Glueful\Extensions\Install\HostCapability::class);
$defs[\Glueful\Extensions\Install\InstallJobStore::class]  = $this->autowire(\Glueful\Extensions\Install\InstallJobStore::class);
$defs[\Glueful\Support\Process\DetachedRunner::class]      = $this->autowire(\Glueful\Support\Process\DetachedRunner::class);
$defs[\Glueful\Extensions\ExtensionCatalog::class]         = $this->autowire(\Glueful\Extensions\ExtensionCatalog::class);
$defs[\Glueful\Extensions\Install\ExtensionInstaller::class] = $this->autowire(\Glueful\Extensions\Install\ExtensionInstaller::class);
```
*(`InstallJobStore` needs the `cache.store` binding for its `CacheStore` param — if autowire can't resolve the interface, add a `FactoryDefinition` that passes `$c->get('cache.store')`.)*

- [ ] **Step 5: Run tests** — PASS.

---

### Task 11: Routes + manifest + command cache

**Files:**
- Create: `routes/extensions.php`
- Modify: `src/Routing/RouteManifest.php`
- Test: `tests/Feature/Extensions/ExtensionsRouteTest.php`

- [ ] **Step 1: Write the failing test** — assert `GET /api/v1/extensions` resolves (200 for an authorized `system.config` user; 401/403 otherwise), proving the route is mounted.

- [ ] **Step 2: Run to verify failure** — FAIL (404, route not registered).

- [ ] **Step 3: Create `routes/extensions.php`**

```php
<?php
use Glueful\Controllers\ExtensionsController;
use Glueful\Routing\Router;

/** @var Router $router */
$router->group(['prefix' => '/extensions'], function (Router $router): void {
    $router->get('/', [ExtensionsController::class, 'index'])->middleware(['auth', 'rate_limit:60,60']);
    $router->get('/catalog', [ExtensionsController::class, 'catalog'])->middleware(['auth', 'rate_limit:30,60']);
    $router->post('/install', [ExtensionsController::class, 'install'])->middleware(['auth', 'rate_limit:10,60']);
    $router->get('/install/{jobId}', [ExtensionsController::class, 'installStatus'])->middleware(['auth', 'rate_limit:120,60']);
    $router->post('/enable', [ExtensionsController::class, 'enable'])->middleware(['auth', 'rate_limit:20,60']);
    $router->post('/disable', [ExtensionsController::class, 'disable'])->middleware(['auth', 'rate_limit:20,60']);
});
```

- [ ] **Step 4: Register it in `RouteManifest::generate()`** — add to the `api_routes` array:

```php
'api_routes' => [
    '/routes/auth.php',
    '/routes/blobs.php',
    '/routes/resource.php',
    '/routes/extensions.php',
],
```

- [ ] **Step 5: Refresh the command manifest** so the two new console commands are discoverable — Run: `php glueful commands:cache`. Expected: manifest rebuilt including `extensions:install-run` and `extensions:enable-installed` (`php glueful list | grep extensions`).

- [ ] **Step 6: Run tests + quality gates**

```bash
vendor/bin/phpunit tests/Feature/Extensions tests/Unit/Extensions tests/Unit/Support/Process
composer run phpcs
composer run analyse:changed
```
Expected: green.

- [ ] **Step 7: Commit Phase 4**

```bash
git add src/DTOs/ExtensionInstallData.php src/DTOs/ExtensionToggleData.php \
        src/Controllers/ExtensionsController.php src/Container/Providers/CoreProvider.php \
        routes/extensions.php src/Routing/RouteManifest.php tests/Feature/Extensions/ tests/Unit/DTOs/
git commit -m "extensions installer: HTTP controller, DTOs, routes"
```

---

## Phase 5 — Thallo admin UI (repo: `glueful/lemma`) — separable follow-on

> This phase lives in a **different repo** (`/Users/michaeltawiahsowah/Sites/glueful/lemma`) and depends on the framework API from Phases 1–4 being released/linked. It can be executed as its own plan. Follows the `settings/email` page patterns.

### Task 12: `queries/extensions.ts`

**Files:**
- Create: `admin/src/queries/extensions.ts`
- Test: covered via Task 14.

**Interfaces:**
- Produces: `fetchInstalled()`, `fetchCatalog()`, `installExtension(package)`, `fetchInstallJob(jobId)`, `enableExtension(package)`, `disableExtension(package)`; types `ExtensionRow`, `CatalogRow`, `InstallJob`.

- [ ] **Step 1: Implement** (mirror `queries/email.ts`; confirm the mount — API-prefixed per `RouteManifest`, so use `${runtimeConfig.apiBase}/extensions/...`, NOT root-mounted):

```ts
import { authFetch } from '@/api/authFetch'

export interface ExtensionRow { package: string; provider: string; version: string | null; label: string; state: 'enabled' | 'available' | 'enabled_missing' }
export interface CatalogRow { package: string; description: string; version: string | null; downloads: number; repository: string; state: 'available' | 'installed' | 'enabled' }
export interface InstallJob { id: string; package: string; status: 'queued' | 'running' | 'succeeded' | 'failed' | 'installed_not_enabled'; output: string; exitCode: number | null; error: string | null; enableError: string | null }

const base = '/api/v1/extensions'  // confirm apiBase prefix at implementation time

export async function fetchInstalled(): Promise<ExtensionRow[]> {
  const json = await authFetch(`${base}`)
  return (json.data?.installed ?? []) as ExtensionRow[]
}
export async function fetchCatalog(): Promise<CatalogRow[]> {
  const json = await authFetch(`${base}/catalog`)
  return (json.data?.catalog ?? []) as CatalogRow[]
}
export async function installExtension(pkg: string): Promise<{ jobId: string }> {
  const json = await authFetch(`${base}/install`, { method: 'POST', body: JSON.stringify({ package: pkg }) })
  return (json.data ?? json) as { jobId: string }
}
export async function fetchInstallJob(jobId: string): Promise<InstallJob> {
  const json = await authFetch(`${base}/install/${encodeURIComponent(jobId)}`)
  return (json.data ?? json) as InstallJob
}
export async function enableExtension(pkg: string): Promise<void> {
  await authFetch(`${base}/enable`, { method: 'POST', body: JSON.stringify({ package: pkg }) })
}
export async function disableExtension(pkg: string): Promise<void> {
  await authFetch(`${base}/disable`, { method: 'POST', body: JSON.stringify({ package: pkg }) })
}
```

### Task 13: `pages/developers/extensions/index.vue`

**Files:**
- Create: `admin/src/pages/developers/extensions/index.vue`

- [ ] **Step 1: Implement** two sections — **Installed** (rows with enable/disable toggles + `state` badges; `enabled_missing` shown as a warning) and **Browse** (catalog cards with Install buttons). Install → `installExtension` → poll `fetchInstallJob` every ~1500ms until a **terminal** `status ∈ {succeeded, failed, installed_not_enabled}`, show streaming `output` in a `UCollapsible`, then refresh the installed list. Terminal display:
  - `succeeded` → success toast, row flips to `enabled`.
  - `installed_not_enabled` → **warning** toast/badge "Installed but not enabled" showing `enableError` (e.g. "needs a dependency"); the row appears in Installed as `available` with an Enable button (so the operator can resolve the dependency and enable manually). This is NOT rendered as a failure.
  - `failed` → error toast showing `error` + the output tail.

  A `403` on install/toggle hides the affordance with a note; a `409` (`reason: read_only_filesystem`) shows "install on deploy". Use `definePage({ meta: { requiresAuth: true } })`. No CodeMirror. (Follow the `settings/email/index.vue` structure: `UDashboardPanel`/`UDashboardNavbar`, `UCard`, `data-test` hooks on every actionable element.)

### Task 14: `extensionsPage.spec.ts`

**Files:**
- Create: `admin/src/__tests__/extensionsPage.spec.ts`

- [ ] **Step 1: Write tests** (mock `@/queries/extensions`, per `emailSettingsPage.spec.ts`): renders installed rows + state badges; renders catalog cards; **install → poll → enabled transition** (advance the poll with fake timers, second `fetchInstallJob` returns `succeeded`, assert installed list refetched and row shows `enabled`); **install → poll → `installed_not_enabled`** (second poll returns `installed_not_enabled` + `enableError`; assert a *warning* — not error — is shown, the `enableError` text renders, and the row appears as `available` with an Enable button); enable/disable call the query; `403` hides install with a note; `409 read_only_filesystem` shows "install on deploy". Assert via `data-test` hooks (Nuxt UI portals/unstubbable components — never assert dropdown DOM).

- [ ] **Step 2: Run** — Run: `pnpm --filter admin test` (or the repo's vitest script). Expected: green. Also run `pnpm --filter admin type-check`.

- [ ] **Step 3: Commit (lemma repo)**

```bash
git add admin/src/queries/extensions.ts admin/src/pages/developers/extensions/index.vue admin/src/__tests__/extensionsPage.spec.ts
git commit -m "admin: extensions manager page (browse, install, enable/disable)"
```

---

## Self-Review Notes

- **Spec coverage:** every spec §5 component maps to a task (HostCapability→T2, InstallJobStore→T3, DetachedRunner→T4, ProcessRunner/EnableInstalled→T5, InstallRun→T6, Catalog→T7, Installer→T8, DTOs→T9, Controller/registration→T10, routes→T11, UI→T12–14). Security controls (§4): kill-switch T1/T8, host-capability T2/T8/T10, allowlist T8, no-shell T4, audit T10, permission T10.
- **Type consistency:** job record shape identical across T3/T6/T10/T12, including the five-value status enum `queued|running|succeeded|failed|installed_not_enabled` (T3 doc ↔ T6 `finish()` ↔ T12 TS union ↔ T13/T14 UI); `catalog()`/`installed()` row shapes match T7↔T10↔T12; `start(): {jobId,status}` consistent T8↔T10.
- **Single-source invariants (review fixes):** composer binary resolves through one `ComposerBinaryResolver` in both preflight (T2/HostCapability) and the runner (T6) — no divergent `ExecutableFinder` call; host-capability checks the nearest existing ancestor for missing paths (T2), so a missing `vendor/`/`composer.lock` under a read-only base fails preflight; the real `proc_open`/no-`proc_close` path is covered by a live child-process test (T4), not only the injected closure.
- **Confirm-at-implementation (non-blocking):** exact `Response::error()` signature and the current-user accessor name in `BaseController`; whether `InstallJobStore`'s `CacheStore` param autowires or needs a `FactoryDefinition` binding `cache.store`; the Thallo `apiBase` prefix for `queries/extensions.ts`.
