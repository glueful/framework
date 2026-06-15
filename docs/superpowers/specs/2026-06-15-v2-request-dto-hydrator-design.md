# v2 Request-DTO Hydrator — Design Spec

**Status:** approved design (brainstorming complete) — feeds an implementation plan.
**Date:** 2026-06-15
**Component:** `Glueful\Validation` (hydrator, attributes, contracts) + `Glueful\Routing\Router` + `Glueful\Support\Documentation` (reflect generator).

## Goal

Promote typed request-DTO hydration from v1 (flat scalars only) to v2, closing the three documented v1 gaps so a controller method can be written as `handler(SomeData $input)` for non-trivial bodies:

1. **Arrays & nested DTOs** — hydrate `array` fields, including arrays of nested `RequestData` DTOs, with per-element validation. Failures become a **422**, never a `TypeError`/500.
2. **Source merging** — a DTO field may be sourced from the **path params** or the **query string**, not only the JSON body, via explicit attributes.
3. **Validation hook** — cross-field/domain validation beyond what per-field `#[Rule]` strings can express.

v2 is a **strict superset**: an existing flat-scalar v1 DTO hydrates byte-identically. New behavior activates only when the new attributes/contracts are present.

## Guiding principle (locked)

> Anything that affects **runtime behavior** or **generated OpenAPI** lives in **attributes/types**, not docblocks.

For **v2 request DTOs**, `#[ArrayOf(...)]` is the **only** source of an array field's element type — for both hydration and the OpenAPI request-body `items`. A bare `array` (no `#[ArrayOf]`) documents `items: {}` and is a mixed passthrough at runtime. `@var Foo[]` is **never** read for v2 request DTOs (neither hydration nor request-body schema). It remains valid documentation and is still honored **only** by the legacy `ResponseData` / schema-reflection path (`ClassSchemaReflector`'s existing `@var` array logic), which is out of scope for this change.

---

## New public surface

### Attributes — `Glueful\Validation\Attributes\`

| Attribute | Target | Meaning |
|---|---|---|
| `FromRoute` | `TARGET_PARAMETER` | field value comes from the matched **path params** |
| `FromQuery` | `TARGET_PARAMETER` | field value comes from the **query string** |
| `ArrayOf(string $type)` | `TARGET_PARAMETER` | element type of an `array` field |

- **No source attribute = JSON body** (the default). A field carries at most one of `FromRoute`/`FromQuery`.
- `#[FromHeader]` is **out of scope** (deferred — YAGNI).
- There is **no source-precedence rule**: each field has exactly one source by construction, so a route/query/body name collision is impossible.

#### `#[ArrayOf]` — fail-loud constructor

```php
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ArrayOf
{
    public function __construct(public readonly string $type) { /* validates, see below */ }
}
```

The constructor validates **hard at load time** (throws `\InvalidArgumentException`, matching `#[ResponseStatus]`/`#[ApiRequestBody]` fail-loud style):

- **Scalar element types** are canonicalized: accepted spellings `string`, `int`, `integer`, `float`, `number`, `bool`, `boolean` → canonical `{string,int,float,bool}` (with `integer→int`, `number→float`, `boolean→bool`).
- **Class-string element types** must be an existing class that **implements `RequestData`** (so it can be recursively hydrated). A class that doesn't implement `RequestData` throws.
- Anything else (unknown scalar name, non-existent class) throws.

A bare `array` field **without** `#[ArrayOf]` is a passthrough of mixed values — no element hydration/validation; OpenAPI documents it with open `items: {}`.

### Contract — `Glueful\Validation\Contracts\ValidatesSelf`

```php
interface ValidatesSelf
{
    /** @return array<string, list<string>>  field => messages (empty = valid) */
    public function validate(): array;
}
```

Runs **after** the DTO is hydrated (typed access to `$this->...`). Its returned errors are merged into the same 422 envelope. For DTO-level invariants only (cross-field: "publishedAt required when status=published"; "exactly one of token|credentials"; "endDate after startDate").

### Custom rules — `RuleRegistry` (a container seam, not global static)

Custom single-field/domain rules (`reserved_username`, `slug_unique`, `password_strength`, tenant-scoped availability) are registered as named `Rule` classes and referenced from a `#[Rule]` string (`#[Rule('required|string|reserved_username')]`).

- A `Glueful\Validation\Support\RuleRegistry` **service** holds `name => class-string<Rule>` mappings. It is resolved from the container/`ApplicationContext` (the same seam `RuleParser` already accepts), **not** mutated via a global static.
- `RuleParser` consults built-ins **plus** the registry when resolving a rule name.
- **Duplicate-name behavior is explicit:** `RuleRegistry::register(string $name, string $ruleClass, bool $overwrite = false)` **throws `\InvalidArgumentException`** when `$name` already exists (built-in or previously registered) unless `$overwrite === true`. This prevents silent shadowing of built-in rules. (Tested both ways.)
- Apps register via their service provider (or a `config/validation.php` `rules` map merged into the registry at boot).

