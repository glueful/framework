# Types-First DTO I/O — Phase C2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Close the remaining typed-response gaps — fix the vestigial `200` documented alongside a non-200 inferred success, and auto-normalize returned API Resources (`JsonResource`/`ResourceCollection`/`PaginatedResourceResponse`) through their own `->toResponse()` so they no longer need a manual call.

**Architecture:** Two narrow, strictly-additive changes delivered as **two separate commits**. (1) OpenAPI response cleanup: in `RouteReflectionDocGenerator::buildOperation()`, drop the seeded description-only `200` when the inferred success status is non-200. (2) Resource auto-normalizer: a single branch in `Router::normalizeResponse()` ahead of the generic object/array fallback that calls `$result->toResponse($successStatus)` for the known framework Resource types only — the Resource keeps full ownership of its envelope (no `ResponseData` envelope is forced onto it).

**Tech Stack:** PHP 8.3+, Glueful framework (NOT Laravel), PHPUnit, PSR-12. Namespace `Glueful\` → `src/`.

**Scope guardrails (from the request):**
- Only normalize the **known framework Resource types**, never arbitrary objects that happen to have a `toResponse()` method.
- The Resource branch goes **before** the generic object/array fallback in `Router::normalizeResponse()`.
- Preserve the existing Symfony `Response` passthrough (a Resource already turned into a `Response` is not re-processed).
- The Resource **owns its output semantics** — call its `toResponse()` and return it; do not wrap it in the `{success, message, data}` ResponseData envelope.
- Regression tests must prove plain objects/arrays still become a plain `JsonResponse` exactly as before.
- **OpenAPI for Resources stays manual via `#[ApiResponse]`.** Resource bodies come from a runtime `toArray()` (conditional fields, `whenLoaded()`, etc.) with **no static, reflectable schema** — confirmed: there is no `schema()` method or typed property map on any Resource class. So this phase adds runtime auto-normalization + a docs boundary, and changes NOTHING in the generator for Resources (a Resource return type already yields no inference).

**Out of scope:** deriving OpenAPI response schemas from Resources (not reflectable); any change to Resource transform/envelope internals.

---

## File Structure

**Modify:**
- `src/Support/Documentation/RouteReflectionDocGenerator.php` — `buildOperation()`: drop the vestigial `200` when the inferred success is non-200. (Task 1)
- `src/Routing/Router.php` — `normalizeResponse()`: add one Resource auto-normalize branch before the generic fallback. (Task 2)
- `docs/RESPONSE_DTOS.md` — add the "DTOs vs Resources" boundary section + the Resource auto-normalization note. (Task 2)
- `CHANGELOG.md` — `[Unreleased] → Added/Fixed` (one line per task). (Tasks 1 & 2)

**Create:**
- `tests/Unit/Support/Documentation/InferredSuccessStatusTest.php` — vestigial-200 regression tests. (Task 1)
- `tests/Unit/Routing/ResourceNormalizationTest.php` — Resource auto-normalize + plain-object regression tests. (Task 2)

**Conventions for every task:**
- Work on branch `dev` directly (no feature branch). Commits carry **NO `Co-Authored-By` trailer**. Never stage `CLAUDE.md`.
- TDD: write the failing test, watch it fail, implement, watch it pass.
- Per-task gates before commit: the task's targeted `phpunit` + the named surrounding suite green; `vendor/bin/phpcs <changed file>` clean; `vendor/bin/phpstan analyse <changed file> --level=6 --no-progress` reports no NEW errors. The PHPStan 2.x upgrade banner is a benign nag — ignore it.
- Line numbers below were captured from HEAD at planning time; **verify against the current file** before editing.

---

## Task 1: OpenAPI response cleanup — drop the vestigial `200` on a non-200 inferred success

