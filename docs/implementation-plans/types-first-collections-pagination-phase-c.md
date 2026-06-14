# Types-First DTO I/O — Phase C Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the typed-I/O story — typed collection/pagination response DTOs, an auto-derived `422` validation-error response in reflect-mode docs, and a `scaffold:dto` generator command.

**Architecture:** Strictly additive, opt-in by type, mirroring Phases A/B. Two concrete value objects (`CollectionResponse`, `PaginatedResponse`) get dedicated branches in `Router::normalizeResponse()` ahead of the generic `ResponseData` branch and render through the existing `Response::success()/created()/paginated()` envelopes; the reflect generator (`RouteReflectionDocGenerator`) infers their list schema from a `@return Type<Item>` docblock and auto-emits a `422` whenever a handler takes a `RequestData` param; a `scaffold:dto` console command scaffolds request/response DTO stubs.

**Tech Stack:** PHP 8.3+, Glueful framework (NOT Laravel), PHPUnit, Symfony Console, PSR-12. Namespace `Glueful\` → `src/`.

**Out of scope (deliberately):** the Resource auto-normalizer (routing a returned `JsonResource`/`ResourceCollection` through `->toResponse()`) — a separate later phase. Status selection via `#[ResponseStatus]` is already delivered in Phase B and is not re-done here.

**Conventions for every task:**
- Work on branch `dev` directly (no feature branch). Commits carry **NO `Co-Authored-By` trailer**. Never stage `CLAUDE.md`.
- TDD: write the failing test, watch it fail, implement, watch it pass.
- Per-task gates before commit: the task's targeted `phpunit` + the surrounding suite (named per task) green; `composer phpcs` clean on changed files; `vendor/bin/phpstan analyse <changed file> --level=6 --no-progress` reports no NEW errors. The PHPStan 2.x upgrade banner is a benign nag — ignore it.
- All line numbers below were captured from HEAD at planning time; **verify against the current file** before editing (they may have drifted a few lines).

---

## File Structure

**Create:**
- `src/Http/Responses/CollectionResponse.php` — value object: a typed list of response items.
- `src/Http/Responses/PaginatedResponse.php` — value object: a typed paginated list (items + page/perPage/total).
- `tests/Unit/Http/Responses/CollectionAndPaginatedResponseTest.php` — construction tests for the two value objects.
- `tests/Unit/Routing/CollectionResponseNormalizationTest.php` — router envelope tests for the two return types.
- `tests/Unit/Support/Documentation/CollectionResponseSchemaTest.php` — generator schema-inference tests.
- `tests/Unit/Support/Documentation/ValidationErrorResponseTest.php` — generator 422 tests.
- `src/Console/Commands/Scaffold/DtoCommand.php` — `scaffold:dto` generator command.
- `tests/Unit/Console/Scaffold/DtoCommandTest.php` (or `tests/Integration/Console/Scaffold/` to match existing console-test placement) — CommandTester tests.

**Modify:**
- `src/Routing/Router.php` — add the `serializeResponseItems()` helper + two branches in `normalizeResponse()`.
- `src/Support/Documentation/RouteReflectionDocGenerator.php` — extend `buildResponseFromReturnType()` for the two return types (+ `wrapInPaginatedEnvelope()`, `collectionItemSchema()`, `itemClassFromReturnDocblock()`, `returnStatus()` helpers); inject the 422 in `buildOperation()` (+ `buildValidationErrorResponse()` helper).
- `CHANGELOG.md` — `[Unreleased] → Added`.
- `docs/RESPONSE_DTOS.md` — new "Collections & pagination" section.
- `docs/REQUEST_DTOS.md` — note the auto-derived 422.

---

## Group A — Collection & pagination response DTOs

### Task A1: `CollectionResponse` + `PaginatedResponse` value objects

**Files:**
- Create: `src/Http/Responses/CollectionResponse.php`
- Create: `src/Http/Responses/PaginatedResponse.php`
- Test: `tests/Unit/Http/Responses/CollectionAndPaginatedResponseTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Http\Responses;

use Glueful\Http\Responses\CollectionResponse;
use Glueful\Http\Responses\PaginatedResponse;
use PHPUnit\Framework\TestCase;

final class CollectionAndPaginatedResponseTest extends TestCase
{
    public function testCollectionResponseHoldsItems(): void
    {
        $c = new CollectionResponse([['id' => 1], ['id' => 2]]);
        self::assertSame([['id' => 1], ['id' => 2]], $c->items);
    }

    public function testPaginatedResponseHoldsItemsAndMeta(): void
    {
        $p = new PaginatedResponse([['id' => 1]], page: 2, perPage: 10, total: 25);
        self::assertSame([['id' => 1]], $p->items);
        self::assertSame(2, $p->page);
        self::assertSame(10, $p->perPage);
        self::assertSame(25, $p->total);
    }

    public function testPaginatedResponseRejectsZeroPerPage(): void
    {
        // perPage 0 would cause a division-by-zero in Response::paginated()'s
        // ceil($total / $perPage) — fail loud at construction instead.
        $this->expectException(\InvalidArgumentException::class);
        new PaginatedResponse([], page: 1, perPage: 0, total: 0);
    }

    public function testPaginatedResponseRejectsZeroPage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaginatedResponse([], page: 0, perPage: 10, total: 0);
    }

    public function testPaginatedResponseRejectsNegativeTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaginatedResponse([], page: 1, perPage: 10, total: -1);
    }
}
```

- [ ] **Step 2: Run → fail** (`vendor/bin/phpunit tests/Unit/Http/Responses/CollectionAndPaginatedResponseTest.php`) — Expected: error, classes not found.

- [ ] **Step 3: Implement the two value objects**

