# `selectRaw()` Parameter Bindings — Design Note

**Status:** Draft 2026-05-27.
**Date:** 2026-05-27

## Goal

Give `QueryBuilder::selectRaw()` an optional bindings array so dynamic values inside a raw SELECT expression can be passed as positional `?` placeholders instead of being string-interpolated. This closes the one genuinely unsafe-by-design gap in the SELECT clause: today `selectRaw(string $expression)` accepts only a raw string with no way to bind a value, so any dynamic value forces unsafe concatenation.

New signature:

```php
public function selectRaw(string $expression, array $bindings = []): static
```

```php
// Before (unsafe — no way to bind):
$qb->selectRaw("CASE WHEN age > {$limit} THEN 'adult' ELSE 'minor' END AS band");

// After (safe — value bound):
$qb->selectRaw('CASE WHEN age > ? THEN ? ELSE ? END AS band', [$limit, 'adult', 'minor']);
```

The change is fully backward compatible: existing `selectRaw($expression)` calls pass `[]` and behave exactly as before.

## Non-goals

- **`orderByRaw()`** — left untouched this pass. It remains a no-bindings method documented as "trusted/static SQL only." A follow-up can apply the same treatment if desired.
- **Validating binding contents** — bindings flow through the existing `ParameterBinder::flattenBindings()` + `PDOStatement::execute()` path. No new validation layer.
- **Protecting non-value SQL fragments** — bindings protect dynamic *values* only. Identifiers, operators, sort directions, function names, and SQL fragments are not bindable and remain the caller's responsibility (allowlist them).
- **Changing `havingRaw`/`whereRaw`/`executeRaw`** — they already accept bindings and are unchanged.

## Current architecture (verified)

- **Placeholders are positional `?`.** Binding order must match the left-to-right order of placeholders in the final SQL.
- **SQL clause order** (`QueryBuilder::buildSelectQuery()`, `src/Database/QueryBuilder.php:984`): `SELECT → JOIN → WHERE → GROUP BY → HAVING → ORDER BY → LIMIT/OFFSET`.
- **Binding assembly** (`QueryBuilder::getAllBindings()`, line 1037): merges `WHERE → JOIN → HAVING`. `JoinClause::getBindings()` (`src/Database/Query/JoinClause.php:115`) **always returns `[]`** — joins use column references only — so the WHERE-before-JOIN order is harmless today.
- **`selectRaw()`** (`QueryBuilder.php:128`) wraps the expression in a `RawExpression` and appends it to `QueryState::$selectColumns`. `SelectBuilder::buildSelectClause()` renders a `RawExpression` verbatim (`src/Database/Query/SelectBuilder.php:137`), so any `?` in the expression already survives into `toSql()` untouched.
- **`QueryState`** (`src/Database/Query/QueryState.php`) is the single store for select columns, with `reset()` (line 181) and a clone path (line 197) that already handle `$selectColumns`.
- **`QueryBuilderInterface`** declares `whereRaw()` (line 105) but not `selectRaw()`, `havingRaw()`, or `orderByRaw()`.
- **No existing `selectRaw` tests** in `tests/`.

## Design decisions

1. **Bindings live in `QueryState`.** `selectRaw()` already writes its `RawExpression` into `QueryState`; the paired bindings belong in the same object so they reset and clone together. (Alternative — a private field on `QueryBuilder` — was rejected: it would live outside the state object and require manual reset/clone handling.)

2. **`getBindings()` order: true SQL placeholder order** → `SELECT → JOIN → WHERE → HAVING`, matching the clause order `buildSelectQuery()` emits. SELECT bindings are prepended, and the existing WHERE-before-JOIN merge order is corrected so JOIN comes before WHERE. This is backward-compatible today — `JoinClause` bindings are always empty, so the reorder changes nothing observable — but it establishes the correct positional contract *now* rather than deferring it, so the day joins gain value bindings the placeholders already line up. (This supersedes the earlier "prepend + revisit-later comment" option, which would have left a latent ordering bug for future join bindings.)