**Why:** `buildResponses()` always seeds `'200' => ['description' => 'Successful response']`. When a handler's success is inferred at a non-200 status (e.g. `#[ResponseStatus(201)]` on a `ResponseData`/`CollectionResponse` return), the operation ends up documenting BOTH a bare description-only `200` AND the full `201`. The bare `200` is vestigial (there is no 200 response at runtime). This affects all inferred paths uniformly. (`PaginatedResponse` always infers at 200, so it is unaffected.)

**Files:**
- Modify: `src/Support/Documentation/RouteReflectionDocGenerator.php` — `buildOperation()` (the inferred-overlay loop, ~lines 141–146)
- Test: `tests/Unit/Support/Documentation/InferredSuccessStatusTest.php`

**Context — current `buildOperation()` response assembly (verify against HEAD):**
```php
$defaults = $this->buildResponses($isSecured, $rateLimited);   // seeds '200' (description-only), +401/403/429

$inferred = $this->buildResponseFromReturnType($route->getHandler());
if ($inferred !== null) {
    foreach ($inferred as $status => $response) {
        $defaults[(string) $status] = $response;
    }
}

// ... 422 injection ...
$operation['responses'] = $this->mergeAttributeResponses($defaults, $route->getHandler());
```
`buildResponses()` (~line 945) seeds `$responses = ['200' => ['description' => 'Successful response']]`. `$inferred` always has exactly ONE entry (one success status). `mergeAttributeResponses()` applies `#[ApiResponse]` LAST.

- [x] **Step 1: Write the failing test**

`tests/Unit/Support/Documentation/InferredSuccessStatusTest.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Http\Responses\CollectionResponse;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\ResponseStatus;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class IssPostData implements ResponseData
{
    public function __construct(public int $id, public string $title)
    {
    }
}

final class IssOtherData implements ResponseData
{
    public function __construct(public string $name)
    {
    }
}

final class IssController
{
    public function show(): IssPostData
    {
        return new IssPostData(1, 'A');
    }

    #[ResponseStatus(201)]
    public function create(): IssPostData
    {
        return new IssPostData(1, 'A');
    }

    /** @return CollectionResponse<IssPostData> */
    #[ResponseStatus(201)]
    public function bulk(): CollectionResponse
    {
        return new CollectionResponse([]);
    }

    #[ResponseStatus(201)]
    #[ApiResponse(200, IssOtherData::class)]
    public function createWith200(): IssPostData
    {
        return new IssPostData(1, 'A');
    }
}

final class InferredSuccessStatusTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/iss_' . uniqid());
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

    /** @return array<string, mixed> */
    private function responsesFor(string $path, string $verb, string $method): array
    {
        $router = $this->makeRouter();
        $router->{$verb}($path, [IssController::class, $method]);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);
        return $paths[$path][$verb]['responses'];
    }

    public function testNon200InferredSuccessDropsVestigial200(): void
    {
        $responses = $this->responsesFor('/posts', 'post', 'create');
        self::assertArrayHasKey('201', $responses);
        self::assertArrayNotHasKey('200', $responses); // the vestigial bare 200 is gone
    }

    public function test200InferredSuccessKeepsFull200(): void
    {
        $responses = $this->responsesFor('/posts/{id}', 'get', 'show');
        self::assertArrayHasKey('200', $responses);
        // The full inferred schema replaced the bare description-only seed.
        self::assertArrayHasKey('content', $responses['200']);
    }

    public function testNon200CollectionDropsVestigial200(): void
    {
        $responses = $this->responsesFor('/posts/bulk', 'post', 'bulk');
        self::assertArrayHasKey('201', $responses);
        self::assertArrayNotHasKey('200', $responses);
    }

    public function testExplicitApiResponse200CoexistsWithNon200Inference(): void
    {
        $responses = $this->responsesFor('/posts/mixed', 'post', 'createWith200');
        // Explicit #[ApiResponse(200)] is re-added by the attribute overlay; 201 from inference.
        self::assertArrayHasKey('201', $responses);
        self::assertArrayHasKey('200', $responses);
        self::assertArrayHasKey('content', $responses['200']);
    }
}
```

