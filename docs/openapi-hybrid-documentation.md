# Code-First OpenAPI — Behavior & Implementation Plan

Status: describes what the reflect-only generator does **today**, identifies the two
remaining sources of attribute noise, and specifies the two changes that remove
them. Implementation-ready.

Glueful's OpenAPI generator is code-first. `RouteReflectionDocGenerator` reads the
live `Router` table and controller signatures and uses attributes only for what PHP
types and routes cannot express. The authoring model is layered:

1. Routes and middleware provide security, error, and field-selection defaults.
2. Request/response DTO types provide params, request bodies, and JSON shapes.
3. Attributes add only the prose and shapes the signature cannot express.

This document does **not** reintroduce a comment grammar, and it does **not** add a
policy class. It closes the gap by extending inference the generator already does.

---

## What the generator infers today (verified)

`RouteReflectionDocGenerator::buildOperation()` already produces, per operation:

- **Path params** from route placeholders/constraints.
- **Security** from route middleware via `SecuritySchemeRegistry` +
  `config('documentation.middleware_map')` (e.g. `auth → BearerAuth`,
  `api_key → ApiKeyAuth`).
- **Default responses** from route shape (`buildResponses()`):
  - `200` always;
  - `401` + `403` when the route is **secured** (has security-mapped middleware);
  - `429` (with `Retry-After` / `X-RateLimit-*` headers) when the route is
    **rate-limited** (`$route->getRateLimitConfig() !== []`);
  - `422` (with the validation-error body schema) when the handler takes a
    `RequestData` parameter.
- **Field-selection params** (`fields`, `expand`) for `#[Fields]` routes.
- **Request body** from a typed `RequestData` param (`#[FromQuery]`/`#[FromRoute]`
  fields are excluded from the body and emitted as parameters instead).
- **Success schema** from a `ResponseData` / `CollectionResponse<T>` /
  `PaginatedResponse<T>` return type.
- **Attribute overlays**, applied last so they win at the same status:
  `#[ApiOperation]`, `#[ApiResponse]`, `#[QueryParam]`, `#[ApiRequestBody]`,
  `#[ResponseStatus]`.

**Precedence (already correct):** defaults → inferred success/422 → explicit
attributes. An explicit `#[ApiResponse(401, …)]` replaces the auto `401`; a handler
with no attributes is left as inferred.

So the often-repeated belief that "401/403/429 must be declared by hand" is **false** —
they are already inferred. The noise has a narrower cause.

## The remaining noise (evidence: `DeliveryController::index()`)

That method carries ~80 lines of attributes: 1 `#[ApiOperation]` (unique prose),
6 `#[QueryParam]`, and 8 `#[ApiResponse]` (`200, 401, 403, 404, 422, 429, 500`). Two
concrete causes, each a small generator gap:

1. **Inferred error responses carry no body schema.** `buildResponses()` emits
   `401`/`403`/`429` as bare `{description}` objects — no `content`/schema. So a
   developer redeclares `#[ApiResponse(401, schema: ErrorResponse::class, envelope:
   false, …)]` (and `403`, `429`, plus a `500` that is not inferred at all) purely to
   attach an error body shape and custom wording. That is 4 of the 8 responses, and
   they repeat on every delivery method.

2. **`#[FromQuery]` cannot carry a description.** It is a bare marker;
   `buildRequestDataSourceParameters()` derives each query param's schema from
   `#[Rule]` and its required-ness from the default value, but reads **no
   description**. The reflector only picks up a description from a same-line
   `@var Type description` docblock — which cannot hold the multi-sentence prose in
   the current `#[QueryParam]` blocks (filter bracket-grammar, operator list,
   pagination-mode switch). So naively moving the 6 query params into a DTO would
   **silently downgrade the docs**.

Fix both and the method collapses to ~5 attribute lines + one DTO — with no new
developer-authored construct.

---

## Implementation plan

### Change A — default error body on inferred error responses

Give the auto `401`/`403`/`429` (and an opt-in default `500`) a body schema so
developers stop redeclaring them.

**Do NOT reflect `Glueful\DTOs\ErrorResponseDTO`** — it is the fat exception/debug
DTO (carries `trace`, `file`, `line`, `originalException`, …) and would emit an ugly
schema. Use a **slim inline error schema**, app-overridable by class.

- **`config/documentation.php`** — add an `errors` block:
  ```php
  'errors' => [
      // null => use the built-in slim inline shape below.
      // Or a class-string of a thin public-typed DTO to reflect via ClassSchemaReflector.
      'schema'   => env('API_DOCS_ERROR_SCHEMA', null),
      'envelope' => false, // error bodies are not wrapped in the success envelope
      // Statuses to seed on EVERY documented operation regardless of middleware.
      // Keep empty by default; set [500] if you want a documented server error.
      'always'   => [],
      // Per-status default descriptions (used when no #[ApiResponse] overrides).
      'descriptions' => [
          401 => 'Unauthenticated.',
          403 => 'Forbidden.',
          429 => 'Too Many Requests.',
          500 => 'Unexpected server error.',
      ],
  ],
  ```

