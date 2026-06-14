# Typed Request DTOs

A controller method parameter whose type implements `Glueful\Validation\Contracts\RequestData` is automatically decoded from the JSON request body, validated, and injected as a typed DTO — no manual `$request->toArray()` or `#[Validate]` needed for the request.

In `documentation.generator='reflect'` mode the OpenAPI request-body schema is inferred from the DTO class automatically.

---

## Worked example

### 1. Define the DTO

```php
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Attributes\Rule;

final class CreatePostData implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:200')]
        public readonly string $title,

        #[Rule('required|string')]
        public readonly string $body,

        #[Rule('string|in:draft,published')]
        public readonly string $status = 'draft',
    ) {}
}
```

Key points:

- Implement `RequestData` — that is the only contract required.
- Use constructor-promoted properties (v1 only; see [Limitations](#v1-limitations)).
- `#[Rule('…')]` accepts any Laravel-style rule string (`required`, `string`, `integer`, `email`, `max:N`, `in:a,b,c`, etc.).

### 2. Type-hint it in the controller

```php
use Glueful\Controllers\BaseController;
use Symfony\Component\HttpFoundation\Response;

class PostController extends BaseController
{
    public function store(CreatePostData $input): Response
    {
        $post = $this->posts->create(
            title:  $input->title,
            body:   $input->body,
            status: $input->status,
        );

        return Response::success(['post' => $post]);
    }
}
```

The method signature requires no `Request` parameter and carries no `#[Validate]` attribute — the DTO is the whole contract.

### 3. Register the route

```php
$router->post('/posts', [PostController::class, 'store'])
    ->middleware(['auth']);
```

---

## How it flows

```
POST /posts
Content-Type: application/json
{ "title": "Hello", "body": "World" }
        │
        ▼
Router::resolveMethodParameters()
  ├─ sees CreatePostData implements RequestData
  ├─ decodes JSON body → array
  ├─ RequestDataHydrator::hydrate(CreatePostData::class, $body)
  │     ├─ collects #[Rule] strings from constructor parameters
  │     ├─ RuleParser → Validator::validate($body)
  │     │     └─ ValidationException (→ 422) if any field fails
  │     └─ constructs CreatePostData with coerced, validated values
  └─ injects the typed DTO into the handler
        │
        ▼
PostController::store(CreatePostData $input)
  // $input is typed, valid, and ready to use
```

**Invalid request** — missing `title`:

```
POST /posts
{ "body": "World" }

HTTP 422 Unprocessable Entity
{
  "success": false,
  "errors": {
    "title": ["The title field is required."]
  }
}
```

**Malformed JSON** — the body cannot be decoded:

```
HTTP 422 Unprocessable Entity
{
  "success": false,
  "errors": {
    "body": ["Invalid JSON body."]
  }
}
```

---

## Use a DTO *instead of* `#[Validate]` for the body — not both

For the request body, choose ONE approach per route: a `RequestData` parameter, OR a `#[Validate]` attribute. Combining both on one route is unsupported, because the two are arbitrated differently:

- In **reflect docs mode**, a `RequestData` parameter supersedes `#[Validate]` for the generated request body — the generator documents the DTO and ignores a `#[Validate]` on the same method.
- At **runtime there is no arbitration.** `#[Validate]` body validation is enforced by the opt-in `ValidationMiddleware`, which runs *before* handler-parameter resolution. So if that middleware is attached to a route whose handler also takes a `RequestData` param, `#[Validate]` validates the body first and can `422` before the DTO is ever built — the DTO does NOT override it.

Reach for the DTO when you want a typed, self-documenting body; keep `#[Validate]` for query-parameter validation or for routes you haven't migrated.

---

## OpenAPI documentation (reflect mode)

With `documentation.generator='reflect'` (env `API_DOCS_GENERATOR=reflect`), the `RouteReflectionDocGenerator` inspects the handler's `RequestData` parameter and builds the `requestBody` schema from the DTO's constructor parameters and their `#[Rule]` constraints — no `#[Validate]` attribute is needed.

The same rules that drive validation (`required`, `string`, `integer`, `email`, `uuid`, `in:`, `min:`, `max:`, …) are mapped to OpenAPI properties by `ValidationRuleSchema::toObjectSchema()`. An example payload is also derived automatically.

If no `RequestData` parameter is found, the generator falls back to `#[Validate]` inference as before — so existing handlers are unaffected.

### Auto-derived 422 response

In reflect mode, a handler whose parameter list includes a `RequestData` parameter automatically documents a `422 Unprocessable Entity` response. No annotation is needed — the generator detects the parameter and emits the standard validation-error body shape:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "title": ["The title field is required."]
  }
}
```

To override the auto-derived 422 (for example to add a custom description or a different body schema), add `#[ApiResponse(422, description: 'Custom validation error')]` to the handler — the explicit attribute wins.

