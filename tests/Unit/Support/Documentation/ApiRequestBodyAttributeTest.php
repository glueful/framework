<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 * @covers \Glueful\Routing\Attributes\ApiRequestBody
 */
final class ApiRequestBodyAttributeTest extends TestCase
{
    private function makeRouter(?ApplicationContext $context = null): Router
    {
        $context ??= new ApplicationContext(sys_get_temp_dir() . '/apireqbody_' . uniqid());

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

    public function testApiRequestBodyEmitsMultipartSchema(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/uploads', [ArbController::class, 'upload']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $requestBody = $paths['/v1/uploads']['post']['requestBody'];

        self::assertTrue($requestBody['required']);
        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'file' => ['type' => 'string', 'format' => 'binary'],
                    'visibility' => ['type' => 'string'],
                ],
                'required' => ['file'],
            ],
            $requestBody['content']['multipart/form-data']['schema'],
        );
    }

    public function testApiRequestBodyRejectsJsonContentType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiRequestBody(schema: ['type' => 'object'], contentType: 'application/json');
    }
}

/**
 * App-namespaced controller stub exercising #[ApiRequestBody].
 */
final class ArbController
{
    #[ApiRequestBody(schema: [
        'type' => 'object',
        'properties' => [
            'file' => ['type' => 'string', 'format' => 'binary'],
            'visibility' => ['type' => 'string'],
        ],
        'required' => ['file'],
    ])]
    public function upload(): void
    {
    }
}
