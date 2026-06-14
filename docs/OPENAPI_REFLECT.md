# OpenAPI: `reflect` Generator

The `reflect` generator (`documentation.generator='reflect'`, or `API_DOCS_GENERATOR=reflect`) builds the OpenAPI spec from the **live route table and PHP types** rather than from PHPDoc/JSON fragments.

---

## What the generator infers automatically

The following are derived without any annotation:

| Signal | What is inferred |
|---|---|
| Route table (`Router`) | Path, HTTP method, path parameters (with `where()` constraints as `pattern`) |
| Middleware (`auth`, `api_key`) | `security` requirements via `SecuritySchemeRegistry` |
| Middleware (`rate_limit`) | `429 Too Many Requests` response + headers |
| Middleware (`require_scope`) | Scope names documented in `description` prose |
| `#[Fields]` attribute | `fields` / `expand` query parameters |
| `RequestData` parameter type | JSON `requestBody` schema (from DTO constructor + `#[Rule]` constraints), auto-422 |
| `ResponseData` / `CollectionResponse` / `PaginatedResponse` return type | Success-response schema, status code (from `#[ResponseStatus]`) |
| `#[Validate]` attribute | Fallback JSON `requestBody` schema when no `RequestData` parameter is present |

> **Configuration:** set `API_DOCS_GENERATOR=reflect` in `.env` or `'generator' => 'reflect'` in `config/documentation.php`. Leave `ROUTE_CACHE=false` during generation so extension routes registered via `ServiceProvider` are included.

---

## DTO-first philosophy

The reflect generator's guiding rule: **structure is inferred from types; you only annotate what types cannot express.**

- **JSON request shapes** are expressed as a typed `RequestData` DTO class. The generator reflects the DTO, reads its `#[Rule]` constraints, and derives the full `requestBody` schema. Never use `#[ApiRequestBody]` for a JSON body — the attribute's constructor rejects `contentType: 'application/json'` with an `InvalidArgumentException`.
- **JSON response shapes** are expressed as a typed `ResponseData` DTO, or as `CollectionResponse<Item>` / `PaginatedResponse<Item>` for lists. The generator reflects the return type and wraps it in Glueful's envelope automatically.
- **Non-JSON / multipart bodies** cannot be modelled by a DTO. `#[ApiRequestBody]` is the deliberate, narrow escape hatch for that case.
- **Non-JSON responses** (file downloads, HTML pages, raw blobs) cannot be modelled by a DTO. The `body:` parameter on `#[ApiResponse]` is the matching escape hatch.
- **`@return CollectionResponse<Item>` / `@return PaginatedResponse<Item>`** in a docblock is standard PHPDoc generics (type metadata that PHPStan and IDEs already consume) — not a doc-comment language. Write it when you need the generator to know the item type. The explicit attribute alternative is `#[ApiResponse(200, Item::class, collection: true)]`.
- **Prose and tags** (`summary`, `description`, `tags`) cannot be derived from types. `#[ApiOperation]` fills that gap.
- **Arbitrary query parameters** (`?days=30`, `?status=active`) are not expressible as PHP types. `#[QueryParam]` documents them.

In practice, roughly 95% of routes need no override attributes at all.

---

## Hand-authored override attributes

All four attributes live in `Glueful\Routing\Attributes\` and are read from the handler method by `RouteReflectionDocGenerator`. Every attribute is optional and additive — omitting it leaves the inferred value unchanged.

---

### `#[ApiOperation]` — prose summary, description, tags, deprecated

**Use when** the derived summary or tag group is wrong, or you need to add a human-readable description or mark an endpoint deprecated.

```php
namespace Glueful\Routing\Attributes;

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
    ) {}
}
```

**Override rules:**
- A non-empty `summary` replaces the derived summary (from the route name).
- A non-empty `tags` replaces the derived tag (from the first path segment).
- A non-empty `description` leads the description block; any auto-generated scope prose is appended after it.
- A non-null `operationId` replaces the generated operation ID.
- `deprecated: true` adds `"deprecated": true` to the operation.
- Omitted or empty fields keep the derived values unchanged.