`src/Http/Responses/CollectionResponse.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * A typed list of response items. Returning one from a controller renders the
 * standard success envelope with `data` set to the serialized list:
 * `{success, message, data: [ ... ]}`.
 *
 * Items are typically {@see ResponseData} DTOs — each serialized via
 * {@see \Glueful\Serialization\ResponseDataSerializer}; plain arrays/scalars
 * pass through unchanged. For precise OpenAPI docs, annotate the handler return
 * with the item type: `@return CollectionResponse<PostData>`.
 */
final class CollectionResponse
{
    /** @param list<mixed> $items */
    public function __construct(public readonly array $items)
    {
    }
}
```

`src/Http/Responses/PaginatedResponse.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Http\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * A typed, paginated list of response items. Returning one from a controller
 * renders Glueful's flat pagination envelope (mirroring
 * {@see \Glueful\Http\Response::paginated()}):
 * `{success, message, data: [...], current_page, per_page, total, total_pages,
 * has_next_page, has_previous_page}`.
 *
 * Items are typically {@see ResponseData} DTOs. For precise OpenAPI docs,
 * annotate the handler return with the item type:
 * `@return PaginatedResponse<PostData>`.
 *
 * Constructor validation fails loud on invalid pagination metadata: `perPage`
 * must be >= 1 (the downstream {@see \Glueful\Http\Response::paginated()} does
 * `ceil($total / $perPage)`, so `perPage: 0` would be a division-by-zero),
 * `page` must be >= 1, and `total` must be >= 0.
 */
final class PaginatedResponse
{
    /** @param list<mixed> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
    ) {
        if ($page < 1) {
            throw new \InvalidArgumentException("PaginatedResponse page must be >= 1; got {$page}.");
        }
        if ($perPage < 1) {
            throw new \InvalidArgumentException("PaginatedResponse perPage must be >= 1; got {$perPage}.");
        }
        if ($total < 0) {
            throw new \InvalidArgumentException("PaginatedResponse total must be >= 0; got {$total}.");
        }
    }
}
```

- [ ] **Step 4: Run → pass.**

- [ ] **Step 5: Gates** — `composer phpcs src/Http/Responses/CollectionResponse.php src/Http/Responses/PaginatedResponse.php`; `vendor/bin/phpstan analyse src/Http/Responses/CollectionResponse.php src/Http/Responses/PaginatedResponse.php --level=6 --no-progress`.

- [ ] **Step 6: Commit**
```bash
git add src/Http/Responses/ tests/Unit/Http/Responses/
git commit -m "Add CollectionResponse + PaginatedResponse value objects"
```

---

### Task A2: Router envelope branches for collection/pagination returns

**Files:**
- Modify: `src/Routing/Router.php` (the `normalizeResponse()` method, ~lines 1076–1108; add a private helper near it)
- Test: `tests/Unit/Routing/CollectionResponseNormalizationTest.php`

**Context — the current `normalizeResponse()` (verify against HEAD):**
```php
private function normalizeResponse(mixed $result, int $successStatus = 200): Response
{
    if ($result instanceof Response) {
        return $result;
    }
    if (is_string($result)) {
        return new Response($result);
    }
    // Phase B: envelope a ResponseData DTO.
    if ($result instanceof \Glueful\Http\Contracts\ResponseData) {
        $data = (new \Glueful\Serialization\ResponseDataSerializer())->toArray($result);
        return match ($successStatus) {
            200 => ApiResponse::success($data),
            201 => ApiResponse::created($data),
            default => new ApiResponse(['success' => true, 'message' => 'Success', 'data' => $data], $successStatus),
        };
    }
    if (is_array($result) || is_object($result)) {
        return new JsonResponse($result);
    }
    return new Response((string) $result);
}
```
NOTE: in `Router.php`, `Response` is aliased to Symfony's response and the Glueful response is imported `use Glueful\Http\Response as ApiResponse;`. Use `ApiResponse` for envelopes (this is what the Phase B branch already does). `ApiResponse::paginated(array $items, int $total, int $page, int $perPage, ?SerializationContext $context = null, string $message = 'Data retrieved successfully'): self` exists on `Glueful\Http\Response` and produces the flat pagination envelope with keys `current_page, per_page, total, total_pages, has_next_page, has_previous_page`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Http\Responses\CollectionResponse;
use Glueful\Http\Responses\PaginatedResponse;
use Glueful\Routing\Attributes\ResponseStatus;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class CrPostData implements ResponseData
{
    public function __construct(public int $id, public string $title)
    {
    }
}

final class CrCollectionController
{
    public function list(): CollectionResponse
    {
        return new CollectionResponse([new CrPostData(1, 'A'), new CrPostData(2, 'B')]);
    }

    #[ResponseStatus(201)]
    public function bulk(): CollectionResponse
    {
        return new CollectionResponse([new CrPostData(3, 'C')]);
    }

    public function paged(): PaginatedResponse
    {
        return new PaginatedResponse([new CrPostData(1, 'A')], page: 2, perPage: 10, total: 25);
    }
}

final class CollectionResponseNormalizationTest extends TestCase
{
    private Router $router;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();
        $context = new ApplicationContext(sys_get_temp_dir() . '/cr_test_' . uniqid());
        (new RouteCache($context))->clear();

