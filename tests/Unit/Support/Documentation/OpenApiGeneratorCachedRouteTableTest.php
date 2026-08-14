<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use Glueful\Services\FileFinder;
use Glueful\Support\Documentation\DocGenerator;
use Glueful\Support\Documentation\OpenApiGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Generation must survive a populated compiled route cache.
 *
 * Reproduces the field defect: with `storage/cache/routes_{env}.php` present,
 * the Router is hydrated from the cache at construction and the boot sequence
 * then loads the route manifest onto it (Framework::initializeHttpLayer). If
 * documentation generation resets the manifest guard and re-runs the route
 * files against that same router, every `->name()` call re-registers an
 * already-registered name and Router::registerNamedRoute() throws
 * "Route name '…' already exists" — generation dies until the (gitignored)
 * cache file is deleted by hand.
 *
 * @covers \Glueful\Support\Documentation\OpenApiGenerator
 */
final class OpenApiGeneratorCachedRouteTableTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/openapi_routecache_' . uniqid();
        mkdir($this->tmpDir . '/routes', 0755, true);
        file_put_contents($this->tmpDir . '/routes/api.php', $this->appRouteFile());
        RouteManifest::reset();
    }

    protected function tearDown(): void
    {
        RouteManifest::reset();
        $this->rrmdir($this->tmpDir);
    }

    /**
     * A populated route cache must not break generation.
     */
    public function testGenerationSucceedsWithPopulatedRouteCache(): void
    {
        $context = $this->makeContext();
        $this->seedRouteCache($context);

        $router = $this->bootRouterFromCache($context);
        self::assertTrue($router->wasLoadedFromCache(), 'precondition: router hydrated from cache');

        $spec = json_decode($this->generate($context), true);

        self::assertIsArray($spec);
        self::assertArrayHasKey('/v1/widgets', $spec['paths'], 'app path present despite populated cache');
        self::assertArrayHasKey('/v1/widgets/{id}', $spec['paths'], 'dynamic app path present despite cache');
    }

    /**
     * The document produced under a populated cache must be byte-identical to
     * the one produced with no cache at all — the cache is a runtime dispatch
     * optimisation and must never leak into the emitted spec.
     */
    public function testCachedAndUncachedGenerationProduceIdenticalDocuments(): void
    {
        // Run 1: no route cache on disk.
        $contextA = $this->makeContext();
        (new RouteCache($contextA))->clear();
        RouteManifest::reset();
        $routerA = new Router($contextA->getContainer());
        self::assertFalse($routerA->wasLoadedFromCache(), 'precondition: no cache for the first run');
        $this->registerRouter($contextA, $routerA);
        RouteManifest::load($routerA, $contextA);
        $uncached = $this->generate($contextA);

        // Run 2: same sources, but a populated cache and a fresh "process".
        $contextB = $this->makeContext();
        $this->seedRouteCache($contextB);
        $routerB = $this->bootRouterFromCache($contextB);
        self::assertTrue($routerB->wasLoadedFromCache(), 'precondition: router hydrated from cache');
        $cached = $this->generate($contextB);

        self::assertSame($uncached, $cached, 'spec must be byte-identical with and without a route cache');
    }

    /**
     * Write a compiled route cache to storage/cache for this base path.
     *
     * Seeded from a bare router carrying only the app routes so the fixture
     * never depends on whether framework route files use closure handlers
     * (RouteCache refuses to compile those).
     */
    private function seedRouteCache(ApplicationContext $context): void
    {
        $cache = new RouteCache($context);
        $cache->clear();

        $seed = new Router($context->getContainer());
        $seed->get('/v1/widgets', [CachedRouteTableController::class, 'index'])->name('widgets.index');
        $seed->get('/v1/widgets/{id}', [CachedRouteTableController::class, 'show'])
            ->where('id', '\d+')
            ->name('widgets.show');

        self::assertTrue($cache->save($seed), 'route cache fixture written');
        self::assertFileExists($cache->getCacheFilePath());
    }

    /**
     * Mirror Framework::initializeHttpLayer() for a process that starts with a
     * populated cache: construct the Router (hydrates from cache), then load
     * the manifest onto it.
     */
    private function bootRouterFromCache(ApplicationContext $context): Router
    {
        RouteManifest::reset();
        $router = new Router($context->getContainer());
        $this->registerRouter($context, $router);
        RouteManifest::load($router, $context);

        return $router;
    }

    /**
     * Run generation and return the raw bytes written to openapi.json.
     */
    private function generate(ApplicationContext $context): string
    {
        $generator = new OpenApiGenerator(
            $context,
            new DocGenerator(context: $context),
            new FileFinder(),
            true,
        );
        $generator->onProgress(static function (): void {
            // silence
        });

        $outputPath = $generator->generateOpenApiSpec();
        self::assertFileExists($outputPath);

        return (string) file_get_contents($outputPath);
    }

    private function registerRouter(ApplicationContext $context, Router $router): void
    {
        /** @var Container $container */
        $container = $context->getContainer();
        $container->load([
            Router::class => new ValueDefinition(Router::class, $router),
        ]);
    }

    private function makeContext(): ApplicationContext
    {
        $context = new ApplicationContext($this->tmpDir, environment: 'development');
        $container = new Container();
        $container->load([
            ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context),
        ]);
        $context->setContainer($container);

        $context->mergeConfigDefaults('documentation', [
            'openapi_version' => '3.1.0',
            'security_schemes' => [
                'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
            ],
            'middleware_map' => ['auth' => ['BearerAuth']],
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

    private function appRouteFile(): string
    {
        $controller = '\\' . CachedRouteTableController::class . '::class';

        return <<<PHP
        <?php

        /** @var \\Glueful\\Routing\\Router \$router */
        \$router->get('/v1/widgets', [{$controller}, 'index'])->name('widgets.index');
        \$router->get('/v1/widgets/{id}', [{$controller}, 'show'])
            ->where('id', '\\\\d+')
            ->name('widgets.show');
        PHP;
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

/**
 * Non-closure handler so the route table is cacheable.
 */
final class CachedRouteTableController
{
    public function index(): void
    {
    }

    public function show(): void
    {
    }
}
