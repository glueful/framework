# OpenAPI Generator — Phase 3: Make `reflect` Canonical, Remove `comments`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax. **Only Stage 1 is execute-ready; Stages 2–3 are scoped + sized outlines to be detailed after Stage 1 lands** (the migration uses the attributes Stage 1 builds — fully specifying it before they exist would be fabricated detail).

**Goal:** Make the code-first `reflect` generator the canonical and only OpenAPI generator: close its hand-authoring gaps with a *minimal, typed* attribute surface, migrate the framework's own route files off docblocks, then flip the default and delete `CommentsDocGenerator` + the `comments` config + the route `@*` docblocks.

**Guiding decision (settled):** Do NOT reintroduce a hand-authored-schema mini-language in attribute form. Inference covers ~95% of routes from types + the route table; structured overrides reference real types (`#[ApiResponse(…, SomeData::class)]`, `#[ResponseStatus]`, `#[RequireScope]`); manual response/request *shapes* use small typed DTOs (superset DTO for polymorphic, `array<string,mixed>` for free-form). The genuinely-not-a-DTO cases are **prose/tags** and **multipart/file uploads** — those, and only those, get new attributes.

**Tech Stack:** PHP 8.3+, Glueful framework (NOT Laravel), PHPUnit, PSR-12. Builds on the shipped reflect generator (`RouteReflectionDocGenerator`) + the typed-DTO I/O work (Phases A–D).

---

## Where the work is (sizing — read this before committing)

| Stage | What | Effort | Risk | Notes |
|---|---|---|---|---|
| **1** | Gap-closing attributes (`#[ApiOperation]`, `#[QueryParam]`, `#[ApiRequestBody]` for multipart, `#[ApiResponse]` `body:` extension for non-JSON responses) + reflect wiring | **M** (~3 small attrs + 1 attr extension + generator wiring + tests) | Low (additive) | **Execute-ready below.** Unblocks 2 & 3. |
| **2** | Migrate the framework's route files/controllers off `@*` docblocks → types + the Stage-1 attributes + small DTOs | **L (the bulk)** | Med | One sub-task per route file (~6 files, dozens of routes). Phase D already did the *runtime* DTO half for a few controllers; this adds the *doc* attributes + migrates the rest. |
| **3** | Flip default to `reflect`; delete `CommentsDocGenerator`, the `comments` branch/config, the route `@*` docblocks, comment-path tests; update docs/env | **M** | Med | Pure removal once 1 & 2 land; the regression gate is "reflect spec ≥ the old comment spec for every route." |

**The honest read:** Stage 1 is a contained week-ish of typed, low-risk work. **Stage 2 is the real cost** — every route file currently carries its docs in comments and must be migrated; that's where the time goes. Stage 3 is cheap *once* 2 is done. So the commit decision is really "am I willing to fund Stage 2's migration?" — Stage 1 is worth doing regardless (it makes `reflect` genuinely complete and is reusable even if you never delete `comments`).

**Out of scope (deliberate):** a general inline-schema attribute for JSON bodies (use DTOs); hand-authored examples (auto-derived `ExampleDeriver` output is adequate; revisit only if needed); `oneOf`/`anyOf` response unions (model a superset DTO).

