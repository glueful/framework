<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Http\Responses\CollectionResponse;
use Glueful\Http\Responses\PaginatedResponse;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Glueful\Support\Documentation\ClassSchemaReflector;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
// Alias for the same-namespace fixture below — used to prove that the docblock
// item resolver does NOT follow `use`-aliases (it falls back to a generic object).
use Glueful\Tests\Unit\Support\Documentation\CsPostData as AliasedPost;

final class CsPostData implements ResponseData
{
    public function __construct(public int $id, public string $title)
    {
    }
}

final class CsOtherData implements ResponseData
{
    public function __construct(public string $name)
    {
    }
}

final class CsCollectionController
{
    /** @return CollectionResponse<CsPostData> */
    public function list(): CollectionResponse
    {
        return new CollectionResponse([]);
    }

    /** @return PaginatedResponse<CsPostData> */
    public function paged(): PaginatedResponse
    {
        return new PaginatedResponse([], 1, 10, 0);
    }

    public function listNoDoc(): CollectionResponse
    {
        return new CollectionResponse([]);
    }

    /** @return CollectionResponse<CsPostData> */
    #[ApiResponse(200, CsOtherData::class, collection: true)]
    public function listOverride(): CollectionResponse
    {
        return new CollectionResponse([]);
    }

    /** @return CollectionResponse<\Glueful\Tests\Unit\Support\Documentation\CsPostData> */
    public function listFqcn(): CollectionResponse
    {
        return new CollectionResponse([]);
    }

    /** @return CollectionResponse<AliasedPost> */
    public function listAliased(): CollectionResponse
    {
        return new CollectionResponse([]);
    }
}

final class CollectionResponseSchemaTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/cs_' . uniqid());
        (new RouteCache($context))->clear();
        $container = new class ($context) implements ContainerInterface {
            /** @var array<string,mixed> */
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
                    extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };
        return new Router($container);
    }

    private function registry(): SecuritySchemeRegistry
    {
        return new SecuritySchemeRegistry([], []);
    }

    public function testCollectionReturnInfersEnvelopedArrayOfItems(): void
    {
        $router = $this->makeRouter();
        $router->get('/posts', [CsCollectionController::class, 'list']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/posts']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
                'data' => ['type' => 'array', 'items' => ClassSchemaReflector::toSchema(CsPostData::class)],
            ],
            'required' => ['success', 'message', 'data'],
        ], $schema);
    }

    public function testPaginatedReturnInfersFlatPaginatedEnvelope(): void
    {
        $router = $this->makeRouter();
        $router->get('/paged', [CsCollectionController::class, 'paged']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/paged']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
                'data' => ['type' => 'array', 'items' => ClassSchemaReflector::toSchema(CsPostData::class)],
                'current_page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
                'total' => ['type' => 'integer'],
                'total_pages' => ['type' => 'integer'],
                'has_next_page' => ['type' => 'boolean'],
                'has_previous_page' => ['type' => 'boolean'],
            ],
            'required' => [
                'success', 'message', 'data', 'current_page', 'per_page',
                'total', 'total_pages', 'has_next_page', 'has_previous_page',
            ],
        ], $schema);
    }

    public function testCollectionWithoutDocblockFallsBackToGenericObjectItems(): void
    {
        $router = $this->makeRouter();
        $router->get('/nodoc', [CsCollectionController::class, 'listNoDoc']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/nodoc']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(['type' => 'array', 'items' => ['type' => 'object']], $schema['properties']['data']);
    }

    public function testExplicitApiResponseOverridesInferredCollection(): void
    {
        $router = $this->makeRouter();
        $router->get('/override', [CsCollectionController::class, 'listOverride']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/override']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(
            ClassSchemaReflector::toSchema(CsOtherData::class),
            $schema['properties']['data']['items']
        );
    }

    public function testFullyQualifiedItemClassInDocblockResolvesPrecisely(): void
    {
        $router = $this->makeRouter();
        $router->get('/fqcn', [CsCollectionController::class, 'listFqcn']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/fqcn']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(ClassSchemaReflector::toSchema(CsPostData::class), $schema['properties']['data']['items']);
    }

    public function testUseAliasedItemClassFallsBackToGenericObject(): void
    {
        // KNOWN LIMITATION (documented): the resolver does NOT follow `use`-aliases.
        $router = $this->makeRouter();
        $router->get('/aliased', [CsCollectionController::class, 'listAliased']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/aliased']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(['type' => 'array', 'items' => ['type' => 'object']], $schema['properties']['data']);
    }
}
