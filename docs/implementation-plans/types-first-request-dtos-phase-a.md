# Types-First Request DTOs — Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Let a controller method declare a typed request DTO parameter (`function store(CreatePostData $input)`) that the router hydrates-and-validates from the JSON body, and have the reflect OpenAPI generator infer that route's request-body schema from the DTO — with no `#[Validate]`/`#[ApiResponse]` annotation.

**Architecture:** A `RequestData` marker interface + a new `#[Rule]` property/parameter attribute. A stateless `RequestDataHydrator` collects each `#[Rule]` string, runs it through the existing `RuleParser::parse()` → `Validator` (which returns an error map), and the hydrator throws `ValidationException` → 422 on validation errors; on success it takes sanitized values from `filtered()` and constructs the DTO. `Router::resolveMethodParameters()` gains a `RequestData` branch inserted **before** the container-resolution branches. The reflect generator (`RouteReflectionDocGenerator`) reflects a `RequestData` parameter via the existing `ClassSchemaReflector` (shape) merged with `ValidationRuleSchema` (constraints). Strictly additive: a parameter not implementing `RequestData` takes the existing code path unchanged.

**Tech Stack:** PHP 8.3, `Glueful\Validation\Validator` + `Glueful\Validation\Support\RuleParser` (existing), `Glueful\Validation\ValidationException` (existing, 422), `Glueful\Routing\Router`, `Glueful\Support\Documentation\{ClassSchemaReflector,ValidationRuleSchema,RouteReflectionDocGenerator}` (existing). Source of truth: [`../proposals/types-first-dto-io.md`](../proposals/types-first-dto-io.md) §2–§4, §7 (Phase A).

**Scope (this plan):** the request half only — `RequestData` + `#[Rule]` + hydrator + router injection + request-schema inference. **Not here:** response DTOs / `ResponseData` / `#[ResponseStatus]` / the return normalizer (Phase B); route/query merging into the DTO (v1 is JSON body only); a `make:dto` scaffolder (optional A4).

---

## Conventions (verified seams — reuse verbatim)
- **Rules → Validator:** `Validator` consumes `Rule` objects, not strings. Convert with `(new RuleParser())->parse($stringRules)` (`src/Validation/Support/RuleParser.php:83`, `parse(array<string,string|array<Rule>>): array<string,array<Rule>>`). **`Validator::validate(array $data): array` RETURNS errors (field => messages) and does NOT throw** (`src/Validation/Validator.php:27`); sanitized/mutated values come from **`filtered(): array`** after a clean run. So the caller checks errors and throws itself: `new ValidationException(array $errors)` (or `ValidationException::forField($field, $msg)`) — `ValidationException extends HttpException` → 422.
- **DTO shape (v1):** v1 supports **constructor-promoted DTOs only** — rules are collected from `#[Rule]` on constructor parameters, and `ClassSchemaReflector` already reads public promoted properties. Non-promoted public properties are NOT scanned in v1 (the `#[Rule]` attribute keeps `TARGET_PROPERTY` for a future iteration). State this in the DTO docs.
- **Router param seam:** `Router::resolveMethodParameters()` (`src/Routing/Router.php:784`). Non-builtin types currently try Request injection (`:800`), FieldSelector/Projector (`:806`/`:817`), `container->has()` (`:823`), then `class_exists → container->get()` (`:829`). The `RequestData` branch goes **after `:803` and before `:823`**.
- **Generator request body:** `RouteReflectionDocGenerator::buildRequestBody()` already builds a body from `#[Validate]`; add a `RequestData`-param path next to it. `ClassSchemaReflector::toSchema(class)` → object schema from typed properties; `ValidationRuleSchema::toObjectSchema(rules)` → schema from rule strings.
- **Tests:** unit tests under `tests/Unit/...` mirroring existing patterns; run `vendor/bin/phpunit <path>`. `composer phpcs` and `vendor/bin/phpstan analyse <files> --level=6 --no-progress` are gates (ignore the benign PHPStan 2.x banner). Don't stage CLAUDE.md.

---

## File structure
```
src/Validation/Attributes/Rule.php                         # NEW: #[Rule('required|email')]
src/Validation/Contracts/RequestData.php                   # NEW: marker interface
src/Validation/RequestDataHydrator.php                     # NEW: body -> validate -> construct DTO
src/Routing/Router.php                                     # MODIFY: RequestData branch in resolveMethodParameters()
src/Support/Documentation/RouteReflectionDocGenerator.php  # MODIFY: request body from a RequestData param
tests/Unit/Validation/RuleAttributeTest.php                # NEW
tests/Unit/Validation/RequestDataHydratorTest.php          # NEW
tests/Unit/Routing/RequestDataInjectionTest.php            # NEW
tests/Unit/Support/Documentation/RequestDataSchemaTest.php # NEW (or extend RouteReflectionDocGeneratorTest)
```

