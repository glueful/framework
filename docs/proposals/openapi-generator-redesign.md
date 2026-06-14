# Proposal: OpenAPI Documentation Generator — Root Causes & Redesign

**Status:** Living roadmap — Phase 0 (`6822eb3`, `8b7f97b`) and Phase 1 (`8e846ff`, the code-first `reflect` generator) implemented; Phases 2–3 remain open (see §7).
**Scope:** `src/Support/Documentation/*`, `src/Console/Commands/Generate/OpenApiDocsCommand.php`, `config/documentation.php`
**Motivation:** An app (Lemma) annotated its routes and tried to generate an agent-ready OpenAPI 3.1 spec from the framework generator alone. It could not: config flags were ignored, `--clean` did nothing, per-route security could not be expressed, and framework/extension endpoints could not be excluded. The app was forced into an ever-growing post-processing script. This document explains *why*, proposes the corrective fixes, and proposes a better long-term generation model.

---

## 1. How the generator works today

Three stages, orchestrated by `OpenApiGenerator`:

1. **Parse** — `CommentsDocGenerator` reads each route file, extracts PHPDoc blocks containing `@route`, and writes a self-contained OpenAPI JSON *fragment* per file to `docs/json-definitions/routes/*.json` and `…/extensions/*.json`.
2. **Merge** — `DocGenerator` merges the fragments' `paths`/`schemas`/`tags`, injects resource-table CRUD (when enabled), and **always unions ~20 default component schemas** (`getDefaultSchemas()`). *Pre-Phase-0 this re-read fragments by globbing the directories (the source of the stale-fragment leak); post-`6822eb3` it merges only the current run's fragment list.*
3. **Write** — `OpenApiGenerator` assembles `info`/`servers`/`securitySchemes` and writes `docs/openapi.json`.

The path set is still **not** a projection of the app's actual route table — it is built from the per-run fragments plus injected defaults. The unconditional default-component-schema union (§2.4) is the remaining accretion.

---

## 2. Original failure mode — why config flags and `--clean` were ignored (fixed by Phase 0 core, `6822eb3`)

These were not independent bugs; they were consequences of the glob-and-merge-from-disk model. **2.1–2.3 are fixed as of `6822eb3`**; they are kept here as the root-cause record. **2.4 remains open.**

### 2.1 Output reflected disk history, not the current run — ✅ fixed
`DocGenerator::generateFromRoutes()` / `generateFromExtensions()` globbed `docs/json-definitions/{routes,extensions}/` and merged **everything present**, including fragments written by *previous* runs — so a `auth.json` / `aegis.json` left by an earlier `--force` run was re-merged forever. *Fix:* the orchestrator now merges only the fragment list produced by the current run (`generateFromRouteFiles()` / `generateFromExtensionFiles()`); stale fragments can no longer leak.

### 2.2 `include_extensions` / `include_routes` were dead flags; `include_framework_routes` was force-only — ✅ fixed
Those keys were **never read** in `src/`, and `include_framework_routes` was consulted **only on the `--force` branch**. *Fix:* all three are now wired in `processExtensionAndRouteDocs()`, and `include_framework_routes` applies on both the force and non-force paths.

### 2.3 `--clean` did not clean the fragments — ✅ fixed
`cleanDefinitionDirectories()` `@rmdir()`'d the `routes/` / `extensions/` subdirectories (which silently fails while non-empty) and only `unlink`'d `*.json` at **depth 0**, so the nested fragments survived. *Fix:* it now recurses — unlinks files at any depth, then removes the emptied subdirectories deepest-first.

### 2.4 Default *component schemas* were injected unconditionally — ✅ fixed (opt-in)
`getSwaggerJson()` unioned ~20 `getDefaultSchemas()` into `components.schemas` with no gate and no reachability check, so unreferenced default schemas (LoginRequest, User, Notification, …) leaked into the output and confused SDK generators. *Fix (`8b7f97b`):* opt-in `documentation.options.prune_unreferenced_schemas` (default `false`) drops default schemas nothing references, computed transitively from `paths`/`webhooks`; fragment-contributed schemas are always kept. Off by default preserves existing specs.

> Scope note: the framework/extension **endpoint** leakage was a stale-fragment + dead-flag problem (2.1/2.2, now fixed), **not** an unconditional endpoint builder, and resource-table CRUD via `ResourceRouteExpander` was already gated by `options.include_resource_routes`. Only the **component-schema** grab-bag was unconditional — and is now prunable.

**Net result:** Phase 0 makes the configured sources authoritative for *routing*, `--clean` deterministic, and (opt-in) the component-schema set reachability-pruned. Flipping config now does what it says.

---