**Settled design decisions (close known contradictions before execution):**
1. **`#[ApiRequestBody]` has two type-first modes; NO inline-JSON-schema.** `schema: SomeData::class` documents a JSON request body from a **DTO class** (reflected via `ClassSchemaReflector`, **doc-only — no runtime hydration/validation**) — for handlers that must stay manual (polymorphic login, resource store/update). `inlineSchema: [...]` is a raw array for **non-JSON only** (multipart/file; the constructor rejects `application/json`). Exactly one of the two is set (XOR). So: a runtime-hydrated JSON body uses a `RequestData` param; a doc-only JSON body uses `#[ApiRequestBody(schema: Class::class)]`; a multipart body uses `#[ApiRequestBody(inlineSchema: [...])]`. An inline JSON schema is never expressible (the comment mini-language is not recreated). *(Refined during Stage 2; shipped in `d2cda61`.)*
2. **Non-JSON RESPONSES (binary/text/html/octet-stream) get a constrained `#[ApiResponse]` `body:` extension** — `body: 'binary'|'text'|'object'` (Task 1.4). Today `#[ApiResponse]` with `schema === null` yields a description-only response and ignores `contentType`, so `reflect` cannot represent the file/HTML/OpenAPI-JSON responses (`DocsController`, `UploadController::show`). This is a *constrained* body kind, NOT a general inline schema — symmetric to the multipart request escape hatch.
3. **`@return CollectionResponse<Item>` is treated as standard PHPDoc generics (type metadata PHPStan/IDEs already consume) — NOT comment-schema language.** It is the type-of-the-return expressed in the only notation PHP lacks natively, consistent with "types are the source of truth." The non-docblock alternative is the existing **`#[ApiResponse(200, Item::class, collection: true)]`** (and the paginated equivalent), which any handler may use instead. No redundant `#[CollectionItem]` attribute is added.
4. **`#[QueryParam]` duplicate/override policy (defined + tested in Task 1.2):** parameters are merged path → field-selection → `#[QueryParam]` and deduped by `(name, in)`, with `#[QueryParam]` **winning** over a generated param of the same `(name, in)` (so a hand-authored override replaces the generated `fields`/`expand`/etc.). Among multiple `#[QueryParam]` of the same `(name, in)`, last-declared wins. Path params (`in: path`) never collide with query params (`in: query`).

**Conventions for every task:** branch `dev`; **NO `Co-Authored-By`**; never stage `CLAUDE.md`. Per-task gates: targeted `phpunit` + the full `tests/Unit/Support/Documentation` suite green; `vendor/bin/phpcs <changed files>` clean; `composer run analyse` no NEW errors (ignore the PHPStan 2.x banner). Verify line numbers before editing.

---

# STAGE 1 — Gap-closing attributes (execute-ready)

All three attributes are method-level, read by `RouteReflectionDocGenerator` for a route whose handler is `[Controller::class, 'method']` (reflect already resolves + reflects the handler method, so attributes work regardless of whether the route is registered in a route file or via `#[Get]`/`#[Post]`).

## Task 1.1: `#[ApiOperation]` — prose summary / description / tags / deprecated

**Why:** reflect currently DERIVES `summary` from the route name and `tags` from the first path segment, with no hand-authored prose path. This is the single biggest gap.

**Files:** Create `src/Routing/Attributes/ApiOperation.php`; modify `src/Support/Documentation/RouteReflectionDocGenerator.php` (`buildOperation()` ~88–159, where `deriveSummary`/`deriveTag`/`buildScopeDescription` are applied); Test `tests/Unit/Support/Documentation/ApiOperationAttributeTest.php`.

- [ ] **Step 1: Failing test** — register a route to a fixture controller method carrying `#[ApiOperation(summary: 'Sign in', description: 'Auth a user.', tags: ['Authentication'], deprecated: true)]`; generate; assert the operation's `summary === 'Sign in'`, `description` contains `'Auth a user.'`, `tags === ['Authentication']`, `deprecated === true`. Add a second method with NO attribute → assert the derived summary/tag still apply (no regression). Mirror `tests/Unit/Support/Documentation/RouteReflectionDocGeneratorTest.php`'s harness. Run → fail.

- [ ] **Step 2: Create the attribute:**
```php
<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

/**
 * Hand-authored OpenAPI operation prose for a controller method, overriding the
 * values the reflect generator derives from the route name/path. Every field is
 * optional — omitted fields keep the derived value.
 *
 * @param list<string> $tags
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ApiOperation
{
    /** @param list<string> $tags */
    public function __construct(
        public readonly string $summary = '',
        public readonly string $description = '',
        public readonly array $tags = [],
        public readonly ?string $operationId = null,
        public readonly bool $deprecated = false,
    ) {
    }
}
```

