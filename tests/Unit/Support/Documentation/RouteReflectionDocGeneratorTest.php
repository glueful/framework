<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use Glueful\Validation\Attributes\Validate;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class RouteReflectionDocGeneratorTest extends TestCase
{
    /** Security schemes used across tests. */
    private const SCHEMES = [
        'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
        'ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
    ];

    /** Middleware-to-scheme map mirroring an app that adds scope middleware. */
    private const MIDDLEWARE_MAP = [
        'auth' => ['BearerAuth'],
        'require_content_scope' => ['ApiKeyAuth'],
    ];

    private function makeRouter(?ApplicationContext $context = null): Router
    {
        $context ??= new ApplicationContext(sys_get_temp_dir() . '/reflectdoc_' . uniqid());

        // Ensure no stale compiled route cache leaks across tests.
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

    public function testSecurityDerivedFromBearerMiddleware(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/profile', [SampleAppController::class, 'show'])
            ->middleware(['auth']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $op = $paths['/v1/profile']['get'];
        self::assertArrayHasKey('security', $op);
        self::assertSame([['BearerAuth' => []]], $op['security']);
        // Secured operations advertise 401/403.
        self::assertArrayHasKey('401', $op['responses']);
        self::assertArrayHasKey('403', $op['responses']);
    }

    public function testParameterizedMiddlewareIsStrippedBeforeRegistryLookup(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/content/{type}', [SampleAppController::class, 'show'])
            ->middleware(['auth', 'require_content_scope:read:content', 'rate_limit:60,1']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $op = $paths['/v1/content/{type}']['get'];
        // Both schemes resolve; `require_content_scope:read:content` must map despite its params.
        self::assertContains(['BearerAuth' => []], $op['security']);
        self::assertContains(['ApiKeyAuth' => []], $op['security']);
    }

    public function testRouteWithoutAuthHasNoSecurityKey(): void
    {
        $router = $this->makeRouter();
        $router->get('/health', [SampleAppController::class, 'show']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $op = $paths['/health']['get'];
        self::assertArrayNotHasKey('security', $op);
        self::assertArrayNotHasKey('401', $op['responses']);
    }

    public function testPathParameterCarriesConstraintPattern(): void
    {
        $router = $this->makeRouter();
        $router->get('/users/{id}', [SampleAppController::class, 'show'])
            ->where('id', '\d+');

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $params = $paths['/users/{id}']['get']['parameters'];
        $idParam = $this->paramNamed($params, 'id');
        self::assertNotNull($idParam);
        self::assertSame('path', $idParam['in']);
        self::assertTrue($idParam['required']);
        self::assertSame('\d+', $idParam['schema']['pattern']);
    }

    public function testRateLimitProduces429WithHeaders(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/messages', [SampleAppController::class, 'show'])
            ->rateLimit(60, 1);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $op = $paths['/v1/messages']['post'];
        self::assertArrayHasKey('429', $op['responses']);
        $headers = $op['responses']['429']['headers'];
        self::assertArrayHasKey('Retry-After', $headers);
        self::assertArrayHasKey('X-RateLimit-Limit', $headers);
        self::assertArrayHasKey('X-RateLimit-Remaining', $headers);
        self::assertSame('integer', $headers['Retry-After']['schema']['type']);
    }

    public function testFieldsConfigAddsFieldsAndExpandParameters(): void
    {
        $router = $this->makeRouter();
        $route = $router->get('/v1/posts/{id}', [SampleAppController::class, 'show']);
        $route->setFieldsConfig(['allowed' => ['id', 'title', 'comments'], 'strict' => true]);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $params = $paths['/v1/posts/{id}']['get']['parameters'];
        $fields = $this->paramNamed($params, 'fields');
        $expand = $this->paramNamed($params, 'expand');
        self::assertNotNull($fields);
        self::assertNotNull($expand);
        self::assertSame('query', $fields['in']);
        self::assertStringContainsString('id, title, comments', $fields['description']);
    }

    public function testRequireScopeAppendsReadableDescription(): void
    {
        $router = $this->makeRouter();
        $route = $router->get('/v1/admin', [SampleAppController::class, 'show'])
            ->middleware(['auth']);
        // Outer = AND, inner = OR.
        $route->setRequireScopeConfig([['write:posts', 'admin'], ['publish']]);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $description = $paths['/v1/admin']['get']['description'];
        self::assertStringContainsString('(write:posts OR admin) AND publish', $description);
    }

    public function testTagDerivedFromPathStrippingVersion(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/content/{type}', [SampleAppController::class, 'show']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertSame(['Content'], $paths['/v1/content/{type}']['get']['tags']);
    }

    public function testFrameworkRoutesExcludedWhenFlagDisabled(): void
    {
        $context = new ApplicationContext(sys_get_temp_dir() . '/reflectdoc_fw_' . uniqid());
        $context->mergeConfigDefaults('documentation', [
            'sources' => ['include_framework_routes' => false],
        ]);

        $router = $this->makeRouter($context);
        // App route stays; framework-namespaced handler is filtered out.
        $router->get('/v1/app-thing', 'App\\Http\\AppController::show');
        $router->get('/v1/fw-thing', 'Glueful\\Some\\FrameworkController::show');

        $paths = (new RouteReflectionDocGenerator($this->registry(), $context))->generate($router);

        self::assertArrayHasKey('/v1/app-thing', $paths);
        self::assertArrayNotHasKey('/v1/fw-thing', $paths);
    }

    public function testOriginOfIsAPureClassifier(): void
    {
        self::assertSame('app', RouteReflectionDocGenerator::originOf('App\\Http\\FooController'));
        self::assertSame('framework', RouteReflectionDocGenerator::originOf('Glueful\\Controllers\\Bar'));
        self::assertSame('extension', RouteReflectionDocGenerator::originOf('Acme\\Blog\\PostController'));
        self::assertSame('app', RouteReflectionDocGenerator::originOf(null));
        self::assertSame('framework', RouteReflectionDocGenerator::originOf('\\Glueful\\Leading\\Slash'));
    }

    public function testOperationIdsAreUnique(): void
    {
        $router = $this->makeRouter();
        // Two distinct paths sharing the same route name collide on operationId
        // and must be de-duped.
        // Distinct names that humanize to the same operationId stem (itemsList).
        $router->get('/items', [SampleAppController::class, 'show'])->name('items.list');
        $router->get('/things', [SampleAppController::class, 'index'])->name('items_list');

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $ids = [];
        foreach ($paths as $verbs) {
            foreach ($verbs as $op) {
                $ids[] = $op['operationId'];
            }
        }

        self::assertCount(count($ids), array_unique($ids), 'operationIds must be unique: ' . implode(',', $ids));
    }

    public function testGenerateIsRepeatableWithoutOperationIdDrift(): void
    {
        $router = $this->makeRouter();
        $router->get('/items', [SampleAppController::class, 'show'])->name('items.list');
        $router->get('/things', [SampleAppController::class, 'index'])->name('items_list');

        $generator = new RouteReflectionDocGenerator($this->registry());

        $first = $generator->generate($router);
        $second = $generator->generate($router);

        // Calling generate() twice must not carry collision suffixes forward:
        // the second run produces byte-identical operationIds to the first.
        self::assertSame($this->extractIds($first), $this->extractIds($second));

        // And within a single run, ids remain unique.
        $ids = $this->extractIds($second);
        self::assertCount(count($ids), array_unique($ids));
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $paths
     * @return list<string>
     */
    private function extractIds(array $paths): array
    {
        $ids = [];
        foreach ($paths as $verbs) {
            foreach ($verbs as $op) {
                $ids[] = (string) $op['operationId'];
            }
        }
        sort($ids);
        return $ids;
    }

    public function testEveryOperationIsStructurallyValid(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/users/{id}', [SampleAppController::class, 'show'])
            ->where('id', '\d+')
            ->middleware(['auth'])
            ->rateLimit(30, 1);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        foreach ($paths as $verbs) {
            foreach ($verbs as $op) {
                self::assertArrayHasKey('operationId', $op);
                self::assertArrayHasKey('summary', $op);
                self::assertArrayHasKey('tags', $op);
                self::assertArrayHasKey('responses', $op);
                self::assertArrayHasKey('200', $op['responses']);
            }
        }
    }

    public function testPostHandlerWithValidateProducesRequestBody(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/users', [SampleAppController::class, 'store']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $op = $paths['/v1/users']['post'];
        self::assertArrayHasKey('requestBody', $op);
        self::assertTrue($op['requestBody']['required']);

        $json = $op['requestBody']['content']['application/json'];
        $schema = $json['schema'];
        self::assertSame('object', $schema['type']);
        self::assertSame('email', $schema['properties']['email']['format']);
        self::assertSame('integer', $schema['properties']['age']['type']);
        self::assertSame(18, $schema['properties']['age']['minimum']);
        self::assertSame(255, $schema['properties']['name']['maxLength']);
        self::assertContains('email', $schema['required']);
        self::assertContains('age', $schema['required']);
        self::assertContains('name', $schema['required']);

        self::assertArrayHasKey('example', $json);
        self::assertSame('user@example.com', $json['example']['email']);
    }

    public function testGetHandlerWithValidateHasNoRequestBody(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/users', [SampleAppController::class, 'store']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertArrayNotHasKey('requestBody', $paths['/v1/users']['get']);
    }

    public function testPostHandlerWithoutValidateHasNoRequestBody(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/ping', [SampleAppController::class, 'show']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertArrayNotHasKey('requestBody', $paths['/v1/ping']['post']);
    }

    public function testClosureHandlerProducesNoRequestBodyAndDoesNotCrash(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/closure', static fn (): array => []);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        self::assertArrayHasKey('/v1/closure', $paths);
        self::assertArrayNotHasKey('requestBody', $paths['/v1/closure']['post']);
    }

    public function testEnvelopeMarksSuccessMessageDataRequired(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/widget', [EnvelopeSampleController::class, 'show']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/v1/widget']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(['success', 'message', 'data'], $schema['required']);
    }

    public function testApiResponseEnvelopeWrapsSchemaAsData(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/sample', [SampleAppController::class, 'envelopeResponse']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $op = $paths['/v1/sample']['get'];
        $schema = $op['responses']['200']['content']['application/json']['schema'];

        self::assertSame('object', $schema['type']);
        self::assertSame('boolean', $schema['properties']['success']['type']);
        self::assertSame('string', $schema['properties']['message']['type']);

        $data = $schema['properties']['data'];
        self::assertSame('object', $data['type']);
        self::assertSame('string', $data['properties']['id']['type']);
        self::assertSame('string', $data['properties']['name']['type']);
    }

    public function testApiResponseEnvelopeFalseProducesRawSchema(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/sample-raw', [SampleAppController::class, 'rawResponse']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/v1/sample-raw']['get']['responses']['200']['content']['application/json']['schema'];

        // No envelope: the DTO schema is the body directly.
        self::assertSame('object', $schema['type']);
        self::assertArrayNotHasKey('success', $schema['properties']);
        self::assertSame('string', $schema['properties']['id']['type']);
    }

    public function testApiResponseCollectionWrapsDataAsArray(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/sample-list', [SampleAppController::class, 'collectionResponse']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $schema = $paths['/v1/sample-list']['get']['responses']['200']['content']['application/json']['schema'];
        $data = $schema['properties']['data'];

        self::assertSame('array', $data['type']);
        self::assertSame('object', $data['items']['type']);
        self::assertSame('string', $data['items']['properties']['id']['type']);
    }

    public function testApiResponseDescriptionOnlyHasNoContent(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/sample-404', [SampleAppController::class, 'notFoundResponse']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $responses = $paths['/v1/sample-404']['get']['responses'];
        self::assertSame('Not found', $responses['404']['description']);
        self::assertArrayNotHasKey('content', $responses['404']);
    }

    public function testApiResponseOverlaysDefaultsKeepingAutoStatuses(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/secured-sample', [SampleAppController::class, 'envelopeResponse'])
            ->middleware(['auth'])
            ->rateLimit(60, 1);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $responses = $paths['/v1/secured-sample']['get']['responses'];
        // Explicit 200 replaced the minimal default (now carries content).
        self::assertArrayHasKey('content', $responses['200']);
        // Auto statuses survive alongside the explicit 200.
        self::assertArrayHasKey('401', $responses);
        self::assertArrayHasKey('403', $responses);
        self::assertArrayHasKey('429', $responses);
    }

    public function testHandlerWithoutApiResponseKeepsDefaultResponses(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/plain', [SampleAppController::class, 'show'])->middleware(['auth']);

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $responses = $paths['/v1/plain']['get']['responses'];
        self::assertSame(['description' => 'Successful response'], $responses['200']);
        self::assertArrayHasKey('401', $responses);
        self::assertArrayHasKey('403', $responses);
    }

    /**
     * @param list<array<string, mixed>> $params
     * @return array<string, mixed>|null
     */
    private function paramNamed(array $params, string $name): ?array
    {
        foreach ($params as $param) {
            if (($param['name'] ?? null) === $name) {
                return $param;
            }
        }
        return null;
    }
}

/**
 * App-namespaced controller stub so routes resolve to origin "app".
 */
final class SampleAppController
{
    public function show(): void
    {
    }

    public function index(): void
    {
    }

    #[Validate([
        'email' => 'required|email|unique:users',
        'age' => 'required|integer|min:18|max:120',
        'name' => 'required|string|min:1|max:255',
    ])]
    public function store(): void
    {
    }

    #[ApiResponse(200, SampleData::class)]
    public function envelopeResponse(): void
    {
    }

    #[ApiResponse(200, SampleData::class, envelope: false)]
    public function rawResponse(): void
    {
    }

    #[ApiResponse(200, SampleData::class, collection: true)]
    public function collectionResponse(): void
    {
    }

    #[ApiResponse(404, description: 'Not found')]
    public function notFoundResponse(): void
    {
    }

    #[ApiResponse(200, NullableSampleData::class)]
    public function nullableResponse(): void
    {
    }
}

/**
 * Typed DTO used as an #[ApiResponse] body schema.
 */
final class SampleData
{
    public string $id = '';
    public string $name = '';
}

/**
 * DTO with a nullable property to exercise 3.1 nullable rendering.
 */
final class NullableSampleData
{
    public string $id = '';
    public ?string $nickname = null;
}

/**
 * Minimal ResponseData fixture for the envelope-required test.
 */
final class EnvelopeItemFixture implements \Glueful\Http\Contracts\ResponseData
{
    public function __construct(
        public readonly string $id = '',
    ) {
    }
}

/**
 * Controller stub that documents an enveloped response for the required-keys test.
 */
final class EnvelopeSampleController
{
    #[ApiResponse(200, EnvelopeItemFixture::class)]
    public function show(): EnvelopeItemFixture
    {
        throw new \LogicException('doc only'); // never executed by the generator
    }
}
