# selectRaw() Parameter Bindings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an optional `array $bindings = []` parameter to `QueryBuilder::selectRaw()` so dynamic values in raw SELECT expressions can be bound as positional `?` placeholders instead of being string-interpolated.

**Architecture:** Raw SELECT bindings are stored in `QueryState` next to the `RawExpression` they pair with (so they reset/clone together). `QueryBuilder::getAllBindings()` returns bindings in true SQL clause order — `SELECT → JOIN → WHERE → HAVING` — so positional placeholders line up. `selectRaw()` already appends a `RawExpression` to the SELECT columns that `SelectBuilder` renders verbatim, so `toSql()` needs no change; any `?` in the expression already survives. Fully backward compatible: existing `selectRaw($expr)` calls pass `[]` and behave exactly as before.

**Tech Stack:** PHP 8.3, PHPUnit 10.5, PDO/SQLite for tests. Design spec: `docs/superpowers/specs/2026-05-27-selectraw-bindings-design.md`.

**Conventions used by this plan:**
- Run a single test: `vendor/bin/phpunit --filter="testName"`
- Run a test file: `vendor/bin/phpunit path/to/Test.php`
- Code style check: `composer run phpcs`
- Static analysis on changed files: `composer run analyse:changed`
- Commits: this repo does **not** use `Co-Authored-By` trailers.

---

## Task 1: Store SELECT-raw bindings in QueryState

`QueryState` gains a `$selectRawBindings` list with append/get accessors, and includes it in `reset()` and `clone()`. The two accessors are also declared on `QueryStateInterface` (so `clone()`, which is typed to return `QueryStateInterface`, can be asserted against in tests under static analysis).

**Files:**
- Modify: `src/Database/Query/QueryState.php`
- Modify: `src/Database/Query/Interfaces/QueryStateInterface.php`
- Test: `tests/Unit/Database/Query/QueryStateTest.php` (create; create the `Query/` dir under `tests/Unit/Database/`)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Database/Query/QueryStateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Query;

use Glueful\Database\Query\QueryState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryState::class)]
final class QueryStateTest extends TestCase
{
    public function testSelectRawBindingsStartEmpty(): void
    {
        $state = new QueryState();

        $this->assertSame([], $state->getSelectRawBindings());
    }

    public function testAppendSelectRawBindingsPreservesOrder(): void
    {
        $state = new QueryState();

        $state->appendSelectRawBindings([1, 'a']);
        $state->appendSelectRawBindings([true]);

        $this->assertSame([1, 'a', true], $state->getSelectRawBindings());
    }

    public function testResetClearsSelectRawBindings(): void
    {
        $state = new QueryState();
        $state->appendSelectRawBindings([1, 2]);

        $state->reset();

        $this->assertSame([], $state->getSelectRawBindings());
    }

    public function testClearSelectRawBindings(): void
    {
        $state = new QueryState();
        $state->appendSelectRawBindings([1, 2]);

        $state->clearSelectRawBindings();

        $this->assertSame([], $state->getSelectRawBindings());
    }

