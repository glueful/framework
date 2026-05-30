# Extension System Re-Architecture — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 4-source / multi-key extension-loading model with **composer-only discovery + a single `enabled` allow-list + a pure resolver**, fixing the incoherent CLI control surface, the dev/prod parity hazard, and the opaque mental model.

**Architecture:** `PackageManifest` discovers composer candidates (with `requires` meta). A pure `ExtensionResolver` selects from `extensions.enabled`, validates (missing provider/dependency, framework version, cycle), and topologically orders → `{providers, errors}`. App service providers (`serviceproviders.enabled`) are a separate flat always-loaded list (not discovered). `ExtensionManager` boots `[app providers] ++ [resolved extensions]` and compiles to `bootstrap/cache/extensions.php` (strict at build, lenient at runtime, prod-fail-if-missing). `ProviderLocator`, the local-folder scan, and runtime PSR-4 registration are deleted.

**Tech Stack:** PHP 8.3, PHPUnit 10, Composer (path repositories for local dev). Spec: `docs/superpowers/specs/2026-05-30-extension-system-rearchitecture-design.md` (authoritative).

**Conventions:**
- Run one test: `vendor/bin/phpunit --filter="testName"`; a file: `vendor/bin/phpunit path/to/Test.php`.
- Style: `composer run phpcs`; static: `composer run analyse` (level 6 gate).
- Commits: this repo does **not** use `Co-Authored-By` trailers. Batch commits at logical groupings (per-task commits below are the natural groupings).
- Pure units extend `PHPUnit\Framework\TestCase` in namespace `Glueful\Tests\Unit\Extensions\...`; integration uses the path-constructible `ApplicationContext` + (where needed) a booted container.
- **Config-reading test harness (use this exact wiring whenever a test needs `config($ctx, ...)` to read temp files).** A bare `new ApplicationContext($base, 'testing')` returns config *defaults* — `config()` only reads files once a loader is attached. So:

  ```php
  use Glueful\Bootstrap\ApplicationContext;
  use Glueful\Bootstrap\ConfigurationLoader;

  $base = sys_get_temp_dir() . '/glueful-XXX-' . uniqid('', true);
  @mkdir($base . '/config', 0777, true);
  file_put_contents($base . '/config/extensions.php', "<?php\nreturn ['enabled' => []];\n");

  $ctx = new ApplicationContext($base, 'testing');
  $ctx->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));
  // now config($ctx, 'extensions.enabled', []) reads $base/config/extensions.php
  ```

  (Tests that read `vendor/composer/installed.php` via `PackageManifest` use `base_path()` off `$base` and do **not** need a config loader.)

---

## File Structure

**New files (framework):**

| Path | Responsibility |
|---|---|
| `src/Extensions/ExtensionCandidate.php` | Immutable value object: `name`, `provider` (FQCN string), `requiresGlueful` (?string constraint), `requiresExtensions` (list<string> provider FQCNs). |
| `src/Extensions/ExtensionResolver.php` | **Pure** resolver: `resolve(array $candidates, array $enabled, string $frameworkVersion): ResolverResult`. Select → validate → topo-sort. Never throws. |
| `src/Extensions/ResolverResult.php` | Immutable: `providers` (ordered list<string>), `errors` (list<ResolverError>). |
| `src/Extensions/ResolverError.php` | Immutable: `kind` (enum-like const), `provider`, `message`. |
| `src/Extensions/AppProviderLoader.php` | Reads `serviceproviders.enabled` → flat ordered list<string>. No discovery/validation. |
| `src/Extensions/ProviderClassResolver.php` | **Single orchestration unit** combining `AppProviderLoader` + `PackageManifest` + `ExtensionResolver` → `ResolverResult` (providers = `[app] ++ [extensions]`, plus errors). Used by **both** `ExtensionManager` and `ContainerFactory` so there is one resolution path, not two copies. |
| `src/Extensions/ExtensionStateWriter.php` | Edits `config/extensions.php` `enabled` string-list (add/remove/sort); refuses non-trivial arrays (after stripping comments); `--dry-run`/`--backup`. |

**Dependency:** add `composer/semver` to `composer.json` `require` (Task 2) — used by `ExtensionResolver` for `requires.glueful` constraint matching. It is explicit, not relied upon transitively.

**Modified (framework):**

| Path | Change |
|---|---|
| `src/Extensions/PackageManifest.php` | Add `getCandidates(): array<string, ExtensionCandidate>` capturing `requires`. Keep `getGluefulProviders()` for now (used until callers migrate), remove at end. |
| `src/Extensions/ExtensionManager.php` | Resolve via `ExtensionResolver` + `AppProviderLoader` instead of `ProviderLocator`; strict/lenient cache; prod-fail-if-missing; expose resolver errors for CLI. |
| `src/Container/Bootstrap/ContainerFactory.php` | `loadExtensionDefinitions()` iterates `[app providers] ++ [resolved extensions]` instead of `ProviderLocator::all()`. |
| `src/Console/Commands/Extensions/EnableCommand.php` | Route through `ExtensionStateWriter`; string FQCNs; recompile (strict). |
| `src/Console/Commands/Extensions/DisableCommand.php` | Remove from `enabled` via `ExtensionStateWriter`; recompile. |
| `src/Console/Commands/Extensions/ListCommand.php` | Show state (`enabled ✓`/`available ○`/`enabled-but-missing ⚠`) + fold in `why`. |
| `src/Console/Commands/Extensions/CacheCommand.php` | Strict: fail on resolver errors. |
| `src/Console/Commands/Extensions/InfoCommand.php` | Show `requires` + state. |
| `src/Console/Commands/Extensions/DiagnoseCommand.php` | Surface resolver errors + cache-present-in-prod. |
| `src/Console/Commands/Extensions/CreateCommand.php` | Scaffold composer package + register path repo; **print** the `composer require` + `extensions:enable` commands (does not run Composer itself). |
| `config/extensions.php` | Single `enabled` array of string FQCNs. |
| `config/serviceproviders.php` | Single `enabled` array of string FQCNs. |

**Deleted (framework):** `src/Extensions/ProviderLocator.php`, `src/Console/Commands/Extensions/WhyCommand.php`.

**api-skeleton:** add Composer path repositories + `composer require` the extensions; rewrite `config/extensions.php` + `config/serviceproviders.php` to single-key.

---

## Task 1: `ExtensionCandidate` value object + `PackageManifest::getCandidates()`

**Files:**
- Create: `src/Extensions/ExtensionCandidate.php`
- Modify: `src/Extensions/PackageManifest.php`
- Test: `tests/Unit/Extensions/PackageManifestCandidatesTest.php`

- [ ] **Step 1: Create the value object**

`src/Extensions/ExtensionCandidate.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * A composer-discovered Glueful extension candidate (not yet activated).
 */
final class ExtensionCandidate
{
    /**
     * @param string $name Composer package name (e.g. "glueful/aegis")
     * @param string $provider Provider FQCN (string, no leading backslash)
     * @param string|null $requiresGlueful Framework version constraint, or null
     * @param list<string> $requiresExtensions Provider FQCNs this extension depends on
     */
    public function __construct(
        public readonly string $name,
        public readonly string $provider,
        public readonly ?string $requiresGlueful = null,
        public readonly array $requiresExtensions = [],
    ) {
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/Extensions/PackageManifestCandidatesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionCandidate;
use Glueful\Extensions\PackageManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageManifest::class)]
final class PackageManifestCandidatesTest extends TestCase
{
    private function manifestFor(array $installedPhp): PackageManifest
    {
        // Build a temp app dir with vendor/composer/installed.php
        $base = sys_get_temp_dir() . '/glueful-pm-' . uniqid('', true);
        @mkdir($base . '/vendor/composer', 0777, true);
        file_put_contents(
            $base . '/vendor/composer/installed.php',
            "<?php\nreturn " . var_export($installedPhp, true) . ";\n"
        );
        $ctx = new ApplicationContext($base);
        return new PackageManifest($ctx);
    }

    public function testCandidateCapturesProviderAndRequires(): void
    {
        $m = $this->manifestFor([
            'versions' => [
                'glueful/aegis' => [
                    'type' => 'glueful-extension',
                    'extra' => ['glueful' => [
                        'provider' => 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
                        'requires' => ['glueful' => '>=1.30.0', 'extensions' => []],
                    ]],
                ],
            ],
        ]);

        $candidates = $m->getCandidates();

        $this->assertArrayHasKey('glueful/aegis', $candidates);
        $c = $candidates['glueful/aegis'];
        $this->assertInstanceOf(ExtensionCandidate::class, $c);
        $this->assertSame('Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider', $c->provider);
        $this->assertSame('>=1.30.0', $c->requiresGlueful);
        $this->assertSame([], $c->requiresExtensions);
    }

    public function testNonExtensionPackagesAreNotCandidates(): void
    {
        $m = $this->manifestFor([
            'versions' => [
                'vendor/plain' => ['type' => 'library'],
            ],
        ]);

        $this->assertSame([], $m->getCandidates());
    }
}
```

