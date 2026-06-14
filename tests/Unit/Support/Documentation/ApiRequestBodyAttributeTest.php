<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\ClassSchemaReflector;
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

    public function testApiRequestBodyEmitsJsonSchemaFromDtoClass(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/widgets', [ArbController::class, 'storeWidget']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $requestBody = $paths['/v1/widgets']['post']['requestBody'];

        self::assertTrue($requestBody['required']);
        self::assertSame(
            ClassSchemaReflector::toSchema(ApiReqInputData::class),
            $requestBody['content']['application/json']['schema'],
        );
    }

    public function testApiRequestBodyEmitsMultipartInlineSchema(): void
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
                ],
                'required' => ['file'],
            ],
            $requestBody['content']['multipart/form-data']['schema'],
        );
    }

    public function testApiRequestBodyRejectsInlineSchemaWithJsonContentType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiRequestBody(inlineSchema: ['type' => 'object'], contentType: 'application/json');
    }

    public function testApiRequestBodyRejectsBothSchemaAndInlineSchema(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiRequestBody(schema: ApiReqInputData::class, inlineSchema: ['type' => 'object']);
    }

    public function testApiRequestBodyRejectsNeitherSchemaNorInlineSchema(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiRequestBody();
    }

    public function testGenerationFailsLoudOnMalformedAttribute(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/broken', [ArbController::class, 'broken']);

        $this->expectException(\InvalidArgumentException::class);

        (new RouteReflectionDocGenerator($this->registry()))->generate($router);
    }

    public function testAttributeWinsOverRequestDataInference(): void
    {
        $router = $this->makeRouter();
        // Handler has BOTH a RequestData param and an #[ApiRequestBody] attribute;
        // the attribute (a multipart inline body) must win over DTO inference.
        $router->post('/v1/override', [ArbController::class, 'overrideWithAttribute']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $requestBody = $paths['/v1/override']['post']['requestBody'];

        self::assertArrayHasKey('multipart/form-data', $requestBody['content']);
        self::assertArrayNotHasKey('application/json', $requestBody['content']);
    }
}

/**
 * Small fixture DTO with public typed props for ClassSchemaReflector to reflect.
 */
final class ApiReqInputData
{
    public string $name = '';
    public int $quantity = 0;
}

/**
 * RequestData fixture so the attribute-wins case has DTO inference to override.
 */
final class ArbRequestData implements \Glueful\Validation\Contracts\RequestData
{
    public string $title = '';

    public static function fromArray(array $data): static
    {
        return new static();
    }
}

/**
 * App-namespaced controller stub exercising #[ApiRequestBody].
 */
final class ArbController
{
    #[ApiRequestBody(schema: ApiReqInputData::class)]
    public function storeWidget(): void
    {
    }

    #[ApiRequestBody(contentType: 'multipart/form-data', inlineSchema: [
        'type' => 'object',
        'properties' => [
            'file' => ['type' => 'string', 'format' => 'binary'],
        ],
        'required' => ['file'],
    ])]
    public function upload(): void
    {
    }

    /**
     * Malformed: inlineSchema with application/json must throw at generation time.
     * @phpstan-ignore-next-line attribute is intentionally malformed for the test
     */
    #[ApiRequestBody(inlineSchema: ['type' => 'object'], contentType: 'application/json')]
    public function broken(): void
    {
    }

    #[ApiRequestBody(contentType: 'multipart/form-data', inlineSchema: [
        'type' => 'object',
        'properties' => ['file' => ['type' => 'string', 'format' => 'binary']],
        'required' => ['file'],
    ])]
    public function overrideWithAttribute(ArbRequestData $data): void
    {
    }
}