**Example:**

```php
use Glueful\Routing\Attributes\{Get, ApiOperation};

class AuthController extends BaseController
{
    #[Get('/auth/login')]
    #[ApiOperation(
        summary: 'Sign in',
        description: 'Authenticate with credentials or a token/api-key shortcut.',
        tags: ['Authentication'],
    )]
    public function login(Request $request): Response
    {
        // ...
    }

    #[Get('/auth/token/legacy')]
    #[ApiOperation(summary: 'Legacy token endpoint', deprecated: true)]
    public function legacyToken(Request $request): Response
    {
        // ...
    }
}
```

---

### `#[QueryParam]` — arbitrary query parameters

**Use when** a route accepts query parameters that are not path parameters, `fields`/`expand` (those come from `#[Fields]`), or a `RequestData` JSON body.

```php
namespace Glueful\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
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
    ) {}
}
```

**Override / dedupe rules:**
- Parameters are merged in order: path params → field-selection params → `#[QueryParam]` attributes.
- Deduplication is by `(name, in)`. A `#[QueryParam]` wins over a generated param of the same name (e.g. overriding the generated `fields` parameter with a hand-authored description). Among multiple `#[QueryParam]` with the same name, last-declared wins.
- Path parameters (`in: path`) never collide with query parameters (`in: query`).

**Example:**

```php
use Glueful\Routing\Attributes\{Get, QueryParam};

class ReportController extends BaseController
{
    #[Get('/reports/summary')]
    #[QueryParam('days', 'integer', description: 'Rolling window in days', required: true)]
    #[QueryParam('status', description: 'Filter by status', enum: ['active', 'paused', 'closed'])]
    #[QueryParam('format', description: 'Response format', enum: ['json', 'csv'])]
    public function summary(Request $request): Response
    {
        // ...
    }
}
```

---

### `#[ApiRequestBody]` — multipart / non-JSON request bodies

**Use only for non-JSON bodies** (multipart file uploads, `application/octet-stream`, etc.). This is the narrow escape hatch for bodies a typed `RequestData` DTO cannot model.

```php
namespace Glueful\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class ApiRequestBody
{
    /** @param array<string,mixed> $schema Inline OpenAPI schema for the non-JSON body. */
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

**Override rules:**
- When present on a handler, `#[ApiRequestBody]` wins over `RequestData` parameter inference and `#[Validate]` inference.
- Passing `contentType: 'application/json'` throws `InvalidArgumentException` at load time. JSON bodies must use a typed `RequestData` DTO.
- The `$schema` array is an inline OpenAPI schema object — necessary here because a multipart/binary body cannot be expressed as a PHP type.

**Example:**

```php
use Glueful\Routing\Attributes\{Post, ApiRequestBody, ApiResponse};

class UploadController extends BaseController
{
    #[Post('/blobs/upload')]
    #[ApiRequestBody(
        schema: [
            'type' => 'object',
            'properties' => [
                'file'       => ['type' => 'string', 'format' => 'binary'],
                'visibility' => ['type' => 'string', 'enum' => ['public', 'private']],
            ],
            'required' => ['file'],
        ],
        description: 'Multipart file upload with optional visibility setting.',
    )]
    #[ApiResponse(201, BlobInfoData::class)]
    public function upload(Request $request): Response
    {
        // ...
    }
}
```

> **Never do this** — `#[ApiRequestBody(schema: [...], contentType: 'application/json')]` throws at load time. For a JSON body (even a free-form or polymorphic one) define a `RequestData` DTO with `array<string,mixed>` properties where the shape is dynamic.

---

### `#[ApiResponse]` — documenting responses, including `body:` for non-JSON

`#[ApiResponse]` is a repeatable attribute for declaring explicit response documentation. Its primary use is DTO-based response schemas; the `body:` parameter is the narrow hatch for non-JSON responses.

