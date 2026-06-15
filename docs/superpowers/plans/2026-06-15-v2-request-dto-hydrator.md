# v2 Request-DTO Hydrator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Promote `RequestData` hydration from v1 (flat scalars) to v2 — recursive array/nested-DTO hydration that returns 422 (not a `TypeError`/500), explicit `#[FromRoute]`/`#[FromQuery]` source attributes, `#[ArrayOf]` as the sole array element-type source for request DTOs, a `ValidatesSelf` cross-field hook, and a `RuleRegistry` container seam for custom named rules.

**Architecture:** Extend the existing `RequestDataHydrator` in place (v2 is a strict superset — flat v1 DTOs hydrate byte-identically). New attributes/contracts live in `Glueful\Validation\`. The Router passes path + query alongside the body. The OpenAPI reflect generator gains a request-mode (`#[ArrayOf]` items, exclude source-attr fields) and emits `#[FromRoute]`/`#[FromQuery]` as path/query parameters.

**Tech Stack:** PHP 8.3, PHPUnit 10, PHPStan level 8, phpcs (PSR-12-ish). Spec: `docs/superpowers/specs/2026-06-15-v2-request-dto-hydrator-design.md`.

**Conventions:**
- Run a single test: `vendor/bin/phpunit --filter testName path/to/Test.php`
- Lint a touched file: `vendor/bin/phpcs <file>` · Analyse: `vendor/bin/phpstan analyse <file> --level=8`
- Commit messages: imperative, no AI/Claude attribution.
- All new attributes target **both** `TARGET_PARAMETER | TARGET_PROPERTY` (so the hydrator reads them off `ReflectionParameter` and the reflector off the promoted `ReflectionProperty`), mirroring the existing `#[Rule]`.
- **Shared test fixtures.** All `RequestData` test DTOs are real autoloaded classes in `tests/Support/Fixtures/RequestData/<Name>.php` — namespace `Glueful\Tests\Support\Fixtures\RequestData` (test autoload is `Glueful\Tests\ → tests/`), **one class per file**. Tests `use` them; do **not** declare fixtures inline inside a `*Test.php` file (those aren't reliably autoloadable from another test file). Each task below shows the fixture class body — create it as its own file in that namespace the first time it appears.

---

### Task 1: `#[ArrayOf]` attribute (fail-loud constructor)

**Files:**
- Create: `src/Validation/Attributes/ArrayOf.php`
- Test: `tests/Unit/Validation/Attributes/ArrayOfTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation\Attributes;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Contracts\RequestData;
use PHPUnit\Framework\TestCase;

final class ArrayOfNestedFixture implements RequestData
{
    public function __construct(public string $name = '')
    {
    }
}

final class ArrayOfPlainFixture
{
}

final class ArrayOfTest extends TestCase
{
    public function testCanonicalizesScalarSpellings(): void
    {
        self::assertSame('int', (new ArrayOf('integer'))->type);
        self::assertSame('int', (new ArrayOf('int'))->type);
        self::assertSame('float', (new ArrayOf('number'))->type);
        self::assertSame('bool', (new ArrayOf('boolean'))->type);
        self::assertSame('string', (new ArrayOf('string'))->type);
    }

    public function testScalarDetection(): void
    {
        self::assertTrue((new ArrayOf('string'))->isScalar());
        self::assertNull((new ArrayOf('string'))->dtoClass());
        self::assertFalse((new ArrayOf(ArrayOfNestedFixture::class))->isScalar());
        self::assertSame(ArrayOfNestedFixture::class, (new ArrayOf(ArrayOfNestedFixture::class))->dtoClass());
    }

    public function testRejectsUnknownScalarName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArrayOf('text');
    }

    public function testRejectsClassNotImplementingRequestData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArrayOf(ArrayOfPlainFixture::class);
    }

    public function testRejectsNonExistentClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ArrayOf('Glueful\\Nope\\DoesNotExist');
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Unit/Validation/Attributes/ArrayOfTest.php`
Expected: FAIL — `Glueful\Validation\Attributes\ArrayOf` not found.

- [ ] **Step 3: Implement the attribute**

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

use Glueful\Validation\Contracts\RequestData;

/**
 * Declares the element type of an `array` field on a v2 RequestData DTO. This is
 * the ONLY source of an array field's element type for request hydration and the
 * generated OpenAPI request-body `items` (a bare `array` is mixed; `@var` is not
 * read for request DTOs). The constructor validates fail-loud at load time.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class ArrayOf
{
    private const SCALARS = ['string' => 'string', 'int' => 'int', 'integer' => 'int',
        'float' => 'float', 'number' => 'float', 'bool' => 'bool', 'boolean' => 'bool'];

    /** Canonical type: one of string|int|float|bool, or a RequestData class-string. */
    public readonly string $type;

    public function __construct(string $type)
    {
        if (isset(self::SCALARS[$type])) {
            $this->type = self::SCALARS[$type];
            return;
        }
        if (!class_exists($type)) {
            throw new \InvalidArgumentException(
                "#[ArrayOf] type '{$type}' is neither a known scalar (string|int|float|bool) nor an existing class."
            );
        }
        if (!is_a($type, RequestData::class, true)) {
            throw new \InvalidArgumentException(
                "#[ArrayOf] class '{$type}' must implement " . RequestData::class . " to be hydrated as a nested element."
            );
        }
        $this->type = $type;
    }

    public function isScalar(): bool
    {
        return in_array($this->type, ['string', 'int', 'float', 'bool'], true);
    }

    /** @return class-string<RequestData>|null */
    public function dtoClass(): ?string
    {
        /** @var class-string<RequestData>|null */
        return $this->isScalar() ? null : $this->type;
    }
}
```

- [ ] **Step 4: Run the test — expect PASS.** `vendor/bin/phpunit tests/Unit/Validation/Attributes/ArrayOfTest.php`
- [ ] **Step 5: Lint + analyse.** `vendor/bin/phpcs src/Validation/Attributes/ArrayOf.php && vendor/bin/phpstan analyse src/Validation/Attributes/ArrayOf.php --level=8`
- [ ] **Step 6: Commit.**

```bash
git add src/Validation/Attributes/ArrayOf.php tests/Unit/Validation/Attributes/ArrayOfTest.php
git commit -m "Add #[ArrayOf] attribute for v2 request-DTO array element types"
```

---

### Task 2: `#[FromRoute]` and `#[FromQuery]` source attributes