---

## Scaffolding a DTO

`scaffold:dto` generates a ready-to-use DTO stub in one command:

```bash
# Request DTO (implements RequestData, with a sample #[Rule] property)
php glueful scaffold:dto CreatePostData

# Response DTO (implements ResponseData)
php glueful scaffold:dto PostData --response

# Overwrite an existing file without prompting
php glueful scaffold:dto CreatePostData --force
```

The generated file is placed in `app/DTOs/` (namespace `App\DTOs`) when an `app/` directory exists, or `src/DTOs/` (namespace `Glueful\DTOs`) for framework-development contexts. The class name must be a valid PHP identifier.

---

## Reference adoption — worked example

`AuthController::refreshToken` is the canonical shipped example of a request DTO in the framework itself. It shows the key naming rule:

```php
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Attributes\Rule;

final class RefreshTokenData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $refresh_token,   // snake_case — matches the JSON key exactly
    ) {}
}
```

And the controller:

```php
// Glueful\Controllers\AuthController
public function refreshToken(RefreshTokenData $input): RefreshedTokenData
{
    $result = $this->authService->refreshTokens($input->refresh_token);
    // ...
    return new RefreshedTokenData(
        access_token:  $result['access_token'],
        refresh_token: $result['refresh_token'],
        // ...
    );
}
```

**Key reminder — exact-name binding:** `RequestDataHydrator` maps JSON body keys to constructor parameters by **exact name** — there is no snake_case↔camelCase conversion. A JSON key `refresh_token` must be a constructor param named `refresh_token`. If a JSON key is camelCase (e.g. `refreshToken`), the param must also be camelCase. The property name IS the JSON key.

---

## v1 limitations

### Explicit `null` can produce a `TypeError` rather than a 422

If a client sends `"status": null` for a non-nullable param whose rules do not include `required` (e.g. `in:draft,published` alone), the validator's `in:` check skips `null` values, so no validation error is raised. The hydrator then passes `null` to a non-nullable constructor parameter, which produces a PHP `TypeError` at instantiation.

**Mitigation:** either mark such fields `required` (so a null value fails the required check), or declare the parameter nullable (`?string $status = null`).

### `integer` rule rejects numeric JSON strings

The strict `integer` rule uses `gettype()` and therefore rejects a JSON string like `"42"` — it only accepts a real JSON integer. If a client might send numeric strings (e.g. from a form-encoded-to-JSON adapter), prefer the `numeric` rule, which accepts both, or ensure the client sends a genuine JSON number.

### Constructor params the hydrator can't safely fill → `TypeError` (500)

The hydrator relies on PHP's type system for anything it doesn't validate/coerce, so the following promoted params raise a `TypeError` (surfaced as **HTTP 500**, not a clean 422) — the same root cause as the explicit-`null` edge above:

- **A non-nullable param with no `#[Rule]` and no default** — nothing validates it and no value is supplied, so `null` reaches a non-nullable parameter. (This is the easiest to hit by accident — forgetting the `#[Rule]`.)
- **An `array` / `list` field** — a non-array JSON value is not coerced or type-checked.
- **A nested `RequestData` (or any non-builtin object) param** — coercion handles only scalars; nested DTOs are not recursively hydrated in v1.

Until a later phase converts these to 422, the rule of thumb is: **keep v1 DTOs flat (scalar fields), give every promoted param a `#[Rule]`, and make it defaultable or nullable.**

---

## What is not in v1

- **Non-promoted / non-public properties.** Only constructor-promoted properties are read for construction. For OpenAPI docs, only **public** promoted properties appear — a `protected`/`private` promoted `#[Rule]` param is validated at runtime but does NOT appear in the documented schema. Use public promoted properties for anything that should be part of the contract.
- **Route / query-parameter merging.** Merging path params or query params into the DTO is a later phase.

See `docs/proposals/types-first-dto-io.md` for the full typed-I/O roadmap. Response DTOs (`ResponseData`, `CollectionResponse`, `PaginatedResponse`) are implemented — see `docs/RESPONSE_DTOS.md`.