> Division of labor (locked): `#[Rule]` = declarative field rules · registered custom `Rule` objects = reusable field/domain rules · `ValidatesSelf` = cross-field DTO invariants.

---

## Hydrator flow (refined order)

`RequestDataHydrator::hydrate(string $dtoClass, array $body, array $route = [], array $query = []): RequestData`

The order separates *proving container shape* from *recursing into elements*, so an `#[ArrayOf(DtoClass)]` parent is confirmed to be an array **before** its items are hydrated, and each nested item runs **its own** rules during recursion:

1. **Resolve raw values by source.** For each constructor parameter, pick its source array (`FromRoute`→`$route`, `FromQuery`→`$query`, else `$body`) and read the raw value by exact name.
2. **Run parent-level field rules only** — enough to prove **container shape**: `required`, scalar `type`, and for array fields the `array` rule plus any item-count bounds (`min`/`max` → minItems/maxItems). **Per-element rules are NOT run here** — they run during recursion (steps 3–4). Collect errors by field name.
3. **Recursively hydrate `#[ArrayOf(DtoClass)]` elements.** For each element call `hydrate(DtoClass, $element, route: [], query: [])` — nested elements are **body-only**; a `#[FromRoute]`/`#[FromQuery]` encountered on a nested DTO is a **developer/config error** (see *Nested source-attribute rule* below), not a no-op. Each item runs its own field rules + nested hydration + `ValidatesSelf`. Collect element errors **dot-pathed** to the parent index: `schema.0.name`, `schema.2.type`. Recursion is **depth-capped at 5** (matches `ClassSchemaReflector::MAX_DEPTH`); exceeding the cap is a validation error, not an infinite loop.
4. **Coerce scalar `#[ArrayOf('scalar')]` elements** — type-check/coerce each element to the canonical scalar; a non-coercible element is an error at `field.index`.
5. **If any field/element errors → throw `ValidationException` (422) now**, *before construction*. This is the guarantee that a malformed array/object can never reach a typed constructor parameter → **no `TypeError`/500**.
6. **Construct** the DTO from the coerced values (scalar coercion for builtin params as in v1; defaults/null for absent optionals).
7. **If the DTO implements `ValidatesSelf` → run `validate()`** and **merge** its errors.
8. **Any errors → throw 422; else return the typed DTO.**

### Error model

Every failure path produces a `Glueful\Validation\ValidationException` → the standard **422** envelope:

```json
{ "success": false, "message": "Validation failed", "errors": { "schema.0.name": ["The name field is required."] } }
```

Nested/element errors use **dot-notation** (`field.index.subfield`). The three v1 footguns now return **422 instead of 500**: an `array`/nested-object field with a bad value, and a non-nullable param with no `#[Rule]` and no default (treated as a missing-required validation error).

---

## Router change

`Router::resolveMethodParameters()` already decodes the JSON body for a `RequestData` param. v2 additionally passes the route params and query bag:

```php
$args[] = $this->requestDataHydrator()->hydrate(
    $typeName,
    $body,
    $params,                    // path params already in scope
    $request->query->all(),     // query string
);
```

The signature gains two **optional** parameters (`$route = []`, `$query = []`) → backward-compatible for any other caller.

---

## OpenAPI (reflect generator) changes

The generator already models nested DTOs/arrays/enums for the request schema via `ClassSchemaReflector`; v2 adds source-aware placement and `#[ArrayOf]`-driven `items`.

- **Request-body schema (v2 request DTOs)**
  - Array `items` come from `#[ArrayOf]` **only**; a bare `array` → `items: {}`. `@var` is **not** consulted for request DTOs. `ClassSchemaReflector`'s existing `@var`-reading array logic is retained **unchanged** for the legacy `ResponseData` path; the request-body path must use the `#[ArrayOf]`-driven resolution (a request-mode in the reflector, or the request builder supplying `items` from `#[ArrayOf]`), never the `@var` one.
  - **Exclude** `#[FromRoute]`/`#[FromQuery]` properties from the request **body** object schema — they are not body.
- **`RouteReflectionDocGenerator`**
  - Emit each `#[FromRoute]` DTO field as an `in: path` parameter, **`required: true`**.
  - Emit each `#[FromQuery]` DTO field as an `in: query` parameter, `required` derived from its `#[Rule]` (`required|...`) **or** the absence of a constructor default.
  - The field's `#[Rule]` constraints (`enum`, `format`, bounds) carry onto the generated parameter schema.
  - Merge/dedupe these with the existing path-param derivation and `#[QueryParam]` attributes (dedupe by `(name, in)`; an explicit `#[QueryParam]` of the same name still wins, as today).
  - The **auto-422** is unchanged (already emitted for any handler with a `RequestData` param). `ValidatesSelf` only widens what can 422 — no schema change.

### Route-placeholder mismatch rule

A `#[FromRoute]` field's name **must** correspond to a `{placeholder}` in the route's path.