3. **`selectRaw()` is added to `QueryBuilderInterface`,** matching the existing `whereRaw()` precedent and completing the contract.

## Changes by file

### 1. `src/Database/Query/QueryState.php`
- Add `protected array $selectRawBindings = [];`
- Add `appendSelectRawBindings(array $bindings): void` — merges onto the existing array, preserving order (supports multiple `selectRaw()` calls).
- Add `getSelectRawBindings(): array`.
- Add `clearSelectRawBindings(): void` — empties the array; called when the SELECT column list is replaced (see `select()` below).
- Reset `$selectRawBindings = []` in `reset()`.
- Copy `$selectRawBindings` in the clone path (line 197).

### 2. `src/Database/Query/Interfaces/QueryStateInterface.php`
- Declare `appendSelectRawBindings(array $bindings): void`, `getSelectRawBindings(): array`, and `clearSelectRawBindings(): void`.

### 3. `src/Database/QueryBuilder.php`
- `selectRaw(string $expression, array $bindings = []): static` — after appending the `RawExpression`, call `$this->state->appendSelectRawBindings($bindings)` when `$bindings !== []`.
- `select(array $columns = ['*']): static` — `select()` *replaces* the column list via `setSelectColumns()`, which drops any prior `RawExpression`. Add `$this->state->clearSelectRawBindings()` so a `select()` after a bound `selectRaw()` does not leave orphaned bindings (which would otherwise make `getBindings()` return values with no matching placeholder).
- Rewrite the docblock: document the new parameter; state plainly that bindings protect **dynamic values only**, not identifiers, operators, directions, function names, or SQL fragments; show the safe `?`-with-bindings example.
- `getAllBindings()` — return bindings in SQL clause order `SELECT → JOIN → WHERE → HAVING`: prepend `$this->state->getSelectRawBindings()` and move the JOIN merge ahead of WHERE to match `buildSelectQuery()`. Add a short comment noting the order mirrors SQL emission so positional placeholders stay aligned.

### 4. `src/Database/Query/SelectBuilder.php`
- Fix `buildSelectClause(QueryStateInterface $state)` to build the column list from the **passed** `$state` (`$state->getSelectColumns()`), not from `$this->state` via `buildColumnList()`. Today it reads columns from its internal `$this->state` reference, so a cloned `QueryBuilder` (which reuses the original `SelectBuilder` but a cloned `QueryState`) renders columns from the *original* state while bindings come from the *clone* — a placeholder/binding mismatch. The sole caller (`QueryBuilder::buildSelectQuery()` at line 986) already passes `$this->state`, so this is behavior-preserving for normal queries and only changes the clone case. `buildColumnList()` (used by the separate `build()` method) is left as-is.

### 5. `src/Database/Query/Interfaces/QueryBuilderInterface.php`
- Declare `selectRaw(string $expression, array $bindings = []): static`.

### 6. `docs/SECURITY.md`
- In the raw-method breakdown, move `selectRaw` into the "accept a bindings array — safe when you use it" group (alongside `whereRaw`/`havingRaw`/`executeRaw`). Leave `orderByRaw` in the "no bindings parameter — never pass user input" group. Reaffirm that bindings cover values only.

## Data flow

```
selectRaw("... ? ...", [$v])
   ├─ RawExpression("... ? ...")  ──▶ QueryState::$selectColumns
   └─ [$v]                        ──▶ QueryState::$selectRawBindings (appended)

toSql()       ──▶ SelectBuilder renders the RawExpression verbatim → "?" appears in SELECT clause
getBindings() ──▶ [ ...selectRaw, ...join(=[]), ...where, ...having ]   (SQL clause order)
execute()     ──▶ flattenBindings() → PDOStatement::execute($params)  (positional bind)
```

## Scope guardrails