**Files:**
- Create: `src/Validation/Attributes/FromRoute.php`, `src/Validation/Attributes/FromQuery.php`
- Test: `tests/Unit/Validation/Attributes/SourceAttributesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation\Attributes;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;
use PHPUnit\Framework\TestCase;

final class SourceAttributesTest extends TestCase
{
    public function testAttributesAreReadableFromAParameter(): void
    {
        $fn = function (#[FromRoute] string $a, #[FromQuery] string $b, string $c): void {
        };
        $params = (new \ReflectionFunction($fn))->getParameters();

        self::assertCount(1, $params[0]->getAttributes(FromRoute::class));
        self::assertCount(1, $params[1]->getAttributes(FromQuery::class));
        self::assertCount(0, $params[2]->getAttributes(FromRoute::class));
        self::assertCount(0, $params[2]->getAttributes(FromQuery::class));
    }
}
```

- [ ] **Step 2: Run it — FAIL** (classes not found). `vendor/bin/phpunit tests/Unit/Validation/Attributes/SourceAttributesTest.php`

- [ ] **Step 3: Implement both attributes**

`src/Validation/Attributes/FromRoute.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

/**
 * Marks a top-level RequestData DTO field as sourced from the matched path
 * parameters (not the JSON body). Valid only on the top-level injected DTO —
 * encountering it on a nested DTO during recursive hydration is a developer
 * error (see RequestDataHydrator).
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class FromRoute
{
}
```

`src/Validation/Attributes/FromQuery.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

/**
 * Marks a top-level RequestData DTO field as sourced from the query string
 * (not the JSON body). Valid only on the top-level injected DTO.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class FromQuery
{
}
```

- [ ] **Step 4: Run the test — PASS.**
- [ ] **Step 5: Lint + analyse both files** (`vendor/bin/phpcs` / `phpstan ... --level=8`).
- [ ] **Step 6: Commit.**

```bash
git add src/Validation/Attributes/FromRoute.php src/Validation/Attributes/FromQuery.php tests/Unit/Validation/Attributes/SourceAttributesTest.php
git commit -m "Add #[FromRoute]/#[FromQuery] source attributes for request DTOs"
```

---

### Task 3: `ValidatesSelf` contract

**Files:**
- Create: `src/Validation/Contracts/ValidatesSelf.php`
- Test: covered by the hydrator test in Task 8 (a marker-style contract needs no standalone test).

- [ ] **Step 1: Implement the contract**

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation\Contracts;

/**
 * A RequestData DTO may implement this to run cross-field / DTO-level validation
 * after hydration. Runs after per-field rules pass and the DTO is constructed
 * (typed access to $this). Returned errors merge into the same 422 envelope.
 */
interface ValidatesSelf
{
    /** @return array<string, list<string>> field => messages (empty array = valid) */
    public function validate(): array;
}
```

- [ ] **Step 2: Lint + analyse.** `vendor/bin/phpcs src/Validation/Contracts/ValidatesSelf.php && vendor/bin/phpstan analyse src/Validation/Contracts/ValidatesSelf.php --level=8`
- [ ] **Step 3: Commit.**

```bash
git add src/Validation/Contracts/ValidatesSelf.php
git commit -m "Add ValidatesSelf contract for cross-field request-DTO validation"
```

---

### Task 4: `RuleRegistry` service + `RuleParser` integration

**Files:**
- Create: `src/Validation/Support/RuleRegistry.php`
- Modify: `src/Validation/Support/RuleParser.php` (consult the registry when resolving a rule name)
- Test: `tests/Unit/Validation/Support/RuleRegistryTest.php`

Context: `RuleParser` has a `protected array $ruleMap` (built-in `name => class`) plus a `protected array $customRules = []` and an existing app-rule resolution path (`parseRule()` → `resolveCustomRule()`); it accepts an optional `?ApplicationContext $context`. `Validator` consumes `array<string, Rule[]>`. The custom `Rule` contract is `Glueful\Validation\Contracts\Rule::validate(mixed $value, array $context = []): ?string`.

**Built-in protection (P1):** `RuleRegistry` MUST reject registering a name that collides with a built-in rule (e.g. `required`, `string`, `array`, `in`, `email`, …) — **always**, with no override path; built-in names are reserved. `overwrite: true` only replaces an existing *custom* rule, never a built-in. It learns the reserved set from a new `RuleParser::builtinRuleNames(): list<string>` static (returns the `$ruleMap` keys). And `RuleParser` resolves rule names **built-ins first, registry second** (never registry-first) so a custom rule can never shadow a built-in — registration-time reservation and resolution-time ordering are therefore consistent.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation\Support;

use Glueful\Validation\Contracts\Rule;
use Glueful\Validation\Support\RuleRegistry;
use PHPUnit\Framework\TestCase;

final class ReservedNameRule implements Rule
{
    public function validate(mixed $value, array $context = []): ?string
    {
        return $value === 'admin' ? 'That name is reserved.' : null;
    }
}

final class RuleRegistryTest extends TestCase
{
    public function testRegistersAndResolvesACustomRule(): void
    {
        $r = new RuleRegistry();
        $r->register('reserved_name', ReservedNameRule::class);
        self::assertTrue($r->has('reserved_name'));
        self::assertSame(ReservedNameRule::class, $r->classFor('reserved_name'));
    }

    public function testDuplicateRegistrationThrowsByDefault(): void
    {
        $r = new RuleRegistry();
        $r->register('reserved_name', ReservedNameRule::class);
        $this->expectException(\InvalidArgumentException::class);
        $r->register('reserved_name', ReservedNameRule::class);
    }

    public function testOverwriteAllowsReRegistration(): void
    {
        $r = new RuleRegistry();
        $r->register('reserved_name', ReservedNameRule::class);
        $r->register('reserved_name', ReservedNameRule::class, overwrite: true);
        self::assertSame(ReservedNameRule::class, $r->classFor('reserved_name'));
    }

    public function testRegisteringBuiltinNameThrows(): void
    {
        $r = new RuleRegistry(['required', 'string', 'array']);
        $this->expectException(\InvalidArgumentException::class);
        $r->register('required', ReservedNameRule::class);
    }

    public function testBuiltinNameIsReservedEvenWithOverwrite(): void
    {
        $r = new RuleRegistry(['required']);
        $this->expectException(\InvalidArgumentException::class);
        $r->register('required', ReservedNameRule::class, overwrite: true); // built-ins never overridable
    }

    public function testRejectsNonRuleClass(): void
    {
        $r = new RuleRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $r->register('bad', \stdClass::class);
    }
}
```