- [x] **Step 2: Run → fail** (`vendor/bin/phpunit tests/Unit/Support/Documentation/InferredSuccessStatusTest.php`) — `testNon200InferredSuccessDropsVestigial200` and `testNon200CollectionDropsVestigial200` fail because the bare `200` is still present alongside `201`.

- [x] **Step 3: Implement** — in `buildOperation()`, drop the seeded `200` when the inferred success status is not `200`:
```php
        $inferred = $this->buildResponseFromReturnType($route->getHandler());
        if ($inferred !== null) {
            foreach ($inferred as $status => $response) {
                // A non-200 inferred success means there is no bare 200 at runtime —
                // drop the vestigial description-only 200 seeded by buildResponses().
                // An explicit #[ApiResponse(200)] is re-added later by the attribute overlay.
                if ((string) $status !== '200') {
                    unset($defaults['200']);
                }
                $defaults[(string) $status] = $response;
            }
        }
```
(Leave the `422` injection and `mergeAttributeResponses()` call exactly as they are — the attribute overlay re-adds an explicit `#[ApiResponse(200)]` after this.)

- [x] **Step 4: Run → pass.**

- [x] **Step 5: Regression gate** — run the FULL `tests/Unit/Support/Documentation` suite: `vendor/bin/phpunit tests/Unit/Support/Documentation`. An existing test for a `#[ResponseStatus(201)]`-returning handler may previously have tolerated a stray `200`; if any test now fails because it asserted the vestigial `200` was present, that assertion was wrong — update it to reflect the corrected behavior (no bare 200) and report which test + why. Quote the final count. Then `vendor/bin/phpcs src/Support/Documentation/RouteReflectionDocGenerator.php` (clean) and `vendor/bin/phpstan analyse src/Support/Documentation/RouteReflectionDocGenerator.php --level=6 --no-progress` (no NEW errors).

- [x] **Step 6: CHANGELOG + commit** — add under `## [Unreleased] → ### Fixed` (create the heading if absent): "Reflect-mode docs no longer emit a vestigial description-only `200` response alongside a non-200 inferred success (e.g. a `#[ResponseStatus(201)]` handler now documents only `201`)." Then:
```bash
git add src/Support/Documentation/RouteReflectionDocGenerator.php tests/Unit/Support/Documentation/InferredSuccessStatusTest.php CHANGELOG.md
git commit -m "Drop vestigial 200 when the inferred success status is non-200"
```

---

## Task 2: Resource auto-normalizer

**Why:** Glueful now auto-envelopes a returned `ResponseData`/`CollectionResponse`/`PaginatedResponse`, but a returned `JsonResource`/`ResourceCollection` still needs a manual `->toResponse()` — otherwise it falls through to `new JsonResponse($result)` and loses its `{success, data, …}` envelope. Auto-normalize the known Resource types so the model is even. The Resource keeps full ownership of its envelope; the router just calls its `toResponse()`.

**Files:**
- Modify: `src/Routing/Router.php` — `normalizeResponse()` (add one branch before the generic `is_array || is_object` fallback, ~line 1127)
- Modify: `docs/RESPONSE_DTOS.md` — boundary section
- Test: `tests/Unit/Routing/ResourceNormalizationTest.php`

**Verified Resource facts (from `src/Http/Resources/`):**
- `Glueful\Http\Resources\JsonResource` — `class JsonResource implements JsonSerializable, ArrayAccess`; `public function toResponse(int $status = 200, array $headers = []): Response`; static `make(mixed $resource): static`. `ModelResource extends JsonResource`.
- `Glueful\Http\Resources\ResourceCollection` — `class ResourceCollection implements Countable, IteratorAggregate, JsonSerializable`; `public function toResponse(int $status = 200, array $headers = []): Response`; static `make(iterable $resource): static`. `AnonymousResourceCollection extends ResourceCollection`.
- `Glueful\Http\Resources\PaginatedResourceResponse` — standalone (`class PaginatedResourceResponse`); `public function toResponse(int $status = 200, array $headers = []): Response`.
- There is **no common base/interface** — check the three types explicitly. The two subclasses are covered by `instanceof` of their parents.
- `JsonResource::make(['name' => 'John'])->toResponse()` → `Glueful\Http\Response` with body `{"success":true,"data":{"name":"John"}}`. `ResourceCollection::make([['name'=>'A'],['name'=>'B']])->toResponse()` → `{"success":true,"data":[{"name":"A"},{"name":"B"}]}`.
- A Resource already turned into a `Response` (controller called `->toResponse()` itself) is a `Glueful\Http\Response` (extends Symfony `Response`) and is caught by the FIRST passthrough branch — so no double-wrapping.

