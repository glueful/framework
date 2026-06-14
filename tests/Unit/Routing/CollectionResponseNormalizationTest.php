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
        self::assertSame([['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']], $body['data']);
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
        self::assertSame(3, $body['total_pages']);
        self::assertTrue($body['has_next_page']);
        self::assertTrue($body['has_previous_page']);
    }
}
