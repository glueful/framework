# Types-First Response DTOs — Phase B Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a controller method *return* a typed `ResponseData` DTO (`function show(...): PostData`); the dispatcher envelopes it into Glueful's standard `{success, message, data}` response with the right status, and the reflect OpenAPI generator infers that route's response-body schema from the return type — no `#[ApiResponse]` for the success case.

**Architecture:** A `ResponseData` marker interface (pure data, no transport knowledge) + a `#[ResponseStatus(n)]` method attribute (default 200) for non-200 success. A `ResponseDataSerializer` turns a DTO's public typed properties into an array (recursing nested `ResponseData`/lists, enums→backing value/name, `DateTimeInterface`→ISO-8601) — the value counterpart to `ClassSchemaReflector` (which produces the schema), so payload and docs match. `Router::dispatch()` reads the handler's `#[ResponseStatus]` once and threads it to `normalizeResponse()`, which gains **one branch**: a returned `ResponseData` is enveloped via the status→factory (`Response::success` for 200, `Response::created` for 201, and a direct enveloped constructor for other 2xx — preferred over mutating a `success()` response so the envelope is built with its final status in one place). `#[ResponseStatus]` rejects non-2xx values at construction (fail loud), and `ResponseData` lives under `Glueful\Http\Contracts` (HTTP/output semantics, not validation). The reflect generator reflects the return type → response schema (reusing the `#[ApiResponse]` envelope builder). **Strictly additive:** a return that is not a `ResponseData` (e.g. `Response`, `JsonResource`, array) takes the existing `normalizeResponse` path unchanged.

**Tech Stack:** PHP 8.3, `Glueful\Routing\Router` (`dispatch`/`normalizeResponse`/`executeWithMiddleware`), `Glueful\Http\Response` (`success`/`created`/ctor), `Glueful\Support\Documentation\{ClassSchemaReflector,RouteReflectionDocGenerator}` (existing). Source of truth: [`../proposals/types-first-dto-io.md`](../proposals/types-first-dto-io.md) §2.2, §3.2, §7 (Phase B). Phase A (request DTOs) is already shipped (`12b4730..75cbb86`).

**Scope (this plan):** response DTOs only — `ResponseData` + `#[ResponseStatus]` + serializer + the `normalizeResponse` branch + return-type schema inference. **NOT here:** routing `JsonResource`/`ResourceCollection` returns through `toResponse()` automatically (Resources stay manual — a separate enhancement); collections/pagination as a first-class return type; request DTOs (Phase A, done).

---

