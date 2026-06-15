# Typed Request DTOs

A controller method parameter whose type implements `Glueful\Validation\Contracts\RequestData` is automatically hydrated from the request, validated, and injected as a typed DTO — no manual `$request->toArray()` or `#[Validate]` needed for the request.

A request DTO can pull its fields from the **body** (default), the **path** (`#[FromRoute]`), and the **query string** (`#[FromQuery]`); declare typed **array** fields (`#[ArrayOf]`) including arrays of nested DTOs; and run **cross-field** checks (`ValidatesSelf`). Every failure — a bad scalar, a malformed nested element, a missing required field, or a cross-field rule — surfaces as a single `422` envelope with dot-path keys. A non-array value where an array is expected, or a non-object nested element, is a `422`, never a `TypeError`/500.

In `documentation.generator='reflect'` mode the OpenAPI operation is inferred from the DTO automatically: body schema, path params, and query params.

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
- Use constructor-promoted properties.
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

## The hydration model

`RequestDataHydrator::hydrate()` receives the decoded body, the matched path params, and the query bag, then builds the DTO in a fixed order. Understanding the order explains exactly which failures produce which 422 key:

```
RequestDataHydrator::hydrate(DtoClass, $body, $route, $query)
  1. Resolve each constructor param's raw value BY SOURCE
       body (default) · #[FromRoute] → $route · #[FromQuery] → $query
       and collect its #[Rule] string.
  2. Field rules — run the Validator over the resolved values.
       Failures collected under the field name.
  3. Array / nested hydration — for each #[ArrayOf] field whose
       container rule passed: coerce scalar elements, or recursively
       hydrate nested-DTO elements. Element failures collected under
       a dot-path key (e.g. schema.0.name). Depth is capped at 5.
  4. Missing required — a required-but-absent field is a 422 here,
       not a TypeError at construction.
  5. 422 before construct — if steps 2–4 produced ANY error, throw
       ValidationException now. The DTO is never constructed with
       partial/invalid data.
  6. ValidatesSelf::validate() — only once the DTO is built (so $this
       is fully typed) run the cross-field hook; its errors merge into
       the same 422 envelope.
```

The single rule of thumb: **field-level rules first, then structure, then cross-field — and nothing constructs until per-field validity is proven.**

---

## Sources — body, path, query

A field's value comes from the **body** by default. Two attributes redirect a field to another source:

```php
use Glueful\Validation\Attributes\{FromRoute, FromQuery, Rule};
use Glueful\Validation\Contracts\RequestData;

final class ShowArticleData implements RequestData
{
    public function __construct(
        #[FromRoute] public string $uuid,                       // path: /articles/{uuid}
        #[FromQuery] #[Rule('in:true,false')] public string $preview = 'false', // ?preview=true
        #[Rule('string')] public string $note = '',             // body (default)
    ) {}
}
```

```php
$router->get('/articles/{uuid}', [ArticleController::class, 'show']);
```

Rules:

- **One source per field.** Body is the default; `#[FromRoute]` and `#[FromQuery]` redirect to the path and query bags respectively.
- `#[Rule]` applies regardless of source — a `#[FromQuery]` field is validated exactly like a body field.
- **Dual-source is fail-loud.** A field carrying both `#[FromRoute]` and `#[FromQuery]` throws a `\LogicException` (a field has exactly one source).
- **Nested-source is fail-loud.** `#[FromRoute]`/`#[FromQuery]` are valid only on a top-level DTO; finding them on a nested (`#[ArrayOf]`-hydrated) DTO throws a `\LogicException`. Nested DTOs are always hydrated from their array element.

Field names bind by **exact name** — there is no snake_case↔camelCase conversion. The property name IS the body/path/query key.

---

## Typed arrays — `#[ArrayOf]`

`#[ArrayOf]` declares the element type of an `array` field. **For request DTOs it is the SOLE source of array element type — the `@var`/`@param` PHPDoc is NOT read.** A bare `array` with no `#[ArrayOf]` is treated as a mixed array.

### Scalar elements

```php
use Glueful\Validation\Attributes\{ArrayOf, Rule};

final class TagInput implements RequestData
{
    public function __construct(
        #[ArrayOf('scalar')] #[Rule('required|array')] public array $tags = [],
    ) {}
}
```

`#[ArrayOf('scalar')]` (or `'string'`, `'int'`, `'float'`, `'bool'`) checks/coerces each element. A non-scalar element fails with a dot-path key (`tags.0`).

### Nested-DTO elements

Point `#[ArrayOf]` at a `RequestData` class to hydrate each element recursively:

```php
final class FieldDef implements RequestData
{
    public function __construct(
        #[Rule('required|string')] public string $name = '',
        #[Rule('required|string')] public string $type = '',
    ) {}
}

final class CreateSchemaData implements RequestData
{
    /** @param array<int,FieldDef> $schema */
    public function __construct(
        #[ArrayOf(FieldDef::class)] #[Rule('required|array')] public array $schema = [],
    ) {}
}
```