        $this->container = new class implements ContainerInterface {
            /** @var array<string,mixed> */
            private array $services = [];
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }
                throw new class ("Service '$id' not found")
                    extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
            public function set(string $id, mixed $service): void
            {
                $this->services[$id] = $service;
            }
        };
        $this->container->set(ApplicationContext::class, $context);
        $this->container->set(CrCollectionController::class, new CrCollectionController());
        $this->router = new Router($this->container);
    }

    public function testCollectionResponseIsEnvelopedAsDataList(): void
    {
        $this->router->get('/posts', [CrCollectionController::class, 'list']);
        $response = $this->router->dispatch(Request::create('/posts', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['success']);
        self::assertSame(
            [['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']],
            $body['data']
        );
    }

    public function testCollectionResponseHonoursResponseStatus(): void
    {
        $this->router->post('/posts/bulk', [CrCollectionController::class, 'bulk']);
        $response = $this->router->dispatch(Request::create('/posts/bulk', 'POST'));

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame([['id' => 3, 'title' => 'C']], $body['data']);
    }

    public function testPaginatedResponseRendersFlatEnvelope(): void
    {
        $this->router->get('/paged', [CrCollectionController::class, 'paged']);
        $response = $this->router->dispatch(Request::create('/paged', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['success']);
        self::assertSame([['id' => 1, 'title' => 'A']], $body['data']);
        self::assertSame(2, $body['current_page']);
        self::assertSame(10, $body['per_page']);
        self::assertSame(25, $body['total']);
        self::assertSame(3, $body['total_pages']);     // ceil(25/10)
        self::assertTrue($body['has_next_page']);
        self::assertTrue($body['has_previous_page']);
    }
}
```

- [ ] **Step 2: Run → fail** (`vendor/bin/phpunit tests/Unit/Routing/CollectionResponseNormalizationTest.php`) — collection/paginated returns currently fall through to `new JsonResponse($result)`, so `data` won't be the serialized list and pagination keys are absent.

- [ ] **Step 3: Implement** — add a helper and two branches.

Add this private helper next to `normalizeResponse()`:
```php
/**
 * Serialize a list of response items: ResponseData DTOs become arrays via the
 * serializer; arrays/scalars pass through unchanged.
 *
 * @param  array<int, mixed> $items
 * @return array<int, mixed>
 */
private function serializeResponseItems(array $items): array
{
    $serializer = new \Glueful\Serialization\ResponseDataSerializer();
    return array_map(
        static fn (mixed $item): mixed => $item instanceof \Glueful\Http\Contracts\ResponseData
            ? $serializer->toArray($item)
            : $item,
        $items,
    );
}
```

Insert these two branches in `normalizeResponse()` **immediately before** the existing `ResponseData` branch (they must precede the generic `is_array || is_object` fallback; both classes are non-`ResponseData`, so without an explicit branch they would wrongly hit `new JsonResponse`):
```php
// A paginated list renders the flat Glueful pagination envelope (always 200,
// matching ApiResponse::paginated()).
if ($result instanceof \Glueful\Http\Responses\PaginatedResponse) {
    return ApiResponse::paginated(
        $this->serializeResponseItems($result->items),
        $result->total,
        $result->page,
        $result->perPage,
    );
}

// A plain collection renders {success, message, data: [...]} at the success status.
if ($result instanceof \Glueful\Http\Responses\CollectionResponse) {
    $data = $this->serializeResponseItems($result->items);
    return match ($successStatus) {
        200 => ApiResponse::success($data),
        201 => ApiResponse::created($data),
        default => new ApiResponse(['success' => true, 'message' => 'Success', 'data' => $data], $successStatus),
    };
}
```

- [ ] **Step 4: Run → pass.**

- [ ] **Step 5: Regression gate (critical — strictly additive)** — run the FULL `tests/Unit/Routing` suite: `vendor/bin/phpunit tests/Unit/Routing`. Every existing dispatch/normalize case (Response/string/array/object/ResponseData) must stay green. Then `composer phpcs src/Routing/Router.php` and `vendor/bin/phpstan analyse src/Routing/Router.php --level=6 --no-progress` (no NEW errors vs baseline).

- [ ] **Step 6: Commit**
```bash
git add src/Routing/Router.php tests/Unit/Routing/CollectionResponseNormalizationTest.php
git commit -m "Envelope CollectionResponse/PaginatedResponse returns in Router"
```

---

### Task A3: Generator — infer list schema from collection/pagination return types

**Files:**
- Modify: `src/Support/Documentation/RouteReflectionDocGenerator.php` (`buildResponseFromReturnType()`, ~lines 276–310; add helpers)
- Test: `tests/Unit/Support/Documentation/CollectionResponseSchemaTest.php`

**Context — the current `buildResponseFromReturnType()` (verify against HEAD):**
```php
private function buildResponseFromReturnType(mixed $handler): ?array
{
    $reflection = $this->handlerReflection($handler);
    if ($reflection === null) {
        return null;
    }
    $returnType = $reflection->getReturnType();
    if (!$returnType instanceof \ReflectionNamedType || $returnType->isBuiltin()) {
        return null;
    }
    $class = $returnType->getName();
    if (!class_exists($class) || !is_subclass_of($class, ResponseData::class)) {
        return null;
    }
    $status = 200;
    $statusAttributes = $reflection->getAttributes(ResponseStatus::class);
    if ($statusAttributes !== []) {
        $status = $statusAttributes[0]->newInstance()->status;
    }
    $envelope = $this->wrapInEnvelope(ClassSchemaReflector::toSchema($class));
    return [
        $status => [
            'description' => self::reasonPhrase($status),
            'content' => ['application/json' => ['schema' => $envelope]],
        ],
    ];
}
```
`wrapInEnvelope(array $schema): array` returns `{type:object, properties:{success:boolean, message:string, data:$schema}}`. `ClassSchemaReflector::toSchema(string $class): array` reflects public typed props (and already resolves `@var Foo[]`/`@var array<Foo>` docblocks for array properties by matching the short name against the declaring class namespace).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Http\Responses\CollectionResponse;
use Glueful\Http\Responses\PaginatedResponse;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Glueful\Support\Documentation\ClassSchemaReflector;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
// Alias for the same-namespace fixture below — used to prove that the docblock
// item resolver does NOT follow `use`-aliases (it falls back to a generic object).
use Glueful\Tests\Unit\Support\Documentation\CsPostData as AliasedPost;

final class CsPostData implements ResponseData
{
    public function __construct(public int $id, public string $title)
    {
    }
}

final class CsOtherData implements ResponseData
{
    public function __construct(public string $name)
    {
    }
}

final class CsCollectionController
{
    /** @return CollectionResponse<CsPostData> */
    public function list(): CollectionResponse
    {
        return new CollectionResponse([]);
    }

    /** @return PaginatedResponse<CsPostData> */
    public function paged(): PaginatedResponse
    {
        return new PaginatedResponse([], 1, 10, 0);
    }

    public function listNoDoc(): CollectionResponse
    {
        return new CollectionResponse([]);
    }

    /** @return CollectionResponse<CsPostData> */
    #[ApiResponse(200, CsOtherData::class, collection: true)]
    public function listOverride(): CollectionResponse
    {
        return new CollectionResponse([]);
    }

    /** @return CollectionResponse<\Glueful\Tests\Unit\Support\Documentation\CsPostData> */
    public function listFqcn(): CollectionResponse
    {
        return new CollectionResponse([]);
    }

    /** @return CollectionResponse<AliasedPost> */
    public function listAliased(): CollectionResponse
    {
        return new CollectionResponse([]);
    }
}

final class CollectionResponseSchemaTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/cs_' . uniqid());
        (new RouteCache($context))->clear();
        $container = new class ($context) implements ContainerInterface {
            /** @var array<string,mixed> */
            private array $services;
            public function __construct(ApplicationContext $context)
            {
                $this->services = [ApplicationContext::class => $context];
            }
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }
                throw new class ("Service '$id' not found")
                    extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };
        return new Router($container);
    }

    private function registry(): SecuritySchemeRegistry
    {
        return new SecuritySchemeRegistry([], []);
    }

    public function testCollectionReturnInfersEnvelopedArrayOfItems(): void
    {
        $router = $this->makeRouter();
        $router->get('/posts', [CsCollectionController::class, 'list']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/posts']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
                'data' => ['type' => 'array', 'items' => ClassSchemaReflector::toSchema(CsPostData::class)],
            ],
        ], $schema);
    }

    public function testPaginatedReturnInfersFlatPaginatedEnvelope(): void
    {
        $router = $this->makeRouter();
        $router->get('/paged', [CsCollectionController::class, 'paged']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/paged']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
                'data' => ['type' => 'array', 'items' => ClassSchemaReflector::toSchema(CsPostData::class)],
                'current_page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
                'total' => ['type' => 'integer'],
                'total_pages' => ['type' => 'integer'],
                'has_next_page' => ['type' => 'boolean'],
                'has_previous_page' => ['type' => 'boolean'],
            ],
        ], $schema);
    }

    public function testCollectionWithoutDocblockFallsBackToGenericObjectItems(): void
    {
        $router = $this->makeRouter();
        $router->get('/nodoc', [CsCollectionController::class, 'listNoDoc']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/nodoc']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(['type' => 'array', 'items' => ['type' => 'object']], $schema['properties']['data']);
    }

    public function testExplicitApiResponseOverridesInferredCollection(): void
    {
        $router = $this->makeRouter();
        $router->get('/override', [CsCollectionController::class, 'listOverride']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/override']['get']['responses']['200']['content']['application/json']['schema'];
        // The explicit #[ApiResponse] uses CsOtherData, not the inferred CsPostData.
        self::assertSame(
            ClassSchemaReflector::toSchema(CsOtherData::class),
            $schema['properties']['data']['items']
        );
    }

    public function testFullyQualifiedItemClassInDocblockResolvesPrecisely(): void
    {
        // A fully-qualified item type in the @return docblock is resolved exactly,
        // regardless of namespace — the supported precise path.
        $router = $this->makeRouter();
        $router->get('/fqcn', [CsCollectionController::class, 'listFqcn']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/fqcn']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(ClassSchemaReflector::toSchema(CsPostData::class), $schema['properties']['data']['items']);
    }

    public function testUseAliasedItemClassFallsBackToGenericObject(): void
    {
        // KNOWN LIMITATION (documented): the resolver does NOT follow `use`-aliases.
        // `@return CollectionResponse<AliasedPost>` (AliasedPost = CsPostData via `use ... as`)
        // resolves to neither a global class nor a same-namespace class, so it falls
        // back to a generic object schema. Write the FQCN or a same-namespace short
        // name for precise docs, or annotate with #[ApiResponse].
        $router = $this->makeRouter();
        $router->get('/aliased', [CsCollectionController::class, 'listAliased']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/aliased']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(['type' => 'array', 'items' => ['type' => 'object']], $schema['properties']['data']);
    }
}
```

- [ ] **Step 2: Run → fail** (`vendor/bin/phpunit tests/Unit/Support/Documentation/CollectionResponseSchemaTest.php`) — today `buildResponseFromReturnType()` returns null for non-`ResponseData` return types, so no inferred response exists for the collection/paginated returns.

- [ ] **Step 3: Implement** — extract a `returnStatus()` helper (DRY with the existing inline status read), then branch on the two container types in `buildResponseFromReturnType()`, and add three helpers.

Add `use Glueful\Http\Responses\CollectionResponse;` and `use Glueful\Http\Responses\PaginatedResponse;` at the top of the file.

Replace the body of `buildResponseFromReturnType()` after `$class = $returnType->getName();` with:
```php
        // Collection / pagination return types document a list of items; the
        // item class comes from the `@return Type<Item>` docblock when present.
        if ($class === CollectionResponse::class) {
            $status = $this->returnStatus($reflection);
            $schema = $this->wrapInEnvelope([
                'type' => 'array',
                'items' => $this->collectionItemSchema($reflection),
            ]);
            return [$status => [
                'description' => self::reasonPhrase($status),
                'content' => ['application/json' => ['schema' => $schema]],
            ]];
        }

        if ($class === PaginatedResponse::class) {
            // Pagination always renders at 200 (ApiResponse::paginated()).
            $schema = $this->wrapInPaginatedEnvelope($this->collectionItemSchema($reflection));
            return [200 => [
                'description' => self::reasonPhrase(200),
                'content' => ['application/json' => ['schema' => $schema]],
            ]];
        }

        if (!class_exists($class) || !is_subclass_of($class, ResponseData::class)) {
            return null;
        }

        $status = $this->returnStatus($reflection);
        $envelope = $this->wrapInEnvelope(ClassSchemaReflector::toSchema($class));

        return [$status => [
            'description' => self::reasonPhrase($status),
            'content' => ['application/json' => ['schema' => $envelope]],
        ]];
```

Add these helpers to the class:
```php
/**
 * The success status for a return-type-inferred response: the method's
 * #[ResponseStatus] value, or 200 when absent. The attribute constructor's
 * own validation is intentionally NOT caught — a malformed #[ResponseStatus]
 * must surface (consistent with the runtime fail-loud rule).
 */
private function returnStatus(\ReflectionMethod $reflection): int
{
    $statusAttributes = $reflection->getAttributes(ResponseStatus::class);
    if ($statusAttributes !== []) {
        return $statusAttributes[0]->newInstance()->status;
    }
    return 200;
}

/**
 * Wrap an item schema in Glueful's flat pagination envelope, mirroring the
 * runtime keys produced by {@see \Glueful\Http\Response::paginated()}.
 *
 * @param  array<string, mixed> $itemSchema
 * @return array<string, mixed>
 */
private function wrapInPaginatedEnvelope(array $itemSchema): array
{
    return [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'data' => ['type' => 'array', 'items' => $itemSchema],
            'current_page' => ['type' => 'integer'],
            'per_page' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'total_pages' => ['type' => 'integer'],
            'has_next_page' => ['type' => 'boolean'],
            'has_previous_page' => ['type' => 'boolean'],
        ],
    ];
}

/**
 * Resolve the item schema for a CollectionResponse/PaginatedResponse return.
 * The item class comes from a `@return Type<Item>` docblock; absent or
 * unresolvable, the items fall back to a generic object schema.
 *
 * @return array<string, mixed>
 */
private function collectionItemSchema(\ReflectionMethod $reflection): array
{
    $itemClass = $this->itemClassFromReturnDocblock($reflection);
    if ($itemClass !== null && class_exists($itemClass)) {
        return ClassSchemaReflector::toSchema($itemClass);
    }
    return ['type' => 'object'];
}

/**
 * Parse a `@return CollectionResponse<Item>` / `@return PaginatedResponse<Item>`
 * docblock and resolve the item class. A fully-qualified name is used as-is; a
 * short name is resolved against the method's declaring-class namespace (same
 * approach as ClassSchemaReflector for `@var` array item types). Returns null
 * when there is no such docblock or the name doesn't resolve. (Use-statement
 * aliases on the item type are not resolved — write the FQCN or a same-namespace
 * name, or document explicitly with #[ApiResponse].)
 */
private function itemClassFromReturnDocblock(\ReflectionMethod $reflection): ?string
{
    $doc = $reflection->getDocComment();
    if ($doc === false) {
        return null;
    }
    if (preg_match('/@return\s+\\\\?(?:CollectionResponse|PaginatedResponse)<\\\\?([\\\\\w]+)>/', $doc, $m) !== 1) {
        return null;
    }
    $name = $m[1];
    if (class_exists($name)) {
        return $name;
    }
    $fqcn = $reflection->getDeclaringClass()->getNamespaceName() . '\\' . $name;
    return class_exists($fqcn) ? $fqcn : null;
}
```

NOTE on overlay order: `buildOperation()` already applies `buildResponseFromReturnType()` BEFORE `mergeAttributeResponses()`, so an explicit `#[ApiResponse(200, ...)]` overrides the inferred collection response (covered by `testExplicitApiResponseOverridesInferredCollection`). Do not change the assembly order.

- [ ] **Step 4: Run → pass.**

- [ ] **Step 5: Regression gate** — full `tests/Unit/Support/Documentation` suite green (`vendor/bin/phpunit tests/Unit/Support/Documentation`); `composer phpcs src/Support/Documentation/RouteReflectionDocGenerator.php`; `vendor/bin/phpstan analyse src/Support/Documentation/RouteReflectionDocGenerator.php --level=6 --no-progress` (no NEW errors).

- [ ] **Step 6: Commit**
```bash
git add src/Support/Documentation/RouteReflectionDocGenerator.php tests/Unit/Support/Documentation/CollectionResponseSchemaTest.php
git commit -m "Infer reflect-mode list schema from CollectionResponse/PaginatedResponse returns"
```

---

## Group B — Auto-derived 422 validation-error response

### Task B1: Emit a `422` whenever a handler takes a `RequestData` param

**Files:**
- Modify: `src/Support/Documentation/RouteReflectionDocGenerator.php` (`buildOperation()` responses-assembly tail, ~lines 134–146; add a helper)
- Test: `tests/Unit/Support/Documentation/ValidationErrorResponseTest.php`

**Context — the runtime 422 body is produced by `Handler::renderValidationException()`:**
```php
new Response(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()], 422);
```
where `errors()` is `array<string, list<string>>` (field → messages). The auto-derived schema must match this exactly. The generator already has `findRequestDataParam(mixed $handler): ?\ReflectionParameter` (used by `buildRequestBodyFromRequestData()`); reuse it for detection.

**Context — the current responses-assembly tail of `buildOperation()` (verify against HEAD):**
```php
$defaults = $this->buildResponses($isSecured, $rateLimited);

$inferred = $this->buildResponseFromReturnType($route->getHandler());
if ($inferred !== null) {
    foreach ($inferred as $status => $response) {
        $defaults[(string) $status] = $response;
    }
}

$operation['responses'] = $this->mergeAttributeResponses($defaults, $route->getHandler());
```

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class VeCreatePostInput implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:200')] public string $title,
    ) {
    }
}

