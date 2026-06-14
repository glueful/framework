<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\RouteCache;
use Glueful\Routing\Router;
use Glueful\Support\Documentation\RouteReflectionDocGenerator;
use Glueful\Support\Documentation\SecuritySchemeRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \Glueful\Support\Documentation\RouteReflectionDocGenerator
 * @covers \Glueful\Routing\Attributes\ApiOperation
 */
final class ApiOperationAttributeTest extends TestCase
{
    private function makeRouter(?ApplicationContext $context = null): Router
    {
        $context ??= new ApplicationContext(sys_get_temp_dir() . '/apiop_' . uniqid());

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

    public function testApiOperationOverridesDerivedValues(): void
    {
        $router = $this->makeRouter();
        $router->post('/v1/auth/sign-in', [AoController::class, 'annotated'])->name('auth.sign-in');

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $op = $paths['/v1/auth/sign-in']['post'];
        self::assertSame('Sign in', $op['summary']);
        self::assertSame(['Authentication'], $op['tags']);
        self::assertStringContainsString('Authenticate a user.', $op['description']);
        self::assertSame('authSignIn', $op['operationId']);
        self::assertTrue($op['deprecated']);
    }

    public function testDerivedValuesStillApplyWithoutAttribute(): void
    {
        $router = $this->makeRouter();
        $router->get('/v1/widgets/list', [AoController::class, 'derived'])->name('widgets.list');

        $paths = (new RouteReflectionDocGenerator($this->registry()))->generate($router);

        $op = $paths['/v1/widgets/list']['get'];
        // Derived summary from the route name, derived tag from the path.
        self::assertNotSame('', $op['summary']);
        self::assertSame('Widgets List', $op['summary']);
        self::assertSame(['Widgets'], $op['tags']);
        self::assertArrayNotHasKey('deprecated', $op);
    }
}

/**
 * App-namespaced controller stub exercising #[ApiOperation].
 */
final class AoController
{
    #[ApiOperation(
        summary: 'Sign in',
        description: 'Authenticate a user.',
        tags: ['Authentication'],
        operationId: 'authSignIn',
        deprecated: true,
    )]
    public function annotated(): void
    {
    }

    public function derived(): void
    {
    }
}