- [ ] **Step 3: Run it — verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Extensions/PackageManifestCandidatesTest.php`
Expected: FAIL — `Call to undefined method ...PackageManifest::getCandidates()`.

- [ ] **Step 4: Implement `getCandidates()`**

In `src/Extensions/PackageManifest.php`, add a method (and reuse the existing `installed.php`/`installed.json` reading). Add after `getGluefulProviders()`:

```php
    /** @return array<string, ExtensionCandidate> package name => candidate */
    public function getCandidates(): array
    {
        $out = [];
        foreach ($this->rawPackages() as $name => $pkg) {
            if (($pkg['type'] ?? '') !== 'glueful-extension') {
                continue;
            }
            $glueful = $pkg['extra']['glueful'] ?? [];
            $provider = $glueful['provider'] ?? null;
            if (!is_string($provider) || !str_contains($provider, '\\')) {
                continue;
            }
            $requires = $glueful['requires'] ?? [];
            $out[$name] = new ExtensionCandidate(
                name: (string) $name,
                provider: ltrim($provider, '\\'),
                requiresGlueful: is_string($requires['glueful'] ?? null) ? $requires['glueful'] : null,
                requiresExtensions: array_values(array_filter(
                    (array) ($requires['extensions'] ?? []),
                    'is_string'
                )),
            );
        }
        ksort($out);
        return $out;
    }

    /**
     * Normalized "package name => package array" map from installed.php (versions
     * shape) or installed.json, whichever is present. Internal helper.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rawPackages(): array
    {
        $installedPhp = base_path($this->context, 'vendor/composer/installed.php');
        if (is_file($installedPhp)) {
            /** @var array<string,mixed> $installed */
            $installed = require $installedPhp;
            // Common Composer 2 shape: top-level 'versions'.
            if (isset($installed['versions']) && is_array($installed['versions'])) {
                return $installed['versions'];
            }
            // Multi-vendor datasets (less common): array of entries each with 'versions'.
            // Preserve the current PackageManifest behavior.
            $merged = [];
            foreach ($installed as $entry) {
                if (is_array($entry) && isset($entry['versions']) && is_array($entry['versions'])) {
                    foreach ($entry['versions'] as $name => $pkg) {
                        $merged[(string) $name] = $pkg;
                    }
                }
            }
            if ($merged !== []) {
                return $merged;
            }
        }
        $installedJson = base_path($this->context, 'vendor/composer/installed.json');
        if (is_file($installedJson)) {
            $json = json_decode((string) file_get_contents($installedJson), true);
            $packages = is_array($json['packages'] ?? null) ? $json['packages'] : (is_array($json) ? $json : []);
            $byName = [];
            foreach ($packages as $pkg) {
                if (is_array($pkg) && isset($pkg['name'])) {
                    $byName[(string) $pkg['name']] = $pkg;
                }
            }
            return $byName;
        }
        return [];
    }
```

Add `use` for nothing new (same namespace). Ensure the file `declare(strict_types=1)` is intact.

- [ ] **Step 5: Run it — verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Extensions/PackageManifestCandidatesTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Extensions/ExtensionCandidate.php src/Extensions/PackageManifest.php tests/Unit/Extensions/PackageManifestCandidatesTest.php
git commit -m "feat(ext): ExtensionCandidate + PackageManifest::getCandidates() with requires meta"
```

---

## Task 2: `ExtensionResolver` (pure: select → validate → topo-order)

**Files:**
- Create: `src/Extensions/ResolverError.php`, `src/Extensions/ResolverResult.php`, `src/Extensions/ExtensionResolver.php`
- Modify: `composer.json`
- Test: `tests/Unit/Extensions/ExtensionResolverTest.php`

- [ ] **Step 0: Add the `composer/semver` dependency (explicit, not transitive)**

```bash
composer require composer/semver
```

Verify it landed in `composer.json` `require` and `composer/semver` autoloads (`Composer\Semver\Semver`). This is what `ExtensionResolver` uses for `requires.glueful` constraint matching — do not rely on it being present transitively.

- [ ] **Step 1: Create the result/error value objects**

`src/Extensions/ResolverError.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

final class ResolverError
{
    public const MISSING_PROVIDER = 'missing_provider';
    public const MISSING_DEPENDENCY = 'missing_dependency';
    public const VERSION_MISMATCH = 'version_mismatch';
    public const DEPENDENCY_CYCLE = 'dependency_cycle';

    public function __construct(
        public readonly string $kind,
        public readonly string $provider,
        public readonly string $message,
    ) {
    }
}
```

`src/Extensions/ResolverResult.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

final class ResolverResult
{
    /**
     * @param list<string> $providers Ordered provider FQCNs to load
     * @param list<ResolverError> $errors
     */
    public function __construct(
        public readonly array $providers,
        public readonly array $errors,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
```

- [ ] **Step 2: Write the failing test (table-driven)**

`tests/Unit/Extensions/ExtensionResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\ExtensionCandidate;
use Glueful\Extensions\ExtensionResolver;
use Glueful\Extensions\ResolverError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionResolver::class)]
final class ExtensionResolverTest extends TestCase
{
    /** @param list<ExtensionCandidate> $list */
    private function candidates(array $list): array
    {
        $map = [];
        foreach ($list as $c) {
            $map[$c->name] = $c;
        }
        return $map;
    }

    private function cand(string $provider, array $deps = [], ?string $reqGlueful = null): ExtensionCandidate
    {
        return new ExtensionCandidate('pkg/' . md5($provider), $provider, $reqGlueful, $deps);
    }

    public function testEmptyEnabledYieldsNothing(): void
    {
        $r = new ExtensionResolver();
        $res = $r->resolve($this->candidates([$this->cand('A')]), [], '1.46.0');
        $this->assertSame([], $res->providers);
        $this->assertSame([], $res->errors);
    }

    public function testEnabledOrderPreservedWhenNoDeps(): void
    {
        $r = new ExtensionResolver();
        $res = $r->resolve($this->candidates([$this->cand('A'), $this->cand('B')]), ['B', 'A'], '1.46.0');
        $this->assertSame(['B', 'A'], $res->providers);
        $this->assertFalse($res->hasErrors());
    }

    public function testEnabledEntryNotACandidateIsMissingProviderError(): void
    {
        $r = new ExtensionResolver();
        $res = $r->resolve($this->candidates([$this->cand('A')]), ['A', 'Ghost'], '1.46.0');
        $this->assertSame(['A'], $res->providers);
        $this->assertCount(1, $res->errors);
        $this->assertSame(ResolverError::MISSING_PROVIDER, $res->errors[0]->kind);
        $this->assertSame('Ghost', $res->errors[0]->provider);
    }

    public function testEnabledExtensionWithUnenabledDependencyErrors(): void
    {
        $r = new ExtensionResolver();
        // A requires B (by provider FQCN), but only A is enabled
        $cands = $this->candidates([$this->cand('A', ['B']), $this->cand('B')]);
        $res = $r->resolve($cands, ['A'], '1.46.0');
        $this->assertCount(1, $res->errors);
        $this->assertSame(ResolverError::MISSING_DEPENDENCY, $res->errors[0]->kind);
    }

    public function testDependencyOrderedBeforeDependent(): void
    {
        $r = new ExtensionResolver();
        $cands = $this->candidates([$this->cand('A', ['B']), $this->cand('B')]);
        // Both enabled, declared dependent-first; resolver must order B before A
        $res = $r->resolve($cands, ['A', 'B'], '1.46.0');
        $this->assertFalse($res->hasErrors());
        $this->assertSame(['B', 'A'], $res->providers);
    }

    public function testVersionMismatchErrors(): void
    {
        $r = new ExtensionResolver();
        $cands = $this->candidates([$this->cand('A', [], '>=2.0.0')]);
        $res = $r->resolve($cands, ['A'], '1.46.0');
        $this->assertCount(1, $res->errors);
        $this->assertSame(ResolverError::VERSION_MISMATCH, $res->errors[0]->kind);
    }

    public function testCycleErrors(): void
    {
        $r = new ExtensionResolver();
        $cands = $this->candidates([$this->cand('A', ['B']), $this->cand('B', ['A'])]);
        $res = $r->resolve($cands, ['A', 'B'], '1.46.0');
        $this->assertTrue($res->hasErrors());
        $kinds = array_map(fn($e) => $e->kind, $res->errors);
        $this->assertContains(ResolverError::DEPENDENCY_CYCLE, $kinds);
    }
}
```