- [ ] **Step 2: Run it — FAIL** (`RuleRegistry` not found).

- [ ] **Step 3: Implement `RuleRegistry`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation\Support;

use Glueful\Validation\Contracts\Rule;

/**
 * Holds application-registered custom validation rules (name => Rule class).
 * Resolved as a container service; NOT a global static. Registering a name that
 * already exists throws unless $overwrite is true, preventing silent shadowing.
 */
final class RuleRegistry
{
    /** @var array<string, class-string<Rule>> */
    private array $rules = [];

    /** @var array<string, true> reserved built-in rule names */
    private array $reserved;

    /** @param list<string> $reservedNames built-in rule names that must not be silently shadowed */
    public function __construct(array $reservedNames = [])
    {
        $this->reserved = array_fill_keys(array_map('strtolower', $reservedNames), true);
    }

    /** @param class-string<Rule> $ruleClass */
    public function register(string $name, string $ruleClass, bool $overwrite = false): void
    {
        if (!is_a($ruleClass, Rule::class, true)) {
            throw new \InvalidArgumentException(
                "Custom rule '{$name}' class '{$ruleClass}' must implement " . Rule::class . '.'
            );
        }
        $key = strtolower($name);
        if (isset($this->reserved[$key])) {
            // Built-in names are ALWAYS reserved — no override path. RuleParser resolves
            // built-ins before the registry, so a registered override would never be used;
            // forbidding registration keeps the contract honest.
            throw new \InvalidArgumentException(
                "Custom rule '{$name}' collides with a built-in rule name; built-in names are reserved and cannot be overridden."
            );
        }
        if (!$overwrite && isset($this->rules[$key])) {
            throw new \InvalidArgumentException(
                "Custom rule '{$name}' is already registered; pass overwrite: true to replace it."
            );
        }
        $this->rules[$key] = $ruleClass;
    }

    public function has(string $name): bool
    {
        return isset($this->rules[strtolower($name)]);
    }

    /** @return class-string<Rule>|null */
    public function classFor(string $name): ?string
    {
        return $this->rules[strtolower($name)] ?? null;
    }
}
```

- [ ] **Step 4: Run the registry test — PASS.**

- [ ] **Step 5: Wire the registry into `RuleParser`.** Read `src/Validation/Support/RuleParser.php`. (a) Add a static `public static function builtinRuleNames(): array { return array_keys((new self())->ruleMap); }` (or refactor the `$ruleMap` keys to a shared const) so `RuleRegistry` can reserve them. (b) In the constructor accept an optional `?RuleRegistry $registry = null` → `__construct(?ApplicationContext $context = null, ?RuleRegistry $registry = null)` (keep BC for single-arg callers). (c) Resolve **built-ins first, registry second**: in `parseRule()` the existing order is `$customRules` → `$ruleMap` → `resolveCustomRule()`; consult the registry **inside `resolveCustomRule()`** (the app-rule path, only reached when the name is NOT a built-in), instantiating the registered parameterless `Rule` class. A custom rule therefore can never shadow a built-in at resolution time, complementing the registration-time guard. Add a focused test in a `RuleParserCustomRuleTest` proving a `#[Rule('reserved_name')]`-style string resolves the registered rule and fails the value `'admin'`:

```php
public function testRuleParserUsesRegisteredCustomRule(): void
{
    $registry = new RuleRegistry();
    $registry->register('reserved_name', ReservedNameRule::class);
    $parser = new \Glueful\Validation\Support\RuleParser(null, $registry);
    $compiled = $parser->parse(['username' => 'required|string|reserved_name']);
    $validator = new \Glueful\Validation\Validator($compiled);
    self::assertNotSame([], $validator->validate(['username' => 'admin']));
    self::assertSame([], $validator->validate(['username' => 'alice']));
}
```

- [ ] **Step 6: Run it — PASS** (after the RuleParser edit). Adjust the RuleParser constructor signature to `__construct(?ApplicationContext $context = null, ?RuleRegistry $registry = null)`; keep BC for existing single-arg callers.
- [ ] **Step 7: Lint + analyse both files; run the full Validation suite** `vendor/bin/phpunit tests/Unit/Validation` (no regressions).
- [ ] **Step 8: Commit.**

```bash
git add src/Validation/Support/RuleRegistry.php src/Validation/Support/RuleParser.php tests/Unit/Validation/Support/RuleRegistryTest.php tests/Unit/Validation/Support/RuleParserCustomRuleTest.php
git commit -m "Add built-in-aware RuleRegistry custom-rule seam wired into RuleParser"
```

---

### Task 5: Hydrator — `build()` refactor + source resolution (`#[FromRoute]`/`#[FromQuery]`) + Router wiring

**Files:**
- Modify: `src/Validation/RequestDataHydrator.php`
- Modify: `src/Routing/Router.php:838-850` (pass route + query into `hydrate`)
- Test: `tests/Unit/Validation/RequestDataHydratorTest.php` (extend) and `tests/Unit/Routing/RequestDataInjectionTest.php`

This refactors `hydrate()` to delegate to an internal `build()` that **collects** errors (rather than throwing inline) so later tasks can compose nested errors, and adds source resolution. Flat v1 DTOs must stay byte-identical.

- [ ] **Step 1: Write failing tests**