- **`count()` / `max()`** build their own `SELECT COUNT(*)` / `SELECT MAX(col)` and use `getWhereBindings()` only — they never include the raw SELECT expression, so they correctly exclude SELECT bindings. No change needed.
- **Backward compatibility** — `selectRaw($expr)` with no bindings appends nothing to `$selectRawBindings`; `toSql()` and `getBindings()` are byte-for-byte unchanged for existing callers.
- **Multiple `selectRaw()` calls** — bindings accumulate in call order, matching the order the expressions are appended to the SELECT clause.
- **`select()` after `selectRaw()`** — `select()` replaces the column list, so it clears `$selectRawBindings` (see file change 3). This prevents orphaned bindings when a caller does `selectRaw('?', [1])->select([...])`.
- **`clone()`** — `QueryBuilder::clone()` (line 930) builds the clone from `$this->state->clone()`, which copies both `$selectColumns` and `$selectRawBindings`. With the `SelectBuilder` fix in file change 4, `toSql()` renders columns from the clone's own state and `getBindings()` reads the clone's own bindings, so a clone's SQL placeholders and bindings stay consistent and isolated from the original. (Before this fix, the clone reused the original `SelectBuilder` bound to the original state, rendering original columns with the clone's bindings — a latent mismatch this feature surfaces and fixes.)

## Error handling

No new error paths. Bindings are `array<mixed>` and pass through the existing pipeline: `ParameterBinder::flattenBindings()` normalizes/flattens nested arrays, then `PDOStatement::execute($params)` binds them positionally. Note: `ParameterBinder::validateParameter()` is *not* on the live execute path (it is only called by the unused `bindParameters()`), so no type validation occurs — adding it is out of scope for this pass. An empty/whitespace expression is not validated today and remains out of scope.

## Testing

`QueryState` is tested directly as a pure unit (no collaborators). For builder-level behavior, `QueryBuilder`'s constructor takes 15 collaborators, so tests obtain a builder via `Connection::table()` over a file-backed SQLite database — the `new Connection(['engine' => 'sqlite', 'sqlite' => ['primary' => $tmpFile], 'pooling' => ['enabled' => false]])` pattern used by `tests/Integration/Auth/TokenManagerSessionVersionTest.php`. Build-only tests call just `toSql()`/`getBindings()`; the execution test runs a query. Exact file locations are pinned in the implementation plan.

**QueryState unit tests** (`tests/Unit/Database/Query/QueryStateTest.php`): append preserves order; `reset()` clears; `clearSelectRawBindings()` clears; `clone()` copies and isolates mutations.

**Builder-level tests** (`tests/Integration/Database/`):

1. **Backward compat** — `selectRaw('COUNT(*) AS c')` with no bindings: `getBindings()` contains no SELECT entry; `toSql()` contains the expression.
2. **Placeholder in `toSql()`** — `selectRaw('CASE WHEN age > ? THEN 1 ELSE 0 END AS is_adult', [18])`: the rendered SELECT clause contains `?` and `getBindings() === [18]`.
3. **Binding order** — `selectRaw('(price * ?) AS total', [1.2])->where('status', '=', 'paid')->havingRaw('total > ?', [100])`: `getBindings() === [1.2, 'paid', 100]`. (Uses the explicit 3-arg `where()` form because the 2-arg shorthand only normalizes non-string operands.)
4. **`select()` clears stale bindings** — `selectRaw('(age * ?) AS x', [2])->select(['name'])`: `getBindings() === []` and `toSql()` contains no `?`.
5. **`clone()` isolation** — a clone of a builder with a bound `selectRaw`, after adding a second `selectRaw` to the clone, has both expressions and both bindings in its `toSql()`/`getBindings()` (placeholder count matches binding count), while the original retains only its single expression and binding. Asserts **both** SQL and bindings to catch column/binding mismatch.
6. **Execution** — against SQLite, a bound `selectRaw` (a `CASE` column with bound threshold/labels) returns the expected computed values.
```