- [ ] **Step 3: Wire into `buildOperation()`** — add a guarded reader (reuse the existing `handlerReflection()`/`getReflection()` helper) that reads `#[ApiOperation]` once, and overlay: non-empty `summary` replaces `deriveSummary()`; non-empty `tags` replaces `[deriveTag()]`; non-empty `description` replaces/leads `buildScopeDescription()` (append the scope prose after it if present); non-null `operationId` replaces the generated id; `deprecated === true` adds `'deprecated' => true`. Keep all derivations as the default when the attribute (or a field) is absent. Reflection guarded — never throw.

- [ ] **Step 4: Run → pass** (override + no-attribute-derives-as-before). **Step 5: gates. Step 6: commit** `Add #[ApiOperation] for hand-authored summary/description/tags in reflect docs`.

## Task 1.2: `#[QueryParam]` — arbitrary query parameters

**Why:** reflect only documents `#[Fields]` field-selection params; arbitrary query params (`?days=30`, `?status=active`, `?page=`) have no path.

**Files:** Create `src/Routing/Attributes/QueryParam.php`; modify `RouteReflectionDocGenerator.php` (`buildParameters()` / a new `buildQueryParamAttributes()` merged into `$parameters` ~114–120); Test `tests/Unit/Support/Documentation/QueryParamAttributeTest.php`.

- [ ] **Step 1: Failing test** — a method with `#[QueryParam('days', 'integer', description: 'Window in days', required: false)]` and `#[QueryParam('status', enum: ['active','paused'])]` (repeatable) → assert two `in: query` parameters with the right `name`/`schema.type`/`description`/`required`/`enum`. PLUS a **duplicate-policy test:** a method with both a `#[Fields]` config (which generates a `fields` query param) AND `#[QueryParam('fields', description: 'override')]` → assert the result has exactly ONE `(name:'fields', in:'query')` parameter and it is the explicit override (description `'override'`), proving `#[QueryParam]` wins over the generated one and there is no duplicate. Run → fail.

- [ ] **Step 2: Create the attribute** (`#[Attribute(TARGET_METHOD | IS_REPEATABLE)]`):
```php
final class QueryParam
{
    /** @param list<string>|null $enum */
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly string $description = '',
        public readonly bool $required = false,
        public readonly ?string $format = null,
        public readonly ?array $enum = null,
    ) {
    }
}
```

- [ ] **Step 3: Wire** — a guarded `buildQueryParamAttributes($handler): array` reading all `#[QueryParam]` attrs into OpenAPI parameter objects (`{name, in: 'query', required, description?, schema: {type, format?, enum?}}`). Merge order: path params → field-selection params → `#[QueryParam]`, then **dedupe by `(name, in)` with later entries winning** — so `#[QueryParam]` (merged last) overrides a generated query param of the same name, and a later `#[QueryParam]` overrides an earlier one. Implement the dedupe explicitly (e.g. key an assoc map by `"$in:$name"` and overwrite). **Step 4–6:** pass (incl. the duplicate-policy test), gates, commit `Add #[QueryParam] for arbitrary query parameters (override-by-name) in reflect docs`.

## Task 1.3: `#[ApiRequestBody]` — non-JSON / multipart bodies (the one inline exception)

**Why:** the ONLY request-body case a typed `RequestData` can't model is a non-JSON body — chiefly **multipart/file upload** (binary, plus form fields). This is the deliberate, narrow exception where a hand-authored schema is unavoidable.

**Files:** Create `src/Routing/Attributes/ApiRequestBody.php`; modify `RouteReflectionDocGenerator.php` (`buildRequestBody()` ~ where RequestData/`#[Validate]` inference happens — the attribute, when present, OVERRIDES inference); Test `tests/Unit/Support/Documentation/ApiRequestBodyAttributeTest.php`.

- [ ] **Step 1: Failing test** — (a) a method with `#[ApiRequestBody(schema: ['type'=>'object','properties'=>['file'=>['type'=>'string','format'=>'binary'],'visibility'=>['type'=>'string']],'required'=>['file']])]` (default content type) → assert `requestBody.content['multipart/form-data'].schema` equals that inline schema and `required` is honored. (b) A **rejection test:** constructing `new ApiRequestBody(schema: [...], contentType: 'application/json')` throws `InvalidArgumentException` (JSON bodies must use a `RequestData` DTO, not this attribute). Run → fail.