## 3. Phase 0 — corrective fixes (make the comment generator honor config)

Backward-compatible; all flags keep their current defaults so existing specs are unchanged.

**Core fixes 1–4 — ✅ implemented in `6822eb3`** (with unit tests; defaults unchanged):

1. **Wire the dead flags.** In `OpenApiGenerator::processExtensionAndRouteDocs()`, gate the extension stages behind `include_extensions` (default `true`) and the route stages behind `include_routes` (default `true`).
2. **Make `include_framework_routes` apply on both branches** — the framework-routes scan is gated on the non-force path too, not just `--force`.
3. **Merge only this run's fragments.** The orchestrator merges exactly the fragment list produced by the current run (`generateFromRouteFiles()` / `generateFromExtensionFiles()`) instead of globbing the directory — stale fragments can no longer leak.
4. **Fix `--clean`.** `cleanDirectoryRecursively()` deletes files at any depth then removes the emptied subdirectories deepest-first, so prior fragments are actually removed.

**Phase-0 item 5 — ✅ implemented in `8b7f97b`:**

5. **(Optional, opt-in) prune unreferenced default schemas.** A pass in `getSwaggerJson()` keeps only schemas reachable (transitively) from `paths`/`webhooks` (plus always-kept fragment schemas), behind `options.prune_unreferenced_schemas` (default `false` to avoid surprising existing consumers). Implemented as the pure static `DocGenerator::pruneUnreferencedSchemas()`.

**Outcome:** an app can produce a spec of *only its own routes* through config alone, `--clean` is deterministic, and (opt-in) the component-schema set carries only what's referenced.

---

## 4. The deeper limitations (beyond scoping)

Even with Phase 0, the comment model has structural gaps:

- **Per-route security cannot be expressed.** Every authed operation calls `securityFor(['auth'])` with a hardcoded literal (`CommentsDocGenerator.php:486,1417`; `DocGenerator.php` ×10). The only docblock lever is the boolean `@requiresAuth true`. The route's *real* middleware (`->middleware('require_content_scope:read:content')`, group `auth`, `requireScopeConfig`, `rateLimitConfig`) is never read — even though it sits in the same file. `SecuritySchemeRegistry::securityFor()` is built to map any middleware list to schemes; it is just never fed the real list.
- **`@example` drops nested JSON.** The regex `/@example\s+(\{[\s\S]*?\}|"[^"]*")/` (`CommentsDocGenerator.php:767`) is non-greedy and stops at the first inner `}`, so any nested example fails to parse and silently falls back to an auto-derived placeholder. The class already owns a correct `findMatchingBrace()` it does not use here.
- **Schema mini-language is under-powered.** `@response`/`@requestBody` support objects, nested objects, `field:array=[{…}]`, scalars, and enums — but no `format` (date-time/uuid/email), no nullable, no `pattern`, and no `required` for *response* objects.
- **No reflection of controllers/attributes/DTOs.** The generator reads route-file docblocks only. `#[Get]`/`#[Post]`/`#[Fields]`/`#[RequireScope]` attributes, controller return types, `JsonResource`/`ModelResource`, and request DTO validation rules contribute nothing. `DocGenerator::addRouteWithFieldsAttribute()` (the `#[Fields]` → `?fields`/`?expand` path) is **dead code**, called only by a test. `ExampleDeriver::fromValidationRules()` exists but is unused.

---

## 5. The better approach: code-first generation from the route table

The framework already compiles every route with its real method, path, params, handler, **middleware**, `requireScopeConfig`, and `rateLimitConfig`, all introspectable:

- `Router::getStaticRoutes()` + `getDynamicRoutes()` — the authoritative route set.
- `Route::getMethod()/getPath()/getHandler()/getMiddleware()` + stored `paramNames`, `where`, `rateLimitConfig`, `requireScopeConfig`.
- Full attribute set: `#[Get/Post/Put/Patch/Delete/Options]`, `#[Controller]`, `#[Middleware]`, `#[RequireScope]`, `#[Fields]`, `#[Route]`.
- Response shaping: `JsonResource`, `ModelResource`, `ResourceCollection`.
- `ExampleDeriver::fromValidationRules()` (already built).

Make the **live route table the single source of truth**:

1. **Enumerate** registered routes from the `Router`. This is exactly what the app serves — no globbing, no stale fragments, no hardcoded injection. *"Only my routes" becomes correct by construction* (filter by route origin, which `RouteManifest` already knows). The entire config-not-honored class of bugs disappears.
2. **Derive structurally from code:**

   | Spec field | Source (already exists) |
   |---|---|
   | path + path params | `Route::getPath()` / `paramNames` / `where` (regex → `pattern`) |
   | method, operationId | `getMethod()` + `OperationIdGenerator` |
   | **security (accurate, per route)** | `getMiddleware()` + `requireScopeConfig` → existing `SecuritySchemeRegistry::securityFor(realMiddleware)` |
   | 429 + rate headers | `rateLimitConfig` |
   | field-selection params | `#[Fields]` (wire up `addRouteWithFieldsAttribute`) |
   | request body schema/example | request DTO / validation rules → `ExampleDeriver::fromValidationRules()` |
   | response schema | `JsonResource`/`ModelResource` return type, or a response attribute |

3. **Layer human metadata** (summary/description/examples/response prose) via **typed attributes** (`#[ApiOperation(...)]`, `#[ApiResponse(200, resource: PostResource::class)]`) — reflected reliably, IDE/type-checked, co-located with the handler. Docblocks remain an optional override so existing annotations are not discarded.

This is the model behind FastAPI (type hints + Pydantic), NestJS (decorators + reflection), and Laravel Scramble (route/controller analysis).

### Approaches compared

| Approach | Source of truth | Per-route security | Drift | Manual effort | Parsing fragility |
|---|---|---|---|---|---|
| Comment/docblock (today) | hand-written tags | ✗ | High | High | High |
| Attribute-driven | `#[Api*]` on handlers | ✓ if attributed | Medium | High (typed) | None |
| Code-first / reflection | route table + DTOs + Resources | ✓ from real middleware | Low | Low | None |
| **Hybrid (recommended)** | code-first skeleton + annotation overrides | ✓ native | Low | Low | None for structure |

**Recommendation:** hybrid code-first — authoritative structure inferred from the route table + DTOs + Resources + `#[Fields]`/`#[RequireScope]`; attributes/docblocks only for prose code cannot infer.

---

## 6. Phasing

- **Phase 0 — ✅ implemented (`6822eb3` + `8b7f97b`):** config flags honored, `--clean` recurses, no stale-fragment leakage, and opt-in unreferenced-schema pruning. Complete.
- **Phase 1 — ✅ implemented (`8e846ff`):** code-first path/security/params/rate-limit from the route table, behind `documentation.generator => 'reflect'`, alongside the existing comment generator. Per-route security comes from the *real* effective middleware (no docblock tag), and scoping is structural. Response/request body *schemas* are deferred to Phase 2.
- **Phase 2 — next design target:** request/response schema inference from DTOs/Resources/attributes; wire `ExampleDeriver::fromValidationRules()` and `addRouteWithFieldsAttribute()`. Add `@example` balanced-brace fix and `format`/nullable/response-`required` grammar for anyone staying on docblocks.
- **Phase 3:** docblocks/attributes become override-only; deprecate the regex parser.

**Downstream note:** apps consume the *vendored* framework, so each phase reaches an app only after a framework release + version pin bump. An app-side post-processor remains the bridge until then and shrinks phase by phase.

---

## 7. Prioritized work list

| # | Change | Phase | Effort | Risk | Status |
|---|--------|-------|--------|------|--------|
| 1 | Wire `include_extensions`/`include_routes`; `include_framework_routes` both branches; merge-only-this-run; fix `--clean` recursion | 0 | M | Low | ✅ Done (`6822eb3`) |
| 2 | Opt-in prune of unreferenced default schemas (`options.prune_unreferenced_schemas`) | 0 | S | Low (opt-in) | ✅ Done (`8b7f97b`) |
| 3 | Per-route security from real middleware → existing registry (via reflect generator) | 1 | M | Low | ✅ Done (`8e846ff`) |
| 4 | Code-first path/param/rate-limit builder from the route table (`RouteReflectionDocGenerator`) | 1 | L | Med | ✅ Done (`8e846ff`) |
| 5 | `@example` balanced-brace capture (`findMatchingBrace`) | 2 | S | Low | ⬜ Open (next target) |
| 6 | Grammar: `format`, nullable, response-level `required`; run `transformSchema` over inline path schemas | 2 | M | Low | ⬜ Open |
| 7 | DTO/Resource/attribute schema inference; wire dead `addRouteWithFieldsAttribute` + `ExampleDeriver::fromValidationRules` | 2 | L | Med | ⬜ Open |
| 8 | Consolidate dual parsers / fragment assembler; remove `3.0.0` hardcode | 2 | M | Low | ⬜ Open |

**Next design target:** Phase 2 (#5 → #7) — schema/example fidelity: DTO/Resource/attribute inference for request & response bodies (the reflect generator's main remaining gap), plus the `@example` and grammar fixes for anyone on the comment path. (Phases 0 and 1, items #1–#4, are complete.)