- [ ] **Step 3: Run it — verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ExtensionResolverTest.php`
Expected: FAIL — `Class "Glueful\Extensions\ExtensionResolver" not found`.

- [ ] **Step 4: Implement the resolver**

`src/Extensions/ExtensionResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Composer\Semver\Semver;

/**
 * Pure resolution: given composer candidates + the enabled allow-list, select,
 * validate, and topologically order the providers to load. Never throws — returns
 * a ResolverResult carrying providers + errors; callers choose severity.
 */
final class ExtensionResolver
{
    /**
     * @param array<string, ExtensionCandidate> $candidates package name => candidate
     * @param list<string> $enabled provider FQCNs (string), in declared order
     * @param string $frameworkVersion e.g. Version::VERSION
     */
    public function resolve(array $candidates, array $enabled, string $frameworkVersion): ResolverResult
    {
        // Index candidates by provider FQCN (the enabled list is provider FQCNs).
        $byProvider = [];
        foreach ($candidates as $c) {
            $byProvider[$c->provider] = $c;
        }

        $errors = [];
        $selected = [];           // provider FQCN => ExtensionCandidate (in enabled order)
        foreach ($enabled as $provider) {
            $provider = ltrim((string) $provider, '\\');
            if (!isset($byProvider[$provider])) {
                $errors[] = new ResolverError(
                    ResolverError::MISSING_PROVIDER,
                    $provider,
                    "Enabled provider {$provider} is not an installed/discovered extension."
                );
                continue;
            }
            $selected[$provider] = $byProvider[$provider];
        }

        // Validate framework version + dependency presence (deps must be enabled too).
        foreach ($selected as $provider => $cand) {
            if ($cand->requiresGlueful !== null && !Semver::satisfies($frameworkVersion, $cand->requiresGlueful)) {
                $errors[] = new ResolverError(
                    ResolverError::VERSION_MISMATCH,
                    $provider,
                    "{$provider} requires Glueful {$cand->requiresGlueful}, running {$frameworkVersion}."
                );
            }
            foreach ($cand->requiresExtensions as $dep) {
                $dep = ltrim($dep, '\\');
                if (!isset($selected[$dep])) {
                    $errors[] = new ResolverError(
                        ResolverError::MISSING_DEPENDENCY,
                        $provider,
                        "{$provider} requires extension {$dep}, which is not enabled."
                    );
                }
            }
        }

        $ordered = $this->topoSort($selected, $errors);

        return new ResolverResult($ordered, $errors);
    }

    /**
     * Stable topological sort over requires-extensions edges among the selected set.
     * Dependencies that are present in $selected are ordered before their dependents.
     * On cycle, records a DEPENDENCY_CYCLE error and falls back to enabled order for
     * the cyclic remainder (never throws).
     *
     * @param array<string, ExtensionCandidate> $selected provider FQCN => candidate (enabled order)
     * @param list<ResolverError> $errors (by-ref accumulator)
     * @return list<string>
     */
    private function topoSort(array $selected, array &$errors): array
    {
        $result = [];
        $state = []; // provider => 0 unvisited, 1 visiting, 2 done
        $order = array_keys($selected);

        $visit = function (string $p) use (&$visit, &$state, &$result, $selected, &$errors): void {
            if (($state[$p] ?? 0) === 2) {
                return;
            }
            if (($state[$p] ?? 0) === 1) {
                $errors[] = new ResolverError(
                    ResolverError::DEPENDENCY_CYCLE,
                    $p,
                    "Dependency cycle involving {$p}."
                );
                return;
            }
            $state[$p] = 1;
            foreach ($selected[$p]->requiresExtensions as $dep) {
                $dep = ltrim($dep, '\\');
                if (isset($selected[$dep])) {
                    $visit($dep);
                }
            }
            $state[$p] = 2;
            $result[] = $p;
        };

        foreach ($order as $p) {
            $visit($p);
        }
        return $result;
    }
}
```

> **Dependency note:** uses `composer/semver` (`Composer\Semver\Semver`) for `requires.glueful` constraint matching. `composer/semver` is an **explicit framework dependency added in Step 0** — do not rely on transitive presence and do not fall back to `version_compare`.

- [ ] **Step 5: Run it — verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ExtensionResolverTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Parity test (structural proof)**

Add to `ExtensionResolverTest`:

```php
    public function testResolutionIsEnvironmentIndependent(): void
    {
        $r = new ExtensionResolver();
        $cands = $this->candidates([$this->cand('A'), $this->cand('B', ['A'])]);

        putenv('APP_ENV=development');
        $dev = $r->resolve($cands, ['A', 'B'], '1.46.0');
        putenv('APP_ENV=production');
        $prod = $r->resolve($cands, ['A', 'B'], '1.46.0');
        putenv('APP_ENV');

        $this->assertSame($dev->providers, $prod->providers);
        $this->assertEquals($dev->errors, $prod->errors);
    }
```

Run: `vendor/bin/phpunit tests/Unit/Extensions/ExtensionResolverTest.php`
Expected: PASS (8 tests). (The resolver reads no env — this proves it.)

- [ ] **Step 7: Commit**

```bash
git add src/Extensions/ResolverError.php src/Extensions/ResolverResult.php src/Extensions/ExtensionResolver.php tests/Unit/Extensions/ExtensionResolverTest.php
git commit -m "feat(ext): pure ExtensionResolver (select/validate/topo-order) with parity proof"
```

---

## Task 3: `AppProviderLoader` + simplify `config/serviceproviders.php`

**Files:**
- Create: `src/Extensions/AppProviderLoader.php`
- Modify: `config/serviceproviders.php`
- Test: `tests/Unit/Extensions/AppProviderLoaderTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Extensions/AppProviderLoaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\AppProviderLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppProviderLoader::class)]
final class AppProviderLoaderTest extends TestCase
{
    private function ctxWithConfig(array $serviceproviders): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/glueful-app-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents(
            $base . '/config/serviceproviders.php',
            "<?php\nreturn " . var_export($serviceproviders, true) . ";\n"
        );
        // config() reads files only once a loader is attached (see Conventions).
        $ctx = new ApplicationContext($base, 'testing');
        $ctx->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));
        return $ctx;
    }

    public function testReturnsEnabledInOrder(): void
    {
        $ctx = $this->ctxWithConfig(['enabled' => ['App\\Providers\\AppServiceProvider', 'App\\Providers\\EventServiceProvider']]);
        $loader = new AppProviderLoader();
        $this->assertSame(
            ['App\\Providers\\AppServiceProvider', 'App\\Providers\\EventServiceProvider'],
            $loader->load($ctx)
        );
    }

    public function testEmptyWhenNoneConfigured(): void
    {
        $ctx = $this->ctxWithConfig([]);
        $this->assertSame([], (new AppProviderLoader())->load($ctx));
    }
}
```

> Uses the config-loader harness from Conventions (`setConfigLoader(new ConfigurationLoader(...))`) — a bare `ApplicationContext` returns config defaults and would not read the temp file.

- [ ] **Step 2: Run it — verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Extensions/AppProviderLoaderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`src/Extensions/AppProviderLoader.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Loads app-level service providers — the application's OWN providers
 * (e.g. AppServiceProvider, EventServiceProvider). These are app-local classes,
 * not composer-discovered packages, so there is no discovery or validation:
 * the configured `enabled` list is loaded verbatim, in declared order.
 */