- [ ] **Step 2: Create the attribute** — default content type is **`multipart/form-data`**, and the constructor **hard-rejects** `application/json` (fail-loud, mirroring `#[ResponseStatus]`):
```php
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ApiRequestBody
{
    /**
     * The deliberate escape hatch for NON-JSON request bodies — chiefly
     * multipart/file uploads. A JSON body (even polymorphic/free-form) MUST use a
     * typed RequestData DTO, never this attribute.
     *
     * @param array<string,mixed> $schema Inline OpenAPI schema for the non-JSON body.
     */
    public function __construct(
        public readonly array $schema,
        public readonly string $contentType = 'multipart/form-data',
        public readonly bool $required = true,
        public readonly string $description = '',
    ) {
        if ($contentType === 'application/json') {
            throw new \InvalidArgumentException(
                '#[ApiRequestBody] is for non-JSON bodies only (e.g. multipart); '
                . 'use a RequestData DTO for an application/json body.'
            );
        }
    }
}
```

- [ ] **Step 3: Wire** — in `buildRequestBody()`, if `#[ApiRequestBody]` is present it WINS over RequestData/`#[Validate]` inference: emit `{required, content: {<contentType>: {schema}}}` (+ description). Guarded. **Step 4–6:** pass, full `tests/Unit/Support/Documentation` green, gates, commit `Add #[ApiRequestBody] for multipart/non-JSON request bodies in reflect docs`.

## Task 1.4: `#[ApiResponse]` `body:` extension — non-JSON responses (binary / text / html)

**Why:** `#[ApiResponse]` today only documents a class-string DTO schema; with `schema === null` it yields a description-only response and **ignores `contentType`**. So `reflect` cannot represent the binary/HTML/OpenAPI-JSON file responses (`DocsController::index`/`openapi`, `UploadController::show`). Add a **constrained** body kind — symmetric to `#[ApiRequestBody]`, NOT a general inline schema.

**Files:** modify `src/Routing/Attributes/ApiResponse.php` (add an optional `body:` param, backward-compatible); modify `RouteReflectionDocGenerator.php` (`buildResponseObject()` — honor `body` when `schema` is null); Test `tests/Unit/Support/Documentation/ApiResponseBinaryBodyTest.php`.

- [ ] **Step 1: Failing test** — methods with:
  - `#[ApiResponse(200, contentType: 'application/octet-stream', body: 'binary')]` → assert `responses['200'].content['application/octet-stream'].schema === ['type'=>'string','format'=>'binary']`.
  - `#[ApiResponse(200, contentType: 'text/html', body: 'text')]` → `schema === ['type'=>'string']`.
  - `#[ApiResponse(200, description: 'No body')]` (neither schema nor body) → still description-only (no `content`) — **regression guard**.
  Run → fail.