final class VeController
{
    public function store(VeCreatePostInput $input): void
    {
    }

    #[ApiResponse(422, description: 'Custom validation problem')]
    public function storeWithExplicit422(VeCreatePostInput $input): void
    {
    }

    public function index(): void
    {
    }
}

final class ValidationErrorResponseTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/ve_' . uniqid());
        (new RouteCache($context))->clear();
        $container = new class ($context) implements ContainerInterface {
            /** @var array<string,mixed> */
            private array $services;
            public function __construct(ApplicationContext $context)
            {
                $this->services = [ApplicationContext::class => $context];
            }
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }
                throw new class ("Service '$id' not found")
                    extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };
        return new Router($container);
    }

    private function registry(): SecuritySchemeRegistry
    {
        return new SecuritySchemeRegistry([], []);
    }

    public function testRequestDataParamEmits422(): void
    {
        $router = $this->makeRouter();
        $router->post('/posts', [VeController::class, 'store']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $resp = $paths['/posts']['post']['responses']['422'];
        self::assertSame('Validation failed', $resp['description']);
        $schema = $resp['content']['application/json']['schema'];
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['properties']['success']['example']);
        self::assertSame('string', $schema['properties']['message']['type']);
        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'string']],
            $schema['properties']['errors']['additionalProperties']
        );
        self::assertSame(['success', 'message', 'errors'], $schema['required']);
    }

    public function testExplicitApiResponse422Wins(): void
    {
        $router = $this->makeRouter();
        $router->post('/posts2', [VeController::class, 'storeWithExplicit422']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertSame('Custom validation problem', $paths['/posts2']['post']['responses']['422']['description']);
    }

    public function testNoRequestDataParamMeansNo422(): void
    {
        $router = $this->makeRouter();
        $router->get('/posts3', [VeController::class, 'index']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertArrayNotHasKey('422', $paths['/posts3']['get']['responses']);
    }
}
```

- [ ] **Step 2: Run → fail** (`vendor/bin/phpunit tests/Unit/Support/Documentation/ValidationErrorResponseTest.php`) — no `422` is emitted today.

- [ ] **Step 3: Implement** — inject the 422 into `$defaults` after the inferred overlay and before `mergeAttributeResponses()` (so an explicit `#[ApiResponse(422)]` still wins via the attribute overlay):
```php
        if ($this->findRequestDataParam($route->getHandler()) !== null) {
            $defaults['422'] = $this->buildValidationErrorResponse();
        }

        $operation['responses'] = $this->mergeAttributeResponses($defaults, $route->getHandler());
```

Add the helper (schema matches `Handler::renderValidationException()` exactly):
```php
/**
 * The auto-derived 422 response for a handler that takes a RequestData param.
 * Mirrors the runtime body of {@see \Glueful\Http\Exceptions\Handler::renderValidationException()}:
 * `{success:false, message:string, errors:{<field>:[string]}}`.
 *
 * @return array<string, mixed>
 */
private function buildValidationErrorResponse(): array
{
    return [
        'description' => 'Validation failed',
        'content' => ['application/json' => ['schema' => [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean', 'example' => false],
                'message' => ['type' => 'string'],
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'required' => ['success', 'message', 'errors'],
        ]]],
    ];
}
```

NOTE: `findRequestDataParam()` is a private method on this class — verify its exact name/signature against HEAD (it returns the `\ReflectionParameter` or null). If it is named differently, use the actual finder that `buildRequestBodyFromRequestData()` uses.

- [ ] **Step 4: Run → pass.**

- [ ] **Step 5: Regression gate** — full `tests/Unit/Support/Documentation` suite green; `composer phpcs` on the file; `vendor/bin/phpstan analyse src/Support/Documentation/RouteReflectionDocGenerator.php --level=6 --no-progress` (no NEW errors).

- [ ] **Step 6: Commit**
```bash
git add src/Support/Documentation/RouteReflectionDocGenerator.php tests/Unit/Support/Documentation/ValidationErrorResponseTest.php
git commit -m "Auto-derive the 422 response from a RequestData handler param"
```

---

## Group C — `scaffold:dto` command

### Task C1: `scaffold:dto` generator command

**Files:**
- Create: `src/Console/Commands/Scaffold/DtoCommand.php`
- Test: `tests/Unit/Console/Scaffold/DtoCommandTest.php` (place under `tests/Integration/Console/Scaffold/` instead if that matches where existing console-command tests live — check first)

**Pre-work (read before writing — the constructor/wiring must match reality):**
- Read `src/Console/Commands/Scaffold/RequestCommand.php` IN FULL — it is the closest analog (a DTO-like generator with app-vs-framework namespace resolution). Mirror its constructor signature, its `base_path()`-based path resolution, and its file-writing approach.
- Read `src/Console/BaseCommand.php` constructor + the `getContext()`, `success()`, `error()`, `warning()`, `confirm()` helpers.
- Confirm how an existing Scaffold command is constructed in its test (CommandTester) — match that exactly (e.g. `new RequestCommand($container, $context)` or `new RequestCommand($context)`).
- Registration is automatic: `ConsoleProvider` discovers any class in `src/Console/Commands/` carrying `#[AsCommand]`. No manual registration. (Production needs `php glueful commands:cache` to refresh `storage/cache/glueful_commands_manifest.php`, but tests instantiate the command directly so discovery is not exercised.)

- [ ] **Step 1: Write the failing test** — adapt the container/constructor wiring to whatever `RequestCommand` uses (the skeleton below assumes the `CreateExtensionTest` style `new DtoCommand($container, $context)`; change if RequestCommand differs).

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Scaffold;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Scaffold\DtoCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class DtoCommandTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-dto-' . uniqid('', true);
        @mkdir($this->base, 0777, true);
    }

    private function container(ApplicationContext $ctx): ContainerInterface
    {
        return new class ($ctx) implements ContainerInterface {
            public function __construct(private ApplicationContext $ctx)
            {
            }
            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->ctx;
                }
                throw new class ("no {$id}") extends \RuntimeException
                    implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }
        };
    }

    public function testScaffoldsRequestDtoByDefault(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');
        $tester = new CommandTester(new DtoCommand($this->container($ctx), $ctx));
        $status = $tester->execute(['name' => 'CreatePostData']);

        self::assertSame(0, $status, $tester->getDisplay());
        $file = $this->base . '/src/DTOs/CreatePostData.php';
        self::assertFileExists($file);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('namespace Glueful\\DTOs;', $content);
        self::assertStringContainsString('implements RequestData', $content);
        self::assertStringContainsString('#[Rule(', $content);
        self::assertStringContainsString('final class CreatePostData', $content);
    }

    public function testScaffoldsResponseDtoWithFlag(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');
        $tester = new CommandTester(new DtoCommand($this->container($ctx), $ctx));
        $status = $tester->execute(['name' => 'PostData', '--response' => true]);

        self::assertSame(0, $status, $tester->getDisplay());
        $content = (string) file_get_contents($this->base . '/src/DTOs/PostData.php');
        self::assertStringContainsString('implements ResponseData', $content);
        self::assertStringNotContainsString('#[Rule(', $content);
    }

    public function testRefusesExistingFileWithoutForce(): void
    {
        $ctx = new ApplicationContext($this->base, 'testing');
        (new CommandTester(new DtoCommand($this->container($ctx), $ctx)))->execute(['name' => 'PostData']);

        $tester = new CommandTester(new DtoCommand($this->container($ctx), $ctx));
        $tester->setInputs(['no']); // decline the overwrite confirmation
        $status = $tester->execute(['name' => 'PostData']);
        self::assertSame(1, $status);
    }
}
```

- [ ] **Step 2: Run → fail** (`vendor/bin/phpunit tests/Unit/Console/Scaffold/DtoCommandTest.php`) — `DtoCommand` not found.

- [ ] **Step 3: Implement** — model the constructor + file-writing on `RequestCommand`/`ControllerCommand`. The command logic + stubs (the valuable part) are below; adapt the constructor/`makeStorage` wiring to the existing scaffold convention.

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Scaffold;

use Glueful\Console\BaseCommand;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scaffold:dto',
    description: 'Scaffold a request or response DTO class'
)]
class DtoCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Scaffold a request or response DTO class')
            ->setHelp('Scaffolds a RequestData DTO (default) or a ResponseData DTO (--response).')
            ->addArgument('name', InputArgument::REQUIRED, 'The DTO class name (e.g. CreatePostData)')
            ->addOption('response', null, InputOption::VALUE_NONE, 'Scaffold a ResponseData DTO instead of a RequestData DTO')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing file without confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $name */
        $name = $input->getArgument('name');
        $name = trim(str_replace(['/', '\\'], '', $name));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            $this->error("Invalid DTO name: {$name}");
            return self::FAILURE;
        }

        $isResponse = (bool) $input->getOption('response');
        $force = (bool) $input->getOption('force');

        $hasApp = is_dir(base_path($this->getContext(), 'app'));
        $namespace = $hasApp ? 'App\\DTOs' : 'Glueful\\DTOs';
        $targetDir = $hasApp
            ? base_path($this->getContext(), 'app/DTOs')
            : base_path($this->getContext(), 'src/DTOs');

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $fileName = $name . '.php';
        $disk = $this->makeStorage($targetDir);
        if ($disk->fileExists($fileName) && !$force) {
            if (!$this->confirm("DTO {$fileName} already exists. Overwrite?", false)) {
                $this->warning('Scaffolding cancelled.');
                return self::FAILURE;
            }
        }

        $content = $isResponse
            ? $this->responseStub($namespace, $name)
            : $this->requestStub($namespace, $name);

        $disk->write($fileName, $content);

        $this->success(sprintf(
            '%s DTO created: %s/%s',
            $isResponse ? 'Response' : 'Request',
            $targetDir,
            $fileName
        ));
        return self::SUCCESS;
    }

    private function makeStorage(string $root): \League\Flysystem\FilesystemOperator
    {
        $sm = new StorageManager([
            'default' => 'scaffold',
            'disks' => [
                'scaffold' => ['driver' => 'local', 'root' => $root, 'visibility' => 'private'],
            ],
        ], new PathGuard());
        return $sm->disk('scaffold');
    }

    private function requestStub(string $namespace, string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Glueful\\Validation\\Contracts\\RequestData;
        use Glueful\\Validation\\Attributes\\Rule;

        final class {$name} implements RequestData
        {
            public function __construct(
                #[Rule('required|string|max:255')]
                public readonly string \$example,
            ) {
            }
        }

        PHP;
    }

    private function responseStub(string $namespace, string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Glueful\\Http\\Contracts\\ResponseData;

        final class {$name} implements ResponseData
        {
            public function __construct(
                public readonly string \$id,
                public readonly string \$example,
            ) {
            }
        }

        PHP;
    }
}
```