final class AppProviderLoader
{
    /** @return list<string> provider FQCNs in declared order */
    public function load(ApplicationContext $context): array
    {
        $enabled = (array) config($context, 'serviceproviders.enabled', []);
        return array_values(array_map(
            static fn($p): string => ltrim((string) $p, '\\'),
            array_filter($enabled, 'is_string')
        ));
    }
}
```

- [ ] **Step 4: Run it — verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Extensions/AppProviderLoaderTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Simplify `config/serviceproviders.php`**

Replace the whole file with the single-key model (preserve the leading docblock comment style of the repo's config files):

```php
<?php

/**
 * Application Service Providers
 *
 * The application's own service providers, loaded in declared order. These are
 * app-local classes (not composer-discovered extensions) and are always loaded.
 * Use string FQCNs (no ::class) so tooling can edit the list safely.
 */

return [
    'enabled' => [
        // \App\Providers\AppServiceProvider::class equivalent, as a string:
        // 'App\\Providers\\AppServiceProvider',
        // 'App\\Providers\\EventServiceProvider',
    ],
];
```

- [ ] **Step 6: Commit**

```bash
git add src/Extensions/AppProviderLoader.php config/serviceproviders.php tests/Unit/Extensions/AppProviderLoaderTest.php
git commit -m "feat(ext): AppProviderLoader + single-key serviceproviders.php"
```

---

## Task 4: `ExtensionStateWriter` (edit the `enabled` string list)

**Files:**
- Create: `src/Extensions/ExtensionStateWriter.php`
- Test: `tests/Unit/Extensions/ExtensionStateWriterTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Extensions/ExtensionStateWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\ExtensionStateWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionStateWriter::class)]
final class ExtensionStateWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/glueful-sw-' . uniqid('', true) . '.php';
        file_put_contents($this->path, "<?php\n\nreturn [\n    'enabled' => [\n    ],\n];\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.bak');
    }

    private function loaded(): array
    {
        return (require $this->path)['enabled'];
    }

    public function testAddAppendsAndIsIdempotent(): void
    {
        $w = new ExtensionStateWriter();
        $w->enable($this->path, 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider');
        $w->enable($this->path, 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider'); // idempotent

        $this->assertSame(
            ['Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider'],
            $this->loaded()
        );
    }

    public function testRemove(): void
    {
        $w = new ExtensionStateWriter();
        $w->enable($this->path, 'A\\B');
        $w->enable($this->path, 'C\\D');
        $w->disable($this->path, 'A\\B');
        $this->assertSame(['C\\D'], array_values($this->loaded()));
    }

    public function testDryRunWritesNothing(): void
    {
        $before = file_get_contents($this->path);
        (new ExtensionStateWriter())->enable($this->path, 'A\\B', dryRun: true);
        $this->assertSame($before, file_get_contents($this->path));
    }

    public function testBackupCreated(): void
    {
        (new ExtensionStateWriter())->enable($this->path, 'A\\B', backup: true);
        $this->assertFileExists($this->path . '.bak');
    }

    public function testRefusesNonTrivialEnabled(): void
    {
        file_put_contents(
            $this->path,
            "<?php\nreturn ['enabled' => env('X') ? ['A\\\\B'] : []];\n"
        );
        $this->expectException(\RuntimeException::class);
        (new ExtensionStateWriter())->enable($this->path, 'C\\D');
    }

    public function testAcceptsCommentedDefaultTemplate(): void
    {
        // Mirrors the shipped config/extensions.php (a comment inside `enabled`).
        file_put_contents(
            $this->path,
            "<?php\nreturn [\n    'enabled' => [\n        // 'Glueful\\\\Extensions\\\\Aegis\\\\Services\\\\AegisServiceProvider',\n    ],\n];\n"
        );
        (new ExtensionStateWriter())->enable($this->path, 'X\\Y');
        $this->assertSame(['X\\Y'], array_values($this->loaded()));
    }
}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ExtensionStateWriterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`src/Extensions/ExtensionStateWriter.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * The single writer for config/extensions.php's `enabled` array. Works only with a
 * flat list of plain string FQCNs; refuses to edit a non-trivial array (conditionals,
 * function calls, ::class constants, non-string entries) and tells the caller to edit
 * by hand — keeping writes safe without a PHP parser.
 */
final class ExtensionStateWriter
{
    public function enable(string $configPath, string $provider, bool $dryRun = false, bool $backup = false): void
    {
        $provider = ltrim($provider, '\\');
        $list = $this->readList($configPath);
        if (in_array($provider, $list, true)) {
            return; // idempotent
        }
        $list[] = $provider;
        sort($list, SORT_STRING);
        $this->writeList($configPath, $list, $dryRun, $backup);
    }

    public function disable(string $configPath, string $provider, bool $dryRun = false, bool $backup = false): void
    {
        $provider = ltrim($provider, '\\');
        $list = $this->readList($configPath);
        $next = array_values(array_filter($list, static fn($p) => $p !== $provider));
        if ($next === $list) {
            return; // not present
        }
        $this->writeList($configPath, $next, $dryRun, $backup);
    }

    /** @return list<string> */
    private function readList(string $configPath): array
    {
        if (!is_file($configPath)) {
            throw new \RuntimeException("Config not found: {$configPath}");
        }
        $config = require $configPath;
        if (!is_array($config) || !array_key_exists('enabled', $config)) {
            throw new \RuntimeException("Config has no 'enabled' key: {$configPath}");
        }
        $enabled = $config['enabled'];
        if (!is_array($enabled)) {
            throw new \RuntimeException("'enabled' is not an array: {$configPath}");
        }
        foreach ($enabled as $entry) {
            if (!is_string($entry)) {
                throw new \RuntimeException(
                    "'enabled' contains a non-string entry; refuse to auto-edit {$configPath} — edit it by hand."
                );
            }
        }
        // Reject conditional/function-call arrays by re-checking the SOURCE for an
        // `'enabled' => [ ...only string literals... ]` shape.
        $src = (string) file_get_contents($configPath);
        if (!$this->enabledArrayIsLiteral($src)) {
            throw new \RuntimeException(
                "'enabled' in {$configPath} is not a flat list of string literals; edit it by hand."
            );
        }
        /** @var list<string> $enabled */
        return array_values($enabled);
    }

    private function enabledArrayIsLiteral(string $src): bool
    {
        // Capture the enabled => [ ... ] block and ensure that, after removing
        // comments and string literals, nothing but commas/whitespace remains —
        // i.e. no conditionals, function calls, ::class, or non-string entries.
        if (!preg_match("/'enabled'\\s*=>\\s*\\[(.*?)\\]/s", $src, $m)) {
            return false;
        }
        $body = $m[1];
        // 1) strip block comments /* ... */
        $body = preg_replace('#/\*.*?\*/#s', '', $body);
        // 2) strip line comments // ... and # ... (to end of line)
        $body = preg_replace('@//[^\r\n]*|#[^\r\n]*@', '', (string) $body);
        // 3) strip single-quoted string literals (the only allowed value form)
        $body = preg_replace("/'(?:\\\\.|[^'\\\\])*'/s", '', (string) $body);
        // 4) what's left must be only commas + whitespace
        $body = str_replace(',', '', (string) $body);
        return trim((string) $body) === '';
    }

    /** @param list<string> $list */
    private function writeList(string $configPath, array $list, bool $dryRun, bool $backup): void
    {
        $items = '';
        foreach ($list as $p) {
            $items .= "        '" . str_replace('\\', '\\\\', $p) . "',\n";
        }
        $src = (string) file_get_contents($configPath);
        $updated = preg_replace(
            "/('enabled'\\s*=>\\s*\\[)(.*?)(\\s*\\])/s",
            "$1\n" . $items . "    $3",
            $src,
            1
        );
        if ($updated === null) {
            throw new \RuntimeException("Failed to rewrite 'enabled' in {$configPath}");
        }
        if ($dryRun) {
            return;
        }
        if ($backup) {
            copy($configPath, $configPath . '.bak');
        }
        file_put_contents($configPath, $updated);
    }
}
```

- [ ] **Step 4: Run it — verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ExtensionStateWriterTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Extensions/ExtensionStateWriter.php tests/Unit/Extensions/ExtensionStateWriterTest.php
git commit -m "feat(ext): ExtensionStateWriter — safe edits to the enabled string list"
```

---

## Task 5: `ProviderClassResolver` + rewire `ExtensionManager`

**Files:**
- Create: `src/Extensions/ProviderClassResolver.php`
- Modify: `src/Extensions/ExtensionManager.php`
- Test: `tests/Unit/Extensions/ProviderClassResolverTest.php`, `tests/Integration/Extensions/ExtensionManagerResolveTest.php`

The combined-resolution logic ([app providers] ++ [resolved extensions]) is needed by **two** seams — `ExtensionManager` and `ContainerFactory`. To avoid the exact "two copies that drift" problem this redesign is curing, it lives in **one** stateless unit, `ProviderClassResolver`, that both call.

- [ ] **Step 1: Create `ProviderClassResolver`**

`src/Extensions/ProviderClassResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Support\Version;

/**
 * The single resolution path for "which provider classes load, in what order".
 * Combines app providers (always) with resolved extensions (composer candidates
 * gated by extensions.enabled). Stateless; returns a ResolverResult whose
 * `providers` is the combined ordered list and whose `errors` are the extension
 * resolver's errors. Used by BOTH ExtensionManager and ContainerFactory.
 */
final class ProviderClassResolver
{
    public function __construct(
        private readonly AppProviderLoader $appProviders = new AppProviderLoader(),
        private readonly ExtensionResolver $resolver = new ExtensionResolver(),
    ) {
    }

    public function resolve(ApplicationContext $context): ResolverResult
    {
        $app = $this->appProviders->load($context);

        $candidates = (new PackageManifest($context))->getCandidates();
        $enabled = array_values(array_map(
            static fn($p): string => ltrim((string) $p, '\\'),
            array_filter((array) config($context, 'extensions.enabled', []), 'is_string')
        ));

        $extResult = $this->resolver->resolve($candidates, $enabled, Version::VERSION);

        // app providers first, then resolved extensions; dedupe preserving order
        $combined = array_values(array_unique([...$app, ...$extResult->providers]));

        return new ResolverResult($combined, $extResult->errors);
    }
}
```

- [ ] **Step 2: Unit-test `ProviderClassResolver`**

`tests/Unit/Extensions/ProviderClassResolverTest.php` — using the config-loader harness from Conventions and a temp `installed.php`, assert: app providers come first, then enabled extensions; a missing enabled extension surfaces in `->errors`; empty config → empty providers. (Mirror the `ExtensionManagerResolveTest` fixture setup below but call `ProviderClassResolver::resolve($ctx)` directly.)

- [ ] **Step 3: `ExtensionManager` delegates to `ProviderClassResolver`**

In `src/Extensions/ExtensionManager.php`, add the error field near the top of the class body:

```php
    /** @var list<\Glueful\Extensions\ResolverError> */
    private array $resolverErrors = [];
```

Add the delegating methods (no duplicated logic — it calls the shared resolver):

```php
    /**
     * Resolve the ordered provider class list via the shared ProviderClassResolver.
     * Records resolver errors for diagnose/cache callers.
     *
     * @return list<class-string>
     */
    public function resolveProviderClasses(): array
    {
        $result = (new ProviderClassResolver())->resolve($this->getContext());
        $this->resolverErrors = $result->errors;
        /** @var list<class-string> $providers */
        $providers = $result->providers;
        return $providers;
    }

    /** @return list<\Glueful\Extensions\ResolverError> */
    public function getResolverErrors(): array
    {
        return $this->resolverErrors;
    }
```

(Same namespace — no `use` needed for `ProviderClassResolver`/`ResolverError`.)

- [ ] **Step 4: Replace the two `ProviderLocator::all()` call sites**

In `loadAllProviders()` (currently iterates `ProviderLocator::all($context)`), change to:

```php
    private function loadAllProviders(): void
    {
        foreach ($this->resolveProviderClasses() as $providerClass) {
            $this->addProvider($providerClass);
        }
    }
```

In `writeCacheNow()` (currently defaults to `ProviderLocator::all($context)`), change the default source:

```php
    public function writeCacheNow(?array $providerClasses = null): void
    {
        $classes = $providerClasses ?? $this->resolveProviderClasses();
        // ... existing cache-writing body unchanged (var_export of $classes) ...
    }
```

> Note: `addProvider()` requires `is_subclass_of($providerClass, ServiceProvider::class)`. App providers that are plain `services()` classes (not extending `ServiceProvider`) are still loaded for their **container defs** by `ContainerFactory` (Task 6); they just won't be added to the boot list here. `EventServiceProvider` extends `ServiceProvider`, so it boots. This matches today's behavior — verify by reading `addProvider()` before editing.

- [ ] **Step 5: Strict/lenient cache + prod-fail-if-missing**

Locate `discover()` and `loadFromCache()`. Change `discover()` so production requires the cache:

```php
    public function discover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        $cached = $this->loadFromCache();
        if ($cached !== null) {
            $this->providers = $cached;
            $this->cacheUsed = true;
            return;
        }

        if ($this->isProduction()) {
            // Production must boot from a compiled manifest — never resolve live.
            throw new \RuntimeException(
                'Extension cache missing in production. Run: php glueful extensions:cache'
            );
        }

        // Development: resolve live.
        $this->loadAllProviders();
        $this->sortProviders();
        $this->registerProviders();
    }