    public function testCloneCopiesSelectRawBindingsAndIsolatesMutations(): void
    {
        $state = new QueryState();
        $state->appendSelectRawBindings([5]);

        $clone = $state->clone();
        $clone->appendSelectRawBindings([6]);

        $this->assertSame([5], $state->getSelectRawBindings());
        $this->assertSame([5, 6], $clone->getSelectRawBindings());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Database/Query/QueryStateTest.php`
Expected: FAIL — `Error: Call to undefined method Glueful\Database\Query\QueryState::getSelectRawBindings()`.

- [ ] **Step 3: Add the storage to QueryState**

In `src/Database/Query/QueryState.php`, add the property after the `$orderBy` property (currently ending at line 29):

```php
    /** @var array<mixed> */
    protected array $selectRawBindings = [];
```

Add these two methods immediately after `getSelectColumns()` (currently ends at line 77):

```php
    /**
     * Append bindings for raw SELECT expressions
     *
     * @param array<mixed> $bindings
     */
    public function appendSelectRawBindings(array $bindings): void
    {
        $this->selectRawBindings = array_merge($this->selectRawBindings, array_values($bindings));
    }

    /**
     * Get bindings for raw SELECT expressions
     *
     * @return array<mixed>
     */
    public function getSelectRawBindings(): array
    {
        return $this->selectRawBindings;
    }

    /**
     * Clear bindings for raw SELECT expressions
     */
    public function clearSelectRawBindings(): void
    {
        $this->selectRawBindings = [];
    }
```

In `reset()`, add this line (after `$this->orderBy = [];`):

```php
        $this->selectRawBindings = [];
```

In `clone()`, add this line (after `$clone->orderBy = $this->orderBy;`):

```php
        $clone->selectRawBindings = $this->selectRawBindings;
```

- [ ] **Step 4: Declare the accessors on the interface**

In `src/Database/Query/Interfaces/QueryStateInterface.php`, add after `getSelectColumns()` (currently ends at line 45):

```php
    /**
     * Append bindings for raw SELECT expressions
     *
     * @param array<mixed> $bindings
     */
    public function appendSelectRawBindings(array $bindings): void;

    /**
     * Get bindings for raw SELECT expressions
     *
     * @return array<mixed>
     */
    public function getSelectRawBindings(): array;

    /**
     * Clear bindings for raw SELECT expressions
     */
    public function clearSelectRawBindings(): void;
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Database/Query/QueryStateTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Database/Query/QueryState.php \
        src/Database/Query/Interfaces/QueryStateInterface.php \
        tests/Unit/Database/Query/QueryStateTest.php
git commit -m "feat(db): store raw SELECT bindings in QueryState"
```

---

## Task 2: selectRaw() accepts bindings; getBindings() returns SQL-order bindings

Add the `$bindings` parameter to `selectRaw()`, wire it into `QueryState`, rewrite its docblock, declare it on `QueryBuilderInterface`, and reorder `getAllBindings()` to true SQL clause order with SELECT prepended.

**Files:**
- Modify: `src/Database/QueryBuilder.php` (docblock + `selectRaw()` at 107-143; `select()` at 100-105; `getAllBindings()` at 1037-1045)
- Modify: `src/Database/Query/SelectBuilder.php` (`buildSelectClause()` at 120-129)
- Modify: `src/Database/Query/Interfaces/QueryBuilderInterface.php`
- Test: `tests/Integration/Database/SelectRawBindingsTest.php` (create)

> Note: `QueryBuilder` has 15 constructor dependencies, so tests build it through `Connection::table()` over a file-backed SQLite database (the pattern in `tests/Integration/Auth/TokenManagerSessionVersionTest.php`). The tests in this task only call `toSql()`/`getBindings()` — no query is executed (execution is covered in Task 3).

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/Database/SelectRawBindingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

final class SelectRawBindingsTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/glueful-selectraw-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function testSelectRawWithoutBindingsAddsNoBindings(): void
    {
        $qb = $this->connection->table('users')->selectRaw('COUNT(*) AS c');

        $this->assertSame([], $qb->getBindings());
        $this->assertStringContainsString('COUNT(*) AS c', $qb->toSql());
    }

    public function testSelectRawWithBindingsRendersPlaceholderAndBinds(): void
    {
        $qb = $this->connection->table('users')
            ->selectRaw('CASE WHEN age > ? THEN 1 ELSE 0 END AS is_adult', [18]);

        $this->assertStringContainsString('?', $qb->toSql());
        $this->assertSame([18], $qb->getBindings());
    }

    public function testGetBindingsReturnsSelectRawBindingsBeforeWhereAndHaving(): void
    {
        // Uses the explicit 3-arg where() form: the 2-arg shorthand only
        // normalizes non-string operands, so where('status', 'paid') would
        // treat 'paid' as the operator.
        $qb = $this->connection->table('orders')
            ->selectRaw('(price * ?) AS total', [1.2])
            ->where('status', '=', 'paid')
            ->havingRaw('total > ?', [100]);

        $this->assertSame([1.2, 'paid', 100], $qb->getBindings());
    }

    public function testSelectAfterSelectRawClearsStaleBindings(): void
    {
        $qb = $this->connection->table('users')
            ->selectRaw('(age * ?) AS x', [2])
            ->select(['name']);

        $this->assertSame([], $qb->getBindings());
        $this->assertStringNotContainsString('?', $qb->toSql());
    }

    public function testCloneIsolatesSelectRawBindingsAndColumns(): void
    {
        $qb = $this->connection->table('users')->selectRaw('(a * ?) AS x', [2]);

        $clone = $qb->clone();
        $clone->selectRaw('(b * ?) AS y', [3]);

        // Original keeps exactly its one expression and one binding.
        $this->assertSame([2], $qb->getBindings());
        $this->assertStringContainsString('(a * ?) AS x', $qb->toSql());
        $this->assertStringNotContainsString('(b * ?) AS y', $qb->toSql());

        // Clone has both expressions and both bindings, and they line up
        // (placeholder count == binding count) — guards against the clone
        // rendering columns from the original state.
        $this->assertSame([2, 3], $clone->getBindings());
        $cloneSql = $clone->toSql();
        $this->assertStringContainsString('(a * ?) AS x', $cloneSql);
        $this->assertStringContainsString('(b * ?) AS y', $cloneSql);
        $this->assertSame(2, substr_count($cloneSql, '?'));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Integration/Database/SelectRawBindingsTest.php`
Expected: FAIL. Note PHP silently ignores extra positional arguments to userland methods, so there is **no** `ArgumentCountError` — the failures are assertion failures:
- `testSelectRawWithBindingsRendersPlaceholderAndBinds`: `getBindings()` returns `[]` (the `[18]` is ignored by the old single-arg `selectRaw()`), so `assertSame([18], ...)` fails. (The `?` assertion passes already because the literal `?` is in the expression string.)
- `testGetBindingsReturnsSelectRawBindingsBeforeWhereAndHaving`: returns `['paid', 100]`, so `assertSame([1.2, 'paid', 100], ...)` fails.
- `testCloneIsolatesSelectRawBindingsAndColumns`: pre-change both builders return `[]` (old `selectRaw` ignores bindings), so the `[2]` assertion fails. (This test also depends on the `SelectBuilder` fix in Step 6 — until then the clone's `toSql()` would render the original state's columns.)

`testSelectRawWithoutBindingsAddsNoBindings` and `testSelectAfterSelectRawClearsStaleBindings` already pass (they assert the unchanged/no-binding behavior) — they are regression guards.

- [ ] **Step 3: Update selectRaw() signature, body, and docblock**

In `src/Database/QueryBuilder.php`, replace the docblock + signature + body (lines 107-143) with:

```php
    /**
     * Add a raw SELECT expression to the query, with optional parameter bindings.
     *
     * Use `?` placeholders in the expression and pass their values via $bindings;
     * they are bound positionally ahead of JOIN/WHERE/HAVING bindings.
     *
     * ⚠️ **SECURITY**: bindings protect dynamic *values* only — never identifiers,
     * operators, sort directions, function names, or SQL fragments. The expression
     * string itself is NOT escaped, so never interpolate user input into it; put
     * dynamic values in $bindings and allowlist anything that cannot be bound.
     *
     * ```php
     * // Safe: values bound via placeholders
     * $query->selectRaw('CASE WHEN age > ? THEN ? ELSE ? END AS band', [$limit, 'adult', 'minor']);
     *
     * // UNSAFE: user input concatenated into the expression
     * $query->selectRaw("CASE WHEN age > {$userInput} THEN 1 ELSE 0 END AS band");
     * ```
     *
     * @param  string      $expression Raw SQL expression to add to the SELECT clause
     * @param  array<mixed> $bindings  Values for `?` placeholders in $expression
     * @return static Returns this QueryBuilder instance for method chaining
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
        $columns = $this->state->getSelectColumns();

        // If the only column is the default '*', replace it with the raw expression
        // to avoid "SELECT *, COUNT(*)" which violates SQL standard GROUP BY rules
        // (all non-aggregated columns must appear in GROUP BY)
        if ($columns === ['*']) {
            $columns = [new RawExpression($expression)];
        } else {
            $columns[] = new RawExpression($expression);
        }

        $this->state->setSelectColumns($columns);

        if ($bindings !== []) {
            $this->state->appendSelectRawBindings($bindings);
        }

        return $this;
    }
```

- [ ] **Step 4: Reorder getAllBindings() to SQL clause order**

In `src/Database/QueryBuilder.php`, replace `getAllBindings()` (lines 1037-1045) with:

```php
    private function getAllBindings(): array
    {
        // Return bindings in SQL clause order so positional `?` placeholders line up:
        // SELECT -> JOIN -> WHERE -> HAVING (matches buildSelectQuery()).
        // JoinClause::getBindings() is always empty today, but ordering it before
        // WHERE keeps the contract correct if joins ever bind values.
        $bindings = [];
        $bindings = array_merge($bindings, $this->state->getSelectRawBindings());
        $bindings = array_merge($bindings, $this->joinClause->getBindings());
        $bindings = array_merge($bindings, $this->whereClause->getBindings());
        $bindings = array_merge($bindings, $this->queryModifiers->getHavingBindings());

        return $bindings;
    }
```

- [ ] **Step 5: Clear stale SELECT-raw bindings in select()**

`select()` replaces the column list (dropping any prior `RawExpression`), so it must also clear the paired bindings — otherwise `selectRaw('?', [1])->select(['name'])` leaves `[1]` in `getBindings()` with no matching placeholder. In `src/Database/QueryBuilder.php`, replace `select()` (currently lines 100-105):

```php
    public function select(array $columns = ['*']): static
    {
        $this->queryValidator->validateColumnNames($columns);
        $this->state->setSelectColumns($columns);
        return $this;
    }
```

with:

```php
    public function select(array $columns = ['*']): static
    {
        $this->queryValidator->validateColumnNames($columns);
        $this->state->setSelectColumns($columns);
        // select() replaces the column list, dropping any prior selectRaw()
        // RawExpression — clear its bindings so they don't outlive their placeholder.
        $this->state->clearSelectRawBindings();
        return $this;
    }
```

- [ ] **Step 6: Fix SelectBuilder::buildSelectClause() to use the passed state**

The clone test fails on its SQL assertions until this is fixed: `buildSelectClause($state)` builds the column list via `buildColumnList()`, which reads the SelectBuilder's *internal* `$this->state`. A cloned `QueryBuilder` reuses the original `SelectBuilder` (bound to the original state) but a cloned `QueryState`, so the clone would render the original's columns with the clone's bindings. Build the column list from the **passed** `$state` instead.

In `src/Database/Query/SelectBuilder.php`, replace `buildSelectClause()` (lines 120-129):

```php
    public function buildSelectClause(\Glueful\Database\Query\Interfaces\QueryStateInterface $state): string
    {
        $table = $state->getTableOrFail();
        $columns = $this->buildColumnList();

        $sql = ($state->isDistinct() ? 'SELECT DISTINCT ' : 'SELECT ') . $columns;
        $sql .= ' FROM ' . $this->driver->wrapIdentifier($table);

        return $sql;
    }
```

with (column list now derived from `$state`):

```php
    public function buildSelectClause(\Glueful\Database\Query\Interfaces\QueryStateInterface $state): string
    {
        $table = $state->getTableOrFail();
        $columns = implode(
            ', ',
            array_map(
                fn($column) => $this->formatColumn($column),
                $state->getSelectColumns()
            )
        );

        $sql = ($state->isDistinct() ? 'SELECT DISTINCT ' : 'SELECT ') . $columns;
        $sql .= ' FROM ' . $this->driver->wrapIdentifier($table);

        return $sql;
    }
```

Leave `buildColumnList()` and `build()` unchanged — they are used by a separate code path.

- [ ] **Step 7: Declare selectRaw() on the interface**

In `src/Database/Query/Interfaces/QueryBuilderInterface.php`, add immediately after the `select()` declaration (currently ends at line 33):

```php
    /**
     * Add a raw SELECT expression with optional parameter bindings
     *
     * Bindings protect dynamic values only (via `?` placeholders), not identifiers,
     * operators, directions, function names, or SQL fragments.
     *
     * @param array<mixed> $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): static;
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/Database/SelectRawBindingsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 9: Commit**

```bash
git add src/Database/QueryBuilder.php \
        src/Database/Query/SelectBuilder.php \
        src/Database/Query/Interfaces/QueryBuilderInterface.php \
        tests/Integration/Database/SelectRawBindingsTest.php
git commit -m "feat(db): selectRaw() accepts parameter bindings; fix clone column/state binding"
```

---

## Task 3: End-to-end execution test for bound selectRaw

Prove a bound `selectRaw` executes correctly against a real (SQLite) database and returns the expected computed column values.

**Files:**
- Modify: `tests/Integration/Database/SelectRawBindingsTest.php` (add one test)

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Integration/Database/SelectRawBindingsTest.php` (inside the class, after the existing tests):

```php
    public function testBoundSelectRawExecutesAndReturnsComputedColumn(): void
    {
        $pdo = $this->connection->getPDO();
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)');
        $pdo->exec("INSERT INTO people (name, age) VALUES ('Alice', 30), ('Bob', 15)");

        $rows = $this->connection->table('people')
            ->select(['name'])
            ->selectRaw('CASE WHEN age >= ? THEN ? ELSE ? END AS band', [18, 'adult', 'minor'])
            ->orderBy('name')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('adult', $rows[0]['band']);
        $this->assertSame('Bob', $rows[1]['name']);
        $this->assertSame('minor', $rows[1]['band']);
    }
```

- [ ] **Step 2: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter="testBoundSelectRawExecutesAndReturnsComputedColumn"`
Expected: PASS.

This test should pass immediately given Task 2's implementation — it is an end-to-end guard, not a new behavior. If it fails, do not weaken it: investigate whether `getBindings()` ordering or the SELECT-clause rendering regressed (the bindings must arrive in the order `[18, 'adult', 'minor']` to match the three placeholders).

- [ ] **Step 3: Run the full test file to confirm no regressions**

Run: `vendor/bin/phpunit tests/Integration/Database/SelectRawBindingsTest.php`
Expected: PASS (6 tests).

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/Database/SelectRawBindingsTest.php
git commit -m "test(db): end-to-end test for bound selectRaw execution"
```

---

## Task 4: Update SECURITY.md and run the verification gate

Move `selectRaw` from the "no bindings" group to the "accepts bindings" group in the security docs (leaving `orderByRaw` in the no-bindings group), then run code style + static analysis + the relevant tests.

**Files:**
- Modify: `docs/SECURITY.md` (raw-method breakdown at lines 67-98; checklist line 150)

- [ ] **Step 1: Move selectRaw into the "accepts bindings" group**

In `docs/SECURITY.md`, replace the code block under **"Accept a bindings array — safe when you use it:"** (lines 69-74) with:

```php
// selectRaw / whereRaw / havingRaw / executeRaw / executeRawFirst
$query->selectRaw('(price * ?) AS total', [$rate]);            // value bound — safe
$query->whereRaw('age > ? AND status = ?', [$age, $status]);   // values bound — safe
$query->havingRaw('COUNT(*) > ?', [$min]);
$connection->executeRaw('SELECT * FROM users WHERE id = ?', [$id]);
```

- [ ] **Step 2: Update the "no bindings" group to drop selectRaw**

In `docs/SECURITY.md`, replace the block under **"Take a raw string with NO bindings parameter — never pass user input:"** (lines 88-96) with:

```php
// orderByRaw(string $expression) has no place to bind values.
// Use only with trusted, static SQL.
$query->orderByRaw('FIELD(status, "active", "pending", "closed")');    // OK — static

// UNSAFE — there is no safe way to interpolate user input here.
// Restructure with a bound where()/having(), or map input through an allowlist.
```

And replace the Note (line 98) with:

```markdown
> **Note:** `selectRaw` accepts a bindings array (use `?` placeholders for dynamic values). `orderByRaw` does not — if you need a dynamic value there, it belongs in a bound `where()`/`having()` clause, or must be validated against an allowlist before use. In all cases, bindings cover *values* only, never identifiers, operators, directions, or SQL fragments.
```

- [ ] **Step 3: Update the checklist line**

In `docs/SECURITY.md`, replace the checklist line (line 150):

```markdown
- [ ] Never pass user input to `selectRaw()` or `orderByRaw()` (no bindings available).
```

with:

```markdown
- [ ] Pass dynamic values in `selectRaw()` via its bindings array (`?` placeholders); never pass user input to `orderByRaw()` (no bindings available).
```

- [ ] **Step 4: Run code style check**

Run: `composer run phpcs`
Expected: no errors on the modified `src/` files. If `phpcs` reports fixable issues, run `composer run phpcbf` and re-run `composer run phpcs`.

- [ ] **Step 5: Run static analysis on changed files**

Run: `composer run analyse:changed`
Expected: no new errors. (Pre-existing baseline/bridge-class notes unrelated to these files are acceptable.)

- [ ] **Step 6: Run the touched test suites**

Run: `vendor/bin/phpunit tests/Unit/Database/Query/QueryStateTest.php tests/Integration/Database/SelectRawBindingsTest.php`
Expected: PASS (11 tests total — 5 QueryState + 6 SelectRawBindings).

- [ ] **Step 7: Commit**

```bash
git add docs/SECURITY.md
git commit -m "docs(security): selectRaw() now supports parameter bindings"
```

---

## Self-Review (completed during planning)

**Spec coverage:**
- `selectRaw(string $expression, array $bindings = [])` → Task 2.
- Backward compatibility for `selectRaw($expression)` → Task 2 Step 1 (`testSelectRawWithoutBindingsAddsNoBindings`).
- Store SELECT-raw bindings separately in `QueryState` → Task 1.
- `select()` clears stale SELECT-raw bindings → Task 2 Step 5 + `testSelectAfterSelectRawClearsStaleBindings`.
- `clone()` isolates SELECT-raw bindings *and* columns → Task 2 `testCloneIsolatesSelectRawBindingsAndColumns` (asserts both `toSql()` and `getBindings()`), backed by `QueryState::clone()` (Task 1) and the `SelectBuilder::buildSelectClause()` fix (Task 2 Step 6).
- `getBindings()` order `SELECT → JOIN → WHERE → HAVING` (true SQL order per the revised spec) → Task 2 Step 4 + Task 2 Step 1 order test.
- `orderByRaw()` unchanged → not touched in any task.
- Docs: bindings protect values only → Task 2 docblock + Task 4 SECURITY.md.
- Tests: no-bindings works; placeholders in `toSql()`; `getBindings()` order; bound execution → Tasks 2 & 3.
- `QueryBuilderInterface` exposes `selectRaw()` → Task 2 Step 7.
- `docs/SECURITY.md` raw-method breakdown updated → Task 4.

**Type consistency:** `appendSelectRawBindings(array): void`, `getSelectRawBindings(): array`, and `clearSelectRawBindings(): void` are declared identically in `QueryState` and `QueryStateInterface`, and called consistently from `QueryBuilder` (`selectRaw()` appends, `select()` clears, `getAllBindings()` reads). `selectRaw(string, array = []): static` matches between `QueryBuilder` and `QueryBuilderInterface`.

**Placeholder scan:** No TBD/TODO; every code and command step is concrete.