NOTE on heredoc indentation: the closing `PHP;` is indented to the method-body level; PHP's flexible heredoc strips that common leading whitespace from every line, so the generated file starts at column 0 with `<?php`. Verify the generated file's indentation by eye (the tests assert on substrings, but the output must be valid PHP — run `php -l` on a generated file during development if unsure).

NOTE on constructor: if `RequestCommand`'s constructor is `__construct(ContainerInterface $container, ApplicationContext $context)` (the `CreateCommand` shape), `DtoCommand` inherits it from `BaseCommand` and the test's `new DtoCommand($container, $context)` works. If the scaffold base differs, match it and update the test instantiation accordingly.

- [ ] **Step 4: Run → pass.**

- [ ] **Step 5: Gates** — `vendor/bin/phpunit tests/Unit/Console/Scaffold/DtoCommandTest.php`; `composer phpcs src/Console/Commands/Scaffold/DtoCommand.php`; `vendor/bin/phpstan analyse src/Console/Commands/Scaffold/DtoCommand.php --level=6 --no-progress` (no NEW errors). Also confirm the command is discoverable: `php glueful list | grep scaffold:dto` should show it (run from the framework root; if it doesn't appear, it's a discovery/manifest issue, not a code issue — note it).

- [ ] **Step 6: Commit**
```bash
git add src/Console/Commands/Scaffold/DtoCommand.php tests/Unit/Console/Scaffold/DtoCommandTest.php
git commit -m "Add scaffold:dto command for request/response DTO stubs"
```