```php
// in RequestDataHydratorTest.php — new fixtures + tests

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;

final class SourcedFixture implements RequestData
{
    public function __construct(
        #[FromRoute] public string $uuid,
        #[FromQuery] #[Rule('in:draft,published')] public string $status = 'draft',
        #[Rule('required|string')] public string $title = '',
    ) {
    }
}

public function testResolvesValuesFromRouteAndQueryBySourceAttribute(): void
{
    $dto = $this->hydrator->hydrate(
        SourcedFixture::class,
        ['title' => 'Hello', 'status' => 'should-be-ignored'], // body
        ['uuid' => 'abc123'],                                   // route
        ['status' => 'published'],                              // query
    );
    self::assertSame('abc123', $dto->uuid);          // from route
    self::assertSame('published', $dto->status);     // from query, NOT body
    self::assertSame('Hello', $dto->title);          // from body (default source)
}

public function testFlatV1DtoUnchanged(): void
{
    $dto = $this->hydrator->hydrate(CreatePostFixture::class, ['title' => 'T', 'body' => 'B']);
    self::assertSame('draft', $dto->status); // characterization: v1 behavior preserved
}

// fixture (shared namespace): RequiredNoRuleFixture — public string $name; (non-nullable, no default, NO #[Rule])
public function testMissingNonNullableNoRuleIs422NotTypeError(): void
{
    try {
        $this->hydrator->hydrate(RequiredNoRuleFixture::class, []);
        self::fail('expected ValidationException');
    } catch (ValidationException $e) {
        self::assertArrayHasKey('name', $e->errors()); // P1: must be 422, never a TypeError/500
    }
}

// fixture (shared namespace): DualSourceFixture — a single param carrying BOTH #[FromRoute] and #[FromQuery].
public function testBothSourceAttributesOnOneFieldThrowLogicException(): void
{
    $this->expectException(\LogicException::class); // developer/config error, not a 422
    $this->hydrator->hydrate(DualSourceFixture::class, [], ['x' => '1'], ['x' => '2']);
}
```

- [ ] **Step 2: Run — the source test FAILS** (status comes from body today), v1 test passes.

- [ ] **Step 3: Refactor `RequestDataHydrator`** to the `build()` shape with source resolution. Full new file:

```php
<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;
use Glueful\Validation\Support\RuleParser;
use Glueful\Validation\Support\RuleRegistry;

/**
 * Hydrates request input into a validated, typed RequestData DTO (v2).
 *
 * v2 adds: source resolution (#[FromRoute]/#[FromQuery], body default), array +
 * nested-DTO hydration via #[ArrayOf] (failing as 422, never TypeError/500),
 * and a post-hydration ValidatesSelf cross-field hook. Flat scalar DTOs behave
 * exactly as v1.
 */
final class RequestDataHydrator
{
    private const MAX_DEPTH = 5;

    public function __construct(private readonly ?RuleRegistry $ruleRegistry = null)
    {
    }

    /**
     * @param  class-string<RequestData> $dtoClass
     * @param  array<string,mixed>       $body
     * @param  array<string,mixed>       $route
     * @param  array<string,mixed>       $query
     */
    public function hydrate(string $dtoClass, array $body, array $route = [], array $query = []): RequestData
    {
        [$instance, $errors] = $this->build($dtoClass, $body, $route, $query, depth: 0, nested: false);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        /** @var RequestData $instance */
        return $instance;
    }

    /**
     * @param  array<string,mixed> $body
     * @param  array<string,mixed> $route
     * @param  array<string,mixed> $query
     * @return array{0: ?RequestData, 1: array<string, list<string>>}
     */
    private function build(string $dtoClass, array $body, array $route, array $query, int $depth, bool $nested): array
    {
        $ref  = new \ReflectionClass($dtoClass);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            /** @var RequestData $instance */
            $instance = $ref->newInstance();
            return [$instance, []];
        }

        // 1. Resolve raw values by source + collect per-field #[Rule] strings.
        $raw   = [];
        $rules = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            $hasRoute = $param->getAttributes(FromRoute::class) !== [];
            $hasQuery = $param->getAttributes(FromQuery::class) !== [];
            if ($hasRoute && $hasQuery) {
                throw new \LogicException(
                    "{$dtoClass}::\${$name} declares both #[FromRoute] and #[FromQuery]; a field has exactly one source."
                );
            }
            if ($hasRoute || $hasQuery) {
                if ($nested) {
                    throw new \LogicException(
                        "#[FromRoute]/#[FromQuery] are only valid on a top-level RequestData DTO; "
                        . "found on nested {$dtoClass}::\${$name}."
                    );
                }
                $source = $hasRoute ? $route : $query;
            } else {
                $source = $body;
            }
            if (array_key_exists($name, $source)) {
                $raw[$name] = $source[$name];
            }
            foreach ($param->getAttributes(Rule::class) as $attr) {
                $rules[$name] = $attr->newInstance()->rules;
            }
        }

        // 2. Parent-level field rules (prove container shape). Collect errors.
        $errors    = [];
        $validated = $raw;
        if ($rules !== []) {
            $validator = new Validator((new RuleParser(null, $this->ruleRegistry))->parse($rules));
            $errors    = $validator->validate($raw);
            $filtered  = array_intersect_key($validator->filtered(), $raw);
            $validated = $filtered + $raw;
        }

        // (Array + nested handling added in Tasks 6/7; for now arrays pass through.)

        // 3b. Required presence — a param absent from input with no default and not
        //     nullable would TypeError at construction; make it a 422 instead (covers
        //     the v1 "no-#[Rule] non-nullable" footgun even without `required`).
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            if (
                !array_key_exists($name, $validated)
                && !$param->isDefaultValueAvailable()
                && !$param->allowsNull()
                && !isset($errors[$name])
            ) {
                $errors[$name][] = "The {$name} field is required.";
            }
        }

        // 4. Errors before construction → 422, never construct.
        if ($errors !== []) {
            return [null, $errors];
        }

        // 5. Construct. (After the gate above, an absent param here is always either
        //    defaultable or nullable — so a bare null is safe.)
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $validated)) {
                $args[] = $this->coerce($validated[$name], $param);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null; // nullable + absent + no default
            }
        }
        /** @var RequestData $instance */
        $instance = $ref->newInstanceArgs($args);

        // 6. ValidatesSelf (Task 8 adds the body).

        return [$instance, []];
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

- [ ] **Step 4: Run the hydrator tests — PASS** (source test + v1 characterization).

- [ ] **Step 5: Wire the Router.** Edit `src/Routing/Router.php` around line 849 — change the hydrate call to pass route params and the query bag:

```php
$args[] = $this->requestDataHydrator()->hydrate(
    $typeName,
    $body,
    $params,
    $request->query->all(),
);
```

- [ ] **Step 6: Add a Router integration test** in `tests/Unit/Routing/RequestDataInjectionTest.php` — register a route `/items/{uuid}` whose handler takes `SourcedFixture` (reuse a fixture), dispatch a request with a `{uuid}` path, `?status=published`, and a JSON body `{"title":"Hi"}`, and assert the hydrated DTO has `uuid` from the path and `status` from the query. (Follow the existing test's router/container setup.)

- [ ] **Step 7: Wire the container seam (P1).** Edit `src/Validation/ServiceProvider/ValidationProvider.php` `defs()` so app-registered rules actually reach the Router (which resolves `RequestDataHydrator` from the container **when bound**, else falls back to `new` with no registry):

```php
// RuleRegistry — shared, seeded with built-in reserved names so customs can't shadow them.
$defs[\Glueful\Validation\Support\RuleRegistry::class] = new FactoryDefinition(
    \Glueful\Validation\Support\RuleRegistry::class,
    static fn (): \Glueful\Validation\Support\RuleRegistry =>
        new \Glueful\Validation\Support\RuleRegistry(\Glueful\Validation\Support\RuleParser::builtinRuleNames()),
);

