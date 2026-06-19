# OpenAPI: Inferred Error Bodies + Source-Param Descriptions — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the two remaining sources of OpenAPI attribute noise so controllers stop redeclaring boilerplate — (A) give the generator's auto-inferred error responses (401/403/429, opt-in 500) a body schema, and (B) let `#[FromQuery]`/`#[FromRoute]` carry a `description`/`example` so query/path params can move into a typed DTO without losing docs.

**Architecture:** Both changes extend `RouteReflectionDocGenerator` (the live code-first generator) plus tiny attribute/config additions. No new public concept, no policy class. Precedence is unchanged: an explicit `#[ApiResponse]` still overrides any default at the same status. Spec: `docs/openapi-hybrid-documentation.md`.

**Tech Stack:** PHP 8.3, PHPUnit 10. Framework OpenAPI generator under `src/Support/Documentation/`. Tests under `tests/Unit/Support/Documentation/`. Run a single test class with `vendor/bin/phpunit --filter <ClassName>`; lint with `composer phpcs`.

---

## File map

- Modify: `config/documentation.php` — add a top-level `errors` block (default error schema, envelope flag, always-statuses, per-status descriptions).
- Modify: `src/Support/Documentation/RouteReflectionDocGenerator.php` — `buildResponses()` attaches the default error body + seeds `always` statuses; new private `errorConfig()` + `buildDefaultErrorBody()`; `buildRequestDataSourceParameters()` reads the source attribute's description/example.
- Modify: `src/Validation/Attributes/FromQuery.php` — add optional `?string $description`, `?string $example` constructor args.
- Modify: `src/Validation/Attributes/FromRoute.php` — same two optional args.
- Create test: `tests/Unit/Support/Documentation/InferredErrorBodyTest.php` (Change A).
- Create test: `tests/Unit/Support/Documentation/SourceParamDescriptionTest.php` (Change B).

These changes are independent; Task 1 and Task 2 can ship in either order.

---

## Task 1 (Change A): Default error body on inferred error responses

**Files:**
- Modify: `config/documentation.php`
- Modify: `src/Support/Documentation/RouteReflectionDocGenerator.php`
- Test: `tests/Unit/Support/Documentation/InferredErrorBodyTest.php`

**Background:** `buildResponses(bool $isSecured, bool $rateLimited)` (around line 1339) currently emits `401`/`403` (secured) and `429` (rate-limited) as bare `{description}` objects with no body. We give them a JSON body — a slim inline `{success:false, message:string}` by default, or a reflected DTO when `documentation.errors.schema` names one — and let config seed extra statuses (e.g. `500`) on every operation. `ErrorResponseDTO` is the fat debug DTO and must NOT be the default — use the inline shape.