---

### Task 1: The `#[Rule]` attribute + `RequestData` marker

**Files:** Create `src/Validation/Attributes/Rule.php`, `src/Validation/Contracts/RequestData.php`; Test `tests/Unit/Validation/RuleAttributeTest.php`.

- [x] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);
namespace Glueful\Tests\Unit\Validation;

use Glueful\Validation\Attributes\Rule;
use PHPUnit\Framework\TestCase;

final class RuleAttributeTest extends TestCase
{
    public function testRuleAttributeHoldsItsRuleString(): void
    {
        $r = new Rule('required|email');
        self::assertSame('required|email', $r->rules);
    }

    public function testRuleAttributeTargetsPropertiesAndParameters(): void
    {
        $ref = new \ReflectionClass(Rule::class);
        $attr = $ref->getAttributes(\Attribute::class)[0]->newInstance();
        // TARGET_PROPERTY | TARGET_PARAMETER
        self::assertSame(
            \Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER,
            $attr->flags & (\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)
        );
    }
}
```
- [x] **Step 2: Run → fail** (`vendor/bin/phpunit tests/Unit/Validation/RuleAttributeTest.php`).
- [x] **Step 3: Implement**
```php
<?php
declare(strict_types=1);
namespace Glueful\Validation\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Rule
{
    /** @param string $rules Laravel-style rule string, e.g. "required|string|max:200". */
    public function __construct(public readonly string $rules) {}
}
```
```php
<?php
declare(strict_types=1);
namespace Glueful\Validation\Contracts;

/** Marker: a parameter typed as a RequestData is hydrated + validated from the request body. */
interface RequestData {}
```
- [x] **Step 4: Run → pass.**
- [x] **Step 5: Commit** `Add #[Rule] attribute + RequestData marker for typed request DTOs`.

---

### Task 2: `RequestDataHydrator` — body → validate → construct

**Files:** Create `src/Validation/RequestDataHydrator.php`; Test `tests/Unit/Validation/RequestDataHydratorTest.php`.

**v1 scope: constructor-promoted DTOs only.** The hydrator: (1) collect `#[Rule]` strings from the DTO's **constructor parameters** (promoted properties carry the attribute on the parameter; non-promoted public properties are out of scope for v1); (2) `RuleParser::parse()` → `Validator::validate()` (returns errors) → throw `ValidationException` if any → take sanitized values from `filtered()`; (3) construct the DTO mapping values to constructor params by name, coercing builtins, using defaults for missing optionals.

