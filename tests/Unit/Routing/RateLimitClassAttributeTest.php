<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Api\RateLimiting\Attributes\RateLimit;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\AttributeRouteLoader;
use Glueful\Routing\Attributes\{Controller, Get, Route as RouteAttr};
use Glueful\Routing\Route;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * #[RateLimit] targets classes as well as methods. A class-level limit applies to every route in
 * the controller; any method-level #[RateLimit] replaces the class-level set for that method
 * (method first, class fallback — the precedence the versioning attributes use).
 */
final class RateLimitClassAttributeTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $context = new ApplicationContext(sys_get_temp_dir() . '/ratelimit_class_test_' . uniqid('', true));
        (new RouteCache($context))->clear();

        $container = new class ($context) implements ContainerInterface {
            public function __construct(private ApplicationContext $context)
            {
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->context;
                }

                throw new class (
                    "Service '" . $id . "' not found"
                ) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }
        };

        $this->router = new Router($container);
        (new AttributeRouteLoader($this->router))->processClass(ClassLimitedController::class);
    }

    public function testClassLevelRateLimitAppliesToRoutesWithoutTheirOwn(): void
    {
        $config = $this->route('/class-limited/inherits')->getRateLimitConfig();

        $this->assertCount(2, $config, 'both stacked class-level limits apply');
        $this->assertSame(30, $config[0]['attempts']);
        $this->assertSame(60, $config[0]['decaySeconds']);
        $this->assertSame(500, $config[1]['attempts']);
        $this->assertSame(3600, $config[1]['decaySeconds']);
    }

    public function testClassLevelRateLimitAlsoAppliesToRouteAttributeRoutes(): void
    {
        $config = $this->route('/class-limited/via-route-attr')->getRateLimitConfig();

        $this->assertSame([30, 500], array_column($config, 'attempts'));
    }

    public function testMethodLevelRateLimitOverridesClassLevel(): void
    {
        $config = $this->route('/class-limited/own')->getRateLimitConfig();

        $this->assertCount(1, $config, 'method-level set replaces, not merges with, the class-level set');
        $this->assertSame(5, $config[0]['attempts']);
    }

    private function route(string $path): Route
    {
        $route = $this->router->getStaticRoutes()['GET:' . $path] ?? null;
        $this->assertInstanceOf(Route::class, $route, "route {$path} registered");

        return $route;
    }
}

#[Controller(prefix: '/class-limited')]
#[RateLimit(attempts: 30)]
#[RateLimit(attempts: 500, perHours: 1)]
final class ClassLimitedController
{
    #[Get('/inherits')]
    public function inherits(): string
    {
        return 'ok';
    }

    #[RouteAttr('/via-route-attr', methods: ['GET'])]
    public function viaRouteAttr(): string
    {
        return 'ok';
    }

    #[Get('/own')]
    #[RateLimit(attempts: 5)]
    public function own(): string
    {
        return 'ok';
    }
}