```

(Remove the old `if ($this->isProduction()) $this->saveToCache();` tail — production no longer writes during boot; `extensions:cache` does that explicitly.)

- [ ] **Step 6: Write the integration test**

`tests/Integration/Extensions/ExtensionManagerResolveTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use PHPUnit\Framework\TestCase;

final class ExtensionManagerResolveTest extends TestCase
{
    public function testResolveCombinesAppProvidersAndEnabledExtensions(): void
    {
        $base = sys_get_temp_dir() . '/glueful-em-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        @mkdir($base . '/vendor/composer', 0777, true);

        file_put_contents($base . '/config/serviceproviders.php',
            "<?php\nreturn ['enabled' => ['App\\\\Providers\\\\AppServiceProvider']];\n");
        file_put_contents($base . '/config/extensions.php',
            "<?php\nreturn ['enabled' => ['Vendor\\\\Ext\\\\Provider']];\n");
        file_put_contents($base . '/vendor/composer/installed.php',
            "<?php\nreturn " . var_export([
                'versions' => [
                    'vendor/ext' => [
                        'type' => 'glueful-extension',
                        'extra' => ['glueful' => ['provider' => 'Vendor\\Ext\\Provider']],
                    ],
                ],
            ], true) . ";\n");

        $ctx = new ApplicationContext($base, 'testing');
        $ctx->setConfigLoader(new \Glueful\Bootstrap\ConfigurationLoader($base, 'testing', $base . '/config'));
        // Construct ExtensionManager with a container that returns $ctx for ApplicationContext::class.
        $manager = $this->managerWithContext($ctx);

        $classes = $manager->resolveProviderClasses();

        $this->assertContains('App\\Providers\\AppServiceProvider', $classes);
        $this->assertContains('Vendor\\Ext\\Provider', $classes);
        // app provider precedes the extension
        $this->assertLessThan(
            array_search('Vendor\\Ext\\Provider', $classes, true),
            array_search('App\\Providers\\AppServiceProvider', $classes, true)
        );
        $this->assertSame([], $manager->getResolverErrors());
    }

