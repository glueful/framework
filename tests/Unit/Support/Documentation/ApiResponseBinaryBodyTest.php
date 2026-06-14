<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Exercises the constrained `body:` escape hatch on #[ApiResponse], which lets
 * the reflect generator document non-JSON responses (binary/text/object) when
 * no DTO class schema applies.
 *
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 * @covers \Glueful\Routing\Attributes\ApiResponse
 */
final class ApiResponseBinaryBodyTest extends TestCase
{
    private function makeRouter(): Router
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/apiresp_body_' . uniqid());

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

    public function testBinaryBodyEmitsStringBinarySchema(): void
    {
        $router = $this->makeRouter();
        $router->get('/download', [BinBodyController::class, 'binary']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/download']['get']['responses']['200']['content']['application/octet-stream']['schema'];

        self::assertSame(['type' => 'string', 'format' => 'binary'], $schema);
    }

    public function testTextBodyEmitsStringSchema(): void
    {
        $router = $this->makeRouter();
        $router->get('/docs', [BinBodyController::class, 'text']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/docs']['get']['responses']['200']['content']['text/html']['schema'];

        self::assertSame(['type' => 'string'], $schema);
    }

    public function testObjectBodyEmitsObjectSchema(): void
    {
        $router = $this->makeRouter();
        $router->get('/blob', [BinBodyController::class, 'object']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/blob']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(['type' => 'object'], $schema);
    }

    public function testDescriptionOnlyResponseHasNoContentKey(): void
    {
        $router = $this->makeRouter();
        $router->get('/missing', [BinBodyController::class, 'notFound']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $response = $paths['/missing']['get']['responses']['404'];

        self::assertSame('Not found', $response['description']);
        self::assertArrayNotHasKey('content', $response);
    }

    public function testClassSchemaWinsWhenBothSchemaAndBodyAreSet(): void
    {
        $router = $this->makeRouter();
        $router->get('/both', [BinBodyController::class, 'both']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $content = $paths['/both']['get']['responses']['200']['content'];

        // The DTO class schema path runs (envelope-wrapped object), NOT the binary
        // escape hatch. So no octet-stream binary schema is emitted.
        self::assertArrayHasKey('application/json', $content);
        $schema = $content['application/json']['schema'];
        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('data', $schema['properties']);
        self::assertNotSame(['type' => 'string', 'format' => 'binary'], $schema);
    }

    public function testInvalidBodyKindThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiResponse(200, body: 'video');
    }
}

/**
 * ResponseData DTO used to prove the class-schema path wins over `body:`.
 */
final class BinBodyDtoData implements ResponseData
{
    public string $reference = '';
}

/**
 * App-namespaced controller stub carrying #[ApiResponse] body variants.
 */
final class BinBodyController
{
    #[ApiResponse(200, contentType: 'application/octet-stream', body: 'binary')]
    public function binary(): \Glueful\Http\Response
    {
        return new \Glueful\Http\Response();
    }

    #[ApiResponse(200, contentType: 'text/html', body: 'text')]
    public function text(): \Glueful\Http\Response
    {
        return new \Glueful\Http\Response();
    }

    #[ApiResponse(200, contentType: 'application/json', body: 'object')]
    public function object(): \Glueful\Http\Response
    {
        return new \Glueful\Http\Response();
    }

    #[ApiResponse(404, description: 'Not found')]
    public function notFound(): \Glueful\Http\Response
    {
        return new \Glueful\Http\Response();
    }

    #[ApiResponse(200, BinBodyDtoData::class, body: 'binary')]
    public function both(): \Glueful\Http\Response
    {
        return new \Glueful\Http\Response();
    }
}