- [x] **Step 1: Write the failing test** — use an inline fixture DTO with promoted properties + `#[Rule]`:
```php
<?php
declare(strict_types=1);
namespace Glueful\Tests\Unit\Validation;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class CreatePostFixture implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:200')] public string $title,
        #[Rule('required|string')]          public string $body,
        #[Rule('in:draft,published')]       public string $status = 'draft',
    ) {}
}

final class RequestDataHydratorTest extends TestCase
{
    private RequestDataHydrator $hydrator;
    protected function setUp(): void { $this->hydrator = new RequestDataHydrator(); }

    public function testHydratesValidBodyIntoDto(): void
    {
        $dto = $this->hydrator->hydrate(CreatePostFixture::class, [
            'title' => 'Hello', 'body' => 'World', 'status' => 'published',
        ]);
        self::assertInstanceOf(CreatePostFixture::class, $dto);
        self::assertSame('Hello', $dto->title);
        self::assertSame('published', $dto->status);
    }

    public function testUsesDefaultForOmittedOptional(): void
    {
        $dto = $this->hydrator->hydrate(CreatePostFixture::class, ['title' => 'T', 'body' => 'B']);
        self::assertSame('draft', $dto->status);
    }

    public function testInvalidBodyThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);
        $this->hydrator->hydrate(CreatePostFixture::class, ['body' => 'no title']); // title required
    }
}
```
- [x] **Step 2: Run → fail.**
- [x] **Step 3: Implement**
```php
<?php
declare(strict_types=1);
namespace Glueful\Validation;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Support\RuleParser;

final class RequestDataHydrator
{
    /**
     * @param  class-string<RequestData> $dtoClass
     * @param  array<string,mixed>       $body
     */
    public function hydrate(string $dtoClass, array $body): RequestData
    {
        $ref  = new \ReflectionClass($dtoClass);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            /** @var RequestData $instance */
            $instance = $ref->newInstance();
            return $instance;
        }

        // 1. Collect #[Rule] strings keyed by parameter name.
        $rules = [];
        foreach ($ctor->getParameters() as $param) {
            foreach ($param->getAttributes(Rule::class) as $attr) {
                $rules[$param->getName()] = $attr->newInstance()->rules;
            }
        }

        // 2. Validate. Validator::validate() RETURNS errors (field => messages) and does NOT throw;
        //    sanitized/mutated values come from filtered() after a clean run.
        $validated = $body;
        if ($rules !== []) {
            $validator = new Validator((new RuleParser())->parse($rules));
            $errors    = $validator->validate($body);
            if ($errors !== []) {
                throw new ValidationException($errors);   // ValidationException extends HttpException -> 422
            }
            $validated = $validator->filtered() + $body;   // filtered = sanitized values; + $body keeps un-ruled keys
        }

        // 3. Construct, mapping validated values by parameter name (coerce builtins, defaults for missing).
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $validated)) {
                $args[] = $this->coerce($validated[$name], $param);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                $args[] = null; // validation should have caught a missing required value
            }
        }

        /** @var RequestData $instance */
        $instance = $ref->newInstanceArgs($args);
        return $instance;
    }

    private function coerce(mixed $value, \ReflectionParameter $param): mixed
    {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin() || $value === null) {
            return $value;
        }
        return match ($type->getName()) {
            'int'    => is_numeric($value) ? (int) $value : $value,
            'float'  => is_numeric($value) ? (float) $value : $value,
            'bool'   => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL),
            'string' => is_scalar($value) ? (string) $value : $value,
            default  => $value,
        };
    }
}
```
> `Validator` / `ValidationException` need no `use` — they share the hydrator's `Glueful\Validation` namespace. Contract (verified): `validate()` returns the error map (empty = valid, never throws), `filtered()` returns the sanitized values from the last run.
- [x] **Step 4: Run → pass.**
- [x] **Step 5: Commit** `Add RequestDataHydrator (body -> RuleParser/Validator -> typed DTO)`.

---

### Task 3: Router injection — hydrate a `RequestData` parameter

**Files:** Modify `src/Routing/Router.php` (`resolveMethodParameters()`); Test `tests/Unit/Routing/RequestDataInjectionTest.php`.

Insert the branch **after** the `Request` check (`:803`) and **before** `container->has()` (`:823`), so a `RequestData` type is never container-resolved as a service.

- [x] **Step 1: Write the failing test** — register a route whose handler takes a `RequestData` param, dispatch a `Request` with a JSON body, assert the handler received a populated DTO. For the invalid-body case, assert `Router::dispatch()` **throws `ValidationException`** — NOT a 422 response: the router throws; the 422 envelope is produced later by the exception handler/middleware, which a unit test of `dispatch()` does not wire. (Also test malformed JSON → `ValidationException`.) Mirror `tests/Unit/Routing/RouterTest.php` for Router construction + a `Request::create(..., content: json)` dispatch.
- [x] **Step 2: Run → fail.**
- [x] **Step 3: Implement** — in the non-builtin block of `resolveMethodParameters`, right after the `Request` branch:
```php
// Hydrate + validate a typed request DTO from the JSON body (before container resolution,
// so a RequestData class is never treated as a service). v1 is JSON body only — no form fallback.
if (is_a($typeName, \Glueful\Validation\Contracts\RequestData::class, true)) {
    $raw = (string) $request->getContent();
    if ($raw === '') {
        $body = [];                                  // empty body -> no fields (let validation decide)
    } else {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {                   // malformed or non-object JSON
            throw \Glueful\Validation\ValidationException::forField('body', 'Invalid JSON body.');
        }
        $body = $decoded;
    }
    $args[] = $this->requestDataHydrator()->hydrate($typeName, $body);
    continue;
}
```
Add a lazy accessor (the hydrator is stateless; resolve from the container if registered, else construct):
```php
private function requestDataHydrator(): \Glueful\Validation\RequestDataHydrator
{
    if ($this->container->has(\Glueful\Validation\RequestDataHydrator::class)) {
        return $this->container->get(\Glueful\Validation\RequestDataHydrator::class);
    }
    return new \Glueful\Validation\RequestDataHydrator();
}
```
- [x] **Step 4: Run → pass.** Re-run the full `tests/Unit/Routing` suite — existing param-resolution behavior must be unchanged (a non-`RequestData` class still container-resolves).
- [x] **Step 5: Commit** `Inject + validate RequestData DTOs in Router::resolveMethodParameters`.