    private function managerWithContext(ApplicationContext $ctx): ExtensionManager
    {
        $container = new class ($ctx) implements \Psr\Container\ContainerInterface {
            public function __construct(private ApplicationContext $ctx) {}
            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->ctx;
                }
                throw new class ("no {$id}") extends \RuntimeException implements
                    \Psr\Container\NotFoundExceptionInterface {};
            }
            public function has(string $id): bool { return $id === ApplicationContext::class; }
        };
        return new ExtensionManager($container);
    }
}
```

> Confirm `ExtensionManager::__construct(ContainerInterface $container)` and that `getContext()` resolves `ApplicationContext::class` from the container (verified: it does). Adapt the fake container if `getContext()` uses a different key.

- [ ] **Step 7: Run both tests**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ProviderClassResolverTest.php tests/Integration/Extensions/ExtensionManagerResolveTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Extensions/ProviderClassResolver.php src/Extensions/ExtensionManager.php tests/Unit/Extensions/ProviderClassResolverTest.php tests/Integration/Extensions/ExtensionManagerResolveTest.php
git commit -m "feat(ext): ProviderClassResolver (shared) + ExtensionManager delegates; strict prod cache"
```

---

## Task 6: Rewire `ContainerFactory` + delete `ProviderLocator`

**Files:**
- Modify: `src/Container/Bootstrap/ContainerFactory.php`
- Delete: `src/Extensions/ProviderLocator.php`
- Test: `tests/Integration/Extensions/ContainerFactoryDefsTest.php` (or extend an existing container test)

- [ ] **Step 1: Replace the `ProviderLocator::all()` iteration in `loadExtensionDefinitions()` with the shared `ProviderClassResolver`**

In `src/Container/Bootstrap/ContainerFactory.php`, replace `foreach (ProviderLocator::all($context) as $providerClass)` with a call to the **same** stateless `ProviderClassResolver` the `ExtensionManager` uses — one resolution path, no duplicated logic, no `ProviderLocator`:

```php
        // Same resolution path as ExtensionManager — the shared, stateless resolver.
        // It takes only the context, so it works during container construction
        // (no need to resolve anything from the not-yet-built container).
        $providerClasses = (new \Glueful\Extensions\ProviderClassResolver())
            ->resolve($context)
            ->providers;

        foreach ($providerClasses as $providerClass) {
            if (!class_exists($providerClass)) {
                continue;
            }
            // ... existing defs()/services() handling unchanged ...
        }
```

> **Boundary note (resolved):** `loadExtensionDefinitions()` runs *during* container construction, so it must not depend on the container. `ProviderClassResolver::resolve($context)` takes only the `ApplicationContext` (which `ContainerFactory` already has in scope here) and constructs its own `AppProviderLoader`/`PackageManifest`/`ExtensionResolver` — so it's safe to call at construction time, and there is exactly one resolution *implementation* shared by both seams.

- [ ] **Step 2: Delete `ProviderLocator` and its remaining references**

```bash
git rm src/Extensions/ProviderLocator.php
grep -rn "ProviderLocator" src/ tests/   # must show ZERO results before continuing
```

Any remaining references (CLI commands — handled in Task 7) must be migrated first; if `grep` shows hits outside Task 7's files, fix them now.

- [ ] **Step 3: Static + style check**

Run: `composer run analyse` and `composer run phpcs src/Container/Bootstrap/ContainerFactory.php src/Extensions/`
Expected: no new errors; no reference to the deleted class.

- [ ] **Step 4: Coverage — no new placeholder test**

`ContainerFactory` now consumes the **same** `ProviderClassResolver` already covered by `ProviderClassResolverTest` (Task 5) + `ExtensionManagerResolveTest`. Do **not** add a `ContainerFactoryDefsTest` that only asserts `true` — that's a placeholder. Instead, **extend the existing container/boot integration test suite** (find it under `tests/Integration/` that boots the container) with one assertion: against a temp app whose `extensions.enabled` lists a fixture provider exposing `services()`, the registered service id resolves from the built container. If the existing suite already proves provider `services()` are registered, no new test is needed — rely on it.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(ext): ContainerFactory uses ExtensionResolver; delete ProviderLocator"
```

---

## Task 7: CLI — enable/disable via writer, strict cache, list states, drop `why`

**Files:**
- Create: `src/Console/Commands/Extensions/Concerns/ResolvesExtensionNeedle.php` (shared trait)
- Modify: `EnableCommand.php`, `DisableCommand.php`, `ListCommand.php`, `CacheCommand.php`, `InfoCommand.php`, `DiagnoseCommand.php`
- Delete: `WhyCommand.php`
- Test: `tests/Integration/Console/Extensions/ExtensionCliTest.php`

- [ ] **Step 1: Rewrite `EnableCommand::execute()` — validate BEFORE writing**

Resolve the needle, then **dry-resolve the *proposed* enabled list and refuse to write if it would introduce errors** (e.g. a missing dependency or version mismatch) — so the command never leaves the config in a broken state. Only write if the proposed list resolves clean. Replace the body after the production guard:

```php
        $needle = (string) $input->getArgument('extension');
        $context = $this->getContext();

        $candidates = (new \Glueful\Extensions\PackageManifest($context))->getCandidates();
        $providerClass = $this->resolveNeedle($needle, $candidates); // helper below
        if ($providerClass === null) {
            $output->writeln("<error>Extension not found among installed packages: {$needle}</error>");
            return self::FAILURE;
        }

        // Current enabled list (string FQCNs).
        $current = array_values(array_map(
            static fn($p): string => ltrim((string) $p, '\\'),
            array_filter((array) config($context, 'extensions.enabled', []), 'is_string')
        ));
        if (in_array($providerClass, $current, true)) {
            $output->writeln("<info>{$providerClass} is already enabled.</info>");
            return self::SUCCESS;
        }

        // Dry-resolve the PROPOSED list; refuse to write if it would error.
        $proposed = [...$current, $providerClass];
        $result = (new \Glueful\Extensions\ExtensionResolver())
            ->resolve($candidates, $proposed, \Glueful\Support\Version::VERSION);
        if ($result->hasErrors()) {
            foreach ($result->errors as $e) {
                $output->writeln("<error>[{$e->kind}] {$e->message}</error>");
            }
            $output->writeln("<error>Not enabling {$providerClass} — fix the above (e.g. enable its dependencies) first.</error>");
            return self::FAILURE;
        }

        // Clean → write, then recompile the cache.
        $configPath = config_path($context, 'extensions.php');
        try {
            (new \Glueful\Extensions\ExtensionStateWriter())->enable(
                $configPath,
                $providerClass,
                dryRun: (bool) $input->getOption('dry-run'),
                backup: (bool) $input->getOption('backup'),
            );
        } catch (\RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return self::FAILURE;
        }

        if ($input->getOption('dry-run') !== true) {
            $this->getService(\Glueful\Extensions\ExtensionManager::class)->writeCacheNow();
        }
        $output->writeln("<info>Enabled {$providerClass}.</info>");
        return self::SUCCESS;
```

Add a shared trait `ResolvesExtensionNeedle` at `src/Console/Commands/Extensions/Concerns/ResolvesExtensionNeedle.php`, and `use` it in `EnableCommand`, `DisableCommand`, and `InfoCommand`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions\Concerns;

use Glueful\Extensions\ExtensionCandidate;

trait ResolvesExtensionNeedle
{
    /**
     * Map a user-supplied needle (package name, provider FQCN, or trailing slug)
     * to a candidate's provider FQCN, or null if no candidate matches.
     *
     * @param array<string, ExtensionCandidate> $candidates package name => candidate
     */
    protected function resolveNeedle(string $needle, array $candidates): ?string
    {
        $needle = ltrim($needle, '\\');
        foreach ($candidates as $name => $c) {
            // match package name, provider FQCN, or trailing slug (last path segment of name)
            $slug = substr((string) strrchr($name, '/') ?: $name, 1) ?: $name;
            if ($needle === $name || $needle === $c->provider || $needle === $slug) {
                return $c->provider;
            }
        }
        return null;
    }
}
```

