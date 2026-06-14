<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Attributes\Validate;
use Glueful\Validation\Contracts\RequestData;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class RequestDataSchemaTest extends TestCase
{
    private const SCHEMES = [
        'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
    ];

    private const MIDDLEWARE_MAP = [
        'auth' => ['BearerAuth'],
    ];

    private function makeRouter(?ApplicationContext $context = null): Router
    {
        $context ??= new ApplicationContext(sys_get_temp_dir() . '/reqdata_' . uniqid());

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

    public function testPostHandlerWithRequestDataParamProducesRequestBody(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/posts', [SamplePostController::class, 'store']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $op = $paths['/v1/posts']['post'];
        self::assertArrayHasKey('requestBody', $op);
        self::assertTrue($op['requestBody']['required']);

        $json = $op['requestBody']['content']['application/json'];
        $schema = $json['schema'];

        self::assertSame('object', $schema['type']);

        // Properties present with the right (DTO-derived) types.
        self::assertSame('string', $schema['properties']['title']['type']);
        self::assertSame('string', $schema['properties']['body']['type']);
        self::assertSame('string', $schema['properties']['status']['type']);

        // Required is rule-driven (NOT $status, which has no `required` rule).
        self::assertSame(['title', 'body'], $schema['required']);

        // Constraints overlaid from the rule strings.
        self::assertSame(['draft', 'published'], $schema['properties']['status']['enum']);
        self::assertSame(200, $schema['properties']['title']['maxLength']);

        // Example present.
        self::assertArrayHasKey('example', $json);
        self::assertArrayHasKey('title', $json['example']);
    }

    public function testRequestDataParamWinsOverValidateAttribute(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/posts-both', [SamplePostController::class, 'storeBoth']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/v1/posts-both']['post']['requestBody']['content']['application/json']['schema'];

        // The RequestData DTO wins: `title`/`body`/`status`, not the #[Validate] `foo`.
        self::assertArrayHasKey('title', $schema['properties']);
        self::assertArrayNotHasKey('foo', $schema['properties']);
        self::assertSame(['title', 'body'], $schema['required']);
    }

    public function testGetHandlerWithRequestDataParamHasNoRequestBody(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/posts', [SamplePostController::class, 'store']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertArrayNotHasKey('requestBody', $paths['/v1/posts']['get']);
    }

    public function testPostHandlerWithoutRequestDataOrValidateHasNoRequestBody(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/plain', [SamplePostController::class, 'plain']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertArrayNotHasKey('requestBody', $paths['/v1/plain']['post']);
    }

    public function testClosureHandlerProducesNoRequestBodyAndDoesNotCrash(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/closure', static fn (): array => []);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertArrayHasKey('/v1/closure', $paths);
        self::assertArrayNotHasKey('requestBody', $paths['/v1/closure']['post']);
    }

    public function testRequiredIsConstrainedToDocumentedProperties(): void
    {
        // A non-public (protected) constructor-promoted #[Rule] param is collected into the
        // rule-driven `required` list, but ClassSchemaReflector documents only PUBLIC properties.
        // `required` must stay a subset of the documented properties — never reference a missing one.
        $router = $this->makeRouter();
        $router->post('/v1/np', [SamplePostController::class, 'storeNonPublic']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);
        $schema = $paths['/v1/np']['post']['requestBody']['content']['application/json']['schema'];

        self::assertArrayNotHasKey('secret', $schema['properties']);
        self::assertSame(['title'], $schema['required']);
        self::assertSame([], array_diff($schema['required'], array_keys($schema['properties'])));
    }
}

/**
 * App-namespaced DTO implementing RequestData with constructor-promoted #[Rule] params.
 */
final class CreatePostInput implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:200')] public string $title,
        #[Rule('required|string')]          public string $body,
        #[Rule('in:draft,published')]       public string $status = 'draft',
    ) {
    }
}

/**
 * App-namespaced controller stub whose methods take a RequestData parameter.
 */
final class NonPublicRuleInput implements RequestData
{
    public function __construct(
        #[Rule('required|string')]    public string $title,
        #[Rule('required|string')] protected string $secret = 'x',
    ) {
    }
}

final class SamplePostController
{
    public function store(CreatePostInput $input): void
    {
    }

    #[Validate(['foo' => 'required|string'])]
    public function storeBoth(CreatePostInput $input): void
    {
    }

    public function storeNonPublic(NonPublicRuleInput $input): void
    {
    }

    public function plain(): void
    {
    }
}