// RequestDataHydrator — autowired so it receives the bound RuleRegistry.
$defs[\Glueful\Validation\RequestDataHydrator::class] =
    $this->autowire(\Glueful\Validation\RequestDataHydrator::class);
```

Apps register custom rules against the resolved registry in their own provider's `boot()`: `$container->get(RuleRegistry::class)->register('reserved_username', ReservedUsername::class);` (documented in Task 11).

- [ ] **Step 8: Seam integration test.** In `tests/Unit/Routing/RequestDataInjectionTest.php`, build a container that binds a `RuleRegistry` with a registered `reserved_name` rule and a `RequestDataHydrator` constructed with it; register a route whose DTO field uses `#[Rule('reserved_name')]`; dispatch a request carrying the reserved value and assert a **422** — proving the seam carries app rules end-to-end through the Router. Run — PASS.

- [ ] **Step 9: Run** `vendor/bin/phpunit tests/Unit/Validation tests/Unit/Routing` — all green; lint + analyse `RequestDataHydrator.php`, `Router.php`, `ValidationProvider.php`.
- [ ] **Step 10: Commit.**

```bash
git add src/Validation/RequestDataHydrator.php src/Routing/Router.php src/Validation/ServiceProvider/ValidationProvider.php tests/Unit/Validation/RequestDataHydratorTest.php tests/Unit/Routing/RequestDataInjectionTest.php
git commit -m "Hydrator v2: source resolution, Router + ValidationProvider wiring, missing-required 422"
```

---

### Task 6: Hydrator — scalar `#[ArrayOf]` element coercion + per-element 422

**Files:**
- Modify: `src/Validation/RequestDataHydrator.php` (array handling in `build()`)
- Test: `tests/Unit/Validation/RequestDataHydratorTest.php`

- [ ] **Step 1: Write failing tests**

```php
use Glueful\Validation\Attributes\ArrayOf;

final class ScalarArrayFixture implements RequestData
{
    /** @param array<int,int> $ids */
    public function __construct(
        #[ArrayOf('int')] #[Rule('required|array')] public array $ids = [],
    ) {
    }
}

public function testCoercesScalarArrayElements(): void
{
    $dto = $this->hydrator->hydrate(ScalarArrayFixture::class, ['ids' => ['1', '2', 3]]);
    self::assertSame([1, 2, 3], $dto->ids);
}

public function testNonCoercibleScalarElementIs422WithDotPath(): void
{
    try {
        $this->hydrator->hydrate(ScalarArrayFixture::class, ['ids' => [1, 'nope']]);
        self::fail('expected ValidationException');
    } catch (ValidationException $e) {
        self::assertArrayHasKey('ids.1', $e->errors());
    }
}

public function testNonArrayValueForArrayFieldIs422NotTypeError(): void
{
    try {
        $this->hydrator->hydrate(ScalarArrayFixture::class, ['ids' => 'not-an-array']);
        self::fail('expected ValidationException');
    } catch (ValidationException $e) {
        self::assertArrayHasKey('ids', $e->errors()); // shallow 'array' rule fired; recursion never ran
    }
}
```

- [ ] **Step 2: Run — FAIL** (scalar arrays not coerced / TypeError on the non-array case).

- [ ] **Step 3: Implement scalar-array handling.** In `build()`, after step 2 (parent rules) and before step 4 (the error gate), add a loop that, **only when the field passed its parent rules and is an array**, coerces `#[ArrayOf('scalar')]` elements and records per-element errors:

```php
// 3. Array element handling for #[ArrayOf] fields (scalar in this task).
foreach ($ctor->getParameters() as $param) {
    $name    = $param->getName();
    $arrayOf = $param->getAttributes(ArrayOf::class);
    if ($arrayOf === [] || isset($errors[$name]) || !array_key_exists($name, $validated) || !is_array($validated[$name])) {
        continue; // no #[ArrayOf], or parent rule already failed, or not an array
    }
    $of = $arrayOf[0]->newInstance();
    if ($of->isScalar()) {
        $coerced = [];
        foreach ($validated[$name] as $i => $element) {
            $result = $this->coerceScalar($element, $of->type);
            if ($result['ok']) {
                $coerced[$i] = $result['value'];
            } else {
                $errors["{$name}.{$i}"][] = "The {$name}.{$i} field must be of type {$of->type}.";
            }
        }
        $validated[$name] = $coerced;
    }
    // nested-DTO arrays handled in Task 7
}
```

Add the helper:

```php
/** @return array{ok: bool, value: mixed} */
private function coerceScalar(mixed $value, string $type): array
{
    return match ($type) {
        'int'    => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)
                        ? ['ok' => true, 'value' => (int) $value] : ['ok' => false, 'value' => null],
        'float'  => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                        ? ['ok' => true, 'value' => (float) $value] : ['ok' => false, 'value' => null],
        'bool'   => is_bool($value) ? ['ok' => true, 'value' => $value] : ['ok' => false, 'value' => null],
        'string' => is_string($value) ? ['ok' => true, 'value' => $value] : ['ok' => false, 'value' => null],
        default  => ['ok' => false, 'value' => null],
    };
}
```

- [ ] **Step 4: Run the tests — PASS** (coercion, dot-path element 422, non-array → 422 not 500).
- [ ] **Step 5: Lint + analyse; run `tests/Unit/Validation`.**
- [ ] **Step 6: Commit.**