- [ ] **Step 2: Extend the attribute** — add `public readonly ?string $body = null` to `ApiResponse` (after the existing params, default null → backward-compatible). Allowed values `'binary'|'text'|'object'` (validate in the constructor; throw `InvalidArgumentException` on an unknown value, mirroring `#[ResponseStatus]`'s fail-loud).

- [ ] **Step 3: Wire** — `buildResponseObject()` currently does `if ($schema === null) { return $object; }` (an early return that ignores `contentType`). REPLACE that early return with a three-way branch so the `body` case is handled before falling back to description-only:
  1. **`$schema !== null`** → existing DTO-schema behavior (reflect the class, apply `collection`/`envelope`, set `content`).
  2. **else if `$body !== null`** → set `content: {<contentType>: {schema: <map>}}` where the map is `binary → ['type'=>'string','format'=>'binary']`, `text → ['type'=>'string']`, `object → ['type'=>'object']`.
  3. **else** → description-only (return `$object` with no `content`, as today).
  When BOTH `schema` and `body` are set, the class `schema` wins (case 1; document it). Run → pass (incl. the description-only regression guard, which exercises case 3).

- [ ] **Step 4: gates. Step 5: commit** `Add #[ApiResponse] body: extension for binary/text/non-JSON responses`.

## Task 1.5: Stage-1 docs + CHANGELOG

- [ ] Document the four additions (`#[ApiOperation]`, `#[QueryParam]`, `#[ApiRequestBody]`, `#[ApiResponse]` `body:`) AND the rules from "Settled design decisions" (DTO-first for JSON; `#[ApiRequestBody]`/`body:` are the narrow non-JSON exceptions; `@return CollectionResponse<Item>` is type metadata; `#[QueryParam]` override policy) in the reflect-generator docs. CHANGELOG `### Added`. Commit. **At this point `reflect` is feature-complete vs `comments`** — this is the gate to start Stage 2.

---

# STAGE 2 — Migrate the framework off docblocks (scoped + sized; detail after Stage 1)

**Why outline-only now:** every task here uses the Stage-1 attributes; specifying exact per-route code before they exist would be fabricated. After Stage 1 lands, expand each bullet into a full task.

**Approach (per route file):** for each route, switch the generator to `reflect` locally (or assert via `RouteReflectionDocGenerator` directly in a test), and migrate its docs so the reflect spec is **≥** the current comment spec: types/DTOs for request+response shapes, `#[ApiOperation]` for prose/tags, `#[QueryParam]` for query params, `#[ApiResponse]` for error variants, `#[ApiRequestBody]` for multipart. Use a **characterization test** per route (capture the current comment-generated operation; assert the reflect-generated operation carries at least the same summary/description/params/security/request+response schema). Then delete that route's `@*` docblock.

- [ ] **2.1 `routes/auth.php` + `AuthController`** — login (polymorphic → superset `LoginResultData` DTO or `#[ApiResponse]` + `#[ApiOperation]`; `#[ApiRequestBody]` not needed, JSON), refreshToken (already typed — add `#[ApiOperation]`), logout/validateToken/refreshPermissions/csrfToken (`#[ApiOperation]` + simple response DTOs or `#[ApiResponse]`). *(Effort M — auth is prose- and variant-heavy.)*
- [ ] **2.2 `routes/resource.php` + `ResourceController`** — index (`#[QueryParam]` for page/per_page/sort/order/fields; response = a typed DTO or `#[ApiResponse]` for the `successWithMeta` shape), show/store/update/destroy (`#[ApiOperation]`). The polymorphic JSON write bodies (store/update/bulk) are documented **doc-only** with `#[ApiRequestBody(schema: ResourceCreateData::class)]` etc. — small generic DTOs (`ResourceCreateData`/`ResourceUpdateData`/`BulkDeleteData`/`BulkUpdateData`, typed `array<string,mixed>` for the dynamic columns) reflected for docs WITHOUT changing the controller's manual runtime (per Settled Decision #1). *(Effort M — polymorphic + query-param heavy.)*
- [ ] **2.3 `routes/blobs.php` + `UploadController`** — upload (`#[ApiRequestBody(contentType: 'multipart/form-data', schema: …)]` — the canonical multipart case), info/signedUrl/delete (already typed in Phase D — add `#[ApiOperation]`), show (binary/stream → `#[ApiResponse(200, contentType: 'application/octet-stream', body: 'binary')]` via the Task-1.4 extension). *(Effort M.)*
- [ ] **2.4 `routes/health.php` + `HealthController`** — probes + checks: `#[ApiOperation]` + small response DTOs (readiness already done) or `#[ApiResponse]`; bare probes documented via `#[ApiResponse]`. *(Effort S–M.)*
- [ ] **2.5 `routes/docs.php` + any remaining route files** — `#[ApiOperation]` + `#[ApiResponse(200, contentType: 'text/html', body: 'text')]` / `#[ApiResponse(200, contentType: 'application/json', body: 'object')]` for the served HTML / OpenAPI-JSON files (Task-1.4 extension). *(Effort S.)*
- [ ] **2.6 Extension route docs** — repeat the pattern for extension routes the framework ships/scans. *(Effort S–M, depends on which extensions are in-repo.)*

**Stage-2 exit gate:** a full-spec comparison test — generate the spec in `reflect` mode for the whole route table and assert no operation is *worse* than the comment-generated one (same paths, ≥ the summary/description/params/security/request+response coverage). This is the green light for Stage 3.

---

# STAGE 3 — Flip default + remove `comments` (scoped; detail after Stage 2)

- [ ] **3.1 Flip the default** — `config/documentation.php`: `'generator' => env('API_DOCS_GENERATOR', 'reflect')`. Update `DocumentationConfigTest`. *(Effort S.)*
- [ ] **3.2 Remove the comment engine** — delete `src/Support/Documentation/CommentsDocGenerator.php`; remove the `comments` branch + `$commentsGenerator` member/wiring from `OpenApiGenerator` (the `usesReflectGenerator()` fork collapses to reflect-only); remove the `generator` config switch (or keep it accepting only `reflect`); delete the comment-path tests (`CommentsDocGenerator*Test`, the flag-gating + comments-mode tests). *(Effort M — verify nothing else references the class; the command-manifest/console wiring may need a touch.)*
- [ ] **3.3 Strip the route `@*` docblocks** — remove the now-dead `@route/@summary/@description/@tag/@requestBody/@response/@queryParam` blocks from the route files migrated in Stage 2 (their content now lives in types/attributes). *(Effort S — mechanical, but only after 2.x proves coverage.)*
- [ ] **3.4 Docs/env** — remove `API_DOCS_GENERATOR=comments` from `.env.example`/docs; document `reflect` as the (only) generator + the attribute override surface. *(Effort S.)*
- [ ] **3.5 Full-suite + spec regen gate** — `composer test` green; regenerate the framework's own OpenAPI and eyeball it; confirm extension doc generation still passes. *(Effort S.)*

**Stage-3 exit:** one generator, no `comments`, route files carry zero doc markup, the spec is produced from types + the minimal attribute surface.

---

## Self-Review

**Matches the agreed shape:** minimal new attributes (`#[ApiOperation]` prose/tags + `#[QueryParam]` + `#[ApiRequestBody]` multipart-request + `#[ApiResponse]` `body:` for non-JSON responses) — NO general inline-schema attribute for JSON (manual JSON shapes use DTOs); examples stay auto-derived; the comment engine is deleted, not kept as a parallel fallback. The end-state is "reflect is the generator; attributes/types are its input," not "two generators."

**Review fixes folded in (Settled design decisions):** (1) `#[ApiRequestBody]` is multipart/non-JSON ONLY — Stage 2.2's resource write bodies use small generic DTOs (`ResourceCreateData` etc.), not inline-JSON via `#[ApiRequestBody]` (the prior contradiction is removed, and the attribute guards against an `application/json` content type). (2) Non-JSON RESPONSES (binary/text/html) get the constrained `#[ApiResponse]` `body:` extension (Task 1.4) — closing the gap where `schema === null` produced a description-only response that ignored `contentType` (`DocsController`, `UploadController::show`). (3) `@return CollectionResponse<Item>` is blessed as standard PHPDoc generics / type metadata (not comment-schema language), with `#[ApiResponse(…, collection: true)]` as the explicit attribute alternative — no redundant `#[CollectionItem]`. (4) `#[QueryParam]` has a defined, tested duplicate policy (override-by-`(name, in)`, `#[QueryParam]` wins over generated, last-declared wins among explicit).

**Dependency order is honest:** Stage 1 (attributes) first because Stage 2 (migration) and Stage 3 (deletion) both consume them; Stage 3 last because deleting `comments` before the routes are migrated would zero out their docs. The sizing table makes the real cost (Stage 2) explicit so the commit decision is informed.

**Stage 1 is no-placeholder; Stages 2–3 are deliberately outlines** — they depend on Stage 1's attributes and on per-route decisions made during migration; fully coding them now would be fabricated detail. Each Stage-2/3 bullet is sized and gated (characterization test per route; spec-not-worse exit gate) so they convert to full tasks cleanly once Stage 1 lands.

**Standalone value:** Stage 1 makes `reflect` feature-complete vs `comments` and is worth shipping even if Stages 2–3 are deferred — it's additive, low-risk, and reusable.