## Conventions (verified seams — reuse verbatim)
- **Dispatch + normalize seam** (`src/Routing/Router.php` — line numbers verified against HEAD *after* Phase A; anchor by method name as they drift): `dispatch()` has `$route->getHandler()` (~651/660). It calls the handler via an `$invoker` (returns the RAW result) and normalizes at TWO sites it owns: `normalizeResponse($invoker())` (~666, no-middleware path) and inside `executeWithMiddleware(...)` → `normalizeResponse($next($request))` (~990, middleware path). `normalizeResponse(mixed $result): Response` (~1042 — Phase A's `RequestData` branch + accessor pushed it down ~24 lines from the pre-Phase-A ~1018) currently: `Response`→passthrough; string→`new Response`; array/object→`new JsonResponse(...)` (~1053). The new `ResponseData` branch goes BEFORE the array/object fallback.
- **Response factories** (`src/Http/Response.php` — a Glueful class extending `Symfony\Component\HttpFoundation\JsonResponse`, with its own enveloping constructor): `success(mixed $data, ...): self` builds `new self(['success'=>true,'message'=>...,'data'=>$data ?? []])` (status 200); `created(...)` → same envelope + 201 (`HTTP_CREATED`); `__construct(mixed $data = null, int $status = 200, array $headers = [], bool $json = false)`. `setStatusCode()` exists (inherited from `JsonResponse`), but for a 2xx status other than 200/201 **prefer building the envelope with its final status in one place** — `new Response(['success'=>true,'message'=>'Success','data'=>$data], $status)` — rather than mutating a `success()` response after the fact.
- **Handler reflection:** `Router::getReflection(mixed $handler): \ReflectionFunction|\ReflectionMethod` (line 743, cached) — reuse to read the method's `#[ResponseStatus]` and (in the generator) the return type. Guard: a closure handler has no method attribute → default 200.
- **Schema reuse:** `ClassSchemaReflector::toSchema(string $class): array` (typed-property → OpenAPI schema; nullable/nested/enum/array/DateTime; never throws). The generator's `#[ApiResponse]` path already builds an envelope-wrapped response schema — reuse that builder, sourced from the return type.
- **Tests/gates:** `vendor/bin/phpunit <path>`; `composer phpcs`; `vendor/bin/phpstan analyse <files> --level=6 --no-progress` (ignore the benign PHPStan 2.x banner). Don't stage CLAUDE.md.

---

## File structure
```
src/Routing/Attributes/ResponseStatus.php                  # NEW: #[ResponseStatus(201)] (TARGET_METHOD), rejects non-2xx
src/Http/Contracts/ResponseData.php                        # NEW: marker interface (pure data — HTTP/output semantics)
src/Serialization/ResponseDataSerializer.php               # NEW: ResponseData -> array (public props, recursive, guarded)
src/Routing/Router.php                                     # MODIFY: ResponseData branch in normalizeResponse + status threading
src/Support/Documentation/RouteReflectionDocGenerator.php  # MODIFY: response schema from the return type
tests/Unit/Serialization/ResponseDataSerializerTest.php    # NEW
tests/Unit/Routing/ResponseDataNormalizationTest.php       # NEW
tests/Unit/Support/Documentation/ResponseDataSchemaTest.php# NEW
```
> `ResponseData` is HTTP/output semantics, so it lives under `Glueful\Http\Contracts` (NOT `Glueful\Validation\Contracts` — that's input binding, where `RequestData` correctly lives). Create `src/Http/Contracts/` if absent. Confirm PSR-4 mapping for `Glueful\Http\Contracts\` / `Glueful\Serialization\` / `Glueful\Routing\Attributes\` (all `Glueful\` → `src/`).

---

### Task 1: `ResponseData` marker + `#[ResponseStatus]` attribute + `ResponseDataSerializer`

**Files:** Create `src/Http/Contracts/ResponseData.php`, `src/Routing/Attributes/ResponseStatus.php`, `src/Serialization/ResponseDataSerializer.php`; Test `tests/Unit/Serialization/ResponseDataSerializerTest.php`.

`ResponseData` is a pure marker (no methods). `#[ResponseStatus(n)]` is a method attribute (default 200 when absent). The serializer converts a DTO's PUBLIC typed properties to an array, mirroring `ClassSchemaReflector`'s type handling so values match the documented schema.

- [ ] **Step 1: Write the failing test** — fixtures + assertions:
```php
<?php
declare(strict_types=1);
namespace Glueful\Tests\Unit\Serialization;

use Glueful\Serialization\ResponseDataSerializer;
use Glueful\Http\Contracts\ResponseData;
use PHPUnit\Framework\TestCase;

enum Status: string { case Draft = 'draft'; case Published = 'published'; }

final class AuthorData implements ResponseData
{
    public function __construct(public string $name) {}
}

final class PostData implements ResponseData
{
    /** @param list<AuthorData> $authors */
    public function __construct(
        public string $id,
        public ?string $publishedAt,
        public Status $status,
        public AuthorData $author,
        public array $authors = [],
    ) {}
}

final class ResponseDataSerializerTest extends TestCase
{
    public function testSerializesScalarsNullablesEnumsAndNesting(): void
    {
        $dto = new PostData('p1', null, Status::Published, new AuthorData('Ada'), [new AuthorData('Lin')]);
        $out = (new ResponseDataSerializer())->toArray($dto);

        self::assertSame('p1', $out['id']);
        self::assertNull($out['publishedAt']);
        self::assertSame('published', $out['status']);          // backed enum -> value
        self::assertSame(['name' => 'Ada'], $out['author']);    // nested ResponseData -> array
        self::assertSame([['name' => 'Lin']], $out['authors']); // list of ResponseData -> array of arrays
    }

    public function testPrefersCustomToArrayWhenPresent(): void
    {
        $dto = new class implements ResponseData {
            public string $a = 'x';
            public function toArray(): array { return ['custom' => true]; }
        };
        self::assertSame(['custom' => true], (new ResponseDataSerializer())->toArray($dto));
    }

    public function testSkipsUninitializedTypedProperties(): void
    {
        // Reading an uninitialized typed property throws — the serializer must skip it.
        $dto = new class implements ResponseData {
            public string $set = 'yes';
            public string $unset; // never assigned
        };
        $out = (new ResponseDataSerializer())->toArray($dto);
        self::assertSame(['set' => 'yes'], $out);
        self::assertArrayNotHasKey('unset', $out);
    }

    public function testSelfReferentialDtoTerminates(): void
    {
        // A cycle must not loop forever (depth/visited guard, matching ClassSchemaReflector).
        $dto = new class implements ResponseData {
            public string $name = 'root';
            public ?ResponseData $next = null;
        };
        $dto->next = $dto; // cycle
        $out = (new ResponseDataSerializer())->toArray($dto); // must return, not hang
        self::assertSame('root', $out['name']);
    }
}
```
Also add: `(new \Glueful\Routing\Attributes\ResponseStatus(404))` throws `\InvalidArgumentException` (non-2xx rejected), and `new ResponseStatus(201)` is accepted. Run → fail.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.**
  - `ResponseData` (HTTP/output semantics → under `Glueful\Http\Contracts`):
    ```php
    namespace Glueful\Http\Contracts;
    /** Marker: a controller method returning a ResponseData is enveloped into the standard response. */
    interface ResponseData {}
    ```
  - `ResponseStatus` — **fail loud** for a non-2xx status (developer-authored metadata; a broken `#[ResponseStatus(404)]`/`#[ResponseStatus(999)]` must not be silently swallowed):
    ```php
    namespace Glueful\Routing\Attributes;
    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class ResponseStatus
    {
        public function __construct(public readonly int $status)
        {
            if ($status < 200 || $status > 299) {
                throw new \InvalidArgumentException(
                    "#[ResponseStatus] must be a 2xx success status; got {$status}."
                );
            }
        }
    }
    ```
  - `ResponseDataSerializer::toArray(\Glueful\Http\Contracts\ResponseData $dto): array` — if `method_exists($dto, 'toArray')` use it (escape hatch); else reflect PUBLIC properties (skip static), mapping each value: scalar/null → as-is; backed enum → `->value`, pure enum → `->name`; `DateTimeInterface` → `->format('c')`; nested `ResponseData` → recurse; array → map each element (recursing `ResponseData`/enum/DateTime); other object → best-effort. **Two mandatory guards:** (a) skip any property where `ReflectionProperty::isInitialized($dto)` is false — reading an uninitialized typed property throws; (b) a cycle/depth guard (a `visited` object set, or a max depth ~5 matching `ClassSchemaReflector`) so a self-referential DTO terminates. With both guards it does not throw on a well-formed DTO.
- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** `Add ResponseData marker + #[ResponseStatus] + ResponseDataSerializer`.

---

### Task 2: `normalizeResponse` branch + status threading

**Files:** Modify `src/Routing/Router.php`; Test `tests/Unit/Routing/ResponseDataNormalizationTest.php`.

`dispatch()` resolves the handler's `#[ResponseStatus]` once and threads it to `normalizeResponse` on both paths; `normalizeResponse` gains a `ResponseData` branch.

- [ ] **Step 1: Write the failing test** — mirror `tests/Unit/Routing/RouterTest.php` setup. Register `GET /post` → a fixture controller method `show(): PostData` (returns a `ResponseData`); dispatch; assert the response is the enveloped DTO: status 200, JSON body `{success:true, message:..., data:{...DTO...}}`. Add: a method with `#[ResponseStatus(201)] store(): PostData` → status 201, enveloped. Add a **regression** assertion: a handler returning a plain `Response`, a `string`, an `array`, and a `JsonResource` are all normalized EXACTLY as before (unchanged) — the ResponseData branch must not alter them. Run → fail.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.**
  - Add `private function responseStatusFor(mixed $handler): int` — `getReflection($handler)`; if it's a `ReflectionMethod` carrying `#[ResponseStatus]`, return `newInstance()->status` and **let any exception propagate** (a malformed `#[ResponseStatus(404)]`'s constructor throws → that route fails loudly, by design — do NOT swallow). If there is no `#[ResponseStatus]` (or the handler is a closure/unresolvable), return `200` (absence is the default). Guard only the reflection resolution, never the attribute's own 2xx validation.
  - In `dispatch()` (after building the invoker, ~line 660): `$successStatus = $this->responseStatusFor($route->getHandler());` Pass it to BOTH normalize paths:
    - no-middleware (line 666): `$response = $this->normalizeResponse($invoker(), $successStatus);`
    - middleware (line 664): `$response = $this->executeWithMiddleware($request, $pipeline, $invoker, $successStatus);`
  - `executeWithMiddleware(Request $request, array $middlewareStack, callable $core, int $successStatus = 200): Response` — forward: `return $this->normalizeResponse($next($request), $successStatus);` (default keeps any other caller unaffected).
  - `normalizeResponse(mixed $result, int $successStatus = 200): Response` — add, BEFORE the array/object branch:
    ```php
    if ($result instanceof \Glueful\Http\Contracts\ResponseData) {
        $data = (new \Glueful\Serialization\ResponseDataSerializer())->toArray($result);
        return match ($successStatus) {
            200 => Response::success($data),
            201 => Response::created($data),
            // Build the envelope with its final status in one place (preferred over mutating success()).
            default => new Response(['success' => true, 'message' => 'Success', 'data' => $data], $successStatus),
        };
    }
    ```
  - Verify-point: the `ResponseData` check is BEFORE `is_array($result) || is_object($result)` (the `new JsonResponse` fallback, currently ~`Router.php:1053`) so a DTO never falls through unenveloped; `Response::success`/`created` produce the envelope and the direct-constructor path matches it.
- [ ] **Step 4: Run → pass.** **Run the FULL `tests/Unit/Routing` suite** — strictly additive: every existing normalize case (Response/string/array/JsonResource) must be byte-identical. (Confirm the `int $successStatus = 200` defaults mean no other caller of `executeWithMiddleware`/`normalizeResponse` breaks.)
- [ ] **Step 5: Commit** `Envelope ResponseData returns in Router::normalizeResponse (status-aware)`.

---

### Task 3: Generator — response schema from the return type

**Files:** Modify `src/Support/Documentation/RouteReflectionDocGenerator.php`; Test `tests/Unit/Support/Documentation/ResponseDataSchemaTest.php`.

When a handler's RETURN TYPE implements `ResponseData`, document the success response from that DTO — envelope-wrapped, at the `#[ResponseStatus]` status (default 200) — reusing the existing `#[ApiResponse]` envelope builder. `#[ApiResponse]` entries still overlay by status (an explicit `#[ApiResponse(200,...)]` overrides the inferred 200; error responses 4xx/etc. come from `#[ApiResponse]` as before).

- [ ] **Step 1: Write the failing test** — register `GET /posts/{id}` → fixture `show(string $id): PostData` (`PostData implements ResponseData` with typed props incl. a nested DTO + enum + nullable). Assert the operation's `responses['200'].content.application/json.schema` is the **envelope** `{type:object, properties:{success, message, data}}` where `data` is `ClassSchemaReflector::toSchema(PostData::class)` (nested author object, enum, nullable rendered as 3.1 `["string","null"]`). Assert a `#[ResponseStatus(201)] store(): PostData` → the response is under `201`. Assert a handler with BOTH a `ResponseData` return AND `#[ApiResponse(200, OtherData::class)]` → the explicit `#[ApiResponse]` wins for 200. Assert a handler returning a plain `Response` (no ResponseData) → response inference unchanged (existing behavior). Run → fail.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.** In the response-building path (where `#[ApiResponse]` responses are merged onto the defaults): resolve the handler `ReflectionMethod` (reuse `handlerReflection()`); read its return type; if it's a class implementing `ResponseData`, build a success response (status from `#[ResponseStatus]`, default 200) whose schema is the envelope-wrapped `ClassSchemaReflector::toSchema($returnClass)` — reuse the same envelope helper the `#[ApiResponse]` path uses. Overlay order: defaults → inferred-return-type response → `#[ApiResponse]` responses (explicit wins). Guard reflection (never throw).
- [ ] **Step 4: Run → pass.** Keep ALL existing `tests/Unit/Support/Documentation` tests green (esp. the `#[ApiResponse]` and request-DTO tests).
- [ ] **Step 5: Commit** `Infer reflect-mode response schema from a ResponseData return type`.

---

### Task 4: Wrap-up — CHANGELOG + docs + full suite

**Files:** Modify `CHANGELOG.md`; Create `docs/RESPONSE_DTOS.md` (or extend `docs/REQUEST_DTOS.md`).

- [ ] **Step 1:** CHANGELOG `[Unreleased] → Added`: response DTOs — a method returning a `ResponseData` is enveloped into `{success, message, data}` (status via `#[ResponseStatus]`, default 200); in reflect mode the response schema is inferred from the return type. State v1 boundaries: **`ResponseData` only** (Resources still return manually via `->toResponse()` — NOT auto-enveloped); public typed properties serialized (or a custom `toArray()`); success response only (error/4xx responses still via `#[ApiResponse]`); collections/pagination not first-class yet.
- [ ] **Step 2:** `docs/RESPONSE_DTOS.md` — worked example (`PostData implements ResponseData` returned from a controller), the envelope + `#[ResponseStatus]`, reflect-mode inference, and the boundaries above. Note the Resources-coexist position (simple DTO path vs rich Resource path) from the proposal.
- [ ] **Step 3:** Run the FULL `vendor/bin/phpunit tests/Unit` → green (the change touches core `Router.php`; confirm no regression). `composer phpcs` clean. Report totals.
- [ ] **Step 4: Commit** `Document typed response DTOs (Phase B wrap-up)`.

---

## Self-review
- **Spec coverage (proposal §7 Phase B):** B1 `ResponseData`+`#[ResponseStatus]`+serializer → Task 1; B1 the `normalizeResponse` branch + status threading → Task 2; B2 return-type schema inference → Task 3.
- **Strictly additive:** the `normalizeResponse` branch only fires for `ResponseData` (before the array/object fallback); `int $successStatus = 200` defaults keep all other callers unchanged; Task 2 Step 4 asserts the Response/string/array/JsonResource cases are byte-identical.
- **Resources untouched:** Phase B does NOT route `JsonResource`/`ResourceCollection` through `toResponse()` — they keep the manual pattern (explicit non-scope).
- **Status from a method attribute, not the data:** `ResponseData` carries no status; `#[ResponseStatus]` is read in `dispatch()` and threaded. Transport stays off the data object.
- **Symmetry:** `ResponseDataSerializer` (values) mirrors `ClassSchemaReflector` (schema) so the runtime payload matches the generated docs — Task 1 + Task 3 should use the same public-property + enum/DateTime/nested handling.
- **Fail-loud `#[ResponseStatus]`:** the attribute constructor rejects non-2xx (developer metadata error surfaces on the route, not silently as 200); `responseStatusFor()` only defaults to 200 on *absence*, never swallows a malformed attribute. The generator stays guarded (doc generation must not crash).
- **Serializer safety:** skips uninitialized typed properties (reading them throws) and bounds recursion (cycle/depth guard) — both test-covered.
- **`ResponseData` placement:** `Glueful\Http\Contracts` (output semantics), parallel to `RequestData` in `Glueful\Validation\Contracts` (input).
- **Verify-points (confirm against real API at implementation):** `Response::success`/`created` envelope shape + that the direct constructor (`new Response([...envelope...], $status)`) matches it for other-2xx (this is preferred over `setStatusCode()`, which exists via the `JsonResponse` parent but mutates after the fact); the two `normalizeResponse` call sites both threaded (no third caller missed); `handlerReflection()` + return-type reflection in the generator (Task 3); the `ResponseData` check precedes `is_array||is_object` in `normalizeResponse`.
- **Out of scope (later):** Resource auto-normalizer; collections/pagination return types; non-2xx success enveloping nuances; request DTOs (Phase A, done).
