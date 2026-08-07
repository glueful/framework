# Level-8 Static-Analysis Adoption

> **Goal:** run PHPStan **level 8 across the whole framework** and enforce it in CI.
> **Status: ACHIEVED 2026-08-07.** `phpstan.neon` sets `level: 8` over `src/` + `config/`,
> and the CI gate (`composer analyse`) enforces it. All 31 areas were cleaned area-by-area
> between 2026-08-06 and 2026-08-07 (see the campaign log below); the per-lane ratchet
> scripts are retired — the main gate now guards everything they guarded.

**Remaining debt:** `phpstan-baseline.neon` — the frozen PHPStan-2 upgrade delta
(111 identifier-tagged entries, generated 2026-08-05 when the gate was level 6). It must
never grow; burn it down opportunistically when touching the files it names, and delete
entries (or regenerate) as they stop matching.

## Regenerate

```bash
# Whole framework — total + breakdown by top-level area
vendor/bin/phpstan analyse src --level=8 --no-progress --memory-limit=2G --error-format=raw 2>/dev/null \
  | sed -E 's#^.*/src/##' | awk -F'/' '{print ($2=="" ? "(src root)" : $1)}' | sort | uniq -c | sort -rn

# A single area (swap Database for any directory)
vendor/bin/phpstan analyse src/Database --level=8 --no-progress --memory-limit=1G
```

## Scope by area

**0 errors — all 31 areas level-8 clean ✓** (gate: `composer analyse`, level 8 in
`phpstan.neon`). Regenerate to confirm.

## Campaign log

- `Container` — cleaned 2026-08-06 (`composer run phpstan:di`).
- `Bootstrap`, `Development`, `Lock`, `Scheduler`, `Storage`, `Testing`, `Uploader` — cleaned
  2026-08-06 as one leaf-lanes pass.
- `Extensions`, `Logging`, `Performance`, `Repository`, `Tasks`, `Validation` — cleaned
  2026-08-06 as one six-area pass (all in `composer run phpstan:lanes`).
- `Events`, `Helpers`, `Permissions`, `Services`, and the src-root files
  (`Application.php`, `Framework.php`) — cleaned 2026-08-06 as one five-area pass.
- `Cache`, `Queue`, `Support` — cleaned 2026-08-06 as one three-area pass. With `Cache`
  in the lane, the temporary `trait.unused` ignore for `Helpers/DatabaseConnectionTrait`
  was removed again.
- `Api`, `Security` — cleaned 2026-08-06. The optional search SDKs
  (`elasticsearch/elasticsearch`, `meilisearch/meilisearch-php`) joined require-dev so the
  search adapters type-check against real SDK classes (properties stay natively `?object`
  for runtime-optional support; the SDK types live in PHPDoc).
- `Auth`, `Controllers` — cleaned 2026-08-06. Token shapes are runtime-validated at the
  refresh/store boundaries; `AuthSessionUser`'s permissions/roles types now tell the truth
  (shape varies by RBAC provider).
- `Http`, `Notifications` — cleaned 2026-08-06. Webhook signature generation throws on an
  unencodable payload; resource collections validate their `collects` classes; the PSR-15
  bridge validates provider-supplied PSR-17 factories.
- `Routing` — cleaned 2026-08-06. The router's closure reflection-cache key is prefixed
  (a bare numeric-string key was being silently cast to int by PHP); CSRF token caching
  throws on an unencodable token record; lockdown state files read through a guarded
  JSON helper.
- `Console` — cleaned 2026-08-07. `BaseCommand::jsonForDisplay()` centralizes JSON output
  for commands ('[unencodable]' on failure); scaffold name validation pins `preg_match`;
  file reads/`glob`/`sys_getloadavg` guard their falses.
- `Database` — cleaned 2026-08-07, closing the campaign. Relation machinery retyped from
  `object` to `Model` (base `Relation` + all six relation classes + `newRelatedInstance()`,
  with `class-string<Model>` relation params); ~57 `preg_match` pins; every SQL
  normalization/signature chain guards `preg_replace` nulls; `WhereClauseInterface` is
  honest about accepting callables; discriminated condition/order/join shapes read
  defensively; `Model`/`Collection` `toJson()` throw named errors instead of returning
  `false` typed as `string`. Gate raised: `phpstan.neon` `level: 8`.
  **Trap for the record:** `is_callable([$class, $method])` is always true on `Model`
  because of `__callStatic` — the boot-hook dispatch must use `method_exists`, or absent
  hooks misroute into the static query proxy (caught by the test suite).

## Recommended adoption strategy (historical)

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

## First detailed slice: `src/Database` (214 under PHPStan 2)

The categories below are representative of what every area will look like; Database was the
first surveyed in depth (under PHPStan 1.12, at 201 errors — the category shape still holds,
but regenerate for exact per-file sets before working the area).

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