---

## Group D — Wrap-up

### Task D1: CHANGELOG + docs + full suite

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `docs/RESPONSE_DTOS.md`
- Modify: `docs/REQUEST_DTOS.md`

- [ ] **Step 1: CHANGELOG** — under `## [Unreleased] → ### Added`, add entries:
  - **Collection & pagination response DTOs:** returning a `Glueful\Http\Responses\CollectionResponse` envelopes the serialized items as `{success, message, data: [...]}` (honoring `#[ResponseStatus]`); returning a `PaginatedResponse` renders Glueful's flat pagination envelope (`current_page/per_page/total/total_pages/has_next_page/has_previous_page`) via `Response::paginated()`. In reflect mode the list item schema is inferred from a `@return CollectionResponse<Item>` / `@return PaginatedResponse<Item>` docblock (generic object items otherwise; `#[ApiResponse]` still overrides).
  - **Auto-derived 422:** in reflect mode, any handler taking a `RequestData` parameter now documents a `422` validation-error response matching the runtime body (`{success:false, message, errors:{field:[string]}}`); an explicit `#[ApiResponse(422, ...)]` overrides it.
  - **`scaffold:dto` command:** scaffolds a `RequestData` DTO (default) or a `ResponseData` DTO (`--response`).
  - State the boundary: collection/pagination items run through `ResponseDataSerializer` (or pass through if already arrays); the Resource auto-normalizer remains out of scope.

