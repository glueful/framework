<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use Glueful\Tests\Support\Fixtures\RequestData\DualSourceFixture;
use Glueful\Tests\Support\Fixtures\RequestData\HasBadNestedFixture;
use Glueful\Tests\Support\Fixtures\RequestData\SourcedFixture;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class RequestDataSourceParamsTest extends TestCase
{
    private function makeRouter(?ApplicationContext $context = null): Router
    {
        $context ??= new ApplicationContext(sys_get_temp_dir() . '/reqsource_' . uniqid());

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

    private function registry(): SecuritySchemeRegistry
    {
        return new SecuritySchemeRegistry([], []);
    }

    /**
     * @param  list<array<string, mixed>> $params
     * @return array<string, mixed>|null
     */
    private function paramWhere(array $params, string $name, string $in): ?array
    {
        foreach ($params as $param) {
            if (($param['name'] ?? null) === $name && ($param['in'] ?? null) === $in) {
                return $param;
            }
        }

        return null;
    }

    public function testFromRouteFieldBecomesPathParam(): void
    {
        $router = $this->makeRouter();
        $router->put('/items/{uuid}', [SourceParamController::class, 'update']);
        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);
        $params = $paths['/items/{uuid}']['put']['parameters'];
        $uuid = $this->paramWhere($params, 'uuid', 'path');
        self::assertNotNull($uuid);
        self::assertTrue($uuid['required']);
        $status = $this->paramWhere($params, 'status', 'query');
        self::assertNotNull($status);
        $body = $paths['/items/{uuid}']['put']['requestBody']['content']['application/json']['schema'];
        self::assertArrayNotHasKey('uuid', $body['properties']);
        self::assertArrayHasKey('title', $body['properties']);
    }

    public function testFromRouteWithoutPlaceholderFailsGeneration(): void
    {
        $router = $this->makeRouter();
        $router->post('/items', [SourceParamController::class, 'orphan']);
        $this->expectException(\LogicException::class);
        (new RouteReflectionDocGenerator($this->registry()))->generate($router);
    }

    public function testBothSourceAttributesFailGeneration(): void
    {
        $router = $this->makeRouter();
        $router->post('/dual', [SourceParamController::class, 'dual']);
        $this->expectException(\LogicException::class);
        (new RouteReflectionDocGenerator($this->registry()))->generate($router);
    }

    public function testNestedSourceAttributeFailsGeneration(): void
    {
        // HasBadNestedFixture has #[ArrayOf(BadNestedSourceFixture)], and that nested
        // DTO carries a #[FromRoute] field — a source attribute below the top level.
        $router = $this->makeRouter();
        $router->post('/bad', [SourceParamController::class, 'bad']);
        $this->expectException(\LogicException::class);
        (new RouteReflectionDocGenerator($this->registry()))->generate($router);
    }
}

/**
 * App-namespaced controller stub exercising RequestData source attributes.
 */
final class SourceParamController
{
    public function update(SourcedFixture $in): \Symfony\Component\HttpFoundation\Response
    {
        return new \Symfony\Component\HttpFoundation\Response();
    }

    public function orphan(SourcedFixture $in): \Symfony\Component\HttpFoundation\Response
    {
        return new \Symfony\Component\HttpFoundation\Response();
    }

    public function dual(DualSourceFixture $in): \Symfony\Component\HttpFoundation\Response
    {
        return new \Symfony\Component\HttpFoundation\Response();
    }

    public function bad(HasBadNestedFixture $in): \Symfony\Component\HttpFoundation\Response
    {
        return new \Symfony\Component\HttpFoundation\Response();
    }
}