```bash
git add src/Validation/RequestDataHydrator.php tests/Unit/Validation/RequestDataHydratorTest.php
git commit -m "Hydrator v2: scalar #[ArrayOf] element coercion with per-element 422"
```

---

### Task 7: Hydrator — nested-DTO `#[ArrayOf]` recursion (dot-path errors, depth cap, nested-source fail-loud)

**Files:**
- Modify: `src/Validation/RequestDataHydrator.php`
- Test: `tests/Unit/Validation/RequestDataHydratorTest.php`

- [ ] **Step 1: Write failing tests**

```php
final class FieldDefFixture implements RequestData
{
    public function __construct(
        #[Rule('required|string')] public string $name = '',
        #[Rule('required|string')] public string $type = '',
    ) {
    }
}

final class NestedArrayFixture implements RequestData
{
    /** @param array<int,FieldDefFixture> $schema */
    public function __construct(
        #[Rule('required|string')] public string $slug = '',
        #[ArrayOf(FieldDefFixture::class)] #[Rule('required|array')] public array $schema = [],
    ) {
    }
}

final class BadNestedSourceFixture implements RequestData
{
    public function __construct(
        #[\Glueful\Validation\Attributes\FromRoute] public string $oops = '',
    ) {
    }
}

final class HasBadNestedFixture implements RequestData
{
    /** @param array<int,BadNestedSourceFixture> $rows */
    public function __construct(
        #[ArrayOf(BadNestedSourceFixture::class)] #[Rule('array')] public array $rows = [],
    ) {
    }
}

public function testHydratesNestedDtoArray(): void
{
    $dto = $this->hydrator->hydrate(NestedArrayFixture::class, [
        'slug'   => 'post',
        'schema' => [['name' => 'title', 'type' => 'string'], ['name' => 'body', 'type' => 'text']],
    ]);
    self::assertCount(2, $dto->schema);
    self::assertInstanceOf(FieldDefFixture::class, $dto->schema[0]);
    self::assertSame('title', $dto->schema[0]->name);
}

public function testInvalidNestedElementIs422WithDotPath(): void
{
    try {
        $this->hydrator->hydrate(NestedArrayFixture::class, [
            'slug'   => 'post',
            'schema' => [['name' => 'title', 'type' => 'string'], ['type' => 'text']], // missing name in [1]
        ]);
        self::fail('expected ValidationException');
    } catch (ValidationException $e) {
        self::assertArrayHasKey('schema.1.name', $e->errors());
    }
}

public function testNestedSourceAttributeThrowsLogicException(): void
{
    $this->expectException(\LogicException::class);
    $this->hydrator->hydrate(HasBadNestedFixture::class, ['rows' => [['oops' => 'x']]]);
}
```

- [ ] **Step 2: Run — FAIL** (elements stay raw arrays; no LogicException).

- [ ] **Step 3: Implement nested-DTO recursion.** Extend the array loop from Task 6 — in the `else` branch (non-scalar `#[ArrayOf]`), recurse per element:

```php
} else {
    $dtoClass = $of->dtoClass();
    if ($depth + 1 >= self::MAX_DEPTH) {
        $errors[$name][] = "The {$name} field is nested too deeply (max " . self::MAX_DEPTH . ').';
        continue;
    }
    $built = [];
    foreach ($validated[$name] as $i => $element) {
        if (!is_array($element)) {
            $errors["{$name}.{$i}"][] = "The {$name}.{$i} field must be an object.";
            continue;
        }
        [$child, $childErrors] = $this->build($dtoClass, $element, [], [], $depth + 1, nested: true);
        foreach ($childErrors as $field => $messages) {
            $errors["{$name}.{$i}.{$field}"] = $messages;
        }
        if ($childErrors === []) {
            $built[$i] = $child;
        }
    }
    $validated[$name] = $built;
}
```

