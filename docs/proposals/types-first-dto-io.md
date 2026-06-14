# Proposal: Types-First Request/Response DTOs

**Status:** Implemented — Phases A (request DTOs), B (response DTOs), C (collections/pagination + auto-422 + `scaffold:dto`), and C2 (response-docs cleanup + Resource auto-normalizer) all shipped on `dev`. See `docs/REQUEST_DTOS.md` and `docs/RESPONSE_DTOS.md`. Both open questions are now RESOLVED: #1 (collections & pagination) by `CollectionResponse`/`PaginatedResponse` + `@return Type<Item>` schema inference; #2 (Resource auto-normalizer) by routing a returned `JsonResource`/`ResourceCollection`/`PaginatedResourceResponse` through its own `->toResponse()` in `Router::normalizeResponse()` (the Resource keeps its own envelope; OpenAPI for Resources stays manual via `#[ApiResponse]` since their bodies are not statically reflectable). The typed-I/O arc is complete.
**Release shape:** Shippable **incrementally in a minor release**, not major-only — it is marker-interface opt-in and the router/dispatch changes are strictly *additive* (a class that doesn't implement `RequestData`/`ResponseData` behaves exactly as today).
**Scope:** `src/Routing/` (parameter resolution + return handling), `src/Validation/`, `src/Http/`, `src/Support/Documentation/` (generator integration), new `RequestData`/`ResponseData` contracts.
**Relationship to other work:** Builds directly on [`openapi-generator-redesign.md`](./openapi-generator-redesign.md). That effort shipped the reflect generator (Phase 1) and the schema machinery — `ClassSchemaReflector` (typed class → OpenAPI schema) and `ValidationRuleSchema` (rules → schema). This proposal adds the **runtime I/O convention** those reflectors were built to read, moving Glueful from annotation-first to types-first API documentation.

---

## 1. Motivation

Modern API frameworks (FastAPI, Spring, NestJS) treat **typed structures as the schema source of truth** and bind them to HTTP operations through the method signature, reserving annotations for what types can't express (status codes, error variants, examples). PHP 8 can reflect parameter and return types at runtime, so Glueful can adopt this posture.

Today Glueful controllers take a generic `Request` and return a generic `Response`:

```php
public function store(Request $request): Response
{
    $data = $request->toArray();
    // manual validation, manual Response::success(...)
}
```

The signature is uninformative, so the doc generator can infer **nothing** from it — request/response bodies require explicit `#[Validate]` / `#[ApiResponse]` attributes. That attribute-first approach works (it's shipped) but is *more* annotation than PHP needs.

**This proposal:** a request DTO that is hydrated-and-validated from the request, and a response DTO that the dispatcher envelopes into the standard response — so the signature itself documents the contract:

```php
public function store(CreatePostData $input): PostData
{
    $post = $this->posts->create($input->title, $input->body, $input->status);
    return PostData::fromModel($post);
}
```

The generator reflects `CreatePostData` → request body and `PostData` → response body, **with zero annotations** for the happy path.

> **This is a runtime convention, not a docs feature.** Types-first only works if the framework actually hydrates+validates the request DTO and envelopes the response DTO. The generator change is the small part; the runtime binding is the substantive work.

---

## 2. The convention

### 2.1 Request DTOs

A request DTO carries both **shape** (typed properties) and **rules** (validation), co-located:

```php
final class CreatePostData implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:200')] public string $title,
        #[Rule('required|string')]          public string $body,
        #[Rule('in:draft,published')]       public string $status = 'draft',
    ) {}
}
```

- `RequestData` is a marker interface that tells the router's parameter resolver "hydrate this from the request body."
- `#[Rule(...)]` is a **new** attribute (`#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]` so it works on constructor-promoted properties). It declares validation in the Laravel-style grammar the existing `Validator` and `ValidationRuleSchema` already understand — distinct from the existing method-level `#[Validate([...])]`. (Alternative: a `public static function rules(): array` method — but property/parameter attributes are more reflectable and co-located; see Open Questions.)

### 2.2 Response DTOs

A response DTO is a plain typed structure the controller returns:

```php
final class PostData implements ResponseData   // pure data — no transport knowledge
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public string $status,        // the post's status, not the HTTP status
        public ?string $publishedAt,
    ) {}

    public static function fromModel(Post $p): self { /* map */ }
}
```

`ResponseData` is a marker so the dispatcher envelopes a returned DTO rather than treating it as a raw `Response`. **It stays pure data — it does not know its HTTP status.** A `ResponseData` defaults to `200`. For non-200 success (e.g. `201` on create), annotate the *method*, keeping transport out of the data object:

```php
#[ResponseStatus(201)]                         // new method attribute
public function store(CreatePostData $input): PostData { ... }
```

(`#[ApiResponse]` remains the docs/error-variant override. If a project really wants the status to live on the DTO, that's available via a separate opt-in `HasResponseStatus` interface with a `status(): int` method — but the default `ResponseData` deliberately omits it.)

---

## 3. The runtime binding (the substantive work)

### 3.1 Request: a hydrating/validating parameter resolver

The exact seam is **`Router::resolveMethodParameters()`** (`src/Routing/Router.php:784`). Critically, **the `RequestData` check must be inserted early — right after the `Request` injection check (~line 803) and *before* the container branches** (`$this->container->has($typeName)` at `:823` and the `class_exists` → `container->get()` instantiate at `:829`). Otherwise a `CreatePostData` is a non-builtin class and gets container-resolved/instantiated as if it were a service.

```
Router::resolveMethodParameters() — for each non-builtin parameter type, in order:
  1. type === Request / subclass        → inject the Request          (existing, :800)
 [NEW] type implements RequestData       → hydrate + validate + inject  ← insert here
  2. FieldSelector / Projector           → existing (:806, :817)
  3. container->has(type)                → inject service             (existing, :823)
  4. class_exists(type)                  → container->get(type)       (existing, :829)
```

The hydrate+validate step:

```
└─ paramType implements RequestData?
      ├─ read the request body (JSON) as an associative array
      ├─ map keys → the DTO's constructor params / typed properties (by name)
      ├─ collect the #[Rule] strings → RuleParser::parse() → Rule objects → run via the existing Validator
      │     └─ on failure → throw ValidationException  (→ 422, already wired)
      └─ construct + inject the DTO

> Rule wiring: the `Validator` consumes **Rule objects**, not pipe-strings. The framework already converts strings via `RuleParser::parse()` (`src/Validation/Support/RuleParser.php:83`) — the same path `FormRequest` and `ValidationMiddleware` use. So `#[Rule]` strings → `RuleParser::parse()` → `Validator`; no second string-rule adapter is invented.
```

Effect: validation moves out of the controller body onto the DTO and runs automatically; the controller receives a guaranteed-valid, typed object. Reuses the existing `Validator` and exception→422 mapping. Because it's inserted *before* the container branch and only triggers for `RequestData` types, it is strictly additive.

### 3.2 Response: extend the existing return normalizer

The seam already exists — **`Router::normalizeResponse()`** (`src/Routing/Router.php:~1009`). Today it passes `Response` through unchanged, wraps strings, and falls arrays/objects through to `new JsonResponse($result)`. At proposal time **a returned `JsonResource` was NOT auto-routed to `toResponse()`** — the controller called `->toResponse()` itself. *(Resolved in Phase C2: the router now auto-normalizes a returned `JsonResource`/`ResourceCollection`/`PaginatedResourceResponse` through its own `toResponse()` — see the status note above.)*

Phase B adds **one branch** for `ResponseData`, inserted before the array/object fallback — strictly additive:

```
Router::normalizeResponse(result):
  result instanceof Response          → unchanged
  is_string(result)                   → unchanged
 [NEW] result instanceof ResponseData → envelope via the status factory (below)
  is_array | is_object                → unchanged (new JsonResponse(...))
```

`ResponseData` status comes from the method's `#[ResponseStatus(n)]` (default `200`); since **`Response::success()` has no status parameter** (`success(mixed $data, string $message, ?SerializationContext)`), the status selects the factory:

```
status 200 → Response::success($result->toArray())
status 201 → Response::created($result->toArray())
other      → new Response($result->toArray(), $status)
```

**Scope: Phase B is `ResponseData` only.** At the time of Phase B, Resources kept their manual `->toResponse()` pattern — the router did not auto-normalize them. A Resource auto-normalizer (routing a returned `JsonResource`/`ResourceCollection` through `toResponse()`) was a worthwhile *separate, additive* enhancement with its own regression tests, deliberately out of scope for Phase B — **and was later shipped in Phase C2.**

---

## 4. Generator integration (small — reflectors already exist)

The reflect generator (`RouteReflectionDocGenerator`) gains two new schema sources, both feeding the **already-shipped** `ClassSchemaReflector`:

- **Request:** if a handler parameter's type implements `RequestData`, reflect that class → request body schema; refine with its `#[Rule]` attributes via `ValidationRuleSchema` (required/format/enum/min-max). No method annotation.
- **Response (success):** reflect the handler's **return type**; if it implements `ResponseData`, build the response schema (envelope-wrapped — reuse the existing `#[ApiResponse]` builder, sourced from the return type instead of an attribute). Status from the method's `#[ResponseStatus(n)]` (default `200`).

`#[ApiResponse]` / `#[Validate]` remain for the gaps (below); they take precedence over inference when both are present on a method.

---

## 5. What's inferred vs. what stays annotated

| Concern | Source | Annotation? |
|---|---|---|
| Request body shape + constraints | typed `RequestData` param + its `#[Rule]`s | none |
| Success response (200/201) body | typed `ResponseData` return | none |
| Path params, security, rate-limit | route table (reflect generator, Phase 1) | none |
| `422` validation error | derived from the request DTO's rules | none |
| `401/403/429` | middleware / rate-limit | none |
| Error / `404` responses | `#[ApiResponse(404, ...)]` | yes |
| List/collection returns | a typed collection return, or `#[ApiResponse(200, X::class, collection: true)]` | yes (until collection return types are supported) |
| Examples, prose descriptions | attribute / docblock | yes |

Annotations shrink to genuinely type-unexpressible concerns — the FastAPI/Spring posture.

---

## 6. Coexistence & migration

- **Nothing breaks.** Existing `Request`→`Response` controllers keep working; they remain opt-in-annotated (`#[Validate]`/`#[ApiResponse]`) or undocumented-body.
- Typed DTOs are adopted **per method**, incrementally — a controller can mix typed and generic handlers.
- The `#[Validate]`/`#[ApiResponse]` attributes are not deprecated; they become the override/gap mechanism.

---

## 7. Phased build plan

Each phase is independently shippable and behind opt-in adoption (defining a `RequestData`/`ResponseData` DTO is the opt-in).

**Ship Phase A first** — it delivers immediate value (typed, auto-validated request input + clean request docs) with a smaller surface than the return path.

### Phase A — Request DTOs (input binding) — *ship first*
- **A1.** `RequestData` marker interface + the new `#[Rule]` attribute (`TARGET_PROPERTY | TARGET_PARAMETER`) + a `RequestDataHydrator` that maps request **body (JSON only for v1)** → DTO (by constructor-param/property name, with type coercion + optional/default handling), converts the `#[Rule]` strings via `RuleParser::parse()`, and validates through the existing `Validator`; failure → `ValidationException`. *(Effort M, Risk Med — error-path + coercion edge cases.)*
- **A2.** Insert the `RequestData` branch into `Router::resolveMethodParameters()` (`Router.php:784`) **before the container branches** (§3.1). *(Effort M, Risk Med — touches dispatch; strictly additive.)*
- **A3.** Generator: reflect a `RequestData` param → request body schema (`ClassSchemaReflector` + `ValidationRuleSchema`), in `RouteReflectionDocGenerator`. *(Effort S, Risk Low — reflectors exist.)*
- **A4.** *(Optional)* `make:dto`/`make:request` scaffolder. *(Effort S.)*

### Phase B — Response DTOs (output enveloping) — *ship second, with a careful return normalizer*
- **B1.** `ResponseData` marker + `#[ResponseStatus(n)]` method attribute + a new `ResponseData` branch in the existing `Router::normalizeResponse()` (§3.2): `ResponseData`→envelope (200/201 status factory); `Response`/string/array/object paths **unchanged**. Scope is `ResponseData` only — Resources stayed manual `->toResponse()` in Phase B; the Resource auto-normalizer was a separate enhancement (shipped in Phase C2). *(Effort M, Risk Med — touches the dispatch return path; must leave existing paths byte-identical.)*
- **B2.** Generator: reflect the handler return type → response schema (reuse the `#[ApiResponse]` envelope builder); status from `#[ResponseStatus]`. *(Effort S, Risk Low.)*
- **B3.** Collection/pagination: a typed collection return (e.g. `ResponseCollection` or `list<PostData>` via return docblock) → array-wrapped schema; otherwise `#[ApiResponse(collection: true)]` stays. *(Effort M, Risk Low.)*

### Phase C — Gaps & ergonomics
- Auto-derive the `422` response from a request DTO's rules. *(Effort S.)*
- Status selection (201 for create) via `#[ResponseStatus(201)]`, with `#[ApiResponse]` as the docs/error override. *(Effort S.)*
- Docs/guides; `make:` scaffolding polish. *(Effort S.)*

### Phase D — Reference adoption (optional, validates the convention)
- Migrate a few core controllers (e.g. auth, a resource controller) to typed DTOs as worked examples + regression coverage. *(Effort M.)*

**Prerequisites (already done):** `ClassSchemaReflector`, `ValidationRuleSchema`, the reflect generator (`8e846ff`, `23ed4d3`, `f0b83be`).

---

## 8. Risks, tradeoffs, open questions

**Tradeoff vs. today.** The shipped `#[ApiResponse]`/`#[Validate]` attributes are the pragmatic 80% — they document I/O *now* with no runtime change. Types-first is the modern-standard ceiling. Because adoption is **marker-interface opt-in** and both runtime changes are **strictly additive** (a class not implementing `RequestData`/`ResponseData` takes the existing code path unchanged), it can ship **incrementally in minor releases** — Phase A, then Phase B — rather than waiting for a major.

**Decided — Resources relationship.** `ResponseData` is the **simple typed API-DTO path** (a small data object the generator can reflect); `JsonResource`/`ModelResource`/`ResourceCollection` remain the **rich transformation path** (conditional fields, relationship loading, pivots). They **coexist**; neither replaces the other, and the return normalizer (§3.2) routes each to its handler. Resources stay runtime-opaque to the generator (use `#[ApiResponse]` to document a Resource-returning endpoint); `ResponseData` is what you reach for when you want the shape *and* the docs from one typed object.

**Risk.** Medium overall — Phases A2/B1 change the request/response pipeline for typed-DTO endpoints. Careful handling needed for: validation error mapping (422 body shape), content negotiation (JSON-first), partial/optional fields, PATCH semantics (per-operation required vs optional), and nested DTO hydration. The biggest single regression risk is the **return normalizer** (B1) — it had to leave the existing `Response`/array/object paths byte-identical. *(Phase B did; Phase C2 then deliberately added a Resource branch ahead of the generic object fallback, with regression tests proving plain objects/arrays still serialize exactly as before.)*

**Settled for v1:**
- **Rule wiring** — `#[Rule]` strings → `RuleParser::parse()` → `Validator` (no new adapter).
- **Rule declaration form** — property/parameter `#[Rule]` attributes (reflectable + co-located).
- **Hydration source** — JSON **body only**. Route/query params are NOT merged into the DTO; `{id}` etc. stay explicit method args.
- **PATCH / partial** — **separate DTOs** (`CreatePostData` / `UpdatePostData`), not a framework-level partial flag.
- **Response status** — `#[ResponseStatus(n)]`, default 200.
- **Resources interplay** — coexist (simple `ResponseData` path vs rich Resource path). As of Phase C2 a returned Resource is auto-normalized through its own `->toResponse()` (it keeps its own envelope, distinct from the `ResponseData` envelope); OpenAPI for Resource-returning endpoints stays manual via `#[ApiResponse]`.

**Open questions:**
1. ~~**Collections & pagination**~~ — RESOLVED in Phase C: first-class `CollectionResponse`/`PaginatedResponse` return types (runtime envelopes + reflect-mode schema from a `@return Type<Item>` docblock). `#[ApiResponse(collection: true)]` remains as the override.
2. ~~**Resource auto-normalizer**~~ — RESOLVED in Phase C2: `Router::normalizeResponse()` routes a returned `JsonResource`/`ResourceCollection`/`PaginatedResourceResponse` through its own `toResponse()` (the Resource keeps its own envelope; `#[ResponseStatus]` threads as the status). OpenAPI for Resource-returning endpoints stays manual via `#[ApiResponse]` — Resource bodies are runtime `toArray()` transforms with no static schema.

**Recommendation.** Pursue this as Glueful's types-first direction; the doc-generator work is the foundation (reflectors built; this adds the runtime binding + signature reflection). **Ship Phase A first** (request DTOs) for immediate, low-surface value, then Phase B (response DTOs + the return normalizer). It's especially timely for **Glueful + Lemma**: Lemma's admin/editor APIs carry many structured payloads where typed request DTOs cut controller noise and make the OpenAPI docs markedly cleaner. If the near-term goal is *just* docs, the shipped attributes already deliver — this is the deliberate next step up.
