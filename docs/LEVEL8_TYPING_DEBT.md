# Level-8 Static-Analysis Adoption

> **Goal:** eventually run PHPStan **level 8 across the whole framework** and enforce it in CI.
> **Status:** Catalogued, not yet addressed. **Not blocking.** The current CI gate is
> `composer analyse` (**level 6**), which is green. Level 8 (`composer analyse:strict`) is the
> target; this document tracks the gap so it can be closed deliberately, area by area, rather
> than discovered ad hoc.

**Last surveyed:** 2026-05-28 — **914 errors across `src/`.**

## Regenerate

```bash
# Whole framework — total + breakdown by top-level area
vendor/bin/phpstan analyse src --level=8 --no-progress --memory-limit=2G --error-format=raw 2>/dev/null \
  | sed -E 's#^.*/src/##' | awk -F'/' '{print ($2=="" ? "(src root)" : $1)}' | sort | uniq -c | sort -rn

# A single area (swap Database for any directory)
vendor/bin/phpstan analyse src/Database --level=8 --no-progress --memory-limit=1G
```

## Scope by area

| Area | Errors | | Area | Errors |
|---|---:|---|---|---:|
| `Database` | 201 | | `Cache` | 28 |
| `Console` | 103 | | `Services` | 24 |
| `Support` | 60 | | `Helpers` | 22 |
| `Queue` | 56 | | `Permissions` | 14 |
| `Controllers` | 50 | | `Extensions` | 13 |
| `Notifications` | 48 | | `Events` | 13 |
| `Security` | 46 | | (src root) | 12 |
| `Api` | 43 | | `Tasks` | 11 |
| `Routing` | 39 | | `Validation` / `Repository` / `Logging` | 8 each |
| `Auth` | 38 | | `Performance` | 7 |
| `Http` | 37 | | `Uploader` / `Container` | 5 each |
| | | | …`Lock`, `Storage`, `Scheduler`, `Testing`, `Bootstrap` | 1–4 each |

**~914 total.** Regenerate for the exact, current set.

## Recommended adoption strategy

1. **Area by area, not one sweep.** Each area is its own branch of work with its own test surface.
2. **Run the full suite after each area** (`composer test`) and **commit per area**.
3. **Ratchet the baseline.** Once an area (or the whole tree) is clean at a level, bump the
   `phpstan.neon` level (6 → 7 → 8) or add a per-path stricter config so fixed code can't regress.
   Going 6 → 7 first across the framework is likely a smaller, useful intermediate milestone.
4. **Sequence by leverage / risk.** Smaller leaf areas (`Container`, `Validation`, `Lock`,
   `Storage`) are quick wins to build momentum. Core areas (`Database`, `Routing`, `Auth`,
   `Controllers`) carry the most regression risk — do them with the test suite close at hand.
5. **Watch for real bugs, not just annotations.** The `string|null → string-function` and
   return-nullability categories regularly hide genuine latent nulls. The "undefined method"
   (generics/`@mixin`) categories are almost always annotation-only.
6. **Get the operator right on `int|false` conditions.** `strpos`/`strrpos`/`array_search`
   → `!== false`; `preg_match` → `=== 1` (rewriting `preg_match` as `!== false` treats a
   non-match as a match — a real bug). This bucket recurs across many areas.

---

## First detailed slice: `src/Database` (201)

The categories below are representative of what every area will look like; Database was the
first surveyed in depth.

| Category | ~Count | Nature | Risk |
|---|---|---|---|
| `int\|false` in `if` / `&&` / negation (`strpos`/`preg_match`/etc.) | ~57 | Mechanical, but each needs the **right** operator (`!== false` vs `=== 1`) | Low — but a wrong fix is a bug |
| ORM "undefined method" (`setRelation`/`getTable`/`getConnection`/`newFromBuilder`) | ~31 | ORM generics / `@mixin` annotations | Medium — fiddly type-model work, annotation-only |
| `string\|null` → string funcs (`preg_replace`/`md5`/`str_*`) | ~25 | Unhandled nullables | **Highest value — can hide real null bugs** |
| SchemaBuilder union (`alterTable(): TableBuilderInterface\|self`) | ~10 | Structural | Medium — needs a real signature/structure fix |
| Property type mismatches (incl. `QueryState::$selectColumns` not accepting `RawExpression`) | ~6 | Annotation fixes | Low |
| Return nullability (`should return string but returns string\|null`) | ~5 | Null handling / return widening | Medium |

### `src/Database` errors per file (top of the list)

| File | Count |
|---|---|
| `QueryLogger.php` | 29 |
| `ORM/Concerns/HasRelationships.php` | 18 |
| `QueryAnalyzer.php` | 13 |
| `ORM/Relations/BelongsToMany.php` | 12 |
| `Schema/Builders/SchemaBuilder.php` | 10 |
| `DevelopmentQueryMonitor.php` | 10 |
| `QueryCacheService.php` | 9 |
| `ORM/Builder.php` | 8 |
| `Features/PaginationBuilder.php` | 8 |
| `QueryOptimizer.php`, `Query/WhereClause.php`, `Features/QueryValidator.php` | 7 each |
| `ORM/Relations/{HasOneThrough,HasManyThrough,BelongsTo}.php` | 6 each |
| …~20 more files | 1–4 each |

## Already fixed (2026-05-28)

Closed while wiring `QueryBuilder::cache(ttl, tags)` — not part of the remaining counts above:

- `ParameterBinderInterface::flattenBindings()` `@param` widened `array<string, mixed>` →
  `array<int|string, mixed>` (bindings are positional/numeric-keyed or named/string-keyed).
- `ParameterBinder.php:125` `if (preg_match(...))` → `if (preg_match(...) === 1)` (behavior-preserving).
