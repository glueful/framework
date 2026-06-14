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

    // JsonResource::collection() returns an AnonymousResourceCollection (a
    // ResourceCollection subclass) — the canonical way to build a collection
    // response. Proves the subclass is caught by the instanceof ResourceCollection arm.
    public function collected(): ResourceCollection
    {
        return JsonResource::collection([['name' => 'A'], ['name' => 'B']]);
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

    public function testAnonymousResourceCollectionIsNormalizedThroughToResponse(): void
    {
        // JsonResource::collection() yields an AnonymousResourceCollection (subclass
        // of ResourceCollection) — confirm the parent instanceof arm catches it.
        $this->router->get('/collected', [ResNormController::class, 'collected']);
        $response = $this->router->dispatch(Request::create('/collected', 'GET'));

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