- [ ] **Step 1: Write the failing test.** Create `tests/Unit/Support/Documentation/InferredErrorBodyTest.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class InferredErrorBodyTest extends TestCase
{
    private function makeRouter(?ApplicationContext $context = null): Router
    {
        $context ??= new ApplicationContext(sys_get_temp_dir() . '/inferr_' . uniqid());
        (new RouteCache($context))->clear();

        $container = new class ($context) implements ContainerInterface {
            /** @var array<string, mixed> */
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
                    extends \RuntimeException
                    implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };

        return new Router($container);
    }

    private function securedRegistry(): SecuritySchemeRegistry
    {
        return new SecuritySchemeRegistry(
            ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
            middlewareMap: ['auth' => ['BearerAuth']],
        );
    }

    public function testSecuredRouteErrorResponsesGainAJsonBody(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/secure', [ErrController::class, 'plain'])->middleware('auth');

        $paths = (new RouteReflectionDocGenerator($this->securedRegistry()))->generate($router);
        $responses = $paths['/v1/secure']['get']['responses'];

        foreach (['401', '403'] as $status) {
            self::assertArrayHasKey($status, $responses);
            $schema = $responses[$status]['content']['application/json']['schema'] ?? null;
            self::assertIsArray($schema, "$status must carry a JSON body schema");
            self::assertSame(['success', 'message'], $schema['required']);
            self::assertFalse($schema['properties']['success']['example']);
        }
    }

    public function testRateLimitedRouteKeepsHeadersAndGainsBody(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/limited', [ErrController::class, 'plain'])->rateLimit(60, 1);

        $paths = (new RouteReflectionDocGenerator(new SecuritySchemeRegistry([], [])))->generate($router);
        $r429 = $paths['/v1/limited']['get']['responses']['429'];

        self::assertArrayHasKey('Retry-After', $r429['headers']);
        self::assertArrayHasKey('application/json', $r429['content']);
    }

    public function testAlwaysConfigSeedsAFiveHundred(): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/inferr_500_' . uniqid());
        $context->mergeConfigDefaults('documentation', ['errors' => ['always' => [500]]]);
        $router = $this->makeRouter($context);
        $router->get('/v1/anything', [ErrController::class, 'plain']);

        $paths = (new RouteReflectionDocGenerator(new SecuritySchemeRegistry([], []), $context))->generate($router);
        $responses = $paths['/v1/anything']['get']['responses'];

        self::assertArrayHasKey('500', $responses);
        self::assertArrayHasKey('application/json', $responses['500']['content']);
    }

    public function testExplicitApiResponseStillOverridesTheDefault(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/custom', [ErrController::class, 'overrides'])->middleware('auth');

        $paths = (new RouteReflectionDocGenerator($this->securedRegistry()))->generate($router);
        $r403 = $paths['/v1/custom']['get']['responses']['403'];

        self::assertSame('Custom forbidden wording.', $r403['description']);
    }

    public function testConfiguredSchemaIsReflectedIntoErrorBody(): void
    {
        // documentation.errors.schema must reflect the named DTO (envelope:false),
        // not the inline default — catches a broken toSchema()/ignored-config path.
        $context = new ApplicationContext(sys_get_temp_dir() . '/inferr_dto_' . uniqid());
        $context->mergeConfigDefaults('documentation', ['errors' => ['schema' => FixtureError::class]]);
        $router = $this->makeRouter($context);
        $router->get('/v1/dto', [ErrController::class, 'plain'])->middleware('auth');

        $paths = (new RouteReflectionDocGenerator($this->securedRegistry(), $context))->generate($router);
        $schema = $paths['/v1/dto']['get']['responses']['401']['content']['application/json']['schema'];

        // FixtureError's public typed properties, NOT the inline {success, message}.
        self::assertArrayHasKey('ok', $schema['properties']);
        self::assertArrayHasKey('reason', $schema['properties']);
        self::assertArrayNotHasKey('message', $schema['properties']);
    }
}

final class ErrController
{
    public function plain(): void
    {
    }

    #[ApiResponse(403, description: 'Custom forbidden wording.')]
    public function overrides(): void
    {
    }
}

/** Thin public-typed error DTO used to prove documentation.errors.schema is reflected. */
final class FixtureError
{
    public bool $ok = false;
    public string $reason = '';
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `vendor/bin/phpunit --filter InferredErrorBodyTest`
Expected: FAIL — `401`/`403`/`429` have no `content` key (and no `500` seeded).

- [ ] **Step 3: Add the `errors` config block.** In `config/documentation.php`, add a top-level key (place it after the `middleware_map` block):
```php
    /*
    |--------------------------------------------------------------------------
    | Inferred error responses
    |--------------------------------------------------------------------------
    | Body schema + descriptions the generator attaches to auto-inferred error
    | responses (401/403 on secured routes, 429 on rate-limited routes, plus any
    | status listed in `always`). `schema: null` uses a slim inline {success,
    | message} shape; set a thin public-typed DTO class to reflect instead.
    | Do NOT point this at Glueful\DTOs\ErrorResponseDTO (the fat debug DTO).
    */
    'errors' => [
        'schema'   => env('API_DOCS_ERROR_SCHEMA', null),
        'envelope' => false,
        'always'   => [],
        'descriptions' => [
            401 => 'Unauthenticated.',
            403 => 'Forbidden.',
            429 => 'Too Many Requests.',
            500 => 'Unexpected server error.',
        ],
    ],