In each command, add `use Glueful\Console\Commands\Extensions\Concerns\ResolvesExtensionNeedle;` at the top and `use ResolvesExtensionNeedle;` inside the class body, then call `$this->resolveNeedle(...)` as shown in Steps 1–2.

- [ ] **Step 2: Rewrite `DisableCommand::execute()` — validate BEFORE writing**

Same shape as enable, but with the inverse guard: **dry-resolve the proposed list with the provider removed, and refuse if that leaves another still-enabled extension with a missing dependency** (i.e. disabling X would break Y that requires X). Then `->disable(...)` + recompile:

```php
        // ... resolveNeedle as in enable; $current as in enable ...
        if (!in_array($providerClass, $current, true)) {
            $output->writeln("<info>{$providerClass} is not enabled.</info>");
            return self::SUCCESS;
        }

        $proposed = array_values(array_filter($current, static fn($p) => $p !== $providerClass));
        $result = (new \Glueful\Extensions\ExtensionResolver())
            ->resolve($candidates, $proposed, \Glueful\Support\Version::VERSION);
        // Only block on MISSING_DEPENDENCY introduced by the removal.
        $blocking = array_filter(
            $result->errors,
            static fn($e) => $e->kind === \Glueful\Extensions\ResolverError::MISSING_DEPENDENCY
        );
        if ($blocking !== []) {
            foreach ($blocking as $e) {
                $output->writeln("<error>[{$e->kind}] {$e->message}</error>");
            }
            $output->writeln("<error>Not disabling {$providerClass} — another enabled extension depends on it. Disable that first.</error>");
            return self::FAILURE;
        }

        $configPath = config_path($context, 'extensions.php');
        (new \Glueful\Extensions\ExtensionStateWriter())->disable(
            $configPath,
            $providerClass,
            dryRun: (bool) $input->getOption('dry-run'),
            backup: (bool) $input->getOption('backup'),
        );
        if ($input->getOption('dry-run') !== true) {
            $this->getService(\Glueful\Extensions\ExtensionManager::class)->writeCacheNow();
        }
        $output->writeln("<info>Disabled {$providerClass}.</info>");
        return self::SUCCESS;
```

> **Note on the post-write `writeCacheNow()`:** enable/disable write the config only after the proposed list **preflights clean** (no resolver errors), so the written config is always *valid*. The subsequent `writeCacheNow()` re-compiles the cache; if it fails for a **non-resolver** reason (filesystem/permissions), the config is already written and correct — only the compiled cache is stale. This is recoverable and non-fatal: surface the error, tell the user to re-run `php glueful extensions:cache`, and note that dev boot resolves live anyway (the stale cache only matters for prod, where the deploy re-runs `extensions:cache`). Treat such failures as exceptional, not as a broken-state risk.

- [ ] **Step 3: Make `CacheCommand` strict**

In `CacheCommand`, resolve and fail on errors before writing:

```php
        $manager = $this->getService(\Glueful\Extensions\ExtensionManager::class);
        $classes = $manager->resolveProviderClasses();
        $errors = $manager->getResolverErrors();
        if ($errors !== []) {
            foreach ($errors as $e) {
                $output->writeln("<error>[{$e->kind}] {$e->message}</error>");
            }
            $output->writeln('<error>Refusing to write extension cache with unresolved errors.</error>');
            return self::FAILURE;
        }
        $manager->writeCacheNow($classes);
        $output->writeln('<info>Extension cache written (' . count($classes) . ' providers).</info>');
        return self::SUCCESS;
```

- [ ] **Step 4: Update `ListCommand` to show state + fold in `why`**

Replace the `ProviderLocator::all()` source with: candidates (from `PackageManifest`) cross-referenced with `extensions.enabled` and the resolver result. For each candidate print `enabled ✓` / `available ○`; for any enabled entry not in candidates print `enabled-but-missing ⚠`. Include version (from meta) and package name (the "source"). Remove the dependency on `ProviderLocator`.

- [ ] **Step 5: Update `InfoCommand` + `DiagnoseCommand`**

`InfoCommand`: print package name, provider, `requiresGlueful`, `requiresExtensions`, and state. `DiagnoseCommand`: call `resolveProviderClasses()` + `getResolverErrors()`, print each error; in production, assert the compiled cache exists/readable and report if missing.

- [ ] **Step 6: Delete `WhyCommand`**

```bash
git rm src/Console/Commands/Extensions/WhyCommand.php
grep -rn "WhyCommand\|why" src/Console/Commands/Extensions/   # ensure no registration references remain
```

- [ ] **Step 7: CLI integration test**

`tests/Integration/Console/Extensions/ExtensionCliTest.php`: in a temp app (config + installed.php fixtures as in Task 5, wired with the config-loader harness from Conventions), assert:
- `enable <name>` → SUCCESS, FQCN added to `config/extensions.php`.
- `enable <name>` again → SUCCESS, "already enabled", no duplicate.
- `disable <name>` → SUCCESS, FQCN removed.
- `enable <unknown>` → FAILURE with the "not found among installed packages" message; config unchanged.
- **`enable <X>` where X declares an unmet `requires.extensions`** → **FAILURE with the dependency error, and `config/extensions.php` is NOT modified** (validate-before-write — the headline guarantee that the command never leaves a broken config).
- **`disable <dep>` while another enabled extension requires it** → FAILURE, config unchanged.

Use the framework's command-test harness (mirror `tests/Unit/Console/Commands/Fields/WhitelistCheckCommandTest.php` for constructing a command with a container; resolve `ExtensionManager` from it for the recompile step).

- [ ] **Step 8: Run + commit**

Run: `vendor/bin/phpunit tests/Integration/Console/Extensions/ExtensionCliTest.php`
Expected: PASS.

```bash
git add -A
git commit -m "feat(ext): CLI governs the single enabled list — enable/disable via writer, strict cache, stateful list, why folded into list"
```

---

## Task 8: `create:extension` → composer package + path repo (prints require)

**Files:**
- Modify: `src/Console/Commands/Extensions/CreateCommand.php`
- Test: `tests/Integration/Console/Extensions/CreateExtensionTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Integration/Console/Extensions/CreateExtensionTest.php`: run `create:extension Widgets` against a temp app dir; assert it creates `extensions/widgets/composer.json` containing `"type": "glueful-extension"`, an `extra.glueful.provider` FQCN, and a PSR-4 mapping; creates `src/WidgetsServiceProvider.php` extending `ServiceProvider`; creates `routes/routes.php` + `database/migrations/`; and adds a path repository entry for `extensions/widgets` to the app's `composer.json`.

```php
public function testScaffoldsComposerPackageAndPathRepo(): void
{
    // ... set up temp app dir with a minimal composer.json ...
    // run command: create:extension Widgets
    $composer = json_decode(file_get_contents($extDir . '/composer.json'), true);
    $this->assertSame('glueful-extension', $composer['type']);
    $this->assertArrayHasKey('provider', $composer['extra']['glueful']);
    $this->assertArrayHasKey('Glueful\\Extensions\\Widgets\\', $composer['autoload']['psr-4']);
    $this->assertFileExists($extDir . '/src/WidgetsServiceProvider.php');
    $this->assertFileExists($extDir . '/routes/routes.php');

    $appComposer = json_decode(file_get_contents($appDir . '/composer.json'), true);
    $repoPaths = array_column($appComposer['repositories'] ?? [], 'url');
    $this->assertContains('extensions/widgets', $repoPaths);
}
```

- [ ] **Step 2: Run it — verify it fails** (the current command writes a different layout / no composer.json / no path repo).

- [ ] **Step 3: Implement the new scaffold**

Update `CreateCommand` to write, under `extensions/<slug>/`:

- `composer.json`:
  ```json
  {
    "name": "glueful/<slug>",
    "type": "glueful-extension",
    "require": { "glueful/framework": ">=1.46.0" },
    "autoload": { "psr-4": { "Glueful\\Extensions\\<Studly>\\": "src/" } },
    "extra": { "glueful": {
      "provider": "Glueful\\Extensions\\<Studly>\\<Studly>ServiceProvider",
      "requires": { "glueful": ">=1.46.0", "extensions": [] }
    } }
  }
  ```
