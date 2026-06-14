# Typed Response DTOs

A controller method whose return type implements `Glueful\Http\Contracts\ResponseData` is automatically serialized and enveloped into the standard Glueful response — no manual `Response::success([...])` call needed for the response.

In `documentation.generator='reflect'` mode the OpenAPI success-response schema is inferred from the DTO class automatically, giving you one typed class that drives both the runtime payload and the generated spec.

---

## Worked example

### 1. Define supporting types

```php
use Glueful\Http\Contracts\ResponseData;

enum PostStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
}

final class AuthorData implements ResponseData
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
    ) {}
}
```

### 2. Define the response DTO

```php
use Glueful\Http\Contracts\ResponseData;

final class PostData implements ResponseData
{
    public function __construct(
        public readonly string       $uuid,
        public readonly string       $title,
        public readonly PostStatus   $status,
        public readonly AuthorData   $author,
        public readonly \DateTimeImmutable $publishedAt,
    ) {}
}
```

Key points:

- Implement `ResponseData` — that is the only contract required.
- Declare public typed properties (promoted or plain).
- Nested `ResponseData` instances, backed enums, and `DateTimeInterface` values are handled automatically.

### 3. Return it from the controller

```php
use Glueful\Controllers\BaseController;
use Glueful\Routing\Attributes\ResponseStatus;
use Symfony\Component\HttpFoundation\Response;

class PostController extends BaseController
{
    #[ResponseStatus(201)]
    public function store(CreatePostData $input): PostData
    {
        $post = $this->posts->create($input);

        return new PostData(
            uuid:        $post['uuid'],
            title:       $post['title'],
            status:      PostStatus::from($post['status']),
            author:      new AuthorData($post['author_uuid'], $post['author_name']),
            publishedAt: new \DateTimeImmutable($post['published_at']),
        );
    }
}
```

### 4. The enveloped JSON response

```
HTTP 201 Created
Content-Type: application/json

{
  "success": true,
  "message": "Created",
  "data": {
    "uuid": "018e4c12-...",
    "title": "Hello, World",
    "status": "draft",
    "author": {
      "uuid": "018e3a00-...",
      "name": "Alice"
    },
    "publishedAt": "2026-06-14T09:00:00+00:00"
  }
}
```

No manual `Response::success()` call is needed — the router detects the `ResponseData` return type, serializes the DTO, and wraps it automatically.

---

## `#[ResponseStatus]` — setting the success status

The `Glueful\Routing\Attributes\ResponseStatus` attribute declares which 2xx HTTP status code the envelope should carry.

```php
use Glueful\Routing\Attributes\ResponseStatus;

// 200 OK (default — no attribute required)
public function show(string $uuid): PostData { ... }

// 201 Created
#[ResponseStatus(201)]
public function store(CreatePostData $input): PostData { ... }

// 202 Accepted
#[ResponseStatus(202)]
public function enqueue(JobData $input): JobData { ... }
```

**Fail-loud rule:** the attribute constructor rejects any non-2xx status with an `InvalidArgumentException` thrown at load time. `#[ResponseStatus(404)]` is a programmer error and surfaces immediately — it is never silently accepted and carried into a runtime response.

```php
// Throws InvalidArgumentException: "#[ResponseStatus] must be a 2xx success status; got 404."
#[ResponseStatus(404)]
public function show(string $uuid): PostData { ... }
```

---

## Serialization rules

`Glueful\Serialization\ResponseDataSerializer::toArray(ResponseData $dto): array` converts the DTO to a plain array using the following rules, in priority order:

### Escape hatch — custom `toArray()`

If the DTO class declares its own `toArray()` method **and it returns an array**, that result is returned verbatim. The `ResponseData` interface does NOT require `toArray()` — this is an optional escape hatch for DTOs that need full control over their serialized shape (e.g. camelCase keys, computed fields, omitting nulls). A `toArray()` that returns a non-array (for instance, one inherited from a base class with a different contract) is ignored and the reflection path is used instead.

> **Symmetry caveat:** the escape hatch opts OUT of payload↔schema symmetry. The reflect-mode doc generator (`ClassSchemaReflector`) always reflects the DTO's public typed properties, so a custom `toArray()` shape is NOT reflected in the generated OpenAPI schema — the documented shape will describe the properties, not your `toArray()` output. Use the escape hatch only when you accept that divergence (or document the response explicitly with `#[ApiResponse]`).

