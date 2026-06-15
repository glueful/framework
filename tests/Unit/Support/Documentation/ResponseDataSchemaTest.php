<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\ResponseStatus;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\ClassSchemaReflector;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class ResponseDataSchemaTest extends TestCase
{
    private const SCHEMES = [
        'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
    ];

    private const MIDDLEWARE_MAP = [
        'auth' => ['BearerAuth'],
    ];

    private function makeRouter(?ApplicationContext $context = null): Router
    {
        $context ??= new ApplicationContext(sys_get_temp_dir() . '/respdata_' . uniqid());

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
        return new SecuritySchemeRegistry(self::SCHEMES, self::MIDDLEWARE_MAP);
    }

    public function testReturnTypeInfersEnvelopeWrappedSchemaAt200(): void
    {
        $router = $this->makeRouter();
        $router->get('/posts/{id}', [PbController::class, 'show']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/posts/{id}']['get']['responses']['200']['content']['application/json']['schema'];

        // The success response is the envelope-wrapped DTO schema, in lockstep
        // with ClassSchemaReflector::toSchema().
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
                'data' => ClassSchemaReflector::toSchema(PbPostData::class),
            ],
            'required' => ['success', 'message', 'data'],
        ], $schema);

        // Spot-check the nested structure the envelope carries.
        $data = $schema['properties']['data'];
        self::assertSame('object', $data['properties']['author']['type']);
        self::assertSame('string', $data['properties']['author']['properties']['name']['type']);
        // Backed string enum.
        self::assertSame(['draft', 'published'], $data['properties']['status']['enum']);
        // Nullable rendered exactly as ClassSchemaReflector emits it (stays in
        // lockstep via the array-equality assertion above).
        self::assertTrue($data['properties']['publishedAt']['nullable']);
    }

    public function testResponseStatusAttributePlacesInferredResponseAtThatStatus(): void
    {
        $router = $this->makeRouter();
        $router->post('/posts', [PbController::class, 'store']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $responses = $paths['/posts']['post']['responses'];

        // Inferred response lives under 201 (the #[ResponseStatus]), not 200.
        self::assertArrayHasKey('201', $responses);
        self::assertArrayHasKey('content', $responses['201']);
        self::assertSame(
            ClassSchemaReflector::toSchema(PbPostData::class),
            $responses['201']['content']['application/json']['schema']['properties']['data'],
        );
        // The vestigial description-only 200 is dropped when the inferred success
        // status is non-200 — only the 201 is documented.
        self::assertArrayNotHasKey('200', $responses);
    }

    public function testExplicitApiResponseWinsOverInferredReturnType(): void
    {
        $router = $this->makeRouter();
        $router->get('/override', [PbController::class, 'override']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/override']['get']['responses']['200']['content']['application/json']['schema'];

        // The explicit #[ApiResponse(200, OtherData::class)] wins: OtherData's
        // shape, NOT PbPostData's.
        $data = $schema['properties']['data'];
        self::assertArrayHasKey('reference', $data['properties']);
        self::assertArrayNotHasKey('author', $data['properties']);
    }

    public function testHandlerWithoutResponseDataReturnTypeKeepsDefaults(): void
    {
        $router = $this->makeRouter();
        $router->get('/plain', [PbController::class, 'plain']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $responses = $paths['/plain']['get']['responses'];

        // No envelope-from-return-type injected: the default 200 is untouched.
        self::assertSame(['description' => 'Successful response'], $responses['200']);
    }
}

/**
 * Backed string enum carried by a ResponseData DTO.
 */
enum PbStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

/**
 * Nested ResponseData DTO.
 */
final class PbAuthorData implements ResponseData
{
    public string $name = '';
    public string $email = '';
}

/**
 * ResponseData DTO with nested object, backed enum and a nullable property.
 */
final class PbPostData implements ResponseData
{
    public string $id = '';
    public string $title = '';
    public PbAuthorData $author;
    public PbStatus $status = PbStatus::Draft;
    public ?string $publishedAt = null;
}

/**
 * Distinct ResponseData DTO used to prove explicit #[ApiResponse] wins.
 */
final class PbOtherData implements ResponseData
{
    public string $reference = '';
}

/**
 * App-namespaced controller stub returning ResponseData DTOs.
 */
final class PbController
{
    public function show(string $id): PbPostData
    {
        return new PbPostData();
    }

    #[ResponseStatus(201)]
    public function store(): PbPostData
    {
        return new PbPostData();
    }

    #[ApiResponse(200, PbOtherData::class)]
    public function override(): PbPostData
    {
        return new PbPostData();
    }

    public function plain(): \Glueful\Http\Response
    {
        return new \Glueful\Http\Response();
    }
}
