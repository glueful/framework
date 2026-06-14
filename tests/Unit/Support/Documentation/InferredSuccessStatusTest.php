<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Http\Responses\CollectionResponse;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\ResponseStatus;
use Glueful\Routing\Router;
use Glueful\Routing\RouteCache;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class IssPostData implements ResponseData
{
    public function __construct(public int $id, public string $title)
    {
    }
}

final class IssOtherData implements ResponseData
{
    public function __construct(public string $name)
    {
    }
}

final class IssController
{
    public function show(): IssPostData
    {
        return new IssPostData(1, 'A');
    }

    #[ResponseStatus(201)]
    public function create(): IssPostData
    {
        return new IssPostData(1, 'A');
    }

    /** @return CollectionResponse<IssPostData> */
    #[ResponseStatus(201)]
    public function bulk(): CollectionResponse
    {
        return new CollectionResponse([]);
    }

    #[ResponseStatus(201)]
    #[ApiResponse(200, IssOtherData::class)]
    public function createWith200(): IssPostData
    {
        return new IssPostData(1, 'A');
    }
}

final class InferredSuccessStatusTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/iss_' . uniqid());
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

    /** @return array<string, mixed> */
    private function responsesFor(string $path, string $verb, string $method): array
    {
        $router = $this->makeRouter();
        $router->{$verb}($path, [IssController::class, $method]);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);
        return $paths[$path][$verb]['responses'];
    }

    public function testNon200InferredSuccessDropsVestigial200(): void
    {
        $responses = $this->responsesFor('/posts', 'post', 'create');
        self::assertArrayHasKey('201', $responses);
        self::assertArrayNotHasKey('200', $responses);
    }

    public function test200InferredSuccessKeepsFull200(): void
    {
        $responses = $this->responsesFor('/posts/{id}', 'get', 'show');
        self::assertArrayHasKey('200', $responses);
        self::assertArrayHasKey('content', $responses['200']);
    }

    public function testNon200CollectionDropsVestigial200(): void
    {
        $responses = $this->responsesFor('/posts/bulk', 'post', 'bulk');
        self::assertArrayHasKey('201', $responses);
        self::assertArrayNotHasKey('200', $responses);
    }

    public function testExplicitApiResponse200CoexistsWithNon200Inference(): void
    {
        $responses = $this->responsesFor('/posts/mixed', 'post', 'createWith200');
        self::assertArrayHasKey('201', $responses);
        self::assertArrayHasKey('200', $responses);
        self::assertArrayHasKey('content', $responses['200']);
    }
}