---

### Task 4: Generator — request body from a `RequestData` parameter

**Files:** Modify `src/Support/Documentation/RouteReflectionDocGenerator.php`; Test `tests/Unit/Support/Documentation/RequestDataSchemaTest.php` (or extend `RouteReflectionDocGeneratorTest`).

For POST/PUT/PATCH, if the handler method has a parameter whose type implements `RequestData`, build the request body from that class: `ClassSchemaReflector::toSchema()` for the property shape, merged with the `#[Rule]`-derived constraints (`ValidationRuleSchema::toObjectSchema()` over the collected rules) so `required`/`format`/`enum`/bounds appear. A `RequestData` param takes precedence over (or coexists with) a `#[Validate]` attribute — pick: **`RequestData` param wins when present**.

- [x] **Step 1: Write the failing test** — a fixture controller method `store(CreatePostFixture $input)` registered as a POST route; assert the operation's `requestBody.content.application/json.schema` has the DTO's properties with `required: [title, body]`, `status` enum `[draft, published]`, and a `maxLength` on `title`.
- [x] **Step 2: Run → fail.**
- [x] **Step 3: Implement** — in `buildRequestBody(Route $route, string $method)`, before the existing `#[Validate]` path: resolve the handler `ReflectionMethod` (reuse the shared `handlerReflection()`), scan its parameters for a type implementing `RequestData`; if found, `$shape = ClassSchemaReflector::toSchema($dtoClass)`, `$constraints = ValidationRuleSchema::toObjectSchema($collectedRules)`, deep-merge constraints onto the shape (constraints win on `required`/`format`/`enum`/min-max), and emit `{required: bool, content: {application/json: {schema}}}`. Reuse `ExampleDeriver::fromValidationRules($collectedRules)` for the example. Guard all reflection (never throw).
- [x] **Step 4: Run → pass.** Keep all existing `tests/Unit/Support/Documentation` tests green.
- [x] **Step 5: Commit** `Infer reflect-mode request body from a typed RequestData parameter`.

---

### Task 5: Wire-up verification + CHANGELOG + docs

**Files:** Modify `CHANGELOG.md`; (optional) a short usage note in `docs/`.

- [x] **Step 1:** Add a `CHANGELOG.md [Unreleased] → Added` entry: typed request DTOs (`RequestData` + `#[Rule]`) auto-hydrated/validated by the router and reflected into the OpenAPI request body; JSON body only; opt-in and additive.
- [x] **Step 2:** Run the FULL suite `composer test` (or `vendor/bin/phpunit`) + `composer phpcs` + the phpstan gate on the changed files → all green/clean.
- [x] **Step 3:** (Optional A4) Note `make:dto` scaffolder as a follow-up — not built here.
- [x] **Step 4: Commit** `Document typed request DTOs (Phase A)`.

---

## Self-review
- **Spec coverage (proposal §7 Phase A):** A1 `RequestData`+`#[Rule]`+hydrator → Tasks 1–2; A2 router injection before container branch → Task 3; A3 request-schema inference → Task 4. A4 (`make:dto`) explicitly deferred.
- **Strictly additive:** the router branch only triggers for `RequestData` types and sits before container resolution; a non-`RequestData` class is unchanged (Task 3 Step 4 asserts this).
- **No invented adapters:** rules go `#[Rule]` string → `RuleParser::parse()` → `Validator`; the hydrator checks `validate()`'s error map and throws `ValidationException` itself (422), taking sanitized values from `filtered()`.
- **v1 boundaries made explicit:** constructor-promoted DTOs only; JSON body only (no form fallback; malformed JSON → `ValidationException::forField('body', ...)`); invalid-body unit test asserts the thrown `ValidationException` at the `dispatch()` layer, not a rendered 422.
- **Verify-points (confirm against real API at implementation):** `filtered()` returns the sanitized subset as expected (Task 2); `Request::getContent()` JSON access in the Router test (Task 3); the shared `handlerReflection()` resolver in the reflect generator (Task 4).
- **Out of scope (Phase B):** `ResponseData`, `#[ResponseStatus]`, the `normalizeResponse()` branch, Resource auto-normalization, route/query merging, PATCH partials (use separate DTOs).
