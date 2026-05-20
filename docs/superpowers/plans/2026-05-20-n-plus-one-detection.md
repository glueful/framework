# N+1 Query Detection — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an ORM-aware N+1 query detector to `glueful/framework` that fires at the model layer with `warn`, `strict`, `off`, and `auto` modes, plus per-model opt-out.

**Architecture:** A new `PreventsLazyLoading` trait on `Model` carries global mode (static) and per-model `loadedFromCollection` flag. `Builder::hydrate()` tags models when result count > 1. `HasRelationships::getRelationshipFromMethod()` checks the flag before lazy-loading and routes violations to the trait's handler. Strict mode throws; warn mode `error_log`s with per-request dedupe.

**Tech Stack:** PHP 8.3+, PHPUnit 10, SQLite (in-memory for integration tests).

**Spec:** `docs/superpowers/specs/2026-05-20-n-plus-one-detection-design.md`

---

## File Structure

**New files:**

| Path | Responsibility |
|---|---|
| `src/Database/ORM/Concerns/PreventsLazyLoading.php` | Trait. Static mode, instance flag, violation handler, dedupe. |
| `src/Database/ORM/Exceptions/LazyLoadingViolationException.php` | `\LogicException` subclass. Public readonly `modelClass` and `relation`. |
| `tests/Support/Traits/ResetsLazyLoading.php` | Opt-in PHPUnit trait calling `Model::resetLazyLoadingState()` in `tearDown()`. |
| `tests/Unit/Database/ORM/LazyLoadingViolationExceptionTest.php` | Exception shape. |
| `tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php` | Trait state + mode resolution + dedupe + reset. |
| `tests/Unit/Database/ORM/BuilderHydrationTaggingTest.php` | Unit-tests the trait setter and the `Model::lazyLoadingEnabled()` guard that `Builder::hydrate()` calls. The real `hydrate()` tagging is verified end-to-end by Task 11. |
| `tests/Integration/Database/ORM/LazyLoadingDetectionTest.php` | Full ORM behavior matrix with SQLite. Includes CI-enforcement scenarios. |
| `docs/ORM/N_PLUS_ONE_DETECTION.md` | Public docs. |

**Edits:**

| Path | Change |
|---|---|
| `src/Database/ORM/Model.php` | `use PreventsLazyLoading;` |
| `src/Database/ORM/Builder.php` | Tagging block in `hydrate()`. |
| `src/Database/ORM/Concerns/HasRelationships.php` | Detection check in `getRelationshipFromMethod()`. |
| `src/Framework.php` | New `initializeOrmFeatures()` method + call from `boot()`. |
| `config/database.php` | Add `orm.lazy_loading_mode` key. |
| `CLAUDE.md` | Pointer bullet under ORM section. |
| `docs/FRAMEWORK_IMPROVEMENTS.md` | Flip Tier 1 N+1 row to ✅. |

---

## Task 1: Test Infrastructure — `ResetsLazyLoading` Trait

**Files:**
- Create: `tests/Support/Traits/ResetsLazyLoading.php`

- [ ] **Step 1: Create the trait**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Traits;

use Glueful\Database\ORM\Model;

/**
 * PHPUnit trait that clears N+1 detector static state in tearDown().
 * Include this in any test class that mutates lazy-loading global state.
 */
trait ResetsLazyLoading
{
    protected function tearDown(): void
    {
        if (method_exists(Model::class, 'resetLazyLoadingState')) {
            Model::resetLazyLoadingState();
        }
        parent::tearDown();
    }
}
```

The `method_exists` guard lets the trait be added before the Model method exists. It will be removed when the implementation is complete (see Task 4 cleanup step).

- [ ] **Step 2: Commit**

```bash
git add tests/Support/Traits/ResetsLazyLoading.php
git commit -m "test(orm): add ResetsLazyLoading trait for N+1 detector state isolation"
```

---

## Task 2: Exception Class — `LazyLoadingViolationException`

**Files:**
- Create: `src/Database/ORM/Exceptions/LazyLoadingViolationException.php`
- Test: `tests/Unit/Database/ORM/LazyLoadingViolationExceptionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Exceptions\LazyLoadingViolationException;
use PHPUnit\Framework\TestCase;

class LazyLoadingViolationExceptionTest extends TestCase
{
    public function testCarriesModelClassAndRelation(): void
    {
        $exception = new LazyLoadingViolationException('App\\Models\\User', 'posts');

        $this->assertSame('App\\Models\\User', $exception->modelClass);
        $this->assertSame('posts', $exception->relation);
    }

    public function testMessageMentionsBothModelAndRelation(): void
    {
        $exception = new LazyLoadingViolationException('App\\Models\\User', 'posts');

        $this->assertStringContainsString('posts', $exception->getMessage());
        $this->assertStringContainsString('App\\Models\\User', $exception->getMessage());
        $this->assertStringContainsString("->with('posts')", $exception->getMessage());
    }