- `src/<Studly>ServiceProvider.php` — extends `Glueful\Extensions\ServiceProvider`, `register()` mergeConfig stub, `boot()` loading `__DIR__ . '/../routes/routes.php'` + `__DIR__ . '/../database/migrations'` + `registerMeta(...)` (mirror the layout the `glueful-create-extension` skill documents).
- `routes/routes.php` (empty router stub), `database/migrations/` (dir), `config/<slug>.php` (empty array).

Then update the app's `composer.json`: add `{ "type": "path", "url": "extensions/<slug>", "options": { "symlink": true } }` to `repositories` (create the key if absent), and print the follow-up command for the developer:

```
$output->writeln('Next: composer require glueful/<slug>:@dev && php glueful extensions:enable <slug>');
```

(Do not shell out to `composer require` automatically — print it; running composer inside the CLI is fragile. The path repo + the printed command complete the loop.)

- [ ] **Step 4: Run + commit**

Run: `vendor/bin/phpunit tests/Integration/Console/Extensions/CreateExtensionTest.php`
Expected: PASS.

```bash
git add -A
git commit -m "feat(ext): create:extension scaffolds a composer package + path repository"
```

---

## Task 9: New `config/extensions.php` template

**Files:**
- Modify: `config/extensions.php`

- [ ] **Step 1: Replace the file with the single-key model**

```php
<?php

/**
 * Extensions
 *
 * Composer discovers installed `glueful-extension` packages (see their
 * extra.glueful.provider). This file is the single activation allow-list:
 * an installed extension does nothing until its provider FQCN appears below.
 *
 * - Entries are plain string FQCNs (no ::class) so `php glueful extensions:enable|disable`
 *   can edit this list safely. Do not use conditionals/function calls here.
 * - Order is preserved; dependencies are reordered automatically.
 * - Empty = nothing loads. To kill everything fast, set `enabled => []`.
 *
 * Manage with: php glueful extensions:list | enable <name> | disable <name> | cache
 */

return [
    'enabled' => [
        // 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
    ],
];
```

- [ ] **Step 2: Commit**

```bash
git add config/extensions.php
git commit -m "feat(ext): single-key config/extensions.php template"
```

---

## Task 10: Migrate api-skeleton (path repos + config) + upgrade note

**Files (api-skeleton repo at `/Users/michaeltawiahsowah/Sites/glueful/api-skeleton`):**
- Modify: `composer.json`, `config/extensions.php`, `config/serviceproviders.php`
- Create: framework `docs/EXTENSIONS_UPGRADE.md`

- [ ] **Step 1: Add path repositories + require the official extensions (only those the skeleton ships enabled)**

In `api-skeleton/composer.json`, add a `repositories` array with a `path` entry per local extension dir consumed in dev (e.g. `../extensions/aegis`), then `composer require glueful/aegis:@dev` etc. for the ones the skeleton enables by default (likely none beyond what it already used — confirm against the skeleton's current behavior).

- [ ] **Step 2: Rewrite `api-skeleton/config/extensions.php`** to the single-key template (Task 9), moving any previously-loaded providers into `enabled` as string FQCNs; map old keys via the spec's table.

- [ ] **Step 3: Rewrite `api-skeleton/config/serviceproviders.php`** to the single-key template (Task 3 Step 5), moving its `enabled` entries to string FQCNs and folding any `dev_only`/`only`/`disabled` per the spec.

- [ ] **Step 4: `php glueful extensions:cache`** in the skeleton; confirm `extensions:list` shows expected states and boot works.

- [ ] **Step 5: Write the upgrade note**

`framework/docs/EXTENSIONS_UPGRADE.md` — the old→new config key-mapping table from the spec, the "install + enable + cache" steps, the path-repository instructions for local extensions, and the behavioral changes (no auto-load on install; deps must be enabled; prod requires `extensions:cache`).

- [ ] **Step 6: Commit (per repo)**

```bash
# in api-skeleton
git add composer.json config/extensions.php config/serviceproviders.php
git commit -m "chore: migrate to single-key extension config + composer path repositories"
# in framework
git add docs/EXTENSIONS_UPGRADE.md
git commit -m "docs(ext): extension system upgrade note (old keys -> enabled; path repos)"
```

---

## Task 11: Final verification

- [ ] **Step 1: Full gate**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/framework
composer run phpcs
composer run analyse
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
```
Expected: all clean; new `tests/Unit/Extensions/*` + `tests/Integration/Extensions/*` + CLI tests pass.

- [ ] **Step 2: Dead-reference sweep**

```bash
grep -rn "ProviderLocator\|local_path\|scan_composer\|extensions\.only\|extensions\.dev_only\|extensions\.disabled\|WhyCommand" src/ config/ tests/
```
Expected: ZERO results (all removed/migrated).

- [ ] **Step 3: Manual smoke (against api-skeleton)**

```bash
php glueful extensions:list                 # shows candidates + states
php glueful extensions:enable aegis         # adds FQCN to config; recompiles
php glueful extensions:disable aegis        # removes it
php glueful extensions:cache                # strict; fails loudly if an enabled dep is missing
```

- [ ] **Step 4: Commit any final fixes**

```bash
git add -A && git commit -m "test(ext): final verification fixes for extension re-architecture"
```

---

## Self-Review (completed during planning)

**Spec coverage:**
- Composer-only discovery → Task 1 (`getCandidates`) + Task 6 (delete ProviderLocator/local-scan).
- Single `enabled` gate → Task 9 (config) + Task 2 (resolver consumes `enabled`).
- Pure resolver (select/validate/order, `{providers, errors}`, no env) → Task 2 + parity test.
- Validation (missing provider/dependency, version, cycle) → Task 2 tests.
- Strict build / lenient runtime / prod-fail-if-missing → Task 5 Step 5 (discover) + Task 7 Step 3 (strict `cache`).
- Single shared resolution path (no drift) → `ProviderClassResolver`, used by both `ExtensionManager` (Task 5) and `ContainerFactory` (Task 6).
- App service providers separate path + single-key serviceproviders.php → Task 3.
- String FQCNs + writer refuses non-trivial (comment-tolerant) → Task 4.
- CLI governs the one list; **enable/disable validate before writing** (no broken config); `why` folded into `list` → Task 7.
- `create:extension` → composer package + path repo, prints `composer require` → Task 8.
- Migration (7 extensions zero-change; api-skeleton; upgrade note) → Task 10.
- Components deleted (`ProviderLocator`, local scan, runtime PSR-4) → Task 6.
- Explicit `composer/semver` dependency → Task 2 Step 0.

**Type consistency:** `ExtensionCandidate{name, provider, requiresGlueful, requiresExtensions}`, `ResolverResult{providers, errors, hasErrors()}`, `ResolverError{kind, provider, message}`, `ExtensionResolver::resolve(candidates, enabled, frameworkVersion): ResolverResult`, `ProviderClassResolver::resolve(context): ResolverResult`, `ExtensionStateWriter::enable|disable(path, provider, dryRun, backup)`, `AppProviderLoader::load(context)`, `ExtensionManager::resolveProviderClasses()/getResolverErrors()` — used identically across tasks.

**Resolved review points (this revision):** config-loader test harness (Conventions + Tasks 3/5); writer strips comments before the literal check (Task 4); enable/disable validate-before-write (Task 7); `PackageManifest::rawPackages()` preserves multi-vendor (Task 1); explicit `composer/semver` (Task 2); `ProviderClassResolver` removes the ContainerFactory/Manager duplication (Tasks 5–6); placeholder container test removed (Task 6); `create:extension` prints rather than runs `composer require` (spec + Task 8).

**Remaining verify-before-edit note (not a placeholder):** the `addProvider()` `is_subclass_of(ServiceProvider::class)` check means a plain `services()` app provider (not extending `ServiceProvider`) gets its container defs via `ContainerFactory` but is not added to the boot list — matches today's behavior; confirm by reading `addProvider()` before editing (Task 5 Step 4 note).