- **Generation:** the generator checks each `#[FromRoute]` field against the route's path placeholders. A field with **no** matching placeholder is a **spec/config mismatch** and is surfaced **fail-loud** — caught by the documentation tests (a route is only documented with that path param when the placeholder exists; an orphan `#[FromRoute]` field raises during generation rather than emitting a phantom path param).
- **Runtime:** a missing route value is **graceful** — the field is simply absent from `$route`, so it falls to its `#[Rule]`/default/nullable handling and yields a clean **422** (or its default), never a 500.

### Nested source-attribute rule

`#[FromRoute]` / `#[FromQuery]` are valid **only on the top-level `RequestData` DTO** injected into a controller method — there is no per-item route/query inside an array. Encountering either on a **nested** DTO (during recursive `#[ArrayOf]` hydration, or anywhere reachable below the top level) is a **developer/config error, not user input**, and **fails loud** rather than being silently ignored — silent ignore would hide a top-level DTO being copied/reused incorrectly as a nested element:

- **Runtime:** throw a developer exception (`\LogicException`) — **not** a 422 (a 422 would wrongly blame the client for a programming mistake).
- **Generation:** the documentation generator detects a source attribute on a nested DTO and **fails loud** — caught by the documentation tests.

---

## Components & boundaries

| Unit | Responsibility | Depends on |
|---|---|---|
| `Attributes\FromRoute` / `FromQuery` | mark a field's source | — |
| `Attributes\ArrayOf` | element type (fail-loud ctor) | `RequestData` (for class types) |
| `Contracts\ValidatesSelf` | cross-field hook contract | — |
| `Support\RuleRegistry` | named custom-rule mappings (container service) | `Contracts\Rule` |
| `RequestDataHydrator` (v2) | source resolution, shallow rules, recursive/scalar array handling, construct, `ValidatesSelf`, 422 | `Validator`, `RuleParser`, `RuleRegistry`, the attributes |
| `Router::resolveMethodParameters` | pass body + route + query to hydrator | hydrator |
| Request-body schema (reflector request-mode) | `items` from `#[ArrayOf]` only (bare `array` → `{}`); exclude route/query props from body; legacy `@var` path left unchanged for `ResponseData` | the attributes |
| `RouteReflectionDocGenerator` | `#[FromRoute]`→path, `#[FromQuery]`→query params; mismatch check | reflector, route table |

Each unit is independently testable; the hydrator is the only place that knows the full v2 flow.

---

## Testing (TDD)

**Hydrator (unit):**
- Scalar `#[ArrayOf('int')]` array — coercion + a non-coercible element → `field.index` 422.
- Nested `#[ArrayOf(DtoClass)]` array — valid hydration; an invalid element → dot-pathed 422 (`schema.0.name`); each element runs its own rules + `ValidatesSelf`.
- Order proof: a non-array value for an `#[ArrayOf]` field fails the shallow parent `array` rule (422) and recursion never runs.
- `FromRoute`/`FromQuery` sourcing; a value present in body but the field is `FromQuery` is **not** taken from body.
- A `#[FromRoute]`/`#[FromQuery]` on a **nested** DTO reached during recursion throws a `\LogicException` at runtime (developer error, not 422).
- `ValidatesSelf` errors merged into the 422; a passing `validate()` is a no-op.
- A registered custom rule resolves and validates; **duplicate registration without `overwrite` throws**; `overwrite: true` succeeds.
- Depth cap at 5 → validation error, not stack overflow.
- Each v1 footgun (array field, nested object, no-rule non-nullable) now returns **422, not 500**.
- Characterization: existing flat v1 DTOs hydrate byte-identically.

**OpenAPI (unit):** `#[FromRoute]`→`in: path required`; `#[FromQuery]`→`in: query` with rule-derived `required`; body schema **excludes** route/query fields; `#[ArrayOf]` drives `items`; a bare `array` (no `#[ArrayOf]`) → `items: {}` and `@var` is **not** read for a request DTO; example still derived; **orphan `#[FromRoute]` (no placeholder) fails generation**; **a source attribute on a nested DTO fails generation**.

**Integration:** one route whose handler takes a DTO exercising `FromRoute` + `FromQuery` + a nested `#[ArrayOf(DtoClass)]` body field + `ValidatesSelf` — assert the live 200 path and a 422 with nested error keys.

---

## Scope / non-goals (YAGNI)

- No `#[FromHeader]`.
- No source-precedence rule (one source per field).
- Bare `array` (no `#[ArrayOf]`) stays mixed-passthrough.
- Custom-rule registration is a container service, **not** global static mutation; duplicates throw by default.

## Docs & rollout

- Rewrite `docs/REQUEST_DTOS.md`: drop the "v1 limitations / what is not in v1" caveats; document `#[FromRoute]`/`#[FromQuery]`/`#[ArrayOf]`, `ValidatesSelf`, custom-rule registration, and the refined flow.
- `CHANGELOG.md` `[Unreleased]` entry (feature; additive — no breaking change since v2 is a superset).
- Land on `dev`; ships in the next framework minor.