- **`RouteReflectionDocGenerator`** —
  1. Add `buildDefaultErrorBody(): array` returning the body once: the configured
     `errors.schema` reflected via `ClassSchemaReflector` (request/response mode,
     `envelope:false`) when set, else the built-in slim inline shape:
     ```php
     ['content' => ['application/json' => ['schema' => [
         'type' => 'object',
         'properties' => [
             'success' => ['type' => 'boolean', 'example' => false],
             'message' => ['type' => 'string'],
         ],
         'required' => ['success', 'message'],
     ]]]]
     ```
  2. In `buildResponses(bool $isSecured, bool $rateLimited)`, merge that body into the
     `401`/`403`/`429` entries (keep the existing `429` headers), set each
     `description` from `errors.descriptions`, and seed any `errors.always` statuses
     (e.g. `500`).
  3. Leave the overlay/precedence untouched — an explicit `#[ApiResponse(403, …)]`
     still replaces the default, so domain-specific wording remains possible.

- **Tests** (`tests/.../RouteReflectionDocGenerator*` style): a secured route's `401`
  now has a JSON body; a rate-limited route's `429` keeps its headers **and** gains a
  body; `errors.always = [500]` seeds a `500`; an explicit `#[ApiResponse(403)]` still
  overrides; `errors.schema = SomeDto::class` reflects that DTO.

**Effect:** delete the manual `401`/`403`/`429`/`500` `#[ApiResponse]`s from delivery
(and every similarly-shaped controller). Zero per-method work; benefits every app.

### Change B — descriptions on `#[FromQuery]` / `#[FromRoute]`

Make the query-DTO move lossless.

- **`src/Validation/Attributes/FromQuery.php`** and
  **`src/Validation/Attributes/FromRoute.php`** — add optional constructor args:
  ```php
  public function __construct(
      public readonly ?string $description = null,
      public readonly ?string $example = null,
  ) {}
  ```
  (Both remain valid as bare `#[FromQuery]` — args are optional.)

- **`RouteReflectionDocGenerator::buildRequestDataSourceParameters()`** — when
  building each parameter, read the `FromQuery`/`FromRoute` instance and set
  `$parameter['description']` and, when present, `schema['example']`. No other call
  site changes.

- **Tests:** a `#[FromQuery(description: '…', example: '…')]` DTO field surfaces both
  in the generated parameter; a bare `#[FromQuery]` field still generates a param with
  no description (unchanged behavior).

**Effect:** the 6 `#[QueryParam]` blocks move into a `DeliveryListQuery` DTO with their
prose intact, and gain runtime validation/coercion via the hydrator.

### Resulting controller (after A + B)

```php
final class DeliveryListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Content locale to read; defaults to the i18n default locale.')]
        #[Rule('string')]
        public readonly ?string $locale = null,

        #[FromQuery(description: 'Typed filters on filterable fields: filter[field][op]=value (eq,neq,gt,gte,lt,lte,in).')]
        #[Rule('array')]
        public readonly array $filter = [],

        // … sort, cursor, page, perPage, each with #[FromQuery(description: …)]
    ) {}
}
```

```php
#[ApiOperation(summary: 'List published entries of a content type', description: '…unique prose…', tags: ['Lemma Delivery'])]
#[ApiResponse(200, schema: DeliveryListData::class)]
#[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown content type slug.')]
#[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Filter/sort references a non-filterable field.')]
public function index(Request $request, DeliveryListQuery $query, string $type): Response
```

~80 attribute lines → ~5 + one reusable DTO. `401`/`403`/`429`/`500` are inferred with
bodies; `200`/`404`/`422` stay because their schema/wording is method-specific.

---

## Explicitly NOT building

The earlier drafts of this doc floated a reusable documentation layer
(`#[ApiResponseSet]` / `#[ApiErrors]` / `ApiPolicy` / `ApiDocumentationPolicy` /
`#[ApiDocSet]`). **We are not building those now**, because Changes A and B remove the
repeated errors *and* the repeated params with **zero** developer-authored construct —
which is strictly better DX than any attribute/class a developer has to write and keep
in sync. A policy/error-set only relocates noise.

Revisit a reusable layer **only if**, after A + B ship, real repetition remains that is
(a) not inferable from route/middleware and (b) not part of any request/response DTO —
e.g. a non-middleware error several endpoints share. Until that evidence exists, the
default path stays: types + inference + a few direct attributes.

## What inference + DTOs still do not replace

Some endpoint behavior is genuinely method-specific prose and belongs in
`#[ApiOperation(description: …)]`, not in a DTO or a default:

- public/private content-type access nuance;
- cursor-vs-offset pagination switching;
- filter-grammar specifics that depend on a content type's filterable fields.

These stay as operation prose. Do not try to encode them as generic defaults.

## Non-Goals

- Do not bring back `@route` / OpenAPI comment blocks as generator input. (A docblock
  `@queryParam` is at most an editor hint; the generator reads types + attributes —
  use `#[FromQuery(description: …)]`, not a docblock tag, for documented query params.)
- Do not inline JSON-schema mini-languages for normal JSON bodies.
- Do not force policy classes on ordinary controllers.
- Do not hide unique endpoint behavior behind broad defaults.

The target stays boring: controller signatures look like the runtime contract, and
attributes explain only what the signature cannot.
```