**Context — current tail of `normalizeResponse()` (verify against HEAD; the new branch goes right before `is_array || is_object`):**
```php
        // ... PaginatedResponse / CollectionResponse / ResponseData branches ...

        if (is_array($result) || is_object($result)) {
            return new JsonResponse($result);
        }

        return new Response((string) $result);
    }
```

- [x] **Step 1: Write the failing test**

`tests/Unit/Routing/ResourceNormalizationTest.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Resources\JsonResource;
use Glueful\Http\Resources\PaginatedResourceResponse;
use Glueful\Http\Resources\ResourceCollection;
use Glueful\Routing\Attributes\ResponseStatus;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ResNormController
{
    public function one(): JsonResource
    {
        return JsonResource::make(['name' => 'John']);
    }

    #[ResponseStatus(201)]
    public function created(): JsonResource
    {
        return JsonResource::make(['name' => 'Jane']);
    }

    public function many(): ResourceCollection
    {
        return ResourceCollection::make([['name' => 'A'], ['name' => 'B']]);
    }

    public function paged(): PaginatedResourceResponse
    {
        return PaginatedResourceResponse::make([['name' => 'A']])
            ->setPage(2)
            ->setPerPage(10)
            ->setTotal(25);
    }

    public function plainObject(): \stdClass
    {
        $o = new \stdClass();
        $o->x = 1;
        return $o;
    }

    /** @return array<string,int> */
    public function plainArray(): array
    {
        return ['x' => 1];
    }
}

final class ResourceNormalizationTest extends TestCase
{
    private Router $router;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();
        $context = new ApplicationContext(sys_get_temp_dir() . '/resnorm_' . uniqid());
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
        $this->container->set(ResNormController::class, new ResNormController());
        $this->router = new Router($this->container);
    }

    public function testJsonResourceIsNormalizedThroughToResponse(): void
    {
        $this->router->get('/one', [ResNormController::class, 'one']);
        $response = $this->router->dispatch(Request::create('/one', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        // The Resource's OWN envelope, not a raw {name:John}.
        self::assertTrue($body['success']);
        self::assertSame(['name' => 'John'], $body['data']);
    }

    public function testResponseStatusIsThreadedToResourceToResponse(): void
    {
        $this->router->post('/created', [ResNormController::class, 'created']);
        $response = $this->router->dispatch(Request::create('/created', 'POST'));

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(['name' => 'Jane'], $body['data']);
    }

    public function testResourceCollectionIsNormalizedThroughToResponse(): void
    {
        $this->router->get('/many', [ResNormController::class, 'many']);
        $response = $this->router->dispatch(Request::create('/many', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['success']);
        self::assertSame([['name' => 'A'], ['name' => 'B']], $body['data']);
    }

    public function testPaginatedResourceResponseIsNormalizedThroughToResponse(): void
    {
        // PaginatedResourceResponse is a SEPARATE class (not a ResourceCollection
        // subclass) — exercise its own branch arm. Bounds: total_pages=ceil(25/10)=3,
        // has_next_page (2<3)=true, has_previous_page (2>1)=true.
        $this->router->get('/paged', [ResNormController::class, 'paged']);
        $response = $this->router->dispatch(Request::create('/paged', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['success']);
        self::assertSame([['name' => 'A']], $body['data']);
        self::assertSame(2, $body['current_page']);
        self::assertSame(10, $body['per_page']);
        self::assertSame(25, $body['total']);
        self::assertSame(3, $body['total_pages']);
        self::assertTrue($body['has_next_page']);
        self::assertTrue($body['has_previous_page']);
    }

    public function testPlainObjectStillBecomesRawJsonResponse(): void
    {
        // REGRESSION GUARD: a plain object must NOT be captured by the Resource
        // branch — it stays a raw JsonResponse exactly as before (no success wrapper).
        $this->router->get('/obj', [ResNormController::class, 'plainObject']);
        $response = $this->router->dispatch(Request::create('/obj', 'GET'));

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(['x' => 1], $body);
        self::assertArrayNotHasKey('success', $body);
    }

    public function testPlainArrayStillBecomesRawJsonResponse(): void
    {
        $this->router->get('/arr', [ResNormController::class, 'plainArray']);
        $response = $this->router->dispatch(Request::create('/arr', 'GET'));

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(['x' => 1], $body);
        self::assertArrayNotHasKey('success', $body);
    }
}
```