```php
namespace Glueful\Routing\Attributes;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiResponse
{
    /**
     * @param int               $status      HTTP status code (e.g. 200, 201, 404).
     * @param class-string|null $schema      Typed DTO class for the body (or the `data`
     *                                       payload when $envelope is true). Null = no body.
     * @param string            $description Human-readable response description.
     * @param bool              $collection  Wrap $schema as an array (a list of items).
     * @param bool              $envelope    Wrap in Glueful's {success, message, data} envelope.
     * @param string            $contentType Response media type.
     * @param 'binary'|'text'|'object'|null $body Non-JSON body kind. Used ONLY when $schema is null.
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $schema = null,
        public readonly string $description = '',
        public readonly bool $collection = false,
        public readonly bool $envelope = true,
        public readonly string $contentType = 'application/json',
        public readonly ?string $body = null,
    ) {
        if ($body !== null && !in_array($body, ['binary', 'text', 'object'], true)) {
            throw new InvalidArgumentException(
                "Invalid #[ApiResponse] body '{$body}': expected one of 'binary', 'text', 'object'."
            );
        }
    }
}
```

**`body:` resolution rules:**
- When `$schema` is a class string, it is reflected and the DTO schema is used — `body:` is ignored even when both are set.
- When `$schema` is null and `$body` is set, the generator emits a `content` entry for `$contentType` with a constrained schema:
  - `'binary'` → `{type: string, format: binary}` (file downloads)
  - `'text'` → `{type: string}` (plain text or HTML pages)
  - `'object'` → `{type: object}` (opaque JSON object without a DTO)
- When both `$schema` and `$body` are null, the result is a description-only response (no `content`).
- An invalid `$body` value throws `InvalidArgumentException` at load time.

**Examples:**

```php
use Glueful\Routing\Attributes\{Get, ApiResponse};

class UserController extends BaseController
{
    // Single envelope-wrapped DTO (most common case)
    #[Get('/users/{id}')]
    #[ApiResponse(200, UserData::class)]
    #[ApiResponse(404, description: 'User not found')]
    public function show(int $id): UserData
    {
        // ...
    }

    // Collection: envelope-wrapped array of DTOs
    #[Get('/users')]
    #[ApiResponse(200, UserData::class, collection: true)]
    public function index(Request $request): CollectionResponse
    {
        // ...
    }

    // Raw object (no envelope)
    #[Get('/users/{id}/raw')]
    #[ApiResponse(201, UserData::class, envelope: false)]
    public function raw(int $id): UserData
    {
        // ...
    }
}

class DocsController extends BaseController
{
    // Non-JSON response: HTML page
    #[Get('/docs')]
    #[ApiResponse(200, contentType: 'text/html', body: 'text')]
    public function index(Request $request): Response
    {
        // ...
    }

    // Non-JSON response: file download
    #[Get('/exports/{id}')]
    #[ApiResponse(200, contentType: 'application/octet-stream', body: 'binary')]
    #[ApiResponse(404, description: 'Export not found')]
    public function download(int $id): Response
    {
        // ...
    }
}
```

---

## Override priority summary

| Layer | Who wins |
|---|---|
| Inferred from return type (`ResponseData` / `CollectionResponse` / `PaginatedResponse`) | Base success response |
| `#[ApiResponse]` at the same status | Replaces the inferred or default response |
| `RequestData` parameter | JSON `requestBody` (type-driven) |
| `#[ApiRequestBody]` | Wins over everything for the request body |
| Generated path / field-selection params | Merged first |
| `#[QueryParam]` | Merged last; wins over same-name generated param |
| Derived `summary` / `tags` / `operationId` | Replaced by non-empty `#[ApiOperation]` fields |

---

## Quick reference

| Attribute | Target | Repeatable | Typical use |
|---|---|---|---|
| `#[ApiOperation]` | Method | No | Prose summary, description, tags, deprecated |
| `#[QueryParam]` | Method | Yes | Arbitrary query params; overrides generated param of same name |
| `#[ApiRequestBody]` | Method | No | Multipart / non-JSON request body only (rejects `application/json`) |
| `#[ApiResponse]` | Method | Yes | Explicit response by status; `body:` for non-JSON response bodies |

See also: [`docs/REQUEST_DTOS.md`](REQUEST_DTOS.md) · [`docs/RESPONSE_DTOS.md`](RESPONSE_DTOS.md)
