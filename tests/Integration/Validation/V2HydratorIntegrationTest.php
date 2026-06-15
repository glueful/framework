<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Validation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Tests\Support\Fixtures\RequestData\DraftArticleFixture;
use Glueful\Tests\Support\Fixtures\RequestData\FieldDefFixture;
use Glueful\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end integration test that dispatches a real request through the Router
 * and exercises every v2 request-DTO hydrator feature at once:
 *  - #[FromRoute] path binding
 *  - #[FromQuery] query binding (with a field #[Rule])
 *  - #[ArrayOf(DtoClass)] recursive nested-DTO hydration (422 not 500)
 *  - ValidatesSelf cross-field validation
 *  - all failures surfacing as 422 with dot-path keys.
 */
class V2HydratorIntegrationTest extends TestCase
{
    private Router $router;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $context = new ApplicationContext(sys_get_temp_dir() . '/v2_hydrator_test_' . uniqid());

        $cache = new RouteCache($context);
        $cache->clear();

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

                throw new class (
                    "Service '" . $id . "' not found"
                ) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function set(string $id, mixed $service): void
            {
                $this->services[$id] = $service;
            }
        };
        $this->container->set(ApplicationContext::class, $context);
        $this->container->set(DraftArticleController::class, new DraftArticleController());

        $this->router = new Router($this->container);
        $this->router->post(
            '/articles/{uuid}/draft/{locale}',
            [DraftArticleController::class, 'store']
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    private function request(string $uri, array $body, array $query = []): Request
    {
        // Query params must live in the URI: for a POST, Request::create() routes the
        // $parameters argument into the request (body) bag, not the query bag.
        if ($query !== []) {
            $uri .= '?' . http_build_query($query);
        }

        return Request::create(
            $uri,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body)
        );
    }

    public function testValidRequestMergesPathQueryAndNestedBodyIntoTypedDto(): void
    {
        $request = $this->request(
            '/articles/abc-123/draft/en_US',
            ['schema' => [['name' => 'title', 'type' => 'string']]],
            ['preview' => 'false']
        );

        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);

        // Path params hydrated via #[FromRoute].
        $this->assertSame('abc-123', $payload['uuid']);
        $this->assertSame('en_US', $payload['locale']);
        // Query param hydrated via #[FromQuery].
        $this->assertSame('false', $payload['preview']);
        // Body array hydrated as FieldDefFixture[] via #[ArrayOf].
        $this->assertSame('FieldDefFixture', $payload['schema_element_class']);
        $this->assertSame([['name' => 'title', 'type' => 'string']], $payload['schema']);
    }

    public function testInvalidNestedElementAndCrossFieldYield422WithDotPathKeys(): void
    {
        // preview=false triggers the ValidatesSelf rule when schema is empty,
        // but here schema has one element that is missing the required `name`,
        // so the nested element fails AND (because the element is invalid) the
        // schema still hydrates a value — we assert the nested dot-path key.
        $request = $this->request(
            '/articles/abc-123/draft/en_US',
            ['schema' => [['type' => 'string']]],
            ['preview' => 'false']
        );

        try {
            $this->router->dispatch($request);
            $this->fail('Expected ValidationException to be thrown.');
        } catch (ValidationException $e) {
            $errors = $e->errors();

            $this->assertArrayHasKey('schema.0.name', $errors, 'Nested element error must use a dot-path key.');
            $this->assertNotEmpty($errors['schema.0.name']);
        }
    }

    public function testEmptySchemaTriggersCrossFieldValidation(): void
    {
        // Empty array passes the per-field `required|array` rule, so hydration
        // succeeds and the DTO is constructed — the ValidatesSelf cross-field
        // hook then rejects an empty schema when not previewing.
        $request = $this->request(
            '/articles/abc-123/draft/en_US',
            ['schema' => []],
            ['preview' => 'false']
        );

        try {
            $this->router->dispatch($request);
            $this->fail('Expected ValidationException to be thrown.');
        } catch (ValidationException $e) {
            $errors = $e->errors();

            $this->assertArrayHasKey('schema', $errors, 'Cross-field error must be present.');
            $this->assertNotEmpty($errors['schema']);
        }
    }

    public function testInvalidQueryRuleYields422(): void
    {
        // preview only accepts true|false (#[Rule('in:true,false')] on a #[FromQuery] field).
        $request = $this->request(
            '/articles/abc-123/draft/en_US',
            ['schema' => [['name' => 'title', 'type' => 'string']]],
            ['preview' => 'maybe']
        );

        $this->expectException(ValidationException::class);
        $this->router->dispatch($request);
    }
}

/**
 * Fixture controller whose handler takes the all-features v2 DTO and echoes
 * the merged, typed values back so the test can assert hydration occurred.
 */
class DraftArticleController
{
    /**
     * @return array<string, mixed>
     */
    public function store(DraftArticleFixture $input): array
    {
        $first = $input->schema[0] ?? null;

        return [
            'uuid' => $input->uuid,
            'locale' => $input->locale,
            'preview' => $input->preview,
            'schema' => array_map(
                static fn (FieldDefFixture $f): array => ['name' => $f->name, 'type' => $f->type],
                $input->schema
            ),
            'schema_element_class' => $first instanceof FieldDefFixture
                ? (new \ReflectionClass($first))->getShortName()
                : null,
        ];
    }
}
