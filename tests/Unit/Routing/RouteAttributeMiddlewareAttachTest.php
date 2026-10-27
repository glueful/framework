<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Routing;

use Glueful\Api\RateLimiting\Attributes\RateLimit;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\AttributeRouteLoader;
use Glueful\Routing\Attributes\{Fields, Get};
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteMiddleware;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A route that declares a rate limit (`->rateLimit()` builder or `#[RateLimit]`) or a field
 * selection policy (`#[Fields]`) must actually run the enforcing middleware. Previously the
 * config was stored on the route but `rate_limit` / `field_selection` were never attached, so
 * the declaration was silently decorative unless the developer also added the middleware.
 */
final class RouteAttributeMiddlewareAttachTest extends TestCase
{
    private Router $router;
    private ContainerInterface $container;
    private CountingMiddleware $rateLimit;
    private CountingMiddleware $fieldSelection;

    protected function setUp(): void
    {
        parent::setUp();

        $context = new ApplicationContext(sys_get_temp_dir() . '/route_attach_test_' . uniqid());
        (new RouteCache($context))->clear();

        $this->container = new class implements ContainerInterface {
            /** @var array<string,mixed> */
            private array $services = [];

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }

            public function get(string $id): mixed
            {
                if ($this->has($id)) {
                    return $this->services[$id];
                }

                throw new class (
                    "Service '" . $id . "' not found"
                ) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function set(string $id, mixed $service): void
            {
                $this->services[$id] = $service;
            }
        };
        $this->container->set(ApplicationContext::class, $context);

        // One counter shared by both aliases: they resolve to the same middleware class.
        $this->rateLimit = new CountingMiddleware();
        $this->container->set('rate_limit', $this->rateLimit);
        $this->container->set('enhanced_rate_limit', $this->rateLimit);
        $this->fieldSelection = new CountingMiddleware();
        $this->container->set('field_selection', $this->fieldSelection);
        $this->container->set(AttachFixtureController::class, new AttachFixtureController());

        $this->router = new Router($this->container);
    }

    public function testRateLimitBuilderAutoAttachesRateLimitMiddleware(): void
    {
        $this->router->get('/limited', fn() => new Response('ok'))->rateLimit(5);

        $this->router->dispatch(Request::create('/limited', 'GET'));

        $this->assertSame(1, $this->rateLimit->calls);
    }

    public function testRouteWithoutRateLimitDoesNotAttachMiddleware(): void
    {
        $this->router->get('/open', fn() => new Response('ok'));

        $this->router->dispatch(Request::create('/open', 'GET'));

        $this->assertSame(0, $this->rateLimit->calls);
        $this->assertSame(0, $this->fieldSelection->calls);
    }

    /**
     * @dataProvider explicitRateLimitMiddlewareProvider
     */
    public function testRateLimitIsNotAttachedTwiceWhenAlreadyPresent(string $explicit): void
    {
        $this->router->get('/limited', fn() => new Response('ok'))
            ->middleware($explicit)
            ->rateLimit(5);

        $this->router->dispatch(Request::create('/limited', 'GET'));

        $this->assertSame(1, $this->rateLimit->calls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function explicitRateLimitMiddlewareProvider(): array
    {
        return [
            'rate_limit' => ['rate_limit'],
            'rate_limit with params' => ['rate_limit:10,60'],
            'enhanced_rate_limit alias' => ['enhanced_rate_limit'],
        ];
    }

    public function testRateLimitAttributeAutoAttachesRateLimitMiddleware(): void
    {
        (new AttributeRouteLoader($this->router))->processClass(AttachFixtureController::class);

        $this->router->dispatch(Request::create('/attr/limited', 'GET'));

        $this->assertSame(1, $this->rateLimit->calls);
        $this->assertSame(0, $this->fieldSelection->calls);
    }

    public function testFieldsAttributeAutoAttachesFieldSelectionMiddleware(): void
    {
        (new AttributeRouteLoader($this->router))->processClass(AttachFixtureController::class);

        $this->router->dispatch(Request::create('/attr/fields', 'GET'));

        $this->assertSame(1, $this->fieldSelection->calls);
        $this->assertSame(0, $this->rateLimit->calls);
    }

    public function testBareFieldsAttributeStillAttachesFieldSelectionMiddleware(): void
    {
        (new AttributeRouteLoader($this->router))->processClass(AttachFixtureController::class);

        $this->router->dispatch(Request::create('/attr/fields-bare', 'GET'));

        $this->assertSame(1, $this->fieldSelection->calls);
    }

    public function testFieldsAttributeDoesNotDuplicateExplicitFieldSelection(): void
    {
        (new AttributeRouteLoader($this->router))->processClass(AttachFixtureController::class);

        $this->router->dispatch(Request::create('/attr/fields-explicit', 'GET'));

        $this->assertSame(1, $this->fieldSelection->calls);
    }
}

final class CountingMiddleware implements RouteMiddleware
{
    public int $calls = 0;

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $this->calls++;
        return $next($request);
    }
}

final class AttachFixtureController
{
    #[Get('/attr/limited')]
    #[RateLimit(attempts: 10)]
    public function limited(): Response
    {
        return new Response('ok');
    }

    #[Get('/attr/fields')]
    #[Fields(allowed: ['id', 'name'], strict: true)]
    public function fields(): Response
    {
        return new Response('ok');
    }

    #[Get('/attr/fields-bare')]
    #[Fields]
    public function fieldsBare(): Response
    {
        return new Response('ok');
    }

    #[Get('/attr/fields-explicit')]
    #[\Glueful\Routing\Attributes\Middleware('field_selection')]
    #[Fields(allowed: ['id'])]
    public function fieldsExplicit(): Response
    {
        return new Response('ok');
    }
}