```

- [ ] **Step 4: Add the config + body helpers.** In `src/Support/Documentation/RouteReflectionDocGenerator.php`, add two private methods (place them next to `buildResponses()`):
```php
    /**
     * Resolved `documentation.errors` config merged over built-in defaults.
     *
     * @return array{schema: ?string, envelope: bool, always: list<int>, descriptions: array<int, string>}
     */
    private function errorConfig(): array
    {
        $defaults = [
            'schema' => null,
            'envelope' => false,
            'always' => [],
            'descriptions' => [
                401 => 'Unauthenticated.',
                403 => 'Forbidden.',
                429 => 'Too Many Requests.',
                500 => 'Unexpected server error.',
            ],
        ];

        $configured = $this->context?->getConfig('documentation.errors', []) ?? [];
        if (!is_array($configured)) {
            $configured = [];
        }

        $merged = array_merge($defaults, $configured);
        $merged['descriptions'] = ($configured['descriptions'] ?? []) + $defaults['descriptions'];
        $merged['always'] = array_map('intval', (array) $merged['always']);

        return $merged;
    }

    /**
     * The shared JSON body attached to every inferred error response: a reflected
     * DTO when `documentation.errors.schema` names one, else a slim inline shape.
     *
     * @param  array{schema: ?string, envelope: bool, always: list<int>, descriptions: array<int, string>} $errors
     * @return array{content: array<string, mixed>}
     */
    private function buildDefaultErrorBody(array $errors): array
    {
        $schema = $errors['schema'];
        if (is_string($schema) && $schema !== '') {
            $bodySchema = ClassSchemaReflector::toSchema($schema);
            if ($errors['envelope']) {
                $bodySchema = $this->wrapInEnvelope($bodySchema);
            }
        } else {
            $bodySchema = [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'message' => ['type' => 'string'],
                ],
                'required' => ['success', 'message'],
            ];
        }

        return ['content' => ['application/json' => ['schema' => $bodySchema]]];
    }
```

- [ ] **Step 5: Rewrite `buildResponses()` to attach the body + seed `always`.** Replace the existing `buildResponses()` body with:
```php
    private function buildResponses(bool $isSecured, bool $rateLimited): array
    {
        $errors = $this->errorConfig();
        $body = $this->buildDefaultErrorBody($errors);
        $descriptions = $errors['descriptions'];

        $responses = [
            '200' => ['description' => 'Successful response'],
        ];

        $statuses = [];
        if ($isSecured) {
            $statuses[] = 401;
            $statuses[] = 403;
        }
        foreach ($errors['always'] as $status) {
            $statuses[] = $status;
        }

        foreach (array_unique($statuses) as $status) {
            $responses[(string) $status] = [
                'description' => $descriptions[$status] ?? self::reasonPhrase($status),
            ] + $body;
        }

        if ($rateLimited) {
            $responses['429'] = [
                'description' => $descriptions[429] ?? 'Too Many Requests',
                'headers' => [
                    'Retry-After' => [
                        'description' => 'Seconds to wait before retrying.',
                        'schema' => ['type' => 'integer'],
                    ],
                    'X-RateLimit-Limit' => [
                        'description' => 'Request quota for the current window.',
                        'schema' => ['type' => 'integer'],
                    ],
                    'X-RateLimit-Remaining' => [
                        'description' => 'Requests remaining in the current window.',
                        'schema' => ['type' => 'integer'],
                    ],
                ],
            ] + $body;
        }

        return $responses;
    }
```
Note: `['description' => …] + $body` is an array union — the `description`/`headers` keys never collide with `content`, so the body is added without clobbering. The `mergeAttributeResponses()` overlay still runs last in `buildOperation()`, so an explicit `#[ApiResponse]` replaces any of these defaults.

- [ ] **Step 6: Run it; verify it passes.**

Run: `vendor/bin/phpunit --filter InferredErrorBodyTest`
Expected: PASS — secured 401/403 and rate-limited 429 carry a JSON body, `always:[500]` seeds a 500, a configured `errors.schema` is reflected (FixtureError props, not the inline shape), and an explicit `#[ApiResponse(403)]` still wins.

- [ ] **Step 7: Guard against regressions in the existing suite.**

