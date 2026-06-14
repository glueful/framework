<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Extensions\ExtensionManager;
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use Glueful\Services\FileFinder;
use Glueful\Support\Documentation\DocGenerator;
use Glueful\Support\Documentation\OpenApiGenerator;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the documentation.generator='reflect' branch.
 *
 * Two seams are covered:
 *  1. The narrow seam: RouteReflectionDocGenerator -> DocGenerator::mergePaths()
 *     -> getSwaggerJson(), proving paths + security + securitySchemes round-trip.
 *  2. The OpenApiGenerator orchestration: with generator='reflect' and a real
 *     Router registered in the container, generateOpenApiSpec() routes through
 *     the reflect generator and writes a spec built from the live route table.
 *
 * @covers \Glueful\Support\Documentation\OpenApiGenerator
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 */
final class OpenApiGeneratorReflectModeTest extends TestCase
{
    private const SCHEMES = [
        'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
    ];

    private const MIDDLEWARE_MAP = ['auth' => ['BearerAuth']];

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/openapi_reflect_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        // Reflect mode calls RouteManifest::load(); reset its static guard so the
        // loaded state never leaks into or out of these tests.
        RouteManifest::reset();
    }

    protected function tearDown(): void
    {
        RouteManifest::reset();
        $this->rrmdir($this->tmpDir);
    }

    /**
     * Narrow seam: feed reflected paths through DocGenerator and assert the
     * emitted spec carries the operation security and component schemes.
     */
    public function testReflectedPathsRoundTripThroughDocGenerator(): void
    {
        $context = $this->makeContext('comments'); // generator value irrelevant here
        $router = $this->makeRouter($context);
        $router->get('/v1/profile', [SampleAppController::class, 'show'])->middleware(['auth']);
        $router->get('/v1/users/{id}', [SampleAppController::class, 'show'])->where('id', '\d+');

        $registry = new SecuritySchemeRegistry(self::SCHEMES, self::MIDDLEWARE_MAP);
        $reflect = new RouteReflectionDocGenerator($registry, $context);

        $doc = new DocGenerator(context: $context);
        $doc->setSecurityRegistry($registry);
        $doc->mergePaths($reflect->generate($router));

        $spec = json_decode($doc->getSwaggerJson(), true);

        self::assertArrayHasKey('/v1/profile', $spec['paths']);
        self::assertArrayHasKey('/v1/users/{id}', $spec['paths']);

        // Secured route advertises BearerAuth.
        self::assertSame([['BearerAuth' => []]], $spec['paths']['/v1/profile']['get']['security']);

        // Path param is present and required.
        $params = $spec['paths']['/v1/users/{id}']['get']['parameters'];
        self::assertSame('id', $params[0]['name']);
        self::assertTrue($params[0]['required']);

        // The registry-driven scheme is advertised under components.
        self::assertArrayHasKey('BearerAuth', $spec['components']['securitySchemes']);
    }

    /**
     * End-to-end: OpenApiGenerator with generator='reflect' writes a spec whose
     * paths came from the live route table, NOT from docblock fragments.
     */
    public function testGenerateOpenApiSpecUsesReflectGenerator(): void
    {
        $context = $this->makeContext('reflect');
        $router = $this->registerRouter($context);

        // Register routes BEFORE generation; RouteManifest::load() is a no-op
        // here (no app routes/ dir), so these are the routes reflected.
        $router->get('/v1/widgets', [SampleAppController::class, 'index'])->middleware(['auth']);
        $router->get('/v1/widgets/{id}', [SampleAppController::class, 'show'])->where('id', '\d+');

        $generator = new OpenApiGenerator(
            $context,
            new DocGenerator(context: $context),
            null,
            new FileFinder(),
            true,
        );
        $generator->onProgress(static function (): void {
            // silence
        });

        $outputPath = $generator->generateOpenApiSpec();

        self::assertFileExists($outputPath);
        $spec = json_decode((string) file_get_contents($outputPath), true);

        self::assertArrayHasKey('/v1/widgets', $spec['paths'], 'Reflected collection path present');
        self::assertArrayHasKey('/v1/widgets/{id}', $spec['paths'], 'Reflected item path present');
        self::assertSame(
            [['BearerAuth' => []]],
            $spec['paths']['/v1/widgets']['get']['security'],
            'Security derived from live auth middleware',
        );
    }

    /**
     * Sanity: in the default 'comments' mode the reflect path is NOT taken, so
     * routes registered on the live router do not leak into the spec.
     */
    public function testCommentsModeDoesNotReflectLiveRoutes(): void
    {
        $context = $this->makeContext('comments');
        $router = $this->registerRouter($context);
        $router->get('/v1/should-not-appear', [SampleAppController::class, 'index']);

        $generator = new OpenApiGenerator(
            $context,
            new DocGenerator(context: $context),
            null,
            new FileFinder(),
            true,
        );
        $generator->onProgress(static function (): void {
        });

        $outputPath = $generator->generateOpenApiSpec();
        $spec = json_decode((string) file_get_contents($outputPath), true);

        self::assertArrayNotHasKey('/v1/should-not-appear', $spec['paths'] ?? []);
    }

    private function makeContext(string $generator): ApplicationContext
    {
        $context = new ApplicationContext($this->tmpDir);
        $container = new Container();
        $container->load([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
        ]);
        $context->setContainer($container);
        // The OpenApiGenerator constructor builds a default CommentsDocGenerator,
        // which resolves an ExtensionManager from the container even in reflect
        // mode (it just isn't used there).
        $container->load([
            ExtensionManager::class => new ValueDefinition(
                ExtensionManager::class,
                new ExtensionManager($container),
            ),
        ]);

        $context->mergeConfigDefaults('documentation', [
            'generator' => $generator,
            'openapi_version' => '3.1.0',
            'security_schemes' => self::SCHEMES,
            'middleware_map' => self::MIDDLEWARE_MAP,
            'paths' => [
                'output' => $this->tmpDir . '/docs',
                'openapi' => $this->tmpDir . '/docs/openapi.json',
                'route_definitions' => $this->tmpDir . '/docs/json-definitions/routes',
                'extension_definitions' => $this->tmpDir . '/docs/json-definitions/extensions',
            ],
            'options' => [
                'include_resource_routes' => false,
                'include_extensions' => true,
                'include_routes' => true,
            ],
            'sources' => [
                'routes' => $this->tmpDir . '/routes',
                'include_framework_routes' => true,
            ],
        ]);

        return $context;
    }

    /**
     * Build a standalone Router (no container registration) with a clean cache.
     */
    private function makeRouter(ApplicationContext $context): Router
    {
        (new RouteCache($context))->clear();
        return new Router($context->getContainer());
    }

    /**
     * Build a Router and register it on the context container so OpenApiGenerator
     * resolves the same instance the test registers routes on.
     */
    private function registerRouter(ApplicationContext $context): Router
    {
        (new RouteCache($context))->clear();
        $router = new Router($context->getContainer());

        /** @var Container $container */
        $container = $context->getContainer();
        $container->load([
            Router::class => new ValueDefinition(Router::class, $router),
        ]);

        return $router;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