- [x] **Step 2: Run → fail** (`vendor/bin/phpunit tests/Unit/Routing/ResourceNormalizationTest.php`) — the JsonResource/ResourceCollection cases fail: they currently fall through to `new JsonResponse($result)`, which serializes via `JsonSerializable::jsonSerialize()` (no `success` wrapper / wrong shape). The plain-object/array cases pass already (they document the unchanged baseline).

- [x] **Step 3: Implement** — add ONE branch in `normalizeResponse()` immediately before the `is_array || is_object` fallback. All three Resource types expose `toResponse(int $status = 200, …): Response`, so one combined `instanceof` branch suffices; passing `$successStatus` lets `#[ResponseStatus]` work uniformly:
```php
        // Auto-normalize a returned framework API Resource through its OWN toResponse().
        // The Resource owns its envelope/semantics — do NOT re-wrap it in the
        // ResponseData envelope. Must precede the generic object/array fallback (a
        // ResourceCollection is an iterable object that would otherwise be JSON-encoded
        // raw). Only the known framework Resource types are matched — never an arbitrary
        // object that merely has a toResponse() method. A Resource the controller already
        // turned into a Response is caught by the Response passthrough above.
        if (
            $result instanceof \Glueful\Http\Resources\JsonResource
            || $result instanceof \Glueful\Http\Resources\ResourceCollection
            || $result instanceof \Glueful\Http\Resources\PaginatedResourceResponse
        ) {
            return $result->toResponse($successStatus);
        }
```

- [x] **Step 4: Run → pass.**

- [x] **Step 5: Regression gate (critical — strictly additive)** — run the FULL `tests/Unit/Routing` suite: `vendor/bin/phpunit tests/Unit/Routing`. Every existing dispatch/normalize case (Response passthrough, string, array→JsonResponse, object→JsonResponse, ResponseData/Collection/Paginated) MUST stay green; the two plain-object/array tests prove non-Resources are unchanged. Quote the count. Then `vendor/bin/phpcs src/Routing/Router.php` (clean) and `vendor/bin/phpstan analyse src/Routing/Router.php --level=6 --no-progress` (no NEW errors — the combined `instanceof` narrows `$result` to a union where all three types declare `toResponse()`, so the call type-checks).