Run: `vendor/bin/phpunit tests/Unit/Support/Documentation`
Expected: PASS after updating any assertions that intentionally pinned the old bare default descriptions. The default error descriptions change from `Unauthenticated`/`Forbidden`/`Too Many Requests` to the trailing-period config defaults (`Unauthenticated.`/`Forbidden.`/`Too Many Requests.`); update those pinned assertions to the new defaults and note it in the commit body. Tests asserting explicit `#[ApiResponse]` wording must NOT change.

- [ ] **Step 8: phpcs + commit.**
```bash
composer phpcs
git add config/documentation.php src/Support/Documentation/RouteReflectionDocGenerator.php \
  tests/Unit/Support/Documentation/InferredErrorBodyTest.php
git commit -m "Attach a JSON body to inferred OpenAPI error responses"
```

---

## Task 2 (Change B): Descriptions/examples on `#[FromQuery]` / `#[FromRoute]`

**Files:**
- Modify: `src/Validation/Attributes/FromQuery.php`
- Modify: `src/Validation/Attributes/FromRoute.php`
- Modify: `src/Support/Documentation/RouteReflectionDocGenerator.php` (`buildRequestDataSourceParameters()`)
- Test: `tests/Unit/Support/Documentation/SourceParamDescriptionTest.php`

**Background:** `#[FromQuery]`/`#[FromRoute]` are bare markers; `buildRequestDataSourceParameters()` (around line 1109) hardcodes `'description' => ''`. Adding optional constructor args is safe — `RequestDataHydrator` only checks attribute *presence* (`getAttributes(...) !== []`), never instantiates them with required args.

- [ ] **Step 1: Write the failing test.** Create `tests/Unit/Support/Documentation/SourceParamDescriptionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 * @covers \Glueful\Validation\Attributes\FromQuery
 * @covers \Glueful\Validation\Attributes\FromRoute
 */
final class SourceParamDescriptionTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/srcdesc_' . uniqid());
        (new RouteCache($context))->clear();

        $container = new class ($context) implements ContainerInterface {
            /** @var array<string, mixed> */
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
                    extends \RuntimeException
                    implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };

        return new Router($container);
    }

    /**
     * @param  list<array<string, mixed>> $params
     */
    private function param(array $params, string $name, string $in): array
    {
        foreach ($params as $p) {
            if (($p['name'] ?? null) === $name && ($p['in'] ?? null) === $in) {
                return $p;
            }
        }
        self::fail("No $in param named $name");
    }

    public function testFromQueryDescriptionAndExampleSurface(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/items/{type}', [SrcController::class, 'index']);

        $paths = (new RouteReflectionDocGenerator(new SecuritySchemeRegistry([], [])))->generate($router);
        $params = $paths['/v1/items/{type}']['get']['parameters'];

        $locale = $this->param($params, 'locale', 'query');
        self::assertSame('Content locale to read.', $locale['description']);
        self::assertSame('en', $locale['schema']['example']);

        $type = $this->param($params, 'type', 'path');
        self::assertSame('Content type slug.', $type['description']);
        self::assertSame('articles', $type['schema']['example']);
    }

    public function testBareFromQueryStillEmitsEmptyDescription(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/items/{type}', [SrcController::class, 'index']);

        $paths = (new RouteReflectionDocGenerator(new SecuritySchemeRegistry([], [])))->generate($router);
        $cursor = $this->param($paths['/v1/items/{type}']['get']['parameters'], 'cursor', 'query');

        self::assertSame('', $cursor['description']);
    }
}

final class SrcQuery implements RequestData
{
    public function __construct(
        #[FromRoute(description: 'Content type slug.', example: 'articles')]
        #[Rule('string')]
        public readonly string $type = '',

        #[FromQuery(description: 'Content locale to read.', example: 'en')]
        #[Rule('string')]
        public readonly ?string $locale = null,

        #[FromQuery]
        #[Rule('string')]
        public readonly ?string $cursor = null,
    ) {
    }
}

final class SrcController
{
    public function index(SrcQuery $query): void
    {
    }
}
```

- [ ] **Step 2: Run it; verify it fails.**

Run: `vendor/bin/phpunit --filter SourceParamDescriptionTest`
Expected: FAIL — `#[FromQuery(description: …)]` throws an unknown-named-argument error (the attribute has no constructor), or descriptions come back as `''`.