Each element of `schema` is hydrated as a `FieldDef`. A bad element produces a nested 422 key:

```
POST { "schema": [ { "type": "string" } ] }   // missing required "name"

HTTP 422
{ "errors": { "schema.0.name": ["The schema.0.name field is required."] } }
```

A non-array `schema`, or a non-object element where a DTO is expected, is also a `422` (not a 500). Nested DTOs may themselves contain `#[ArrayOf]` fields; recursion is **depth-capped at 5** — exceeding it is a 422 on the offending field.

The `#[ArrayOf]` constructor validates fail-loud at class-load time: an unknown scalar name, a non-existent class, or a class that does not implement `RequestData` throws `\InvalidArgumentException`.

---

## Cross-field validation — `ValidatesSelf`

For checks that span multiple fields, implement `ValidatesSelf`. Its `validate()` runs **after** per-field rules pass and the DTO is constructed, so `$this` is fully typed:

```php
use Glueful\Validation\Contracts\{RequestData, ValidatesSelf};
use Glueful\Validation\Attributes\Rule;

final class PublishData implements RequestData, ValidatesSelf
{
    public function __construct(
        #[Rule('required|in:draft,published')] public string $status = 'draft',
        #[Rule('string')] public ?string $publishedAt = null,
    ) {}

    /** @return array<string, list<string>> field => messages (empty = valid) */
    public function validate(): array
    {
        if ($this->status === 'published' && $this->publishedAt === null) {
            return ['publishedAt' => ['Required when status is published.']];
        }
        return [];
    }
}
```

Return an empty array when valid. Any returned `field => messages` entries merge into the same 422 envelope as the per-field errors. Because the hook runs last, it only fires for input that is otherwise individually valid.

---

## Custom rules — `RuleRegistry`

`#[Rule]` strings can reference app-registered custom rules by name. Register them in a service provider's `boot()` against the `RuleRegistry`:

```php
use Glueful\Validation\Support\RuleRegistry;

public function boot(ApplicationContext $context): void
{
    /** @var RuleRegistry $registry */
    $registry = app($context, RuleRegistry::class);
    $registry->register('reserved_name', ReservedNameRule::class);
}
```

Then use the name in any `#[Rule]` string — it composes with built-ins via `|`:

```php
#[Rule('required|string|reserved_name')] public string $name = '';
```

Built-in rule names (`required`, `string`, `integer`, `in`, `max`, …) are **reserved**: `register()` will not silently shadow one (pass `overwrite: true` to force it). `RuleParser::builtinRuleNames()` is the authoritative list. The same registry drives validation at every level, including nested-DTO and array-element rules.

---

## Use a DTO *instead of* `#[Validate]` for the body — not both

For the request body, choose ONE approach per route: a `RequestData` parameter, OR a `#[Validate]` attribute. Combining both on one route is unsupported, because the two are arbitrated differently:

- In **reflect docs mode**, a `RequestData` parameter supersedes `#[Validate]` for the generated request body — the generator documents the DTO and ignores a `#[Validate]` on the same method.
- At **runtime there is no arbitration.** `#[Validate]` body validation is enforced by the opt-in `ValidationMiddleware`, which runs *before* handler-parameter resolution. So if that middleware is attached to a route whose handler also takes a `RequestData` param, `#[Validate]` validates the body first and can `422` before the DTO is ever built — the DTO does NOT override it.

Reach for the DTO when you want a typed, self-documenting request; keep `#[Validate]` for routes you haven't migrated.

---

## OpenAPI documentation (reflect mode)

With the `reflect` generator, `RouteReflectionDocGenerator` inspects the handler's `RequestData` parameter and documents the whole operation — and it understands every v2 feature:

- **`#[FromRoute]` → a `path` parameter** (and the field is excluded from the request-body schema).
- **`#[FromQuery]` → a `query` parameter** (and excluded from the body schema).
- **Body fields → the `requestBody` schema**, built from the remaining constructor params and their `#[Rule]` constraints.
- **`#[ArrayOf]` drives `items`** — an `array` field documents its element type (scalar schema, or the nested DTO's object schema) from the attribute, since `@var` is not read.

The same rules that drive validation (`required`, `string`, `integer`, `email`, `uuid`, `in:`, `min:`, `max:`, …) are mapped to OpenAPI properties by `ValidationRuleSchema::toObjectSchema()`. An example payload is derived automatically.

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
}
```

**Key reminder — exact-name binding:** `RequestDataHydrator` maps body keys to constructor parameters by **exact name** — there is no snake_case↔camelCase conversion. A JSON key `refresh_token` must be a constructor param named `refresh_token`. The property name IS the key. (The same exact-name binding applies to `#[FromRoute]` path params and `#[FromQuery]` query params.)

---

See `docs/proposals/types-first-dto-io.md` for the full typed-I/O roadmap. Response DTOs (`ResponseData`, `CollectionResponse`, `PaginatedResponse`) are implemented — see `docs/RESPONSE_DTOS.md`.