- [x] **Step 6: Docs** — in `docs/RESPONSE_DTOS.md`, add a "Typed DTOs vs Resources" boundary section:
  - Returning a `JsonResource`/`ResourceCollection`/`PaginatedResourceResponse` is now auto-normalized through its own `toResponse()` — no manual `->toResponse()` call needed (a `#[ResponseStatus(n)]` is threaded as the status). The Resource keeps its own envelope; the router does not apply the `ResponseData` envelope to it.
  - **When to use which:** reach for a `ResponseData` DTO (and the `CollectionResponse`/`PaginatedResponse` wrappers) for **simple, statically-typed** outputs where you want one class to drive both the runtime payload and the OpenAPI schema. Reach for a **Resource** for **transformation-heavy** responses (conditional fields, `whenLoaded()`, relationship/pivot shaping). Both coexist; neither replaces the other.
  - **OpenAPI note:** Resource bodies are a runtime `toArray()` transform with no static schema, so reflect mode does **not** infer a schema from a Resource return type — document Resource-returning endpoints explicitly with `#[ApiResponse(...)]`. (Typed DTO returns remain auto-documented.)
  Verify class names/namespaces against `src/Http/Resources/*.php` before writing them.

- [x] **Step 7: CHANGELOG + commit** — add under `## [Unreleased] → ### Added`: "Returning a `JsonResource`, `ResourceCollection`, or `PaginatedResourceResponse` from a controller is now auto-normalized through its own `->toResponse()` (the manual call is no longer required; `#[ResponseStatus]` is threaded as the status). Resource bodies are not reflectable, so document Resource-returning endpoints with `#[ApiResponse]`." Then:
```bash
git add src/Routing/Router.php docs/RESPONSE_DTOS.md CHANGELOG.md tests/Unit/Routing/ResourceNormalizationTest.php
git commit -m "Auto-normalize returned API Resources through their own toResponse()"
```

---

## Self-Review

**Spec coverage (the request):**
- "Fix vestigial default 200 when inferred success status is non-200" → Task 1 (covers all inferred paths: single `ResponseData` and `CollectionResponse`; `PaginatedResponse` is always-200 so unaffected).
- "Auto-normalize returned JsonResource / ResourceCollection" → Task 2 (plus `PaginatedResourceResponse` — same `toResponse()` contract). All three branch arms are test-covered: `JsonResource`, `ResourceCollection`, and `PaginatedResourceResponse` (a separate class, not a `ResourceCollection` subclass, so its arm is exercised explicitly via `make(...)->setPage()->setPerPage()->setTotal()` with assertions on `success`/`data`/pagination metadata).
- "Only normalize known framework resource types" → the branch matches exactly the three framework classes (subclasses via `instanceof`), never an arbitrary `toResponse()` object.
- "Branch before generic object/array fallback" → inserted immediately before `is_array || is_object`.
- "Preserve Symfony Response passthrough" → unchanged first branch; a Resource already converted to a `Response` is caught there.
- "Resource owns its output semantics" → the branch returns `$result->toResponse($successStatus)` with no re-wrapping.
- "Regression tests prove plain objects still become JsonResponse" → `testPlainObjectStillBecomesRawJsonResponse` + `testPlainArrayStillBecomesRawJsonResponse`.
- "OpenAPI only if reflectable; else document runtime + manual `#[ApiResponse]`" → confirmed not reflectable; no generator change; the docs boundary states Resources stay manual.
- "Two separate commits" → Task 1 commit + Task 2 commit.
- Naming → `types-first-response-resources-phase-c2.md`.

**Strictly additive:** Task 1 only removes a seeded entry when a non-200 success is inferred (no inference / 200 inference / no-DTO handlers are unchanged; explicit `#[ApiResponse(200)]` is re-added by the unchanged attribute overlay). Task 2's branch fires only for the three Resource types and sits before the generic fallback, so every existing path (incl. plain object/array → `JsonResponse`) is byte-identical; both tasks name a full-suite regression gate.

**Type consistency:** `toResponse(int $status = 200, array $headers = []): Response` is the exact shared signature used for all three Resource types; the branch passes `$successStatus` (the same variable already threaded into `normalizeResponse()` for the ResponseData/Collection branches). `JsonResource::make()`/`ResourceCollection::make()` are the construction calls used in the test and match the real static factories.

**Residual verification points (flagged inline):** current line numbers in both files; whether any existing `tests/Unit/Support/Documentation` test asserted the now-removed vestigial `200` (update it if so, Task 1 Step 5).