- [ ] **Step 3: Add args to `#[FromQuery]`.** Replace the class body of `src/Validation/Attributes/FromQuery.php`:
```php
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class FromQuery
{
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $example = null,
    ) {
    }
}
```

- [ ] **Step 4: Add args to `#[FromRoute]`.** Replace the class body of `src/Validation/Attributes/FromRoute.php`:
```php
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class FromRoute
{
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $example = null,
    ) {
    }
}
```

- [ ] **Step 5: Read the attribute in the source-param builder.** In `RouteReflectionDocGenerator::buildRequestDataSourceParameters()`, replace the two `$parameters[] = [ … ]` blocks (the path branch and the query branch) so each reads its source attribute:
```php
            if ($hasFromRoute) {
                if (!in_array($name, $placeholders, true)) {
                    throw new \LogicException(sprintf(
                        '#[FromRoute] field %s::$%s has no {%s} placeholder in route %s',
                        $dtoClass,
                        $name,
                        $name,
                        $route->getPath(),
                    ));
                }

                $fromRoute = $param->getAttributes(FromRoute::class)[0]->newInstance();
                if ($fromRoute->example !== null) {
                    $schema['example'] = $fromRoute->example;
                }

                $parameters[] = [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'description' => $fromRoute->description ?? '',
                    'schema' => $schema,
                ];
                continue;
            }

            $fromQuery = $param->getAttributes(FromQuery::class)[0]->newInstance();
            if ($fromQuery->example !== null) {
                $schema['example'] = $fromQuery->example;
            }

            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => $this->fromQueryRequired($param),
                'description' => $fromQuery->description ?? '',
                'schema' => $schema,
            ];
```

- [ ] **Step 6: Run it; verify it passes.**

Run: `vendor/bin/phpunit --filter SourceParamDescriptionTest`
Expected: PASS — described/exampled fields surface both; a bare `#[FromQuery]` still yields `description: ''`.

- [ ] **Step 7: Guard the hydrator path.**

Run: `vendor/bin/phpunit --filter RequestDataHydrator && vendor/bin/phpunit tests/Unit/Support/Documentation`
Expected: PASS — adding optional constructor args does not affect presence-based hydration or existing source-param generation.

- [ ] **Step 8: phpcs + commit.**
```bash
composer phpcs
git add src/Validation/Attributes/FromQuery.php src/Validation/Attributes/FromRoute.php \
  src/Support/Documentation/RouteReflectionDocGenerator.php \
  tests/Unit/Support/Documentation/SourceParamDescriptionTest.php
git commit -m "Let #[FromQuery]/#[FromRoute] carry an OpenAPI description and example"
```

---

## Follow-ups (not in this plan)

- **Lemma `DeliveryController` cleanup** lives in the `glueful/lemma` repo and is **blocked on releasing these two changes first** (release-the-framework-first rule). Once a framework version ships with Tasks 1+2, convert `index()`/`show()` query params to a `DeliveryListQuery`/`DeliveryShowQuery` DTO with `#[FromQuery(description: …)]`, delete the redundant `#[ApiResponse(401/403/429/500)]`, and keep only `200`/`404`/method-specific `422`.
- **CHANGELOG:** add both changes under `[Unreleased]` (minor — new config key + new optional attribute args; no breaking change) when wrapping up.

## Self-review

- **Spec coverage:** Change A (default error body, inline default + configured-DTO schema reflection, 500 opt-in, precedence preserved) → Task 1. Change B (`description`/`example` on both source attributes, asserted for `#[FromQuery]` and `#[FromRoute]`) → Task 2. Both mapped.
- **Placeholder scan:** none — every step has the literal code/command and expected output.
- **Type/name consistency:** `errorConfig()`/`buildDefaultErrorBody()` shapes match how `buildResponses()` consumes them; `ClassSchemaReflector::toSchema()` and `wrapInEnvelope()` are existing methods reused as-is; the `+ $body` union relies on `content` not colliding with `description`/`headers`. `FromQuery`/`FromRoute` gain identical `(?string $description, ?string $example)` signatures, read identically in the builder.
- **Precedence preserved:** `mergeAttributeResponses()` still runs last in `buildOperation()`, so explicit `#[ApiResponse]` overrides every new default (asserted in Task 1 Step 1).
```