```php
final class PostData implements ResponseData
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $title,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'    => $this->uuid,
            'label' => strtoupper($this->title),
        ];
    }
}
```

### Reflection path — public typed properties

When no `toArray()` exists the serializer reflects the DTO's public, non-static properties:

| Property value type | Serialized form |
|---|---|
| `null` / scalar | As-is |
| Backed enum (`string`/`int`) | `->value` |
| Pure (unit) enum | `->name` |
| `DateTimeInterface` | ISO-8601 string via `format('c')` |
| Nested `ResponseData` | Recursed (same rules apply) |
| `array` | Element-wise (each element mapped through same rules) |
| Uninitialized typed property | Skipped entirely |
| Any other object | Best-effort `get_object_vars()`, each member mapped |

### Cycle and depth guard

Recursion is capped at depth 5 (matching `ClassSchemaReflector`). An object already on the current branch (self-referential DTO) is also detected via `SplObjectStorage`. Either condition renders the value as `null` rather than looping forever. The guard is per top-level `toArray()` call and has no cross-call state.

---

## OpenAPI documentation (reflect mode)

With `documentation.generator='reflect'` (env `API_DOCS_GENERATOR=reflect`), `RouteReflectionDocGenerator` infers the success-response OpenAPI schema from the handler method's `ResponseData` return type — the same DTO drives both the runtime payload and the generated spec.

**What is inferred automatically:**

- The return type is inspected; if it is a single non-builtin class implementing `ResponseData`, `ClassSchemaReflector::toSchema()` reflects its public typed properties into an OpenAPI object schema.
- The schema is wrapped in Glueful's standard success envelope (`{success: boolean, message: string, data: <DTO schema>}`), matching `Response::success()` exactly.
- The status code comes from `#[ResponseStatus]` (default 200).
- A nullable return type (`?PostData`) still infers from the inner class; union/intersection types and non-`ResponseData` classes yield no inference.

**`#[ApiResponse]` still takes precedence:**

An explicit `#[ApiResponse]` at the same status code overrides the inferred schema for that status. Use this when you need to document a shape that differs from the actual return type (e.g. the DTO wraps a union type you cannot express statically), or to annotate multiple responses at different status codes.

```php
use Glueful\Routing\Attributes\{ApiResponse, ResponseStatus};

// Reflected automatically from PostData:
#[ResponseStatus(201)]
public function store(CreatePostData $input): PostData { ... }

// Explicit override — #[ApiResponse(201)] wins over the return type:
#[ResponseStatus(201)]
#[ApiResponse(201, PostData::class, description: 'Post created')]
#[ApiResponse(422, description: 'Validation failed')]
public function storeWithDocs(CreatePostData $input): PostData { ... }
```

**4xx and error responses** always require explicit `#[ApiResponse]` — they are never inferred from the return type.

---

## v1 boundaries

### `ResponseData` only — Resources are a separate path

`JsonResource`, `ModelResource`, and `ResourceCollection` (the "rich transformation path" with conditional fields, relationship loading, and pivot access) still return via `->toResponse()` and are **not auto-enveloped**. The two paths coexist and are intended for different use cases:

| | Simple DTO path | Rich Resource path |
|---|---|---|
| **Contract** | `implements ResponseData` | extends `JsonResource` / `ResourceCollection` |
| **Serialization** | Automatic (reflection or `toArray()`) | Manual (`->toResponse()`) |
| **Docs (reflect mode)** | Inferred from return type | Annotate with `#[ApiResponse]` |
| **Best for** | Small typed outputs, symmetry with request DTOs | Conditional fields, relationships, pagination, `whenLoaded` |

### Success response only

Error and 4xx responses are not produced from a `ResponseData` return. They still come from `#[ApiResponse]` (for documented error shapes) or from exceptions thrown inside the handler (caught and converted by the exception handler).

### Collections and pagination are not first-class

There is no built-in `ResponseData` wrapper for a paginated list. The idiomatic workaround is a plain array property on the DTO:

```php
final class PostListData implements ResponseData
{
    /** @param PostData[] $items */
    public function __construct(
        public readonly array $items,
        public readonly int   $total,
        public readonly int   $page,
        public readonly int   $perPage,
    ) {}
}
```

For full pagination link generation and `ResourceCollection` semantics, use the Resource path instead.

### Public typed properties only

The serializer reflects `public` non-static properties. `protected`/`private` properties are not serialized, and the escape hatch (`toArray()`) must be used if the DTO needs to expose computed or visibility-restricted fields.