(The `nested: true` argument makes `build()` throw `\LogicException` when a nested DTO carries `#[FromRoute]`/`#[FromQuery]` — already implemented in Task 5's source-resolution block.)

- [ ] **Step 4: Run the tests — PASS** (nested hydration, `schema.1.name` 422, LogicException on nested source attr).
- [ ] **Step 5: Add a depth-cap test** — a self-referential `#[ArrayOf(self)]`-style fixture nested 6 deep yields a validation error (not a stack overflow). Run it — PASS.
- [ ] **Step 6: Lint + analyse; run `tests/Unit/Validation`.**
- [ ] **Step 7: Commit.**

```bash
git add src/Validation/RequestDataHydrator.php tests/Unit/Validation/RequestDataHydratorTest.php
git commit -m "Hydrator v2: nested-DTO #[ArrayOf] recursion, dot-path errors, depth cap, nested-source guard"
```

---

### Task 8: Hydrator — `ValidatesSelf` cross-field hook

**Files:**
- Modify: `src/Validation/RequestDataHydrator.php` (step 6 of `build()`)
- Test: `tests/Unit/Validation/RequestDataHydratorTest.php`

- [ ] **Step 1: Write failing tests**

```php
use Glueful\Validation\Contracts\ValidatesSelf;

final class PublishFixture implements RequestData, ValidatesSelf
{
    public function __construct(
        #[Rule('required|in:draft,published')] public string $status = 'draft',
        #[Rule('string')] public ?string $publishedAt = null,
    ) {
    }

    public function validate(): array
    {
        if ($this->status === 'published' && $this->publishedAt === null) {
            return ['publishedAt' => ['Required when status is published.']];
        }
        return [];
    }
}

public function testValidatesSelfPasses(): void
{
    $dto = $this->hydrator->hydrate(PublishFixture::class, ['status' => 'draft']);
    self::assertInstanceOf(PublishFixture::class, $dto);
}

public function testValidatesSelfErrorsBecome422(): void
{
    try {
        $this->hydrator->hydrate(PublishFixture::class, ['status' => 'published']);
        self::fail('expected ValidationException');
    } catch (ValidationException $e) {
        self::assertArrayHasKey('publishedAt', $e->errors());
    }
}
```

- [ ] **Step 2: Run — FAIL** (no cross-field check yet).

- [ ] **Step 3: Implement.** In `build()`, after construction (step 5), before `return`:

```php
// 6. Cross-field validation hook.
if ($instance instanceof ValidatesSelf) {
    $selfErrors = $instance->validate();
    if ($selfErrors !== []) {
        return [null, $selfErrors];
    }
}
```

- [ ] **Step 4: Run the tests — PASS.** (For a nested element implementing `ValidatesSelf`, its errors already bubble dot-pathed via Task 7's child-error merge.)
- [ ] **Step 5: Lint + analyse; run `tests/Unit/Validation`.**
- [ ] **Step 6: Commit.**

```bash
git add src/Validation/RequestDataHydrator.php tests/Unit/Validation/RequestDataHydratorTest.php
git commit -m "Hydrator v2: ValidatesSelf cross-field hook merged into the 422"
```

---

### Task 9: OpenAPI — `ClassSchemaReflector` request-mode (`#[ArrayOf]` items, exclude source-attr fields)

**Files:**
- Modify: `src/Support/Documentation/ClassSchemaReflector.php`
- Test: `tests/Unit/Support/Documentation/ClassSchemaReflectorRequestModeTest.php`

Add an opt-in request-mode that (a) reads `#[ArrayOf]` for an array property's `items` (a bare array → `items: {}`; **never** `@var`), and (b) excludes `#[FromRoute]`/`#[FromQuery]` properties. The legacy `@var` path is untouched for `requestMode = false` (responses).

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Support\Documentation\ClassSchemaReflector;
use Glueful\Tests\Support\Fixtures\RequestData\NestedArrayFixture; // slug + #[ArrayOf(FieldDefFixture)] schema
use Glueful\Tests\Support\Fixtures\RequestData\SourcedFixture;     // #[FromRoute] uuid + #[FromQuery] status + title
use PHPUnit\Framework\TestCase;

final class ClassSchemaReflectorRequestModeTest extends TestCase
{
    public function testRequestModeUsesArrayOfForItems(): void
    {
        $schema = ClassSchemaReflector::toSchema(NestedArrayFixture::class, requestMode: true);
        $items = $schema['properties']['schema']['items'];
        self::assertSame('object', $items['type']);
        self::assertArrayHasKey('name', $items['properties']);
    }

    public function testRequestModeExcludesSourceAttributedFields(): void
    {
        $schema = ClassSchemaReflector::toSchema(SourcedFixture::class, requestMode: true);
        self::assertArrayNotHasKey('uuid', $schema['properties']);   // #[FromRoute]
        self::assertArrayNotHasKey('status', $schema['properties']); // #[FromQuery]
        self::assertArrayHasKey('title', $schema['properties']);     // body
    }
}
```

- [ ] **Step 2: Run — FAIL** (`toSchema` has no `requestMode` param; items come from `@var`/`{}`).

- [ ] **Step 3: Implement.** Add `bool $requestMode = false` threaded from `toSchema()` → `reflect()` → `propertySchema()`/`arraySchema()`. In `reflect()`, when `$requestMode`, skip a property whose `ReflectionProperty::getAttributes(FromRoute::class)` or `getAttributes(FromQuery::class)` is non-empty. In `arraySchema()`, when `$requestMode`, resolve items from `#[ArrayOf]` (`$property->getAttributes(ArrayOf::class)`): scalar → `scalarSchema($of->type)`; DTO class → `reflect($of->dtoClass(), $visited, true)`; absent → `['type' => 'array', 'items' => new \stdClass()]` (no `@var` lookup). Keep the existing `@var` branch only for `!$requestMode`.

- [ ] **Step 4: Run the tests — PASS.** Run the existing `tests/Unit/Support/Documentation` suite to confirm response-mode reflection is unchanged.
- [ ] **Step 5: Lint + analyse.**
- [ ] **Step 6: Commit.**

```bash
git add src/Support/Documentation/ClassSchemaReflector.php tests/Unit/Support/Documentation/ClassSchemaReflectorRequestModeTest.php
git commit -m "OpenAPI: ClassSchemaReflector request-mode (#[ArrayOf] items, exclude source fields)"
```

---

### Task 10: OpenAPI — emit `#[FromRoute]`/`#[FromQuery]` params; placeholder + nested-source fail-loud

**Files:**
- Modify: `src/Support/Documentation/RouteReflectionDocGenerator.php`
- Test: `tests/Unit/Support/Documentation/RequestDataSourceParamsTest.php`

The request-body builder must call `ClassSchemaReflector::toSchema($dtoClass, requestMode: true)` (so source fields are excluded). New: emit `#[FromRoute]` DTO fields as `in: path required: true` and `#[FromQuery]` as `in: query` (`required` from a `required` rule or no default), merged/deduped with the existing path params (`buildParameters`) and `#[QueryParam]`. Fail loud on a `#[FromRoute]` field with no route placeholder, and on a source attribute found on a nested DTO.

- [ ] **Step 1: Write failing tests** (follow the existing generator-test harness — build a `Router`, register a route, run `RouteReflectionDocGenerator::generate`):

```php
public function testFromRouteFieldBecomesPathParam(): void
{
    $router = $this->makeRouter();
    $router->put('/items/{uuid}', [SourceParamController::class, 'update']); // handler takes SourcedFixture
    $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);
    $params = $paths['/items/{uuid}']['put']['parameters'];
    $uuid = $this->paramWhere($params, 'uuid', 'path');
    self::assertNotNull($uuid);
    self::assertTrue($uuid['required']);
    $status = $this->paramWhere($params, 'status', 'query');
    self::assertNotNull($status);
    // body schema excludes uuid + status
    $body = $paths['/items/{uuid}']['put']['requestBody']['content']['application/json']['schema'];
    self::assertArrayNotHasKey('uuid', $body['properties']);
    self::assertArrayHasKey('title', $body['properties']);
}

public function testFromRouteWithoutPlaceholderFailsGeneration(): void
{
    $router = $this->makeRouter();
    $router->post('/items', [SourceParamController::class, 'orphan']); // SourcedFixture has #[FromRoute] uuid, no {uuid}
    $this->expectException(\LogicException::class);
    (new RouteReflectionDocGenerator($this->registry()))->generate($router);
}

public function testBothSourceAttributesFailGeneration(): void
{
    $router = $this->makeRouter();
    $router->post('/dual', [SourceParamController::class, 'dual']); // handler takes DualSourceFixture
    $this->expectException(\LogicException::class);
    (new RouteReflectionDocGenerator($this->registry()))->generate($router);
}
```

(Add a `SourceParamController` test stub with `update(SourcedFixture $in)`, `orphan(SourcedFixture $in)`, and `dual(DualSourceFixture $in)`, and a `paramWhere()` helper mirroring `QueryParamAttributeTest::paramsWhere()`.)

- [ ] **Step 2: Run — FAIL** (source fields appear in body; no path/query param emitted; no mismatch check).

- [ ] **Step 3: Implement.**
  - In `buildRequestBodyFromRequestData()`, change the `ClassSchemaReflector::toSchema($dtoClass)` call to `toSchema($dtoClass, requestMode: true)`.
  - Add `buildRequestDataSourceParameters(Route $route): array` — reflect the handler's `RequestData` param; for each ctor param with `#[FromRoute]`, assert `in_array($name, $route->getParamNames(), true)` else `throw new \LogicException("#[FromRoute] field {$dtoClass}::\${$name} has no {{$name}} placeholder in route {$route->getPath()}")`; emit `['name'=>$name,'in'=>'path','required'=>true,'description'=>'','schema'=>$this->ruleSchemaFor($name,$dtoClass)]`. For each `#[FromQuery]` param, emit `in:query` with `required` = (has a `required` rule) || (no constructor default), schema from its `#[Rule]`.
  - Detect a source attribute on a nested DTO (reachable via `#[ArrayOf]` class fields) and `throw new \LogicException(...)` — a small recursive check mirroring the hydrator's nested rule.
  - A ctor param carrying **both** `#[FromRoute]` and `#[FromQuery]` → `throw new \LogicException(...)` during generation, mirroring the hydrator's dual-source guard.
  - Merge these into the operation's `parameters` after `buildParameters()` + field-selection + `#[QueryParam]`, deduping by `(name, in)` (a `#[FromRoute]` `uuid` replaces the generic path-param `uuid` so the rule-derived schema/description win).

- [ ] **Step 4: Run the tests — PASS.** Run the full `tests/Unit/Support/Documentation` suite (no regressions in existing reflect-doc tests).
- [ ] **Step 5: Lint + analyse.**
- [ ] **Step 6: Commit.**

```bash
git add src/Support/Documentation/RouteReflectionDocGenerator.php tests/Unit/Support/Documentation/RequestDataSourceParamsTest.php
git commit -m "OpenAPI: emit #[FromRoute]/#[FromQuery] params; fail loud on placeholder/nested-source mismatch"
```

---

### Task 11: Integration test, docs, CHANGELOG

**Files:**
- Test: `tests/Integration/Validation/V2HydratorIntegrationTest.php`
- Modify: `docs/REQUEST_DTOS.md`, `CHANGELOG.md`

- [ ] **Step 1: Integration test** — register a real route `/articles/{uuid}/draft/{locale}` whose handler takes a DTO with `#[FromRoute] uuid`, `#[FromRoute] locale`, `#[FromQuery] preview`, a `#[ArrayOf(FieldDefFixture::class)] schema` body field, and `ValidatesSelf`. Dispatch (a) a valid request → assert 200 and the handler saw the merged/typed DTO; (b) an invalid request (bad nested element + failing cross-field) → assert **422** with nested error keys (`schema.0.name`) and the cross-field key present. Run — PASS.

- [ ] **Step 2: Rewrite `docs/REQUEST_DTOS.md`** — remove the "v1 limitations" and "What is not in v1" sections; document `#[FromRoute]`/`#[FromQuery]` (body default), `#[ArrayOf]` (scalar + DTO; the sole item source; `@var` not read for request DTOs), `ValidatesSelf`, `RuleRegistry` custom rules, and the refined hydration order. Keep the worked example, updated for v2.

- [ ] **Step 3: Add a `CHANGELOG.md` `[Unreleased]` entry** under `### Added`:

```markdown
- **Typed request-DTO hydration v2.** `RequestData` DTOs now hydrate arrays and nested DTOs (failing as 422, never a `TypeError`/500), pull fields from path/query via `#[FromRoute]`/`#[FromQuery]`, declare array element types with `#[ArrayOf]`, run cross-field checks via the `ValidatesSelf` contract, and resolve app-registered custom rules through `RuleRegistry`. Flat scalar DTOs are unchanged. See `docs/REQUEST_DTOS.md`.
```

- [ ] **Step 4: Run the full suite** `composer test`; `composer phpcs`; `composer analyse`. All green.
- [ ] **Step 5: Commit.**

```bash
git add tests/Integration/Validation/V2HydratorIntegrationTest.php docs/REQUEST_DTOS.md CHANGELOG.md
git commit -m "v2 hydrator: integration test, REQUEST_DTOS docs, CHANGELOG"
```

---

## Self-Review

**Spec coverage:** arrays/nested (Tasks 6–7), source merging (Task 5 + Task 10), validation hook (Task 8 `ValidatesSelf` + Task 4 custom rules), `#[ArrayOf]` fail-loud (Task 1), refined order/422-before-construct + **missing-required→422 (Task 5, Step 3b)**, `RuleRegistry` duplicate-throws + **built-in names always reserved (no override; consistent with built-ins-first resolution)** (Task 4), **container seam wiring `RuleRegistry`+`RequestDataHydrator` in `ValidationProvider` with an end-to-end Router test (Task 5, Steps 7–8)**, **dual-source (`#[FromRoute]`+`#[FromQuery]` on one field) fail-loud at runtime AND generation (Tasks 5, 10)**, route-placeholder + nested-source fail-loud (Tasks 7 runtime + 10 generation), `@var`-not-for-request + bare→`{}` (Task 9), Router wiring (Task 5), OpenAPI param placement (Task 10), characterization + docs (Tasks 5, 11). No gaps.

**Type consistency:** `build(string, array, array, array, int $depth, bool $nested): array{0: ?RequestData, 1: array}`; `ArrayOf::isScalar()/dtoClass()/type`; `RuleRegistry::__construct(list<string> $reservedNames)`/`register/has/classFor`; `RuleParser::builtinRuleNames(): list<string>`; `ClassSchemaReflector::toSchema($class, requestMode)`; `ValidatesSelf::validate(): array`. Built-ins resolve before the registry (Task 4) so a custom rule can never shadow a built-in. All shared `RequestData` fixtures live in `Glueful\Tests\Support\Fixtures\RequestData` (P2). Names used consistently across Tasks 5–10.

**Placeholder scan:** every code/test step shows complete code or an exact command; no TBD/"handle edge cases".