    public function testExtendsLogicException(): void
    {
        $this->assertInstanceOf(
            \LogicException::class,
            new LazyLoadingViolationException('A', 'b')
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/LazyLoadingViolationExceptionTest.php -v`
Expected: FAIL — class `LazyLoadingViolationException` not found.

- [ ] **Step 3: Implement the exception class**

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Exceptions;

final class LazyLoadingViolationException extends \LogicException
{
    public function __construct(
        public readonly string $modelClass,
        public readonly string $relation,
    ) {
        parent::__construct(sprintf(
            'Attempted to lazy-load [%s] on model [%s], but lazy loading is disabled. '
            . "Add ->with('%s') to the query, or set "
            . '$instanceLazyLoadingMode = \'off\' on the model.',
            $relation,
            $modelClass,
            $relation,
        ));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/LazyLoadingViolationExceptionTest.php -v`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Database/ORM/Exceptions/LazyLoadingViolationException.php \
        tests/Unit/Database/ORM/LazyLoadingViolationExceptionTest.php
git commit -m "feat(orm): add LazyLoadingViolationException for strict-mode N+1 detection"
```

---

## Task 3: Trait Skeleton — Static State and Mode Helpers

**Files:**
- Create: `src/Database/ORM/Concerns/PreventsLazyLoading.php`
- Test: `tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php`

- [ ] **Step 1: Write failing tests for state and mode helpers**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Concerns\PreventsLazyLoading;
use PHPUnit\Framework\TestCase;

/**
 * Anonymous host class that uses the trait under test. Static properties
 * on a trait are per-using-class in PHP, so TraitHost has its own copy
 * independent of Model. Tests reset TraitHost state directly in tearDown.
 */
class TraitHost
{
    use PreventsLazyLoading;
}

class PreventsLazyLoadingTraitTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset BOTH the local TraitHost and Model — each class that uses
        // the trait owns its own copy of the static state. Later tasks
        // (Task 7+) add tests that mutate Model state, so reset both here.
        TraitHost::resetLazyLoadingState();
        if (method_exists(\Glueful\Database\ORM\Model::class, 'resetLazyLoadingState')) {
            \Glueful\Database\ORM\Model::resetLazyLoadingState();
        }
        parent::tearDown();
    }

    public function testDefaultModeIsOff(): void
    {
        $this->assertFalse(TraitHost::lazyLoadingEnabled());
    }

    public function testPreventLazyLoadingSetsMode(): void
    {
        TraitHost::preventLazyLoading('warn');
        $this->assertTrue(TraitHost::lazyLoadingEnabled());
    }

    public function testLazyLoadingEnabledIsFalseForOffMode(): void
    {
        TraitHost::preventLazyLoading('off');
        $this->assertFalse(TraitHost::lazyLoadingEnabled());
    }

    public function testResetClearsMode(): void
    {
        TraitHost::preventLazyLoading('strict');
        TraitHost::resetLazyLoadingState();
        $this->assertFalse(TraitHost::lazyLoadingEnabled());
    }

    public function testAutoResolvesToWarnInDevelopment(): void
    {
        $prev = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'development';
        try {
            TraitHost::preventLazyLoading('auto');
            $this->assertTrue(TraitHost::lazyLoadingEnabled());
            $this->assertSame('warn', TraitHost::resolvedGlobalMode());
        } finally {
            if ($prev === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $prev;
            }
        }
    }

    public function testAutoResolvesToOffOutsideDevelopment(): void
    {
        $prev = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'production';
        try {
            TraitHost::preventLazyLoading('auto');
            $this->assertFalse(TraitHost::lazyLoadingEnabled());
        } finally {
            if ($prev === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $prev;
            }
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php -v`
Expected: FAIL — trait `PreventsLazyLoading` not found.

- [ ] **Step 3: Implement the trait — static state and mode helpers**

```php
<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Concerns;

use Glueful\Database\ORM\Exceptions\LazyLoadingViolationException;

/**
 * ORM-aware N+1 detection. Models tagged as loaded-from-collection by
 * Builder::hydrate() route relation-access attempts through this trait
 * when global mode is 'warn' or 'strict'. See:
 *   docs/ORM/N_PLUS_ONE_DETECTION.md
 *   docs/superpowers/specs/2026-05-20-n-plus-one-detection-design.md
 */
trait PreventsLazyLoading
{
    protected static string $lazyLoadingMode = 'off';

    protected static ?\Closure $violationCallback = null;

    /** @var array<string, true> */
    protected static array $warnedPairs = [];

    protected ?string $instanceLazyLoadingMode = null;

    protected bool $loadedFromCollection = false;

    public static function preventLazyLoading(string $mode = 'strict'): void
    {
        self::$lazyLoadingMode = self::resolveAutoMode($mode);
    }

    public static function lazyLoadingEnabled(): bool
    {
        return self::$lazyLoadingMode !== 'off';
    }

    public static function resolvedGlobalMode(): string
    {
        return self::$lazyLoadingMode;
    }

    public static function resetLazyLoadingState(): void
    {
        self::$lazyLoadingMode = 'off';
        self::$violationCallback = null;
        self::$warnedPairs = [];
    }

    private static function resolveAutoMode(string $mode): string
    {
        if ($mode !== 'auto') {
            return $mode;
        }

        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '';

        return $env === 'development' ? 'warn' : 'off';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php -v`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Database/ORM/Concerns/PreventsLazyLoading.php \
        tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php
git commit -m "feat(orm): add PreventsLazyLoading trait with mode resolution"
```

---

## Task 4: Wire Trait into Model

**Files:**
- Modify: `src/Database/ORM/Model.php`
- Modify: `tests/Support/Traits/ResetsLazyLoading.php`

- [ ] **Step 1: Add the trait to Model**

Open `src/Database/ORM/Model.php`. After the existing `use HasRelationships;` line near the top of the class body (around line 57-65), add:

```php
    use PreventsLazyLoading;
```

And add to the imports near the top of the file (after the existing `use Glueful\Database\ORM\Concerns\HasRelationships;` line):

```php
use Glueful\Database\ORM\Concerns\PreventsLazyLoading;
```

- [ ] **Step 2: Remove the method_exists guard from the test trait**

The guard in `ResetsLazyLoading` is no longer needed because `Model::resetLazyLoadingState()` now exists. Edit `tests/Support/Traits/ResetsLazyLoading.php`:

Replace:
```php
        if (method_exists(Model::class, 'resetLazyLoadingState')) {
            Model::resetLazyLoadingState();
        }
```

With:
```php
        Model::resetLazyLoadingState();
```

- [ ] **Step 3: Run the existing model tests to make sure nothing broke**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/ModelTest.php -v`
Expected: PASS (or skipped tests, same as before — no regressions).

- [ ] **Step 4: Commit**

```bash
git add src/Database/ORM/Model.php tests/Support/Traits/ResetsLazyLoading.php
git commit -m "feat(orm): wire PreventsLazyLoading trait into Model base class"
```

---

## Task 5: Config Entry and Framework Boot Wiring

**Files:**
- Modify: `config/database.php`
- Modify: `src/Framework.php`
- Test: `tests/Integration/FrameworkBootTest.php` (add a method)

- [ ] **Step 1: Add config entry**

Open `config/database.php`. Above the existing `'migrations' => [...]` block near the end, add:

```php
    // ORM features
    'orm' => [
        'lazy_loading_mode' => env('DB_LAZY_LOADING_MODE', 'auto'),
        // 'off'    — skips hydration tagging and violation checks; minimal overhead
        // 'warn'   — log warning via error_log() with [GLUEFUL-N+1] prefix
        // 'strict' — throw LazyLoadingViolationException
        // 'auto'   — 'warn' in development, 'off' otherwise
    ],

```

- [ ] **Step 2: Write a failing test that proves boot read the config**

Open `tests/Integration/FrameworkBootTest.php`. Add this method to the existing class. It writes an explicit `'strict'` into the temp config file, boots the framework, and asserts the mode was applied — proving boot actually ran the wiring (not just that the default happened to match).

```php
    public function testBootAppliesLazyLoadingModeFromConfig(): void
    {
        // Override the database config written in setUp() with an explicit
        // strict mode, so passing the assertion proves the boot wiring ran.
        file_put_contents(
            $this->testConfigPath . '/database.php',
            "<?php\nreturn ["
            . "'engine' => 'sqlite', "
            . "'sqlite' => ['primary' => ':memory:'], "
            . "'pooling' => ['enabled' => false], "
            . "'orm' => ['lazy_loading_mode' => 'strict']"
            . "];\n"
        );

        try {
            Framework::create($this->testAppPath)->boot(allowReboot: true);

            $this->assertSame(
                'strict',
                \Glueful\Database\ORM\Model::resolvedGlobalMode(),
                'Framework::boot() should have read database.orm.lazy_loading_mode '
                . 'from config and called Model::preventLazyLoading()'
            );
        } finally {
            \Glueful\Database\ORM\Model::resetLazyLoadingState();
        }
    }
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/FrameworkBootTest.php --filter testBootAppliesLazyLoadingModeFromConfig -v`
Expected: FAIL — the global mode is still `'off'` (default) because `Framework::initializeOrmFeatures()` doesn't exist yet.

- [ ] **Step 4: Wire boot in Framework**

Open `src/Framework.php`. Locate the `boot()` method body (line 92 onwards). After the existing `CacheInvalidationService::warmupPatterns();` line (around line 340), and BEFORE the `if ($this->environment === 'development')` block, add:

```php
        // Initialize ORM features (unconditional; mode resolution handles env defaults)
        $this->initializeOrmFeatures();
```

Then add a new private method at the bottom of the class (after `initializeDevelopmentTools()`):

```php
    /**
     * Initialize ORM-level features.
     *
     * Runs unconditionally on boot so opt-in modes (e.g. strict for CI) work
     * outside the development environment.
     */
    private function initializeOrmFeatures(): void
    {
        if ($this->container === null) {
            return;
        }

        $context = $this->container->get(\Glueful\Bootstrap\ApplicationContext::class);
        $mode = config($context, 'database.orm.lazy_loading_mode', 'auto');

        if (!is_string($mode)) {
            $mode = 'auto';
        }

        \Glueful\Database\ORM\Model::preventLazyLoading($mode);
    }
```

If the container or context resolution differs in your installation, mirror the pattern used by other `initialize*` methods in the same file.

- [ ] **Step 5: Add a dev-environment resolution test**

In `tests/Integration/FrameworkBootTest.php`, add:

```php
    public function testAutoModeResolvesToWarnInDevelopmentEnvironment(): void
    {
        $prev = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'development';
        try {
            \Glueful\Database\ORM\Model::preventLazyLoading('auto');
            $this->assertSame('warn', \Glueful\Database\ORM\Model::resolvedGlobalMode());
        } finally {
            \Glueful\Database\ORM\Model::resetLazyLoadingState();
            if ($prev === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $prev;
            }
        }
    }
```

- [ ] **Step 6: Run both tests**

Run: `vendor/bin/phpunit tests/Integration/FrameworkBootTest.php -v`
Expected: PASS (existing tests + the two new ones).

- [ ] **Step 7: Commit**

```bash
git add config/database.php src/Framework.php tests/Integration/FrameworkBootTest.php
git commit -m "feat(orm): wire lazy-loading mode config into Framework boot"
```

---

## Task 6: Builder Hydration Tagging

**Files:**
- Modify: `src/Database/ORM/Builder.php`
- Modify: `src/Database/ORM/Concerns/PreventsLazyLoading.php` (add `setLoadedFromCollection`)
- Test: `tests/Unit/Database/ORM/BuilderHydrationTaggingTest.php`

- [ ] **Step 1: Add the instance setter to the trait**

Open `src/Database/ORM/Concerns/PreventsLazyLoading.php`. Append before the closing brace of the trait:

```php

    public function setLoadedFromCollection(bool $value): void
    {
        $this->loadedFromCollection = $value;
    }

    public function wasLoadedFromCollection(): bool
    {
        return $this->loadedFromCollection;
    }
```

- [ ] **Step 2: Create the shared stub model**

Create `tests/Support/Stubs/HydrationTaggingTestModel.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Stubs;

use Glueful\Database\ORM\Model;

/**
 * Minimal model stub for N+1 detector tests. Defined once in Support/
 * to avoid duplicate-class errors across test files that need this fixture.
 */
class HydrationTaggingTestModel extends Model
{
    protected string $table = 'fake';
    public bool $exists = false;
}
```

- [ ] **Step 3: Write the failing test**

Create `tests/Unit/Database/ORM/BuilderHydrationTaggingTest.php`. Note: we test the trait's tagging behavior in isolation here — the actual `Builder::hydrate()` change is verified end-to-end by the integration test in Task 11 (`testHydratedCollectionIsTaggedAsLoadedFromCollection`), which boots the framework and uses a real ORM query. Constructing a real `Builder` in a unit test requires a real `QueryBuilder` (the framework's `setModel()` calls `$query->from()`), and that infrastructure is heavy for what is fundamentally a one-line tagging block.

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\ORM;

use Glueful\Database\ORM\Model;
use PHPUnit\Framework\TestCase;
use Glueful\Tests\Support\Stubs\HydrationTaggingTestModel;
use Glueful\Tests\Support\Traits\ResetsLazyLoading;

class BuilderHydrationTaggingTest extends TestCase
{
    use ResetsLazyLoading;

    public function testSetLoadedFromCollectionFlagPersists(): void
    {
        $model = new HydrationTaggingTestModel();
        $this->assertFalse($model->wasLoadedFromCollection());

        $model->setLoadedFromCollection(true);
        $this->assertTrue($model->wasLoadedFromCollection());

        $model->setLoadedFromCollection(false);
        $this->assertFalse($model->wasLoadedFromCollection());
    }

    public function testLazyLoadingEnabledControlsTaggingDecision(): void
    {
        // This test verifies the GUARD logic that Builder::hydrate() uses —
        // namely Model::lazyLoadingEnabled(). It does NOT exercise the real
        // hydrate(); the integration test in Task 11 does that.
        Model::preventLazyLoading('off');
        $this->assertFalse(Model::lazyLoadingEnabled());

        Model::preventLazyLoading('warn');
        $this->assertTrue(Model::lazyLoadingEnabled());

        Model::preventLazyLoading('strict');
        $this->assertTrue(Model::lazyLoadingEnabled());
    }
}
```

- [ ] **Step 4: Run the test to verify it passes already**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/BuilderHydrationTaggingTest.php -v`
Expected: PASS, 2 tests. The trait setter/getter and `Model::lazyLoadingEnabled()` were already added in earlier tasks; this unit test simply documents the contract that `Builder::hydrate()` depends on. The actual end-to-end behavior is verified in Task 11.

- [ ] **Step 5: Add the tagging logic to `Builder::hydrate()`**

Open `src/Database/ORM/Builder.php`. The current `hydrate()` method (around line 398-407):

```php
    public function hydrate(array $results): array
    {
        $models = [];

        foreach ($results as $result) {
            $models[] = $this->model->newFromBuilder($result);
        }

        return $models;
    }
```

Replace with:

```php
    public function hydrate(array $results): array
    {
        $models = [];

        foreach ($results as $result) {
            $models[] = $this->model->newFromBuilder($result);
        }

        if (count($models) > 1 && Model::lazyLoadingEnabled()) {
            foreach ($models as $m) {
                $m->setLoadedFromCollection(true);
            }
        }

        return $models;
    }
```

- [ ] **Step 6: Run tests to verify they still pass after the Builder edit**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/BuilderHydrationTaggingTest.php -v`
Expected: PASS, 2 tests. The Builder change is exercised end-to-end by Task 11's integration test.

- [ ] **Step 7: Commit**

```bash
git add src/Database/ORM/Builder.php \
        src/Database/ORM/Concerns/PreventsLazyLoading.php \
        tests/Support/Stubs/HydrationTaggingTestModel.php \
        tests/Unit/Database/ORM/BuilderHydrationTaggingTest.php
git commit -m "feat(orm): tag collection-hydrated models for N+1 detection"
```

---

## Task 7: Detection Hook + Warn Handler with Dedupe

**Files:**
- Modify: `src/Database/ORM/Concerns/PreventsLazyLoading.php`
- Modify: `src/Database/ORM/Concerns/HasRelationships.php`
- Modify: `tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php`

- [ ] **Step 1: Write failing tests for the warn handler and dedupe**

Append to `tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php` (inside the same class):

```php
    public function testWarnModeLogsViaErrorLog(): void
    {
        Model::preventLazyLoading('warn');

        // Capture error_log output by routing to a temp file
        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prevLog = ini_set('error_log', $tmp);
        try {
            $model = new HydrationTaggingTestModel();
            $model->setLoadedFromCollection(true);

            // Use reflection to invoke the protected handler
            $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
            $ref->setAccessible(true);
            $ref->invoke($model, 'posts');

            $logged = file_get_contents($tmp);
            $this->assertStringContainsString('[GLUEFUL-N+1]', $logged);
            $this->assertStringContainsString('posts', $logged);
        } finally {
            ini_set('error_log', $prevLog);
            @unlink($tmp);
        }
    }

    public function testWarnModeDedupesWithinRequest(): void
    {
        Model::preventLazyLoading('warn');

        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prevLog = ini_set('error_log', $tmp);
        try {
            $model = new HydrationTaggingTestModel();
            $model->setLoadedFromCollection(true);

            $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
            $ref->setAccessible(true);
            $ref->invoke($model, 'posts');
            $ref->invoke($model, 'posts');
            $ref->invoke($model, 'posts');

            $occurrences = substr_count(file_get_contents($tmp), '[GLUEFUL-N+1]');
            $this->assertSame(1, $occurrences, 'Same pair should only warn once');
        } finally {
            ini_set('error_log', $prevLog);
            @unlink($tmp);
        }
    }

    public function testClearLazyLoadingWarningsAllowsRewarning(): void
    {
        Model::preventLazyLoading('warn');

        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prevLog = ini_set('error_log', $tmp);
        try {
            $model = new HydrationTaggingTestModel();
            $model->setLoadedFromCollection(true);

            $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
            $ref->setAccessible(true);
            $ref->invoke($model, 'posts');
            Model::clearLazyLoadingWarnings();
            $ref->invoke($model, 'posts');

            $occurrences = substr_count(file_get_contents($tmp), '[GLUEFUL-N+1]');
            $this->assertSame(2, $occurrences);
        } finally {
            ini_set('error_log', $prevLog);
            @unlink($tmp);
        }
    }
```

Add these imports at the top of `PreventsLazyLoadingTraitTest.php`:

```php
use Glueful\Database\ORM\Model;
use Glueful\Tests\Support\Stubs\HydrationTaggingTestModel;
```

The stub was moved to `tests/Support/Stubs/HydrationTaggingTestModel.php` in Task 6 specifically so it can be shared across test files without duplicate-class errors.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php -v`
Expected: FAIL — `handleLazyLoadingViolation` and `clearLazyLoadingWarnings` don't exist yet.

- [ ] **Step 3: Add the warn handler, dedupe, and `clearLazyLoadingWarnings` to the trait**

Open `src/Database/ORM/Concerns/PreventsLazyLoading.php`. Append before the closing brace:

```php

    public static function clearLazyLoadingWarnings(): void
    {
        self::$warnedPairs = [];
    }

    protected function preventsLazyLoadingNow(): bool
    {
        return $this->loadedFromCollection && self::$lazyLoadingMode !== 'off';
    }

    protected function handleLazyLoadingViolation(string $relation): void
    {
        if (self::$lazyLoadingMode === 'warn') {
            $key = static::class . '::' . $relation;
            if (isset(self::$warnedPairs[$key])) {
                return;
            }
            self::$warnedPairs[$key] = true;

            error_log(sprintf(
                "[GLUEFUL-N+1] Lazy-load detected on collection-loaded model: %s::%s. "
                . "Add ->with('%s') to the query.",
                static::class,
                $relation,
                $relation,
            ));
        }
    }
```

- [ ] **Step 4: Wire the check into `HasRelationships::getRelationshipFromMethod`**

Open `src/Database/ORM/Concerns/HasRelationships.php`. The current method (lines 393-406):

```php
    protected function getRelationshipFromMethod(string $method): mixed
    {
        $relation = $this->$method();

        if (!$relation instanceof Relation) {
            return null;
        }

        $results = $relation->getResults();

        $this->setRelation($method, $results);

        return $results;
    }
```

Replace with:

```php
    protected function getRelationshipFromMethod(string $method): mixed
    {
        $relation = $this->$method();

        if (!$relation instanceof Relation) {
            return null;
        }

        if (method_exists($this, 'preventsLazyLoadingNow') && $this->preventsLazyLoadingNow()) {
            $this->handleLazyLoadingViolation($method);
        }

        $results = $relation->getResults();

        $this->setRelation($method, $results);

        return $results;
    }
```

The `method_exists` check is defensive — it covers the case where `HasRelationships` is used on a class that does NOT also `use PreventsLazyLoading`. Since `Model` uses both, this is essentially always true, but the guard avoids a fatal error if the traits are ever decoupled.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php -v`
Expected: PASS — all original tests + the 3 new ones.

- [ ] **Step 6: Commit**

```bash
git add src/Database/ORM/Concerns/PreventsLazyLoading.php \
        src/Database/ORM/Concerns/HasRelationships.php \
        tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php
git commit -m "feat(orm): wire warn-mode N+1 detection with per-request dedupe"
```

---

## Task 8: Strict Mode

**Files:**
- Modify: `src/Database/ORM/Concerns/PreventsLazyLoading.php`
- Modify: `tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php`

- [ ] **Step 1: Write the failing test**

Append to `PreventsLazyLoadingTraitTest`:

```php
    public function testStrictModeThrowsException(): void
    {
        Model::preventLazyLoading('strict');

        $model = new HydrationTaggingTestModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
        $ref->setAccessible(true);

        $this->expectException(\Glueful\Database\ORM\Exceptions\LazyLoadingViolationException::class);
        $ref->invoke($model, 'posts');
    }

    public function testStrictModeExceptionCarriesContext(): void
    {
        Model::preventLazyLoading('strict');

        $model = new HydrationTaggingTestModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
        $ref->setAccessible(true);

        try {
            $ref->invoke($model, 'posts');
            $this->fail('Expected exception not thrown');
        } catch (\Glueful\Database\ORM\Exceptions\LazyLoadingViolationException $e) {
            $this->assertSame(HydrationTaggingTestModel::class, $e->modelClass);
            $this->assertSame('posts', $e->relation);
        }
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php --filter Strict -v`
Expected: FAIL — strict mode doesn't throw yet.

- [ ] **Step 3: Add strict mode to the handler**

In `src/Database/ORM/Concerns/PreventsLazyLoading.php`, update `handleLazyLoadingViolation()` to:

```php
    protected function handleLazyLoadingViolation(string $relation): void
    {
        if (self::$lazyLoadingMode === 'strict') {
            throw new LazyLoadingViolationException(static::class, $relation);
        }

        if (self::$lazyLoadingMode === 'warn') {
            $key = static::class . '::' . $relation;
            if (isset(self::$warnedPairs[$key])) {
                return;
            }
            self::$warnedPairs[$key] = true;

            error_log(sprintf(
                "[GLUEFUL-N+1] Lazy-load detected on collection-loaded model: %s::%s. "
                . "Add ->with('%s') to the query.",
                static::class,
                $relation,
                $relation,
            ));
        }
    }
```

(Note: `LazyLoadingViolationException` is already imported at the top of the trait file from Task 3.)

- [ ] **Step 4: Run all trait tests**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php -v`
Expected: PASS — original + warn + strict tests.

- [ ] **Step 5: Commit**

```bash
git add src/Database/ORM/Concerns/PreventsLazyLoading.php \
        tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php
git commit -m "feat(orm): add strict-mode N+1 detection that throws"
```

---

## Task 9: Custom Violation Callback

**Files:**
- Modify: `src/Database/ORM/Concerns/PreventsLazyLoading.php`
- Modify: `tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php`

- [ ] **Step 1: Write failing tests**

Append to `PreventsLazyLoadingTraitTest`:

```php
    public function testCustomCallbackReceivesModelAndRelation(): void
    {
        Model::preventLazyLoading('warn');

        $captured = null;
        Model::handleLazyLoadingViolationUsing(function ($model, $relation) use (&$captured) {
            $captured = [$model::class, $relation];
        });

        $model = new HydrationTaggingTestModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
        $ref->setAccessible(true);
        $ref->invoke($model, 'posts');

        $this->assertSame([HydrationTaggingTestModel::class, 'posts'], $captured);
    }

    public function testCustomCallbackReplacesDefaultBehavior(): void
    {
        Model::preventLazyLoading('strict');

        $invoked = false;
        Model::handleLazyLoadingViolationUsing(function () use (&$invoked) {
            $invoked = true;
            // Note: NOT throwing — proves strict-mode default is replaced
        });

        $model = new HydrationTaggingTestModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
        $ref->setAccessible(true);
        $ref->invoke($model, 'posts');  // Should NOT throw

        $this->assertTrue($invoked);
    }

    public function testNullCallbackClearsRegistration(): void
    {
        Model::preventLazyLoading('warn');

        $invoked = false;
        Model::handleLazyLoadingViolationUsing(function () use (&$invoked) {
            $invoked = true;
        });
        Model::handleLazyLoadingViolationUsing(null);

        // After clearing, default warn behavior resumes (we just check the callback didn't fire)
        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prevLog = ini_set('error_log', $tmp);
        try {
            $model = new HydrationTaggingTestModel();
            $model->setLoadedFromCollection(true);

            $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
            $ref->setAccessible(true);
            $ref->invoke($model, 'posts');

            $this->assertFalse($invoked);
            $this->assertStringContainsString('[GLUEFUL-N+1]', file_get_contents($tmp));
        } finally {
            ini_set('error_log', $prevLog);
            @unlink($tmp);
        }
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php --filter Callback -v`
Expected: FAIL — `handleLazyLoadingViolationUsing` doesn't exist.

- [ ] **Step 3: Add the callback support**

In `src/Database/ORM/Concerns/PreventsLazyLoading.php`:

```php
    public static function handleLazyLoadingViolationUsing(?\Closure $callback): void
    {
        self::$violationCallback = $callback;
    }
```

And update `handleLazyLoadingViolation()` to invoke the callback first:

```php
    protected function handleLazyLoadingViolation(string $relation): void
    {
        if (self::$violationCallback !== null) {
            (self::$violationCallback)($this, $relation);
            return;
        }

        if (self::$lazyLoadingMode === 'strict') {
            throw new LazyLoadingViolationException(static::class, $relation);
        }

        if (self::$lazyLoadingMode === 'warn') {
            $key = static::class . '::' . $relation;
            if (isset(self::$warnedPairs[$key])) {
                return;
            }
            self::$warnedPairs[$key] = true;

            error_log(sprintf(
                "[GLUEFUL-N+1] Lazy-load detected on collection-loaded model: %s::%s. "
                . "Add ->with('%s') to the query.",
                static::class,
                $relation,
                $relation,
            ));
        }
    }
```

- [ ] **Step 4: Run all tests**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php -v`
Expected: PASS, all tests.

- [ ] **Step 5: Commit**

```bash
git add src/Database/ORM/Concerns/PreventsLazyLoading.php \
        tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php
git commit -m "feat(orm): add custom violation callback for N+1 detection"
```

---

## Task 10: Per-Model Opt-Out

**Files:**
- Modify: `src/Database/ORM/Concerns/PreventsLazyLoading.php`
- Modify: `tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php`

- [ ] **Step 1: Write failing tests**

At the bottom of `PreventsLazyLoadingTraitTest.php` (outside the class), add a stub:

```php
class LegacyOptOutModel extends \Glueful\Database\ORM\Model
{
    protected string $table = 'fake';
    public bool $exists = false;
    protected ?string $instanceLazyLoadingMode = 'off';
}
```

Inside the class, add:

```php
    public function testPerModelOptOutBeatsGlobalStrict(): void
    {
        Model::preventLazyLoading('strict');

        $model = new LegacyOptOutModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'handleLazyLoadingViolation');
        $ref->setAccessible(true);
        $ref->invoke($model, 'posts');  // Should NOT throw

        $this->assertTrue(true, 'No exception thrown — opt-out worked');
    }

    public function testPreventsLazyLoadingNowReturnsFalseForOptedOutModel(): void
    {
        Model::preventLazyLoading('strict');

        $model = new LegacyOptOutModel();
        $model->setLoadedFromCollection(true);

        $ref = new \ReflectionMethod($model, 'preventsLazyLoadingNow');
        $ref->setAccessible(true);
        $this->assertFalse($ref->invoke($model));
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php --filter OptOut -v`
Expected: FAIL — `preventsLazyLoadingNow()` doesn't check the per-model property yet.

- [ ] **Step 3: Update `preventsLazyLoadingNow` to respect the per-model property**

In `src/Database/ORM/Concerns/PreventsLazyLoading.php`, replace `preventsLazyLoadingNow()`:

```php
    protected function preventsLazyLoadingNow(): bool
    {
        if (!$this->loadedFromCollection) {
            return false;
        }

        $mode = $this->instanceLazyLoadingMode ?? self::$lazyLoadingMode;

        return $mode !== 'off';
    }
```

Also update `handleLazyLoadingViolation()` to use `$mode` (the resolved per-instance mode), not `self::$lazyLoadingMode`:

```php
    protected function handleLazyLoadingViolation(string $relation): void
    {
        if (self::$violationCallback !== null) {
            (self::$violationCallback)($this, $relation);
            return;
        }

        $mode = $this->instanceLazyLoadingMode ?? self::$lazyLoadingMode;

        if ($mode === 'strict') {
            throw new LazyLoadingViolationException(static::class, $relation);
        }

        if ($mode === 'warn') {
            $key = static::class . '::' . $relation;
            if (isset(self::$warnedPairs[$key])) {
                return;
            }
            self::$warnedPairs[$key] = true;

            error_log(sprintf(
                "[GLUEFUL-N+1] Lazy-load detected on collection-loaded model: %s::%s. "
                . "Add ->with('%s') to the query.",
                static::class,
                $relation,
                $relation,
            ));
        }
    }
```

- [ ] **Step 4: Run all unit tests**

Run: `vendor/bin/phpunit tests/Unit/Database/ORM/ -v`
Expected: PASS for all ORM unit tests.

- [ ] **Step 5: Commit**

```bash
git add src/Database/ORM/Concerns/PreventsLazyLoading.php \
        tests/Unit/Database/ORM/PreventsLazyLoadingTraitTest.php
git commit -m "feat(orm): support per-model lazy-loading opt-out"
```

---

## Task 11: Integration Test — Behavior Matrix with SQLite

**Files:**
- Create: `tests/Integration/Database/ORM/LazyLoadingDetectionTest.php`

This task covers every row in the spec's behavior matrix using the real ORM against in-memory SQLite. It's a single test file with multiple methods.

- [ ] **Step 1: Create the test directory and fixtures**

```bash
mkdir -p tests/Integration/Database/ORM
```

- [ ] **Step 2: Verify the ORM query API signature before writing tests**

The Model API requires an `ApplicationContext`:

```php
public static function query(ApplicationContext $context): Builder
public static function with(ApplicationContext $context, array|string $relations): Builder
```

This means every test must boot the framework to obtain the context, similar to `tests/Integration/FrameworkBootTest.php`. Read that file first to understand the boot pattern before proceeding.

- [ ] **Step 3: Write the integration test using a real framework boot + in-memory SQLite**

Create `tests/Integration/Database/ORM/LazyLoadingDetectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database\ORM;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\ORM\Model;
use Glueful\Database\ORM\Exceptions\LazyLoadingViolationException;
use Glueful\Framework;
use PHPUnit\Framework\TestCase;
use Glueful\Tests\Support\Traits\ResetsLazyLoading;

class IntUser extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name'];

    public function posts(): \Glueful\Database\ORM\Relations\HasMany
    {
        return $this->hasMany(IntPost::class, 'user_id');
    }
}

class IntPost extends Model
{
    protected string $table = 'posts';
    protected array $fillable = ['user_id', 'title'];

    public function comments(): \Glueful\Database\ORM\Relations\HasMany
    {
        return $this->hasMany(IntComment::class, 'post_id');
    }
}

class IntComment extends Model
{
    protected string $table = 'comments';
    protected array $fillable = ['post_id', 'text'];
}

class LegacyIntUser extends IntUser
{
    protected ?string $instanceLazyLoadingMode = 'off';
}

class LazyLoadingDetectionTest extends TestCase
{
    use ResetsLazyLoading;

    private string $appPath;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFramework();
        $this->setUpSchema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    public function testCollectionThenLazyAccessTriggersStrict(): void
    {
        Model::preventLazyLoading('strict');

        $users = IntUser::query($this->context)->get();
        $this->assertGreaterThan(1, count($users));

        $this->expectException(LazyLoadingViolationException::class);
        $users[0]->posts;
    }

    public function testFindThenAccessDoesNotTrigger(): void
    {
        Model::preventLazyLoading('strict');

        $user = IntUser::query($this->context)->find(1);
        $posts = $user->posts;  // single-row hydration — no detection
        $this->assertNotNull($posts);
    }

    public function testEagerLoadedRelationDoesNotTrigger(): void
    {
        Model::preventLazyLoading('strict');

        $users = IntUser::query($this->context)->with('posts')->get();
        $posts = $users[0]->posts;  // already loaded — no lazy load
        $this->assertNotNull($posts);
    }

    public function testNestedCollectionLoadStillTriggers(): void
    {
        Model::preventLazyLoading('strict');

        $users = IntUser::query($this->context)->with('posts')->get();
        $firstPost = $users[0]->posts[0] ?? null;
        $this->assertNotNull($firstPost);

        $this->expectException(LazyLoadingViolationException::class);
        $firstPost->comments;  // comments NOT eager-loaded
    }

    public function testPerModelOptOutSkipsDetection(): void
    {
        Model::preventLazyLoading('strict');

        $users = LegacyIntUser::query($this->context)->get();
        $posts = $users[0]->posts;  // opted out, no detection
        $this->assertNotNull($posts);
    }

    public function testWarnModeLogsButDoesNotThrow(): void
    {
        Model::preventLazyLoading('warn');

        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prev = ini_set('error_log', $tmp);
        try {
            $users = IntUser::query($this->context)->get();
            $users[0]->posts;

            $this->assertStringContainsString('[GLUEFUL-N+1]', file_get_contents($tmp));
        } finally {
            ini_set('error_log', $prev);
            @unlink($tmp);
        }
    }

    public function testHydratedCollectionIsTaggedAsLoadedFromCollection(): void
    {
        Model::preventLazyLoading('warn');

        $users = IntUser::query($this->context)->get();
        $this->assertGreaterThan(1, count($users));
        $this->assertTrue($users[0]->wasLoadedFromCollection());
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-n1-' . uniqid();
        $configPath = $this->appPath . '/config';
        mkdir($configPath, 0755, true);

        file_put_contents($configPath . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents($configPath . '/database.php',
            "<?php\nreturn ["
            . "'engine' => 'sqlite', "
            . "'sqlite' => ['primary' => ':memory:'], "
            . "'pooling' => ['enabled' => false], "
            . "'orm' => ['lazy_loading_mode' => 'off']"
            . "];\n"
        );
        file_put_contents($configPath . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', "
            . "'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($configPath . '/security.php',
            "<?php\nreturn ['csrf' => ['enabled' => false]];\n"
        );
        file_put_contents($configPath . '/session.php',
            "<?php\nreturn ['jwt_key' => 'test'];\n"
        );

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function setUpSchema(): void
    {
        // CoreProvider registers the Connection under the service id 'database',
        // not under the Glueful\Database\Connection class name.
        $pdo = $this->app->getContainer()->get('database')->getPDO();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
        $pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, text TEXT)');
    }

    private function seed(): void
    {
        $pdo = $this->app->getContainer()->get('database')->getPDO();
        $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Alice'), (2, 'Bob')");
        $pdo->exec("INSERT INTO posts (id, user_id, title) VALUES (1, 1, 'P1'), (2, 1, 'P2'), (3, 2, 'P3')");
        $pdo->exec("INSERT INTO comments (id, post_id, text) VALUES (1, 1, 'C1'), (2, 1, 'C2')");
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
```

**Implementation note:** The Connection is registered in the container under the service id `'database'` (see `src/Container/Providers/CoreProvider.php:202`), not under the `Glueful\Database\Connection::class` name. PDO is accessed via `getPDO()` (see `src/Database/Connection.php:466`). Both are wired correctly in the test above; if you adapt this pattern elsewhere, use the same service id and method name.

- [ ] **Step 4: Run the integration tests**

Run: `vendor/bin/phpunit tests/Integration/Database/ORM/LazyLoadingDetectionTest.php -v`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/Database/ORM/LazyLoadingDetectionTest.php
git commit -m "test(orm): integration tests for N+1 detection behavior matrix"
```

---

## Task 12: Coexistence with DevelopmentQueryMonitor

**Files:**
- Modify: `tests/Integration/Database/ORM/LazyLoadingDetectionTest.php`

- [ ] **Step 1: Add a coexistence test**

Append to `LazyLoadingDetectionTest`:

```php
    public function testCoexistsWithDevelopmentQueryMonitor(): void
    {
        // Both detectors should be able to fire on the same query without
        // interfering with each other.
        \Glueful\Database\DevelopmentQueryMonitor::reset();
        Model::preventLazyLoading('warn');

        $tmp = tempnam(sys_get_temp_dir(), 'glueful-n1-');
        $prev = ini_set('error_log', $tmp);
        try {
            $users = IntUser::query($this->context)->get();
            // Lazy-load — triggers our detector and potentially the SQL one
            $users[0]->posts;

            $log = file_get_contents($tmp);
            $this->assertStringContainsString('[GLUEFUL-N+1]', $log);
            // The existing monitor uses different prefixes; we just verify
            // no fatal interaction by getting here without an exception.
        } finally {
            ini_set('error_log', $prev);
            @unlink($tmp);
        }
    }
```

- [ ] **Step 2: Run the test**

Run: `vendor/bin/phpunit tests/Integration/Database/ORM/LazyLoadingDetectionTest.php --filter Coexists -v`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Database/ORM/LazyLoadingDetectionTest.php
git commit -m "test(orm): verify N+1 detector coexists with DevelopmentQueryMonitor"
```

---

## Task 13: Public Documentation

**Files:**
- Create: `docs/ORM/N_PLUS_ONE_DETECTION.md`

- [ ] **Step 1: Create the documentation directory if needed**

```bash
mkdir -p docs/ORM
```

- [ ] **Step 2: Write the documentation**

Create `docs/ORM/N_PLUS_ONE_DETECTION.md`:

```markdown
# N+1 Query Detection

Glueful's ORM ships with an N+1 detector that fires at the model layer with enough context to give actionable warnings — `User::profile lazy-loaded from a collection of 50, add ->with('profile')`. It is opt-in via configuration, dev-only by default, and supports a strict mode that throws for CI enforcement.

## Overview

When you load a collection of models (e.g., `User::query($context)->get()`) and then access a relation on one of them without having eager-loaded it, that's the classic N+1 pattern. The framework tracks which models came from a collection and warns or throws when those models lazy-load a relation.

> Glueful's ORM requires an `ApplicationContext` on `Model::query()`. In controllers it's typically `$this->context`; in services, inject it. The examples below show the pattern.

**Does trigger detection:**

- `User::query($context)->get()` → `$user->posts`
- `User::query($context)->with('posts')->get()` → `$user->posts[0]->comments`

**Does not trigger:**

- `User::query($context)->find(1)->posts` (single-row find)
- `User::query($context)->with('posts')->get()` → `$user->posts` (already loaded)
- `$user->load('posts')->posts` (explicit load)
- `new User([...], $context)->posts` (manually constructed, not hydrated)

## Modes

| Mode | Behavior |
|---|---|
| `off` | Detection disabled. Minimal overhead (one static read per query). |
| `warn` | Log `[GLUEFUL-N+1] ...` via `error_log()`. Deduped per request — same `(model, relation)` pair warns once. |
| `strict` | Throw `LazyLoadingViolationException` (extends `\LogicException`). |
| `auto` | Resolves to `warn` in development, `off` otherwise. |

## Configuration

```env
DB_LAZY_LOADING_MODE=auto
```

Or in `config/database.php`:

```php
'orm' => [
    'lazy_loading_mode' => env('DB_LAZY_LOADING_MODE', 'auto'),
],
```

## Per-model opt-out

Some legacy models intentionally lazy-load. Opt out per-class:

```php
class LegacyUser extends Model
{
    protected ?string $instanceLazyLoadingMode = 'off';
}
```

The per-model override beats the global setting.

## Custom violation handler

Replace the default warn/throw behavior with your own handler — e.g., to route through PSR logger, dispatch an event, or capture to Sentry:

```php
use Glueful\Database\ORM\Model;
use Glueful\Bootstrap\ApplicationContext;
use Psr\Log\LoggerInterface;

// In a service provider's boot() method, with $context available:
Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use ($context) {
    $logger = app($context, LoggerInterface::class);
    $logger->warning("N+1: {$model::class}::$relation");

    if ($context->getEnvironment() === 'testing') {
        throw new \LogicException("N+1 detected in test");
    }
});
```

The callback **replaces** default behavior — neither `error_log()` nor the strict-mode throw fires automatically. Pass `null` to clear the callback.

## CI enforcement

Enable strict mode in tests so any N+1 fails CI:

```php
use Glueful\Tests\Support\Traits\ResetsLazyLoading;

class MyControllerTest extends TestCase
{
    use ResetsLazyLoading;

    protected function setUp(): void
    {
        parent::setUp();
        Model::preventLazyLoading('strict');
    }

    public function testIndex(): void
    {
        // If the controller does foreach($users as $u) { $u->posts; },
        // the test fails with LazyLoadingViolationException.
        $this->get('/users');
    }
}
```

## Coexistence with the SQL-pattern detectors

`DevelopmentQueryMonitor` and `QueryLogger::detectN1Patterns()` (the existing SQL-pattern detectors) continue to work. They fire later in the request lifecycle and detect *patterns* in raw SQL, which catches some cases this detector misses (and vice versa). All three can fire on the same query.

The new ORM-aware detector's advantage is *context*: it tells you the model and relation, not just that "10 similar queries ran."

## Performance

`off` mode skips hydration tagging and violation handling. The only cost is one static property read per query — not zero, but indistinguishable from noise.

## Long-running runtimes

Under PHP-FPM and CLI, PHP's request shutdown automatically clears the dedupe set between requests. Under long-running runtimes (Swoole, RoadRunner, FrankenPHP via `glueful/runiva`), state persists across requests. Call `Model::clearLazyLoadingWarnings()` at request boundaries — for example, from a Runiva middleware.
```

- [ ] **Step 3: Commit**

```bash
git add docs/ORM/N_PLUS_ONE_DETECTION.md
git commit -m "docs(orm): document N+1 detection — modes, opt-out, callback, CI"
```

---

## Task 14: Update CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add the bullet**

Open `CLAUDE.md`. Find the ORM-related section (search for "ORM" or "Model"). Add this bullet under it:

```markdown
- **N+1 detection** — ORM-aware detector at `src/Database/ORM/Concerns/PreventsLazyLoading.php`. Modes: `off | warn | strict | auto`. Configure via `DB_LAZY_LOADING_MODE`. Per-model opt-out via `$instanceLazyLoadingMode = 'off'`. See `docs/ORM/N_PLUS_ONE_DETECTION.md`.
```

If there is no dedicated ORM section, add the bullet under the database-related guidance (look for the "Database Architecture" section or similar).

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: point CLAUDE.md to N+1 detection guidance"
```

---

## Task 15: Update FRAMEWORK_IMPROVEMENTS.md

**Files:**
- Modify: `docs/FRAMEWORK_IMPROVEMENTS.md`

- [ ] **Step 1: Flip the Tier 1 N+1 row to complete**

Open `docs/FRAMEWORK_IMPROVEMENTS.md`. In the "Tier 1 — Core, Near-Term" table, find the row:

```markdown
| **N+1 query detection (dev-only)** | 6.2 (partial) | The ORM landed in Phase 1; without N+1 detection in dev, users hit performance cliffs and blame the framework. Cheapest win on the list. |
```

Change the first cell to mark it complete:

```markdown
| **N+1 query detection (dev-only)** ✅ | 6.2 (partial) | The ORM landed in Phase 1; without N+1 detection in dev, users hit performance cliffs and blame the framework. Cheapest win on the list. **Shipped 2026-05-20.** |
```

- [ ] **Step 2: Final commit**

```bash
git add docs/FRAMEWORK_IMPROVEMENTS.md
git commit -m "docs(roadmap): mark Tier 1 N+1 detection complete"
```

- [ ] **Step 3: Run the full test suite**

Run: `composer test`
Expected: PASS (or no new failures vs. main).

Run: `composer run analyse`
Expected: No new PHPStan errors. Existing deprecation warnings on bridge classes are acceptable.

Run: `composer run phpcs`
Expected: No PSR-12 violations in new files.

---

## Done

All 15 tasks complete. The framework now has an ORM-aware N+1 detector with warn/strict modes, per-model opt-out, custom handler support, and CI enforcement patterns documented.

**Open question deferred to a follow-up:** how to wire `Model::clearLazyLoadingWarnings()` at request boundaries under Runiva (Swoole/RoadRunner/FrankenPHP). See the spec's "Request-boundary state clearing" section. Not blocking for default `auto` mode in production (resolves to `off`).
