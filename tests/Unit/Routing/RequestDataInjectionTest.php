<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
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
}