- [ ] **Step 2: `docs/RESPONSE_DTOS.md`** — add a "Collections & pagination" section: worked examples returning `CollectionResponse` and `PaginatedResponse` from a controller, the two resulting envelopes (plain list vs flat paginated), the `@return Type<Item>` docblock for precise OpenAPI item schemas (and the generic-object fallback + `#[ApiResponse]` override), and that pagination always renders at 200. Verify class names/namespaces against the source files created in Tasks A1–A3 before writing them.

- [ ] **Step 3: `docs/REQUEST_DTOS.md`** — add a short note that, in reflect mode, a handler taking a `RequestData` param automatically gets a documented `422` validation-error response (no annotation needed; override with `#[ApiResponse(422, ...)]`). Mention `scaffold:dto` as the quick way to create one.

- [ ] **Step 4: Full suite gate** — `vendor/bin/phpunit tests/Unit` must be fully green (this phase touched core `Router.php` and the generator). `composer phpcs` clean. Quote the totals.

- [ ] **Step 5: Commit**
```bash
git add CHANGELOG.md docs/RESPONSE_DTOS.md docs/REQUEST_DTOS.md
git commit -m "Document Phase C: collections/pagination, auto-422, scaffold:dto"
```

---

## Self-Review

**Spec coverage (proposal §7 Phase C + deferred B3 + open question #1):**
- Deferred B3 (collections/pagination typed returns) → Tasks A1–A3 (runtime value objects + router envelopes + generator schema inference).
- Phase C "auto-derive 422 from a request DTO's rules" → Task B1.
- Phase C "status selection (201) via #[ResponseStatus]" → already delivered in Phase B; explicitly noted as not re-done.
- Phase C "docs/guides; make: scaffolding polish" → Task C1 (`scaffold:dto`) + Task D1 (docs).
- Open question #2 (Resource auto-normalizer) → explicitly out of scope.

**Strictly additive:** the new router branches fire only for `CollectionResponse`/`PaginatedResponse` (neither implements `ResponseData`; placed before the generic array/object fallback so they don't change any existing path). The generator additions only fire for those two return types / for handlers with a `RequestData` param; the response-assembly order is unchanged, so `#[ApiResponse]` still overrides. Each task names a full-suite regression gate.

**Type consistency:** `CollectionResponse(array $items)`; `PaginatedResponse(array $items, int $page, int $perPage, int $total)` — same names used in the router branches (`$result->items/total/page/perPage`), the `Response::paginated($items, $total, $page, $perPage)` argument order, the generator (`@return ...<Item>`), and the tests. The paginated envelope keys (`current_page/per_page/total/total_pages/has_next_page/has_previous_page`) match `Response::paginated()`'s output in both the runtime test (A2) and the schema (A3). The 422 schema matches `Handler::renderValidationException()` in both the helper and the test (B1).

**Fail-loud pagination metadata:** `PaginatedResponse` validates `page >= 1`, `perPage >= 1`, `total >= 0` in its constructor (Task A1) — `perPage: 0` would otherwise hit a division-by-zero in `Response::paginated()`'s `ceil($total / $perPage)`. Three rejection tests cover this; the failure surfaces at the construction site, not deep in the router.

**Docblock item-resolution scope is proven, not just claimed:** `itemClassFromReturnDocblock()` resolves FQCNs and same-namespace short names only — NOT `use`-aliases. Task A3 includes a FQCN test (`testFullyQualifiedItemClassInDocblockResolvesPrecisely`, precise) AND an alias-fallback test (`testUseAliasedItemClassFallsBackToGenericObject`, generic object), so the limitation is locked in by a test rather than silently surprising a `use App\DTO\PostData;` + `@return CollectionResponse<PostData>` caller. The fix for that case is documented inline: write the FQCN, use a same-namespace name, or annotate with `#[ApiResponse]`.

**Residual verification points (flagged inline for implementers):** the exact name/signature of `findRequestDataParam()` (Task B1); the scaffold-command constructor convention and console-test directory (Task C1); current line numbers in `Router.php` and `RouteReflectionDocGenerator.php`.
