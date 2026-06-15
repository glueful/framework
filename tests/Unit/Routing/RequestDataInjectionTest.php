<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Glueful\Tests\Support\Fixtures\RequestData\ReservedNameInput;
use Glueful\Tests\Support\Fixtures\RequestData\SourcedFixture;
use Glueful\Tests\Support\Fixtures\Validation\ReservedNameRule;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\Support\RuleParser;
use Glueful\Validation\Support\RuleRegistry;
use Glueful\Validation\ValidationException;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fixture DTO implementing RequestData, validated via #[Rule] attributes.
 */
class CreatePostInput implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public string $title = '',
        #[Rule('required|string')]
        public string $body = '',
    ) {
    }
}

/**
 * Fixture controller whose store() method type-hints a RequestData DTO.
 */
class PostFixtureController
{
    /**
     * @return array<string, mixed>
     */
    public function store(CreatePostInput $input): array
    {
        return ['title' => $input->title, 'body' => $input->body];
    }
}

/**
 * Fixture controller whose method takes a source-aware RequestData DTO.
 */
class SourcedFixtureController
{
    /**
     * @return array<string, mixed>
     */
    public function show(SourcedFixture $input): array
    {
        return ['uuid' => $input->uuid, 'status' => $input->status, 'title' => $input->title];
    }
}

/**
 * Fixture controller whose DTO field uses an app-registered custom rule.
 */
class ReservedNameController
{
    /**
     * @return array<string, mixed>
     */
    public function store(ReservedNameInput $input): array
    {
        return ['name' => $input->name];
    }
}

class RequestDataInjectionTest extends TestCase
{
    private Router $router;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $context = new ApplicationContext(sys_get_temp_dir() . '/router_rd_test_' . uniqid());

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
        // Router resolves array-handler controllers via the container.
        $this->container->set(PostFixtureController::class, new PostFixtureController());
        $this->router = new Router($this->container);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create(
            '/posts',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body)
        );
    }

    public function testHappyPathHydratesAndInjectsDto(): void
    {
        $this->router->post('/posts', [PostFixtureController::class, 'store']);

        $request = $this->jsonRequest(['title' => 'Hello', 'body' => 'World']);
        $response = $this->router->dispatch($request);

        $payload = json_decode((string) $response->getContent(), true);

        $this->assertSame('Hello', $payload['title']);
        $this->assertSame('World', $payload['body']);
    }

    public function testInvalidBodyThrowsValidationExceptionAtDispatch(): void
    {
        $this->router->post('/posts', [PostFixtureController::class, 'store']);

        // Missing required 'title'
        $request = $this->jsonRequest(['body' => 'x']);

        $this->expectException(ValidationException::class);
        $this->router->dispatch($request);
    }

    public function testMalformedJsonThrowsValidationException(): void
    {
        $this->router->post('/posts', [PostFixtureController::class, 'store']);

        $request = Request::create(
            '/posts',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not json'
        );

        $this->expectException(ValidationException::class);
        $this->router->dispatch($request);
    }

    public function testResolvesRouteAndQuerySourcesThroughRouter(): void
    {
        $this->container->set(SourcedFixtureController::class, new SourcedFixtureController());
        $this->router->get('/items/{uuid}', [SourcedFixtureController::class, 'show']);

        $request = Request::create(
            '/items/abc',
            'GET',
            ['status' => 'published'],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => 'Hi'])
        );
        $response = $this->router->dispatch($request);

        $payload = json_decode((string) $response->getContent(), true);

        $this->assertSame('abc', $payload['uuid']);
        $this->assertSame('published', $payload['status']);
        $this->assertSame('Hi', $payload['title']);
    }

    public function testAppRegisteredRuleCarriesThroughRouterAsValidationException(): void
    {
        $registry = new RuleRegistry(RuleParser::builtinRuleNames());
        $registry->register('reserved_name', ReservedNameRule::class);

        $this->container->set(RuleRegistry::class, $registry);
        $this->container->set(
            RequestDataHydrator::class,
            new RequestDataHydrator($registry)
        );
        $this->container->set(ReservedNameController::class, new ReservedNameController());
        $this->router->post('/reserved', [ReservedNameController::class, 'store']);

        $request = Request::create(
            '/reserved',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'admin'])
        );

        $this->expectException(ValidationException::class);
        $this->router->dispatch($request);
    }
}
