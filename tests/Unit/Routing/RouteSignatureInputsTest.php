<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * An application can add its own state to the compiled route table's signature. A table compiled
 * under one state is then rejected by a context booted under another, even when the table was
 * first built on a cold cache (load() misses without computing a signature, so only save() sees
 * it), and each context in one process keeps its own inputs.
 */
final class RouteSignatureInputsTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::removeTree($root);
        }
        parent::tearDown();
    }

    public function testAColdCacheIsSavedUnderTheContextsInputNotALaterValue(): void
    {
        [$ctxA, $routerA] = $this->freshContextWithOneRoute(null, ['capability_state' => '1']);
        self::assertNull((new RouteCache($ctxA))->load(), 'cold: no table yet');
        self::assertTrue((new RouteCache($ctxA))->save($routerA));

        [$ctxB] = $this->freshContextWithOneRoute($ctxA->getBasePath(), ['capability_state' => '2']);
        self::assertNull((new RouteCache($ctxB))->load(), 'built under 1, unusable under 2');
    }

    public function testConsecutiveContextsInOneProcessKeepTheirOwnInputs(): void
    {
        [$ctxA, $routerA] = $this->freshContextWithOneRoute(null, ['capability_state' => '1']);
        self::assertTrue((new RouteCache($ctxA))->save($routerA));

        [$ctxB] = $this->freshContextWithOneRoute($ctxA->getBasePath(), ['capability_state' => '1']);
        self::assertNotNull((new RouteCache($ctxB))->load(), 'same state, same table');

        [$ctxC] = $this->freshContextWithOneRoute($ctxA->getBasePath());
        self::assertNull((new RouteCache($ctxC))->load(), 'a context without the input is a different state');
    }

    public function testNoInputsKeepTodaysSignature(): void
    {
        [$ctx] = $this->freshContextWithOneRoute();
        $cache = new RouteCache($ctx);
        self::assertSame(self::todaysSignatureFor($cache), $cache->getSignature());
    }

    public function testInputsAreReturnedSortedByName(): void
    {
        [$ctx] = $this->freshContextWithOneRoute();
        $ctx->setRouteSignatureInput('zeta', '1');
        $ctx->setRouteSignatureInput('alpha', '2');
        self::assertSame(['alpha' => '2', 'zeta' => '1'], $ctx->routeSignatureInputs());
    }

    /**
     * Like an application boot: the inputs are set before the Router exists (its constructor loads
     * the cache, and a mismatched table is deleted there).
     *
     * @param array<string, string> $inputs
     * @return array{0: ApplicationContext, 1: Router}
     */
    private function freshContextWithOneRoute(?string $root = null, array $inputs = []): array
    {
        if ($root === null) {
            $root = sys_get_temp_dir() . '/route_inputs_' . bin2hex(random_bytes(4));
            mkdir($root . '/routes', 0755, true);
            mkdir($root . '/storage/cache', 0755, true);
            $this->roots[] = $root;
        }
        $context = new ApplicationContext($root, environment: 'development');
        $container = new Container();
        $container->load([ApplicationContext::class => new ValueDefinition(ApplicationContext::class, $context)]);
        $context->setContainer($container);
        foreach ($inputs as $name => $value) {
            $context->setRouteSignatureInput($name, $value);
        }

        $router = new Router($container);
        $router->get('/v1/widgets', [RouteSignatureInputsController::class, 'index']);

        return [$context, $router];
    }

    /** The signature algorithm before inputs existed, over the same source files. */
    private static function todaysSignatureFor(RouteCache $cache): string
    {
        $method = new \ReflectionMethod(RouteCache::class, 'getRouteSourceFiles');
        /** @var list<string> $files */
        $files = $method->invoke($cache);
        sort($files);
        $format = (new \ReflectionClassConstant(RouteCache::class, 'CACHE_FORMAT'))->getValue();

        $ctx = hash_init('sha256');
        hash_update($ctx, 'fmt:' . $format);
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $stat = @stat($file);
            if ($stat === false) {
                continue;
            }
            hash_update($ctx, $file);
            hash_update($ctx, (string) ($stat['mtime'] ?? 0));
            hash_update($ctx, (string) ($stat['size'] ?? 0));
        }
        return hash_final($ctx);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

final class RouteSignatureInputsController
{
    public function index(): array
    {
        return [];
    }
}
