<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Routing\Attributes\ResponseStatus;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * A ResponseData DTO returned from a handler must be enveloped into the standard
 * Glueful response with the status declared by #[ResponseStatus] (200 by default).
 *
 * These cases are strictly ADDITIVE: the plain Response / string / array
 * normalization paths must behave exactly as before.
 */
class ResponseDataNormalizationTest extends TestCase
{
    private Router $router;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $context = new ApplicationContext(sys_get_temp_dir() . '/respdata_test_' . uniqid());

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
        $this->router = new Router($this->container);
    }

    public function testResponseDataIsEnvelopedWith200(): void
    {
        // @phpstan-ignore-next-line - test container provides set()
        $this->container->set(ResponseDataFixtureController::class, new ResponseDataFixtureController());
        $this->router->get('/post', [ResponseDataFixtureController::class, 'show']);

        $response = $this->router->dispatch(Request::create('/post', 'GET'));

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame(true, $body['success']);
        $this->assertArrayHasKey('message', $body);
        $this->assertSame(['id' => 7, 'title' => 'Hello'], $body['data']);
    }

    public function testResponseDataIsEnvelopedWith201ViaAttribute(): void
    {
        // @phpstan-ignore-next-line - test container provides set()
        $this->container->set(ResponseDataFixtureController::class, new ResponseDataFixtureController());
        $this->router->post('/post', [ResponseDataFixtureController::class, 'create']);

        $response = $this->router->dispatch(Request::create('/post', 'POST'));

        $this->assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame(true, $body['success']);
        $this->assertSame(['id' => 7, 'title' => 'Hello'], $body['data']);
    }

    /**
     * Regression: a handler returning a plain Glueful Response passes through unchanged.
     */
    public function testPlainResponseStillPassesThrough(): void
    {
        $this->router->get('/passthrough', fn() => new \Glueful\Http\Response(['x' => 1], 202));

        $response = $this->router->dispatch(Request::create('/passthrough', 'GET'));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertInstanceOf(\Glueful\Http\Response::class, $response);
    }

    /**
     * Regression: a string return is still normalized to a bare Response of that string.
     */
    public function testStringStillNormalizedUnchanged(): void
    {
        $this->router->get('/string', fn() => 'plain text');

        $response = $this->router->dispatch(Request::create('/string', 'GET'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('plain text', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Regression: an array return is still normalized to a bare JsonResponse of that array.
     */
    public function testArrayStillNormalizedUnchanged(): void
    {
        $this->router->get('/array', fn() => ['key' => 'value']);

        $response = $this->router->dispatch(Request::create('/array', 'GET'));

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('{"key":"value"}', $response->getContent());
    }

    /**
     * A malformed #[ResponseStatus(404)] must fail loud at dispatch time.
     */
    public function testMalformedResponseStatusFailsLoud(): void
    {
        // @phpstan-ignore-next-line - test container provides set()
        $this->container->set(ResponseDataFixtureController::class, new ResponseDataFixtureController());
        $this->router->get('/broken', [ResponseDataFixtureController::class, 'broken']);

        $this->expectException(\InvalidArgumentException::class);
        $this->router->dispatch(Request::create('/broken', 'GET'));
    }
}

/**
 * Minimal ResponseData DTO with a couple of public typed properties.
 */
class PostData implements ResponseData
{
    public function __construct(
        public int $id,
        public string $title,
    ) {
    }
}

class ResponseDataFixtureController
{
    public function show(): PostData
    {
        return new PostData(7, 'Hello');
    }

    #[ResponseStatus(201)]
    public function create(): PostData
    {
        return new PostData(7, 'Hello');
    }

    #[ResponseStatus(404)]
    public function broken(): PostData
    {
        return new PostData(7, 'Hello');
    }
}
